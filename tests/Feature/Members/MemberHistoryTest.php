<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\MyAccess;
use App\Filament\App\Pages\MyStats;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\MemberHistory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Members' own numbers without Tracearr or Tautulli: from Jellyfin/Emby and Silo. */
class MemberHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
    }

    private function member(MediaServer $server, string $remoteId): User
    {
        $user = User::create(['name' => 'Anna', 'email' => 'anna-'.$remoteId.'@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $server->id, 'remote_id' => $remoteId, 'remote_username' => 'anna', 'status' => AccountStatus::Active]);

        return $user;
    }

    public function test_jellyfin_gives_numbers_and_continue_watching(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $anna = $this->member($server, 'j1');
        Http::fake([
            'jf.local/Items/m1/Similar*' => Http::response(['Items' => [['Id' => 'm9', 'Name' => 'Arrival', 'Type' => 'Movie'], ['Id' => 'mx', 'Name' => 'Dune', 'Type' => 'Movie']]]),
            'jf.local/Users/j1/Items/Resume*' => Http::response(['Items' => [['Id' => 'e9', 'Type' => 'Episode', 'SeriesId' => 's1', 'SeriesName' => 'Severance', 'UserData' => ['PlayedPercentage' => 42.4]]]]),
            'jf.local/Users/j1/Items*' => Http::response(['Items' => [
                ['Id' => 'm1', 'Name' => 'Dune', 'Type' => 'Movie', 'RunTimeTicks' => 2 * 3600 * 10_000_000, 'UserData' => ['LastPlayedDate' => now()->subDays(2)->toIso8601String()]],
                ['Name' => 'Pilot', 'Type' => 'Episode', 'SeriesName' => 'Severance', 'RunTimeTicks' => 3600 * 10_000_000, 'UserData' => ['LastPlayedDate' => now()->subDays(20)->toIso8601String()]],
            ]]),
        ]);

        // The page itself only queues the fetch; with the sync queue in tests it runs at once.
        Filament::setCurrentPanel('app');
        $this->actingAs($anna);
        Livewire::test(MyAccess::class);

        $stats = app(MemberHistory::class)->stats($anna);
        $this->assertSame([2.0, 3.0, 2, 'Dune'], [$stats['hours7'], $stats['hours30'], $stats['plays30'], $stats['top']]);
        $resume = app(MemberHistory::class)->resume($anna);
        $this->assertSame(['Severance', 42], [$resume[0]['title'], $resume[0]['percent']]);
        Livewire::test(MyAccess::class)->assertSee('Watched, 7 days')->assertSee('Continue watching')->assertSee('Severance')
            ->assertSee('Because you watched Dune')->assertSee('Arrival');
        $this->assertSame(['Arrival'], array_column(app(MemberHistory::class)->because($anna)['items'], 'title'));
        $this->assertTrue(MyStats::canAccess());
        $this->get('/my-stats')->assertOk()->assertSee('Dune');
    }

    public function test_silo_gives_numbers_from_its_own_history_and_only_the_members(): void
    {
        $server = MediaServer::create(['type' => ServerType::Silo, 'name' => 'Silo', 'url' => 'http://silo.local', 'api_key' => 'sa_x']);
        $anna = $this->member($server, '7');
        Http::fake(['silo.local/api/v2/admin/playback-history*' => Http::response(['items' => [
            ['user_id' => '7', 'started_at' => now()->subDay()->toIso8601String(), 'ended_at' => now()->subDay()->addHour()->toIso8601String(), 'media_type' => 'movie',
                'media_title' => 'Arrival', 'media_item_id' => 'm1', 'measured' => true, 'played_seconds' => 5400, 'duration_seconds' => 7000, 'watched_seconds' => 5400, 'client_name' => 'Silo TV'],
            ['user_id' => '8', 'started_at' => now()->subDay()->toIso8601String(), 'media_type' => 'movie', 'media_title' => 'Not Anna\'s', 'played_seconds' => 9000],
        ], 'page' => ['has_more' => false]])]);

        Queue::fake();
        app(MemberHistory::class)->refresh($anna);

        $stats = app(MemberHistory::class)->stats($anna);
        $this->assertSame([1.5, 1, 'Arrival'], [$stats['hours7'], $stats['plays30'], $stats['top']]);
        $this->assertSame(['Arrival'], array_column(app(MemberHistory::class)->resume($anna), 'title'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'user_id=7') && $r->header('Authorization')[0] === 'Bearer sa_x');
    }
}
