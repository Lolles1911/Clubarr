<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Library;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MemberAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        foreach (['films' => 'Films', 'kids' => 'Kids', 'tv' => 'TV'] as $id => $name) {
            Library::create(['media_server_id' => $this->server->id, 'external_id' => $id, 'name' => $name]);
        }
    }

    private function member(string $name, ?array $libraries = null): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $this->server->id, 'remote_id' => strtolower($name), 'remote_username' => strtolower($name), 'status' => AccountStatus::Active, 'library_ids' => $libraries]);

        return $user;
    }

    public function test_an_admin_changes_a_members_libraries_on_the_server(): void
    {
        $anna = $this->member('Anna');
        Http::fake(['jf.local/Users/anna' => Http::response(['Id' => 'anna', 'Policy' => ['IsAdministrator' => false]]), 'jf.local/Users/anna/Policy' => Http::response(null, 204)]);
        $account = $anna->serverAccounts()->sole();

        Livewire::test(ListUsers::class)->callAction(TestAction::make('libraries')->table($anna), ['libs' => [$account->id => ['films', 'tv']]])
            ->assertNotified('Libraries changed.');

        $this->assertSame(['films', 'tv'], $account->fresh()->library_ids);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Policy') && $r['EnabledFolders'] === ['films', 'tv'] && $r['EnableAllFolders'] === false);

        // All ticked again = all libraries (also new ones).
        Livewire::test(ListUsers::class)->callAction(TestAction::make('libraries')->table($anna), ['libs' => [$account->id => ['films', 'kids', 'tv']]]);
        $this->assertNull($account->fresh()->library_ids);
    }

    public function test_bulk_end_date_and_pause_never_touch_the_admin_doing_it(): void
    {
        $a = $this->member('Anna');
        $b = $this->member('Bo');
        Http::fake(['jf.local/Users/*' => Http::response(['Id' => 'x', 'Policy' => ['IsAdministrator' => false]]), 'jf.local/Users/*/Policy' => Http::response(null, 204)]);

        Livewire::test(ListUsers::class)->callTableBulkAction('bulkEnd', [$a, $b, $this->admin], ['days' => 10]);
        $this->assertTrue($a->fresh()->access_expires_at->between(now()->addDays(9), now()->addDays(11)));
        $this->assertNull($this->admin->fresh()->access_expires_at);

        Livewire::test(ListUsers::class)->callTableBulkAction('bulkDisable', [$a, $b, $this->admin]);
        $this->assertNotNull($b->fresh()->disabled_at);
        $this->assertNull($this->admin->fresh()->disabled_at);
    }

    public function test_the_dashboard_shows_members_watching_and_events(): void
    {
        $this->member('Anna');
        app(SiteSettings::class)->set(['tracearr_url' => 'http://tr.local', 'tracearr_api_key' => 'trr_pub_x']);
        Http::fake(['tr.local/api/v2/public/streams' => Http::response(['data' => [['username' => 'anna', 'media_title' => 'Dune', 'media_type' => 'movie', 'player' => 'Apple TV']]]),
            'tr.local/*' => Http::response(['data' => []])]);

        Livewire::test(\App\Filament\Admin\Widgets\WatchingNow::class)->assertSee('Dune')->assertSee('Apple TV');
        Livewire::test(\App\Filament\Admin\Widgets\MembersChart::class)->assertOk();
    }
}
