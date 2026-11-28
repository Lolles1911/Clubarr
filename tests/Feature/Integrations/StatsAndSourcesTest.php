<?php

namespace Tests\Feature\Integrations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Pages\Stats;
use App\Filament\App\Pages\MyStats;
use App\Integrations\Tautulli;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\ComingSoon;
use App\Services\SiteSettings;
use App\Services\Watching;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StatsAndSourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'tautulli_url' => 'http://tt.local', 'tautulli_api_key' => 'tt-key']);
        $server = MediaServer::create(['type' => ServerType::Plex, 'name' => 'Plex', 'url' => 'http://plex.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'x']);
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $server->id, 'remote_id' => '1', 'remote_username' => 'AnnaPlex', 'status' => AccountStatus::Active]);
        $now = now()->getTimestamp();
        Http::fake(function ($request) use ($now) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match ($q['cmd'] ?? '') {
                'get_activity' => Http::response(['response' => ['result' => 'success', 'data' => ['sessions' => [
                    ['session_id' => 's1', 'user' => 'annaplex', 'media_type' => 'episode', 'grandparent_title' => 'Severance', 'title' => 'Hello', 'player' => 'Apple TV', 'platform' => 'tvOS'],
                ]]]]),
                'get_history' => Http::response(['response' => ['result' => 'success', 'data' => ['data' => [
                    ['user' => 'annaplex', 'media_type' => 'movie', 'title' => 'Dune', 'started' => $now - 3600, 'play_duration' => 7200, 'platform' => 'tvOS', 'thumb' => '/library/metadata/5/thumb/123'],
                    ['user' => 'someone', 'media_type' => 'movie', 'title' => 'Other', 'started' => $now - 7200, 'play_duration' => 3600, 'platform' => 'Web'],
                ]]]]),
                default => Http::response(['response' => ['result' => 'error', 'message' => 'nope']]),
            };
        });
    }

    public function test_tautulli_stands_in_for_tracearr_and_its_key_never_shows_in_errors(): void
    {
        $watching = app(Watching::class);
        $this->assertTrue($watching->enabled());
        $this->assertSame('Severance · Hello', $watching->streamsOf($this->anna)[0]['show_title'].' · '.$watching->streamsOf($this->anna)[0]['media_title']);
        $this->assertSame(2.0, $watching->statsOf($this->anna)['hours30']);
        $this->assertSame('Dune', Watching::summarize($watching->yearPlaysOf($this->anna))['titles'][0]['title']);
        try {
            (new Tautulli('http://tt.local', 'tt-key'))->version();
            $this->fail('An error answer must throw.');
        } catch (MediaServerException $e) {
            $this->assertStringNotContainsString('tt-key', $e->getMessage());
        }
    }

    public function test_the_stats_pages_show_numbers_to_the_right_people(): void
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(Stats::class)->assertSee('Dune')->assertSee('Anna');

        $this->actingAs($this->anna);
        Filament::setCurrentPanel('app');
        Livewire::test(MyStats::class)->assertSee('Dune')->assertDontSee('Other'); // only her own plays
    }

    public function test_coming_soon_reads_sonarr_and_radarr_and_only_tmdb_posters(): void
    {
        app(SiteSettings::class)->set(['sonarr_url' => 'http://sonarr.local', 'sonarr_api_key' => 's', 'radarr_url' => 'http://radarr.local', 'radarr_api_key' => 'r']);
        Http::swap(new Factory);
        Http::fake([
            'sonarr.local/api/v3/calendar*' => Http::response([
                ['airDateUtc' => now()->addDays(2)->toIso8601String(), 'seasonNumber' => 2, 'episodeNumber' => 3, 'hasFile' => false, 'series' => ['title' => 'Severance', 'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://artworks.thetvdb.com/x.jpg']]]],
                ['airDateUtc' => now()->addDays(3)->toIso8601String(), 'seasonNumber' => 2, 'episodeNumber' => 4, 'hasFile' => false, 'series' => ['title' => 'Severance']],
            ]),
            'radarr.local/api/v3/calendar*' => Http::response([
                ['title' => 'Dune Part Three', 'year' => 2026, 'digitalRelease' => now()->addDay()->toIso8601String(), 'hasFile' => false, 'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://image.tmdb.org/t/p/original/abcdef.jpg']]],
            ]),
        ]);

        $items = app(ComingSoon::class)->fetch();

        $this->assertSame(['Dune Part Three', 'Severance'], array_column($items, 'title'));
        $this->assertSame(['abcdef.jpg', null], array_column($items, 'tmdb'));
        $this->assertSame('S02E03', $items[1]['detail']);
    }
}
