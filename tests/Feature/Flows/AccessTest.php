<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\MyAccess;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\AccessEnded;
use App\Notifications\AccessEndingSoon;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, ?\DateTimeInterface $until = null): User
    {
        $user = User::create(['name' => 'M', 'email' => $email, 'password' => 'Member-pass-1']);
        $user->forceFill(['access_expires_at' => $until])->save();

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
    }

    public function test_members_cannot_open_the_admin(): void
    {
        $this->actingAs($this->member('m@example.com'))->get('/admin')->assertForbidden();
        $this->get('/')->assertOk();
    }

    public function test_a_member_cannot_change_another_members_password(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $victim = ServerAccount::create(['user_id' => $this->member('victim@example.com')->id, 'media_server_id' => $server->id, 'remote_id' => 'v1', 'remote_username' => 'victim', 'status' => AccountStatus::Active]);
        $attacker = $this->member('attacker@example.com');
        $own = ServerAccount::create(['user_id' => $attacker->id, 'media_server_id' => $server->id, 'remote_id' => 'a1', 'remote_username' => 'attacker', 'status' => AccountStatus::Active]);
        Http::fake([
            'jf.local/Users/a1' => Http::response(['Id' => 'a1', 'Policy' => []]),
            'jf.local/Users/a1/Password' => Http::response(null, 204),
        ]);

        $this->actingAs($attacker);
        Queue::fake(); // the page's own history refresh is queued, not a call here
        Livewire::test(MyAccess::class)->callAction('changePassword', [], ['account' => $victim->id]);
        Http::assertNothingSent();

        Livewire::test(MyAccess::class)
            ->callAction('changePassword', ['password' => 'Own-new-pass-1', 'password_confirmation' => 'Own-new-pass-1'], ['account' => $own->id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Users/a1/Password') && $r['NewPw'] === 'Own-new-pass-1');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'v1'));
    }

    public function test_ending_access_warns_first_then_pauses_the_users(): void
    {
        Notification::fake();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $soon = $this->member('soon@example.com', now()->addDays(2));
        $ended = $this->member('ended@example.com', now()->subHour());
        $account = ServerAccount::create(['user_id' => $ended->id, 'media_server_id' => $server->id, 'remote_id' => 'e1', 'remote_username' => 'ended', 'status' => AccountStatus::Active]);
        Http::fake([
            'jf.local/Users/e1' => Http::response(['Id' => 'e1', 'Policy' => ['EnabledFolders' => []]]),
            'jf.local/Users/e1/Policy' => Http::response(null, 204),
        ]);

        $this->artisan('clubarr:check-access')->assertSuccessful();

        Notification::assertSentTo($soon, AccessEndingSoon::class);
        Notification::assertSentTo($ended, AccessEnded::class);
        $this->assertSame(AccountStatus::Disabled, $account->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Users/e1/Policy') && $r['IsDisabled'] === true);

        $this->artisan('clubarr:check-access');
        Notification::assertSentToTimes($soon, AccessEndingSoon::class, 1);
    }

    public function test_admin_two_factor_is_required_unless_turned_off(): void
    {
        $admin = User::where('email', 'admin@example.com')->sole();
        $this->actingAs($admin)->get('/admin')->assertRedirectContains('/admin/multi-factor-authentication/set-up');

        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_disabled_members_cannot_sign_in(): void
    {
        $member = $this->member('off@example.com');
        $member->forceFill(['disabled_at' => now()])->save();
        $this->actingAs($member)->get('/')->assertForbidden();
    }
}
