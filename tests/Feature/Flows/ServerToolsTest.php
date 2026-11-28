<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Admin\Resources\MediaServers\Pages\CreateMediaServer;
use App\Filament\Admin\Resources\MediaServers\Pages\ListMediaServers;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Services\PlexFinder;
use App\Services\PlexLogin;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ServerToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_the_test_button_checks_without_saving(): void
    {
        Http::fake([
            'jf.local/System/Info' => Http::response(['ServerName' => 'Home', 'Version' => '10.11.0', 'Id' => 's1']),
            'jf.local/Library/MediaFolders' => Http::response(['Items' => [['Id' => 'a', 'Name' => 'Movies']]]),
        ]);
        Livewire::test(CreateMediaServer::class)
            ->fillForm(['type' => 'jellyfin', 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k'])
            ->callAction(TestAction::make('test')->schemaComponent('serverActions'))
            ->assertNotified('Connected to Home.');
        $this->assertSame(0, MediaServer::count());
    }

    public function test_sync_updates_members_from_the_server_and_remembers_the_version(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $member = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        $paused = ServerAccount::create(['user_id' => $member->id, 'media_server_id' => $server->id, 'remote_id' => 'u1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        $other = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
        ServerAccount::create(['user_id' => $other->id, 'media_server_id' => $server->id, 'remote_id' => 'gone', 'remote_username' => 'bob', 'status' => AccountStatus::Active]);
        Http::fake([
            'jf.local/System/Info' => Http::response(['ServerName' => 'Home', 'Version' => '10.11.2', 'Id' => 's1']),
            'jf.local/Library/MediaFolders' => Http::response(['Items' => []]),
            'jf.local/Users' => Http::response([['Id' => 'u1', 'Name' => 'anna2', 'Policy' => ['IsDisabled' => true]]]),
        ]);

        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('sync')->table($server))->assertNotified();

        $this->assertSame([AccountStatus::Disabled, 'anna2'], [$paused->fresh()->status, $paused->fresh()->remote_username]);
        $this->assertSame('10.11.2', $server->fresh()->setting('version'));
    }

    public function test_find_plex_scans_only_the_private_network_clubarr_is_opened_on(): void
    {
        $this->assertSame('192.168.1', PlexFinder::networkOf('192.168.1.235'));
        $this->assertSame('10.0.0', PlexFinder::networkOf('10.0.0.5'));
        foreach (['8.8.8.8', 'clubarr.example.com', '::1', null] as $notPrivate) {
            $this->assertNull(PlexFinder::networkOf($notPrivate));
        }
    }

    public function test_find_plex_fills_in_the_address_of_a_server_on_the_network(): void
    {
        config(['app.url' => 'http://192.168.1.235:8087']);
        Http::fake([
            'http://192.168.1.235:32401/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'm1', 'version' => '1.41.3']]),
            '*' => Http::response('', 404),
        ]);

        Livewire::test(CreateMediaServer::class)
            ->fillForm(['type' => 'plex'])
            ->callAction(TestAction::make('plexFind')->schemaComponent('serverActions'), ['server' => 'http://192.168.1.235:32401'])
            ->assertNotified('Address filled in. Add the token, then press Test.')
            ->assertSchemaStateSet(['url' => 'http://192.168.1.235:32401']);
        Http::assertNotSent(fn ($r) => $r->hasHeader('X-Plex-Token'));
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'http://192.168.1.'));
    }

    public function test_find_plex_with_the_token_lists_the_accounts_servers_and_tries_each_address(): void
    {
        Http::fake([
            'plex.tv/api/v2/resources*' => Http::response([
                ['name' => 'Phone', 'provides' => 'player', 'owned' => true, 'connections' => []],
                ['name' => 'Friend', 'provides' => 'server', 'owned' => false, 'connections' => [['uri' => 'http://10.0.0.9:32400', 'local' => true]]],
                ['name' => 'WaffleHub', 'provides' => 'server', 'owned' => true, 'productVersion' => '1.43.5', 'connections' => [
                    ['uri' => 'https://81-1-2-3.abc.plex.direct:32400', 'local' => false, 'relay' => false],
                    ['uri' => 'https://172-17-0-11.abc.plex.direct:32400', 'local' => true, 'relay' => false],
                    ['uri' => 'https://relay.plex.direct:8443', 'local' => false, 'relay' => true],
                ]],
            ]),
            'https://172-17-0-11.abc.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'm1', 'version' => '1.43.5']]),
            '*' => Http::response('', 404),
        ]);

        $found = app(PlexFinder::class)->fromAccount('owner-token');
        $this->assertSame([['https://172-17-0-11.abc.plex.direct:32400', true], ['https://81-1-2-3.abc.plex.direct:32400', false]],
            array_map(fn ($s) => [$s['uri'], $s['ok']], $found));

        Livewire::test(CreateMediaServer::class)
            ->fillForm(['type' => 'plex', 'api_key' => 'owner-token'])
            ->callAction(TestAction::make('plexFind')->schemaComponent('serverActions'), ['server' => 'https://172-17-0-11.abc.plex.direct:32400'])
            ->assertSchemaStateSet(['url' => 'https://172-17-0-11.abc.plex.direct:32400', 'name' => 'WaffleHub']);
        // The token goes to plex.tv only.
        Http::assertNotSent(fn ($r) => $r->hasHeader('X-Plex-Token') && ! str_starts_with($r->url(), 'https://plex.tv/'));
    }

    public function test_an_invitation_link_can_be_mailed_when_mail_is_set_up(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);

        Livewire::test(CreateInvitation::class)
            ->fillForm(['servers' => [$server->id], 'max_uses' => 1, 'send_to' => 'friend@example.com', 'greeting' => 'Velkommen!'])
            ->call('create')->assertHasNoFormErrors();

        Notification::assertSentOnDemand(InvitationLink::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'friend@example.com');
        $this->assertSame(1, Invitation::count());
    }

    public function test_admins_get_a_way_to_the_admin_from_the_members_side(): void
    {
        $this->get('/')->assertSee(url('/admin'), false);
        $member = User::create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'x']);
        $this->actingAs($member)->get('/')->assertDontSee(url('/admin'), false);
    }

    public function test_plex_sends_the_window_back_to_clubarr_and_never_off_site(): void
    {
        Http::fake(['plex.tv/api/v2/pins' => Http::response(['id' => 42, 'code' => 'abcd'])]);

        $pin = app(PlexLogin::class)->start(url('/admin/media-servers/create'));

        $this->assertStringContainsString('forwardUrl='.urlencode(route('filament.app.plex.return')), $pin['url']);
        $this->get('/plex/return')->assertOk()->assertSee('href="'.url('/admin/media-servers/create').'"', false);

        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('/', PlexLogin::localPath($bad));
        }
        $this->assertSame('/join/ABC?x=1', PlexLogin::localPath(url('/join/ABC?x=1')));
    }

    public function test_settings_has_tabs_and_one_test_button_per_integration(): void
    {
        Http::fake(['tr.local/api/v2/public/servers' => Http::response(['data' => [['id' => 1]]])]);
        app(SiteSettings::class)->set(['tracearr_url' => 'http://tr.local', 'tracearr_api_key' => 'trr_pub_x']);

        Livewire::test(Settings::class)
            ->assertSee(['General', 'Security', 'Mail', 'Integrations', 'Waiting list'])
            ->assertDontSee('trr_pub_x')
            ->callAction(TestAction::make('testTracearr')->schemaComponent('integrations.tracearr', 'form'))
            ->assertNotified('Tracearr: OK (1 servers)');
    }
}
