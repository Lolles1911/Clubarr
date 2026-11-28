<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\MediaServers\Pages\ListMediaServers;
use App\Filament\App\Pages\Join;
use App\MediaServers\Contracts\CreatesUsers;
use App\Models\Claim;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\ServerSync;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Check, import and join, for every kind of server, with each server's own
 * answers and a cache that serializes (as in the container).
 */
class AllServersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{ServerType}> */
    public static function servers(): array
    {
        return ['Jellyfin' => [ServerType::Jellyfin], 'Emby' => [ServerType::Emby], 'Plex' => [ServerType::Plex], 'Silo' => [ServerType::Silo]];
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        Notification::fake();
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
    }

    private function server(ServerType $type): MediaServer
    {
        $url = ['jellyfin' => 'http://jf.local', 'emby' => 'http://emby.local', 'plex' => 'http://plex.local', 'silo' => 'https://silo.local'][$type->value];

        return MediaServer::create(['type' => $type, 'name' => $type->label(), 'url' => $url, 'api_key' => 'key', 'settings' => $type === ServerType::Plex ? ['machine_id' => 'm1', 'owner_username' => 'owner'] : null]);
    }

    /** Each server's answers: one administrator ("owner") and one ordinary user ("grandma"). */
    private function fakeServer(ServerType $type): void
    {
        $mb = fn (string $host) => [
            "$host/System/Info" => Http::response(['ServerName' => 'Home', 'Version' => '10.11', 'Id' => 's1']),
            "$host/Library/MediaFolders" => Http::response(['Items' => [['Id' => 'l1', 'Guid' => 'g1', 'Name' => 'Movies'], ['Id' => 'l2', 'Guid' => 'g2', 'Name' => 'TV']]]),
            "$host/Users" => Http::response([
                ['Id' => 'admin0', 'Name' => 'owner', 'Policy' => ['IsAdministrator' => true]],
                ['Id' => 'old1', 'Name' => 'grandma', 'Policy' => ['IsDisabled' => false]],
            ]),
            "$host/Users/New" => Http::response(['Id' => 'new1', 'Name' => 'anna']),
            "$host/Users/new1" => Http::response(['Id' => 'new1', 'Policy' => []]),
            "$host/Users/new1/Policy" => Http::response(null, 204),
            "$host/Users/new1/Password" => Http::response(null, 204),
        ];
        Http::fake(match ($type) {
            ServerType::Jellyfin => $mb('jf.local'),
            ServerType::Emby => $mb('emby.local'),
            ServerType::Plex => [
                'plex.local/' => Http::response(['MediaContainer' => ['machineIdentifier' => 'm1', 'friendlyName' => 'Home', 'version' => '1.41']]),
                'plex.tv/api/v2/user' => Http::response(['id' => 1, 'username' => 'owner']),
                'plex.tv/api/v2/servers/m1' => Http::response(['librarySections' => [['id' => 11, 'title' => 'Movies'], ['id' => 12, 'title' => 'TV']]]),
                'clients.plex.tv/api/v2/shared_servers/owned/accepted' => Http::response([
                    ['id' => 5, 'machineIdentifier' => 'other', 'invitedId' => 999, 'invited' => ['username' => 'elsewhere']],
                    ['id' => 6, 'machineIdentifier' => 'm1', 'invitedId' => 'old1', 'invited' => ['id' => 'old1', 'username' => 'grandma', 'email' => 'g@example.com']],
                ]),
                'plex.tv/api/servers/m1/shared_servers' => Http::response(['id' => 7]),
                'clients.plex.tv/api/v2/shared_servers/invites/received/pending' => Http::response([['owner' => ['username' => 'owner'], 'sharedServers' => [['id' => 7, 'machineIdentifier' => 'm1']]]]),
                'clients.plex.tv/api/v2/shared_servers/7/accept' => Http::response(null, 204),
            ],
            ServerType::Silo => [
                'silo.local/api/v2/admin/invitations/capabilities' => Http::response(['delivery' => ['link']]),
                'silo.local/api/v2/libraries' => Http::response(['items' => [['id' => 1, 'name' => 'Movies'], ['id' => 2, 'name' => 'TV']]]),
                'silo.local/api/v2/admin/users*' => Http::response(['items' => [
                    ['id' => 1, 'username' => 'owner', 'role' => 'admin', 'enabled' => true],
                    ['id' => 'old1', 'username' => 'grandma', 'email' => 'grandma@example.com', 'role' => 'user', 'enabled' => true],
                ], 'page' => ['has_more' => false]]),
                'silo.local/api/v2/admin/invitations' => Http::response(['invitation' => ['id' => 9], 'claim_url' => 'https://silo.local/claim/abc']),
            ],
        });
    }

    #[DataProvider('servers')]
    public function test_check_reads_the_libraries(ServerType $type): void
    {
        $this->fakeServer($type);
        $server = $this->server($type);

        app(ServerSync::class)->check($server);
        $this->assertSame(2, app(ServerSync::class)->syncLibraries($server));
        $this->assertNull($server->fresh()->last_error);
    }

    #[DataProvider('servers')]
    public function test_bulk_import_mails_when_the_email_is_known_else_makes_a_link(ServerType $type): void
    {
        $this->fakeServer($type);
        $server = $this->server($type);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');

        // The administrator ("admin0" / "owner") is not offered: sending it anyway imports nothing.
        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('import')->table($server), ['remote' => ['admin0']]);
        $this->assertSame([0, 0], [ServerAccount::count(), Claim::count()]);
        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('import')->table($server), ['remote' => ['old1']])->assertHasNoFormErrors();

        $knowsEmail = in_array($type, [ServerType::Plex, ServerType::Silo], true);
        if ($knowsEmail) {
            $account = ServerAccount::sole();
            $this->assertSame(['old1', 'grandma', AccountStatus::Active], [$account->remote_id, $account->remote_username, $account->status], $type->label());
            $this->assertSame(0, Claim::count());
        } else {
            $this->assertSame(0, ServerAccount::count(), 'no account before the person claims it');
            $claim = Claim::sole();
            $this->assertSame(['old1', 'grandma'], [$claim->remote_id, $claim->remote_username], $type->label());
        }
    }

    #[DataProvider('servers')]
    public function test_join_gives_access_the_way_the_server_works(ServerType $type): void
    {
        $this->fakeServer($type);
        $server = $this->server($type);
        $invitation = new Invitation(['max_uses' => 1]);
        $code = $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($server->id);

        if ($type === ServerType::Plex) {
            // As if "Sign in with Plex" was done.
            session()->put('join.plex.done', ['invitation' => $invitation->id, 'id' => '555', 'username' => 'anna', 'email' => 'anna@example.com', 'token' => 'member-token']);
        }
        Livewire::test(Join::class, ['code' => $code])->fillForm(array_filter([
            'name' => 'Anna', 'email' => 'anna@example.com',
            'username' => $type->can(CreatesUsers::class) ? 'anna' : null,
            'password' => 'Anna-pass-123', 'password_confirmation' => 'Anna-pass-123',
        ]))->call('join')->assertHasNoFormErrors();

        $account = ServerAccount::sole();
        $expected = match ($type) {
            ServerType::Jellyfin, ServerType::Emby => ['new1', AccountStatus::Active],
            ServerType::Plex => ['555', AccountStatus::Active],
            ServerType::Silo => [null, AccountStatus::Pending],
        };
        $this->assertSame($expected, [$account->remote_id, $account->status], $type->label());
        if ($type === ServerType::Silo) {
            $this->assertSame('https://silo.local/claim/abc', $account->claim_url);
        }
        $this->assertSame(1, $invitation->fresh()->uses);
    }
}
