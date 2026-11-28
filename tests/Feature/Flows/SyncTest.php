<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\ServerSync;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
    }

    private function member(string $name, string $remoteId, ?MediaServer $server = null): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => ($server ?? $this->server)->id, 'remote_id' => $remoteId, 'remote_username' => strtolower($name), 'status' => AccountStatus::Active]);

        return $user;
    }

    private function serverHas(array $users): void
    {
        Http::fake(['jf.local/Users' => Http::response(array_map(fn ($u) => ['Id' => $u[0], 'Name' => $u[1], 'Policy' => ['IsAdministrator' => $u[2] ?? false]], $users))]);
    }

    public function test_a_user_deleted_on_the_server_loses_the_access_and_a_member_with_none_left_is_paused(): void
    {
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Other', 'url' => 'http://jf2.local', 'api_key' => 'k']);
        $gone = $this->member('Gone', 'g1');
        $alsoElsewhere = $this->member('Both', 'b1');
        ServerAccount::create(['user_id' => $alsoElsewhere->id, 'media_server_id' => $other->id, 'remote_id' => 'x1', 'remote_username' => 'both', 'status' => AccountStatus::Active]);
        foreach (['Keep1', 'Keep2', 'Keep3', 'Keep4'] as $i => $name) {
            $this->member($name, 'k'.$i);
        }
        $this->serverHas([['k0', 'keep1'], ['k1', 'keep2'], ['k2', 'keep3'], ['k3', 'keep4'], ['admin', 'owner', true], ['new', 'stranger']]);

        $result = app(ServerSync::class)->syncUsers($this->server);

        $this->assertSame([2, 2, 1, false], [$result['missing'], $result['removed'], $result['unlinked'], $result['held']]);
        $this->assertFalse($gone->serverAccounts()->exists());
        $this->assertNotNull($gone->fresh()->disabled_at); // no servers left: paused, not deleted
        $this->assertNull($alsoElsewhere->fresh()->disabled_at); // still has Other
        $this->assertSame(1, (int) $this->server->fresh()->setting('unlinked'));
    }

    public function test_when_most_users_seem_gone_at_once_nothing_is_removed(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $i => $name) {
            $this->member($name, 'u'.$i);
        }
        $this->serverHas([['u0', 'a']]);

        $result = app(ServerSync::class)->syncUsers($this->server);

        $this->assertTrue($result['held']);
        $this->assertSame(5, ServerAccount::count());
    }

    public function test_remove_a_member_from_one_server_only(): void
    {
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Other', 'url' => 'http://jf2.local', 'api_key' => 'k']);
        $anna = $this->member('Anna', 'a1');
        $keep = ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $other->id, 'remote_id' => 'a2', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        Http::fake([
            'jf.local/Users/a1' => Http::sequence()->push(['Id' => 'a1', 'Policy' => ['IsAdministrator' => false]])->push(null, 204),
            '*' => Http::response('', 404),
        ]);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('removeServer')->table($anna), ['account' => $anna->serverAccounts()->where('media_server_id', $this->server->id)->value('id')])
            ->assertNotified('Removed from Home.');

        $this->assertSame([$keep->id], $anna->serverAccounts()->pluck('id')->all());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/Users/a1'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'jf2.local'));
    }

    public function test_the_plex_button_opens_the_server_itself(): void
    {
        $plex = MediaServer::create(['type' => ServerType::Plex, 'name' => 'WaffleHub', 'url' => 'http://plex.local', 'api_key' => 'k', 'settings' => ['machine_id' => 'abc123def456']]);
        $this->assertSame('https://app.plex.tv/desktop/#!/media/abc123def456/com.plexapp.plugins.library', $plex->watchUrl());
        $plex->settings = ['machine_id' => '"><script>'];
        $this->assertSame('https://app.plex.tv', $plex->watchUrl());
        $this->assertSame('http://jf.local', $this->server->watchUrl());
    }
}
