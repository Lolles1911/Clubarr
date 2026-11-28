<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerType;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Widgets\UpdateNotice;
use App\Jobs\SendAdminAlert;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\AdminAlerts;
use App\Services\Audit;
use App\Services\ServerSync;
use App\Services\SiteSettings;
use App\Services\UpdateCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AlertsAndUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz_-123';

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_only_discord_webhooks_are_accepted(): void
    {
        foreach ([self::HOOK, 'https://ptb.discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz'] as $good) {
            app(SiteSettings::class)->set(['alerts_webhook_url' => $good]);
            $this->assertSame($good, AdminAlerts::webhook());
        }
        foreach (['http://discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz', 'https://evil.example/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz',
            'https://discord.com.evil.example/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz', 'https://192.168.1.1/x'] as $bad) {
            app(SiteSettings::class)->set(['alerts_webhook_url' => $bad]);
            $this->assertNull(AdminAlerts::webhook(), $bad);
        }
    }

    public function test_events_can_be_turned_off_one_by_one(): void
    {
        Queue::fake();
        Livewire::test(Settings::class)
            ->fillForm(['alerts_webhook_url' => self::HOOK, 'alerts_events' => ['server_down']])
            ->call('save')->assertHasNoFormErrors();

        Audit::log('waitlist.joined');
        Queue::assertNothingPushed();

        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        Http::fake(['jf.local/*' => Http::response('', 500)]);
        try {
            app(ServerSync::class)->check($server);
        } catch (\Throwable) {
        }
        Queue::assertPushed(SendAdminAlert::class, fn ($job) => str_contains($job->text, 'Home'));

        // None chosen means none, not "all".
        Livewire::test(Settings::class)->fillForm(['alerts_events' => []])->call('save');
        $this->assertFalse(AdminAlerts::enabled('server_down'));
        $this->assertFalse(AdminAlerts::enabled('member_joined'));
    }

    public function test_the_alert_goes_to_the_webhook_without_mentions_and_the_url_stays_secret(): void
    {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        app(SiteSettings::class)->set(['alerts_webhook_url' => self::HOOK]);

        (new SendAdminAlert('@everyone hi'))->handle();

        Http::assertSent(fn ($r) => $r->url() === self::HOOK && $r['allowed_mentions'] === ['parse' => []]);
        $this->assertStringNotContainsString('abcdefghijklmnop', Livewire::test(Settings::class)->html());
    }

    public function test_a_newer_release_shows_and_is_told_once(): void
    {
        Queue::fake();
        app(SiteSettings::class)->set(['alerts_webhook_url' => self::HOOK]);
        config(['clubarr.version' => '0.3.0-beta']);
        Http::fake(['api.github.com/*' => Http::response([
            ['tag_name' => 'v0.3.1-beta', 'html_url' => 'https://github.com/Lolles1911/Clubarr/releases/tag/v0.3.1-beta', 'draft' => false],
            ['tag_name' => 'v9.9.9', 'draft' => true],
            ['tag_name' => 'v0.2.3-beta', 'draft' => false],
        ])]);

        $this->assertSame('0.3.1-beta', app(UpdateCheck::class)->run()['version']);
        app(UpdateCheck::class)->run();
        Queue::assertPushed(SendAdminAlert::class, 1);
        Livewire::test(UpdateNotice::class)->assertSee('0.3.1-beta')->assertSee('0.3.0-beta');

        app(SiteSettings::class)->set(['update_check' => '0', 'internal_latest_version' => '']);
        $this->assertNull(app(UpdateCheck::class)->run());
    }
}
