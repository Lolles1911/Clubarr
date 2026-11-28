<?php

namespace Tests\Feature\Integrations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\SiteSettings;
use App\Services\Watching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchingTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, string $username, ?int $maxStreams = null): User
    {
        $server = MediaServer::firstOrCreate(['name' => 'Home'], ['type' => ServerType::Jellyfin, 'url' => 'http://jf.local', 'api_key' => 'k']);
        $user = User::create(['name' => $username, 'email' => $email, 'password' => 'Member-pass-1']);
        $user->forceFill(['max_streams' => $maxStreams])->save();
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $server->id, 'remote_id' => $username.'-id', 'remote_username' => $username, 'status' => AccountStatus::Active]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
        app(SiteSettings::class)->set(['tracearr_url' => 'http://tr.local', 'tracearr_api_key' => 'trr_pub_x']);
    }

    private function streams(array $streams): void
    {
        Http::fake([
            'tr.local/api/v2/public/streams' => Http::response(['data' => $streams]),
            'tr.local/api/v1/public/streams/*' => Http::response(null, 204),
        ]);
    }

    public function test_members_see_and_stop_only_their_own_streams(): void
    {
        $anna = $this->member('anna@example.com', 'anna');
        $this->member('bob@example.com', 'Bob');
        $this->streams([
            ['id' => 's1', 'server_type' => 'jellyfin', 'username' => 'Anna', 'media_title' => 'Dune'],
            ['id' => 's2', 'server_type' => 'jellyfin', 'username' => 'bob', 'media_title' => 'Heat'],
            ['id' => 's3', 'server_type' => 'plex', 'username' => 'anna', 'media_title' => 'Other server type'],
        ]);
        $watching = new Watching;

        $this->assertSame(['s1'], array_column($watching->streamsOf($anna), 'id'));
        $this->assertFalse($watching->stop($anna, 's2'), 'someone else\'s stream');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/s2/terminate'));
        $this->assertTrue($watching->stop($anna, 's1'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/streams/s1/terminate') && $r->header('Authorization')[0] === 'Bearer trr_pub_x');
    }

    public function test_streams_over_the_limit_are_stopped_newest_first(): void
    {
        $this->member('anna@example.com', 'anna', maxStreams: 2);
        $this->member('bob@example.com', 'bob'); // no limit
        $this->streams([
            ['id' => 'a1', 'server_type' => 'jellyfin', 'username' => 'anna', 'started_at' => '2026-11-28T10:00:00Z'],
            ['id' => 'a2', 'server_type' => 'jellyfin', 'username' => 'anna', 'started_at' => '2026-11-28T11:00:00Z'],
            ['id' => 'a3', 'server_type' => 'jellyfin', 'username' => 'anna', 'started_at' => '2026-11-28T12:00:00Z'],
            ['id' => 'b1', 'server_type' => 'jellyfin', 'username' => 'bob'], ['id' => 'b2', 'server_type' => 'jellyfin', 'username' => 'bob'],
            ['id' => 'b3', 'server_type' => 'jellyfin', 'username' => 'bob'],
        ]);

        $this->assertSame(1, (new Watching)->enforceLimits());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/streams/a3/terminate') && str_contains($r['reason'], '2'));
        Http::assertNotSent(fn (Request $r) => preg_match('~/streams/(a1|a2|b\d)/terminate~', $r->url()) === 1);
    }

    public function test_only_listed_posters_are_served_and_only_to_members(): void
    {
        $anna = $this->member('anna@example.com', 'anna');
        $this->get('/posters/'.str_repeat('a', 20))->assertRedirect(); // not signed in
        $this->actingAs($anna)->get('/posters/'.str_repeat('a', 20))->assertNotFound();
        $this->actingAs($anna)->get('/posters/../../etc')->assertNotFound();
        $this->actingAs($anna)->get('/tmdb/..%2F..%2Fetc.jpg')->assertNotFound();
    }

    public function test_without_tracearr_nothing_is_asked(): void
    {
        app(SiteSettings::class)->set(['tracearr_url' => '']);
        Http::fake();
        $anna = $this->member('anna@example.com', 'anna', maxStreams: 1);

        $this->assertSame([], (new Watching)->streamsOf($anna));
        $this->assertSame(0, (new Watching)->enforceLimits());
        Http::assertNothingSent();
        $this->actingAs($anna)->get('/my-devices')->assertForbidden();
    }
}
