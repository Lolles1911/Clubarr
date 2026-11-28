<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\ServerCheck;
use App\Models\User;
use App\Notifications\MemberNote;
use App\Services\NewOnServers;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Every admin and member page opens. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_opens(): void
    {
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $member = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $this->actingAs($admin);

        foreach (['/admin', '/admin/settings', '/admin/integrations', '/admin/alerts', '/admin/mail-texts', '/admin/stats', '/admin/api-keys',
            '/admin/maintenances', '/admin/checks', '/admin/movie-nights', '/admin/challenges', '/admin/wizard', '/admin/wizard-bundles', '/admin/users', '/admin/users/'.$member->id, '/admin/member-groups', '/admin/invitations', '/admin/media-servers', '/admin/audit-logs'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/admin')->assertSee('Clubarr '.config('clubarr.version'));
        $this->actingAs($member);
        foreach (['/', '/profile', '/my-stats', '/help'] as $path) {
            $this->followingRedirects()->get($path)->assertOk();
        }
        $this->assertContains($this->get('/admin/users')->status(), [302, 403]);
    }

    public function test_each_theme_opens_every_page_with_its_own_look(): void
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        foreach (['streaming' => true, 'midnight' => true, 'classic' => false] as $theme => $sheet) {
            app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'theme' => $theme]);
            $this->actingAs($admin);
            foreach (['/admin', '/admin/invitations', '/admin/users', '/profile', '/'] as $path) {
                $html = $this->followingRedirects()->get($path)->assertOk()->getContent();
                $this->assertSame($sheet, str_contains((string) $html, 'css/themes/'.$theme.'.css'), $theme.' '.$path);
            }
            auth()->logout();
            $this->get('/login')->assertOk();
        }
    }

    public function test_status_page_manifest_and_new_episodes(): void
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);

        $this->get('/status')->assertNotFound();
        app(SiteSettings::class)->set(['status_page' => '1', 'site_name' => 'Waffles']);
        ServerCheck::create(['media_server_id' => $server->id, 'up' => true, 'checked_at' => now()->subHour()]);
        ServerCheck::create(['media_server_id' => $server->id, 'up' => false, 'checked_at' => now()->subMinutes(30)]);
        $this->get('/status')->assertOk()->assertSee('Home')->assertSee('50 %')->assertDontSee('admin@example.com');

        $this->getJson('/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'Waffles')->assertJsonPath('display', 'standalone');

        // New episodes: a show they watched got something new on their server.
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        Cache::put('clubarr:member-history:'.$anna->id, ['resume' => [], 'plays' => [
            ['started_at' => now()->subDays(3)->toIso8601String(), 'media_type' => 'episode', 'media_title' => 'Pilot', 'show_title' => 'Severance', 'duration_ms' => 1],
        ]], 600);
        $key = (new \ReflectionMethod(NewOnServers::class, 'cacheKey'))->invoke(null, $server->id, null);
        Cache::put($key, [['ref' => 's1', 'title' => 'Severance', 'year' => null, 'added' => now()->subHours(2)->getTimestamp()],
            ['ref' => 'm1', 'title' => 'Some Film', 'year' => 2024, 'added' => now()->subHours(2)->getTimestamp()]], 600);
        Artisan::call('clubarr:new-episodes');
        Notification::assertSentTo($anna, MemberNote::class, fn ($n) => str_contains(json_encode((array) $n->toMail($anna)), 'Severance') && ! str_contains(json_encode((array) $n->toMail($anna)), 'Some Film'));
    }
}
