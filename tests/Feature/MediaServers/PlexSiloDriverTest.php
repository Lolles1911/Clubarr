<?php

namespace Tests\Feature\MediaServers;

use App\Enums\ServerType;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlexSiloDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_plex_shares_with_the_members_account_using_plex_tv_library_ids(): void
    {
        $server = MediaServer::create(['type' => ServerType::Plex, 'name' => 'Plex', 'url' => 'http://plex.local:32400', 'api_key' => 'owner-token', 'settings' => ['machine_id' => 'm1']]);
        Http::fake([
            'plex.tv/api/v2/servers/m1' => Http::response(['librarySections' => [['id' => 11, 'title' => 'Movies'], ['id' => 12, 'title' => 'TV']]]),
            'plex.tv/api/servers/m1/shared_servers' => Http::response(['id' => 99]),
        ]);

        $user = $server->driver()->share(new PlexIdentity('555', 'anna', 'anna@example.com'), null);

        $this->assertSame('555', $user->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/servers/m1/shared_servers')
            && $r['shared_server']['library_section_ids'] === [11, 12] && $r['shared_server']['invited_email'] === 'anna@example.com'
            && $r->header('X-Plex-Token')[0] === 'owner-token' && ! str_contains($r->url(), 'owner-token'));
    }

    public function test_plex_unshare_removes_only_this_servers_share(): void
    {
        $server = MediaServer::create(['type' => ServerType::Plex, 'name' => 'Plex', 'url' => 'http://plex.local:32400', 'api_key' => 't', 'settings' => ['machine_id' => 'm1']]);
        Http::fake([
            'clients.plex.tv/api/v2/shared_servers/owned/accepted' => Http::response([
                ['id' => 1, 'machineIdentifier' => 'other', 'invitedId' => 555],
                ['id' => 2, 'machineIdentifier' => 'm1', 'invitedId' => 555, 'invited' => ['username' => 'anna']],
            ]),
            'clients.plex.tv/api/v2/shared_servers/2' => Http::response(null, 204),
        ]);

        $server->driver()->disable('555', 'anna');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/shared_servers/2'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/shared_servers/1'));
    }

    public function test_silo_claim_links_and_the_edit_guard(): void
    {
        $server = MediaServer::create(['type' => ServerType::Silo, 'name' => 'Silo', 'url' => 'https://silo.local', 'api_key' => 'silo-key']);
        Http::fake([
            'silo.local/api/v2/admin/invitations' => Http::response(['invitation' => ['id' => 7], 'claim_url' => 'https://silo.local/claim/abc']),
            'silo.local/api/v2/admin/users/5' => Http::sequence()
                ->push(['id' => 5, 'role' => 'user'], 200, ['ETag' => '"v1"'])
                ->push(null, 204),
        ]);
        $driver = $server->driver();

        $claim = $driver->createClaim(['1']);
        $this->assertSame(['7', 'https://silo.local/claim/abc'], [$claim->id, $claim->url]);

        $driver->disable('5', 'anna');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->header('If-Match')[0] === '"v1"' && $r['enabled'] === false
            && $r->header('Authorization')[0] === 'Bearer silo-key');
    }

    public function test_silo_administrators_are_never_changed(): void
    {
        $server = MediaServer::create(['type' => ServerType::Silo, 'name' => 'Silo', 'url' => 'https://silo.local', 'api_key' => 'k']);
        Http::fake(['silo.local/api/v2/admin/users/1' => Http::response(['id' => 1, 'role' => 'admin'], 200, ['ETag' => '"x"'])]);

        $this->expectException(MediaServerException::class);
        $server->driver()->delete('1', 'owner');
    }
}
