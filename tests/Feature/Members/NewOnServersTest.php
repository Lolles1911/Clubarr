<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\MyAccess;
use App\Jobs\RefreshMemberHistory;
use App\Models\Library;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\NewOnServers;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NewOnServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        Filament::setCurrentPanel('app');
    }

    private function member(MediaServer $server, ?array $libraries, string $email = 'anna@example.com'): User
    {
        $user = User::create(['name' => 'Anna Berg', 'email' => $email, 'password' => 'x']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $server->id, 'remote_id' => 'r'.$user->id, 'remote_username' => 'anna', 'status' => AccountStatus::Active, 'library_ids' => $libraries]);

        return $user;
    }

    public function test_jellyfin_news_come_from_the_members_libraries_a_show_once(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $anna = $this->member($server, ['lib-films']);
        Http::fake([
            'jf.local/Library/MediaFolders' => Http::response(['Items' => [['Id' => 'lib-films', 'Name' => 'Films'], ['Id' => 'lib-kids', 'Name' => 'Kids']]]),
            'jf.local/Items?*ParentId=lib-films*' => Http::response(['Items' => [
                ['Id' => 'm1', 'Name' => 'Dune', 'Type' => 'Movie', 'ProductionYear' => 2021, 'DateCreated' => '2026-11-20T10:00:00Z'],
                ['Id' => 'e1', 'Type' => 'Episode', 'SeriesId' => 's1', 'SeriesName' => 'Severance', 'DateCreated' => '2026-11-27T10:00:00Z'],
                ['Id' => 'e2', 'Type' => 'Episode', 'SeriesId' => 's1', 'SeriesName' => 'Severance', 'DateCreated' => '2026-11-26T10:00:00Z'],
            ]]),
            'jf.local/Items?*' => Http::response(['Items' => [['Id' => 'k1', 'Name' => 'Not for Anna', 'Type' => 'Movie', 'DateCreated' => '2026-11-28T10:00:00Z']]]),
            'jf.local/Items/s1/Images/Primary*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        app(NewOnServers::class)->warm($server);
        $rows = app(NewOnServers::class)->forMember($anna);

        $this->assertSame(['Severance', 'Dune'], array_column($rows, 'title'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'lib-kids'));

        $this->actingAs($anna)->get(route('filament.app.server-poster', ['key' => $rows[0]['key']]))->assertOk()->assertHeader('Content-Type', 'image/png');
        // Someone without access to that server gets nothing.
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Other', 'url' => 'http://jf2.local', 'api_key' => 'k']);
        $bo = $this->member($other, null, 'bo@example.com');
        $this->flushSession();
        $this->actingAs($bo)->get(route('filament.app.server-poster', ['key' => $rows[0]['key']]))->assertNotFound();
    }

    public function test_plex_news_are_matched_to_the_members_libraries_by_name(): void
    {
        $server = MediaServer::create(['type' => ServerType::Plex, 'name' => 'Plex', 'url' => 'http://plex.local', 'api_key' => 'k', 'settings' => ['machine_id' => 'm1']]);
        Library::create(['media_server_id' => $server->id, 'external_id' => '11', 'name' => 'Movies']);
        Library::create(['media_server_id' => $server->id, 'external_id' => '12', 'name' => 'Private']);
        $anna = $this->member($server, ['11']);
        Http::fake(['plex.local/library/recentlyAdded*' => Http::response(['MediaContainer' => ['Metadata' => [
            ['type' => 'movie', 'ratingKey' => '5', 'title' => 'Arrival', 'year' => 2016, 'thumb' => '/library/metadata/5/thumb/1700000000', 'addedAt' => 1700000000, 'librarySectionTitle' => 'Movies'],
            ['type' => 'movie', 'ratingKey' => '6', 'title' => 'Hidden', 'thumb' => '/library/metadata/6/thumb/1700000001', 'addedAt' => 1700000001, 'librarySectionTitle' => 'Private'],
            ['type' => 'movie', 'ratingKey' => '7', 'title' => 'Odd thumb', 'thumb' => 'http://evil.example/x.jpg', 'librarySectionTitle' => 'Movies'],
        ]]])]);

        app(NewOnServers::class)->warm($server);

        $this->assertSame(['Arrival'], array_column(app(NewOnServers::class)->forMember($anna), 'title'));
    }

    public function test_the_members_page_shows_them_without_calling_the_servers(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $anna = $this->member($server, null);
        Http::fake();
        Queue::fake();

        $this->actingAs($anna);
        Livewire::test(MyAccess::class)->assertSee('Hi Anna')->assertSee('Open Home')->assertDontSee('New on the server');
        Http::assertNothingSent();
        // Their own history (no Tracearr) is fetched on the queue, once.
        Queue::assertPushed(RefreshMemberHistory::class, 1);
    }
}
