<?php

namespace Tests\Feature\Flows;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Profile;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\NewSignIn;
use App\Services\LoginGuard;
use App\Services\MemberRequests;
use App\Services\SiteSettings;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        Filament::setCurrentPanel('app');
    }

    public function test_a_failed_sign_in_is_logged_without_what_was_typed(): void
    {
        Livewire::test(Login::class)->fillForm(['email' => 'anna@example.com', 'password' => 'Wrong-pass-1'])->call('authenticate')->assertHasErrors(['data.email']);

        $line = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertSame($this->anna->id, $line->subject_id);
        $this->assertStringNotContainsString('Wrong-pass-1', json_encode($line->toArray()));
    }

    public function test_an_account_is_locked_after_too_many_failures_even_with_the_right_password(): void
    {
        for ($i = 0; $i < LoginGuard::MAX_FAILURES; $i++) {
            LoginGuard::failed('Anna@example.com ', $this->anna);
        }
        $this->assertSame(1, AuditLog::where('action', 'auth.locked')->count());

        Livewire::test(Login::class)->fillForm(['email' => 'anna@example.com', 'password' => 'Member-pass-1'])->call('authenticate')->assertHasErrors(['data.email']);
        $this->assertGuest();

        // Any email typed counts the same, so the lock says nothing about which accounts exist.
        $this->assertNull(LoginGuard::lockedFor('nobody@example.com'));
    }

    public function test_a_new_device_is_mailed_but_not_the_first_one_or_a_known_one(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);

        LoginGuard::signedIn($this->anna); // first device: just joined
        Notification::assertNothingSent();
        $this->assertSame(1, $this->anna->knownDevices()->count());

        $this->anna->knownDevices()->update(['token_hash' => hash('sha256', $token = str_repeat('a', 40))]);
        request()->cookies->set(LoginGuard::COOKIE, $token);
        LoginGuard::signedIn($this->anna); // known
        Notification::assertNothingSent();

        request()->cookies->remove(LoginGuard::COOKIE);
        LoginGuard::signedIn($this->anna); // new
        Notification::assertSentTo($this->anna, NewSignIn::class);
        $this->assertSame(2, $this->anna->knownDevices()->count());

        app(SiteSettings::class)->set(['login_alerts' => '0']);
        LoginGuard::signedIn($this->anna);
        Notification::assertSentToTimes($this->anna, NewSignIn::class, 1);
    }

    public function test_signing_out_everywhere_else_keeps_this_browser(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'other', 'user_id' => $this->anna->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'payload' => '', 'last_activity' => time()],
        ]);
        $this->actingAs($this->anna);

        Livewire::test(Profile::class)->assertSee('Safari on iPhone')
            ->callAction('signOutOthers', ['password' => 'wrong'])->assertHasActionErrors(['password']);
        $this->assertSame(1, DB::table('sessions')->count());

        Livewire::test(Profile::class)->callAction('signOutOthers', ['password' => 'Member-pass-1'])->assertHasNoActionErrors();
        $this->assertSame(0, DB::table('sessions')->where('id', 'other')->count());
        $this->assertTrue(AuditLog::where('action', 'member.signed_out_others')->exists());
    }

    public function test_a_new_email_waits_for_the_link_when_mail_is_on(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);
        $this->actingAs($this->anna);

        Livewire::test(Profile::class)->fillForm(['email' => 'new@example.com', 'currentPassword' => 'Member-pass-1'])->call('save')->assertHasNoErrors();
        $this->assertSame('anna@example.com', $this->anna->fresh()->email);
        Notification::assertSentOnDemand(VerifyEmailChange::class);

        app(SiteSettings::class)->set(['mail_enabled' => '0']);
        Livewire::test(Profile::class)->fillForm(['email' => 'new@example.com', 'currentPassword' => 'Member-pass-1'])->call('save')->assertHasNoErrors();
        $this->assertSame('new@example.com', $this->anna->fresh()->email);
    }

    public function test_notes_to_members_also_go_to_discord(): void
    {
        app(SiteSettings::class)->set(['discord_client_id' => '111', 'discord_client_secret' => 'secret', 'discord_bot_token' => 'bot-token', 'discord_guild_id' => '222']);
        $this->anna->forceFill(['discord_id' => '444'])->save();
        Http::fake([
            'discord.com/api/v10/users/@me/channels' => Http::response(['id' => '555']),
            'discord.com/api/v10/channels/555/messages' => Http::response(['id' => '1']),
        ]);

        MemberRequests::tell($this->anna->fresh(), 'Your access is extended', 'Good news.');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/channels/555/messages') && str_contains((string) $r['content'], 'Your access is extended'));
    }
}
