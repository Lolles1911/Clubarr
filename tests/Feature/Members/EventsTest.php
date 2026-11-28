<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\MyAccess;
use App\Models\Challenge;
use App\Models\MediaServer;
use App\Models\MovieNight;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\MemberNote;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Movie nights and challenges on members' page. */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        Filament::setCurrentPanel('app');
        $this->actingAs($this->anna);
    }

    public function test_members_say_they_are_coming_and_get_a_reminder(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);
        $night = MovieNight::create(['title' => 'Dune', 'starts_at' => now()->addMinutes(90), 'note' => 'Popcorn!']);

        Livewire::test(MyAccess::class)->assertSee('Movie night: Dune')->callAction('coming', [], ['night' => $night->id]);
        $this->assertSame([$this->anna->id], $night->attendees()->pluck('users.id')->all());

        Artisan::call('clubarr:check-access');
        Notification::assertSentTo($this->anna, MemberNote::class);
        $this->assertTrue($night->fresh()->reminded);
    }

    public function test_a_challenge_shows_the_members_progress(): void
    {
        Challenge::create(['title' => '3 films this month', 'metric' => 'movies', 'target' => 3, 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(20)]);
        Cache::put('clubarr:member-history:'.$this->anna->id, ['resume' => [], 'plays' => [
            ['started_at' => now()->subDays(2)->toIso8601String(), 'media_type' => 'movie', 'media_title' => 'Dune', 'duration_ms' => 1],
            ['started_at' => now()->subDays(1)->toIso8601String(), 'media_type' => 'movie', 'media_title' => 'Dune', 'duration_ms' => 1],
            ['started_at' => now()->subDays(1)->toIso8601String(), 'media_type' => 'movie', 'media_title' => 'Arrival', 'duration_ms' => 1],
            ['started_at' => now()->subDays(9)->toIso8601String(), 'media_type' => 'movie', 'media_title' => 'Before it began', 'duration_ms' => 1],
        ]], 600);

        Livewire::test(MyAccess::class)->assertSee('3 films this month')->assertSee('2 of 3 films');
    }
}
