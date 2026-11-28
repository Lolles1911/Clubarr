<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Pages\Auth\Profile;
use App\Models\ExtraUser;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Family users, pausing and moving server, done by members on My page. */
class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $this->anna->forceFill(['access_expires_at' => now()->addDays(10)])->save();
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $this->server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active, 'library_ids' => ['lib1']]);
        Http::fake([
            'jf.local/Users/New' => Http::response(['Id' => 'f1', 'Name' => 'emma']),
            'jf.local/Users/*' => Http::response(['Id' => 'x', 'Policy' => ['IsAdministrator' => false]]),
            'emby.local/Users/New' => Http::response(['Id' => 'e1', 'Name' => 'anna']),
            'emby.local/Users/*' => Http::response(['Id' => 'e1', 'Name' => 'anna', 'Policy' => ['IsAdministrator' => false]]),
            '*' => Http::response(null, 204),
        ]);
        Filament::setCurrentPanel('app');
        $this->actingAs($this->anna);
    }

    public function test_family_users_get_the_members_libraries_follow_their_access_and_have_a_limit(): void
    {
        app(SiteSettings::class)->set(['extra_users_max' => '1']);
        Livewire::test(Profile::class)->callAction('addFamily', ['server' => $this->server->id, 'label' => 'Emma', 'username' => 'emma', 'password' => 'Emma-pass-123'])->assertHasNoActionErrors();
        $extra = ExtraUser::sole();
        $this->assertSame(['f1', 'Emma'], [$extra->remote_id, $extra->label]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Users/f1/Policy') && in_array('lib1', (array) ($r['EnabledFolders'] ?? []), true));
        Livewire::test(Profile::class)->assertActionHidden('addFamily');

        app(AccessManager::class)->disableAll($this->anna->fresh(), 'test');
        $this->assertSame(AccountStatus::Disabled, $extra->fresh()->status);
        app(AccessManager::class)->removeAll($this->anna->fresh());
        $this->assertSame(0, ExtraUser::count());
    }

    public function test_pausing_moves_the_end_date_and_ends_by_itself(): void
    {
        app(SiteSettings::class)->set(['self_pause_days' => '30']);
        Livewire::test(Profile::class)->callAction('pause', ['days' => 5]);
        $anna = $this->anna->fresh();
        $this->assertNotNull($anna->self_paused_at);
        $this->assertSame(AccountStatus::Disabled, $anna->serverAccounts()->sole()->status);

        $this->travel(5)->days();
        $this->travel(1)->minutes();
        Artisan::call('clubarr:check-access');
        $anna = $anna->fresh();
        $this->assertNull($anna->self_paused_at);
        $this->assertSame(AccountStatus::Active, $anna->serverAccounts()->sole()->status);
        $this->assertTrue($anna->access_expires_at->between(now()->addDays(9), now()->addDays(11)));
    }

    public function test_moving_server_makes_the_new_user_and_removes_the_old(): void
    {
        $other = MediaServer::create(['type' => ServerType::Emby, 'name' => 'Other', 'url' => 'http://emby.local', 'api_key' => 'k']);
        Livewire::test(Profile::class)->assertActionHidden('moveServer');
        app(SiteSettings::class)->set(['switch_servers' => (string) $other->id]);

        $from = ServerAccount::sole();
        Livewire::test(Profile::class)->assertActionVisible('moveServer');
        Livewire::test(Profile::class)->callAction('moveServer', ['from' => $from->id, 'to' => $other->id, 'password' => 'Anna-pass-123'])->assertHasNoActionErrors()->assertNotified('Done. You are on Other now.');
        $this->assertSame([$other->id], $this->anna->serverAccounts()->pluck('media_server_id')->all());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/Users/a1'));
    }
}
