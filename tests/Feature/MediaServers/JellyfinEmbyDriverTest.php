<?php

namespace Tests\Feature\MediaServers;

use App\Enums\ServerType;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JellyfinEmbyDriverTest extends TestCase
{
    use RefreshDatabase;

    private function server(ServerType $type = ServerType::Jellyfin): MediaServer
    {
        return MediaServer::create(['type' => $type, 'name' => 'Home', 'url' => 'http://jf.local:8096/', 'api_key' => 'secret-key-123']);
    }

    public function test_the_key_is_stored_encrypted_and_never_serialized(): void
    {
        $server = $this->server();
        $this->assertNotSame('secret-key-123', \DB::table('media_servers')->value('api_key'));
        $this->assertSame('secret-key-123', $server->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $server->toArray());
    }

    public function test_a_new_user_gets_only_the_chosen_libraries_and_is_never_admin(): void
    {
        Http::fake([
            'jf.local:8096/Users/New' => Http::response(['Id' => 'u1', 'Name' => 'anna']),
            'jf.local:8096/Users/u1' => Http::response(['Id' => 'u1', 'Policy' => ['IsAdministrator' => true, 'EnableAllFolders' => true]]),
            'jf.local:8096/Users/u1/Policy' => Http::response(null, 204),
        ]);

        $user = $this->server()->driver()->createUser('anna', 'pw-123456', ['lib1', 'lib2']);

        $this->assertSame('u1', $user->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Users/New') && $r['Name'] === 'anna' && $r['Password'] === 'pw-123456'
            && $r->header('Authorization')[0] === 'MediaBrowser Token="secret-key-123"');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Users/u1/Policy') && $r['IsAdministrator'] === false
            && $r['EnableAllFolders'] === false && $r['EnabledFolders'] === ['lib1', 'lib2']);
    }

    public function test_a_user_whose_policy_fails_is_removed_again(): void
    {
        Http::fake([
            'jf.local:8096/Users/New' => Http::response(['Id' => 'u1']),
            'jf.local:8096/Users/u1' => Http::sequence()->push(['Id' => 'u1', 'Policy' => []])->push(null, 204),
            'jf.local:8096/Users/u1/Policy' => Http::response('boom', 500),
        ]);

        try {
            $this->server()->driver()->createUser('anna', 'pw-123456', null);
            $this->fail('expected an exception');
        } catch (MediaServerException) {
        }
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/Users/u1'));
    }

    public function test_a_taken_name_is_told_apart(): void
    {
        Http::fake(['jf.local:8096/Users/New' => Http::response('A user with the name \'anna\' already exists.', 400)]);

        try {
            $this->server()->driver()->createUser('anna', 'pw-123456', null);
            $this->fail('expected an exception');
        } catch (MediaServerException $e) {
            $this->assertTrue($e->userTaken);
        }
    }

    public function test_administrators_are_never_changed(): void
    {
        Http::fake(['jf.local:8096/Users/admin1' => Http::response(['Id' => 'admin1', 'Policy' => ['IsAdministrator' => true]])]);
        $driver = $this->server()->driver();

        foreach ([fn () => $driver->disable('admin1', 'x'), fn () => $driver->delete('admin1', 'x'), fn () => $driver->setPassword('admin1', 'new-password')] as $change) {
            try {
                $change();
                $this->fail('expected a refusal');
            } catch (MediaServerException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        Http::assertSentCount(3); // only the three reads
    }

    public function test_disable_keeps_the_policy_and_sets_is_disabled(): void
    {
        Http::fake([
            'jf.local:8096/Users/u1' => Http::response(['Id' => 'u1', 'Policy' => ['EnableAllFolders' => false, 'EnabledFolders' => ['a']]]),
            'jf.local:8096/Users/u1/Policy' => Http::response(null, 204),
        ]);
        $this->server()->driver()->disable('u1', 'anna');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Policy') && $r['IsDisabled'] === true && $r['EnabledFolders'] === ['a']);
    }

    public function test_emby_uses_the_library_guid_and_removes_a_user_without_password(): void
    {
        Http::fake([
            'jf.local:8096/Library/MediaFolders' => Http::response(['Items' => [['Id' => '3', 'Guid' => 'g-3', 'Name' => 'Movies']]]),
            'jf.local:8096/Users/New' => Http::response(['Id' => 'e1']),
            'jf.local:8096/Users/e1/Password' => Http::response('nope', 500),
            'jf.local:8096/Users/e1' => Http::response(null, 204),
        ]);
        $driver = $this->server(ServerType::Emby)->driver();
        $this->assertSame('g-3', $driver->libraries()[0]->id);

        $this->expectException(MediaServerException::class);
        try {
            $driver->createUser('anna', 'pw-123456', null);
        } finally {
            Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/Users/e1'));
        }
    }

    public function test_errors_never_contain_the_key_and_bad_addresses_are_refused(): void
    {
        Http::fake(['jf.local:8096/*' => Http::response('invalid token secret-key-123-abcdefghijklmnopqrstuvwxyz', 500)]);
        try {
            $this->server()->driver()->test();
        } catch (MediaServerException $e) {
            $this->assertStringNotContainsString('secret-key-123-abcdefghijklmnopqrstuvwxyz', $e->getMessage());
        }

        foreach (['ftp://x', 'http://user:pw@host', 'http://host/?a=1', 'not a url'] as $bad) {
            $server = MediaServer::make(['type' => ServerType::Jellyfin, 'name' => 'x', 'url' => $bad, 'api_key' => 'k']);
            try {
                $server->driver();
                $this->fail($bad.' was accepted');
            } catch (MediaServerException) {
                $this->assertTrue(true);
            }
        }
    }
}
