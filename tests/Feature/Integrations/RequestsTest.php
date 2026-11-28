<?php

namespace Tests\Feature\Integrations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\MediaServers\MediaServerException;
use App\Models\MediaRequest;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\Requests;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['seerr_url' => 'http://seerr.local', 'seerr_api_key' => 'sk']);
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $this->anna->forceFill(['request_limit' => 2, 'seerr_access' => true])->save();
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $server->id, 'remote_id' => 'jf-anna', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        Http::fake([
            'seerr.local/api/v1/settings/main' => Http::response(['mediaServerType' => 2]),
            'seerr.local/api/v1/user?*' => Http::response(['results' => [['id' => 7, 'jellyfinUserId' => 'jf-anna']]]),
            'seerr.local/api/v1/movie/*' => Http::response(['id' => 603, 'title' => 'The Matrix', 'releaseDate' => '1999-03-31', 'posterPath' => '/m.jpg']),
            'seerr.local/api/v1/request' => Http::response(['id' => 1], 201),
        ]);
    }

    public function test_a_request_is_made_as_the_members_own_seerr_user_within_the_weekly_limit(): void
    {
        $requests = new Requests;
        $requests->request($this->anna, 'movie', 603);
        $requests->request($this->anna, 'movie', 603);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/request')
            && $r->header('X-API-User')[0] === '7' && $r['mediaId'] === 603 && $r->header('X-Api-Key')[0] === 'sk');
        $this->assertSame(7, $this->anna->fresh()->seerr_user_id);
        $this->assertSame(0, $requests->remaining($this->anna));

        $this->expectException(MediaServerException::class);
        $requests->request($this->anna, 'movie', 603);
    }

    public function test_old_requests_and_no_limit(): void
    {
        MediaRequest::create(['user_id' => $this->anna->id, 'type' => 'movie', 'tmdb_id' => 1, 'title' => 'Old'])->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->assertSame(2, (new Requests)->remaining($this->anna), 'older than a week does not count');

        $this->anna->forceFill(['request_limit' => null])->save();
        $this->assertNull((new Requests)->remaining($this->anna));
    }

    public function test_no_access_no_requests_and_bad_input_is_refused(): void
    {
        $requests = new Requests;
        foreach ([['show', 1], ['movie', 0]] as [$type, $id]) {
            try {
                $requests->request($this->anna, $type, $id);
                $this->fail('accepted '.$type);
            } catch (MediaServerException) {
                $this->assertTrue(true);
            }
        }
        $this->anna->forceFill(['access_expires_at' => now()->subDay()])->save();
        $this->expectException(MediaServerException::class);
        $requests->request($this->anna, 'movie', 603);
    }

    public function test_requests_are_only_for_members_the_admin_gave_them_to(): void
    {
        $this->anna->forceFill(['seerr_access' => false])->save();
        $this->actingAs($this->anna);
        Filament::setCurrentPanel('app');
        $this->assertFalse(\App\Filament\App\Pages\Requests::canAccess());
        $this->expectException(MediaServerException::class);
        app(Requests::class)->seerrUser($this->anna);
    }
}
