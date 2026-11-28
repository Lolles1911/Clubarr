<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\ExtensionRequests\Pages\ListExtensionRequests;
use App\Filament\Admin\Resources\ProblemReports\Pages\ListProblemReports;
use App\Filament\App\Pages\Help;
use App\Filament\App\Pages\MyAccess;
use App\Filament\Pages\Auth\Profile;
use App\Models\ExtensionRequest;
use App\Models\MediaServer;
use App\Models\ProblemReport;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\MemberNote;
use App\Notifications\WeeklyNew;
use App\Services\MemberRequests;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MemberRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $anna;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'mail_enabled' => '1']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Anna-pass-123']);
        $this->anna->forceFill(['access_expires_at' => now()->addDays(5)])->save();
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $this->server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
    }

    private function asMember(): void
    {
        $this->actingAs($this->anna);
        Filament::setCurrentPanel('app');
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_a_member_asks_for_more_time_once_and_an_admin_approves(): void
    {
        Notification::fake();
        $this->asMember();
        Livewire::test(MyAccess::class)->callAction('askForTime', ['note' => 'Please!'])->assertNotified();
        Livewire::test(MyAccess::class)->assertActionHidden('askForTime'); // one at a time

        $this->asAdmin();
        $request = ExtensionRequest::sole();
        Livewire::test(ListExtensionRequests::class)->callAction(TestAction::make('approve')->table($request), ['days' => 30]);

        $this->assertTrue($this->anna->fresh()->access_expires_at->between(now()->addDays(34), now()->addDays(36)));
        Notification::assertSentTo($this->anna, MemberNote::class);
    }

    public function test_a_problem_is_reported_answered_by_mail_and_shown_to_the_member_only(): void
    {
        Notification::fake();
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Not hers', 'url' => 'http://x.local', 'api_key' => 'k']);
        $this->asMember();
        Livewire::test(Help::class)->callAction('report', ['server' => $other->id, 'subject' => 'x', 'body' => 'y'])->assertHasActionErrors(['server']);
        $this->assertNull(app(MemberRequests::class)->report($this->anna, $other->id, 'x', 'y')->media_server_id); // not one of her servers
        ProblemReport::query()->delete();
        Livewire::test(Help::class)->callAction('report', ['server' => $this->server->id, 'subject' => 'Dune won\'t play', 'body' => 'Spinner forever']);
        $report = ProblemReport::sole();
        $this->assertSame($this->server->id, $report->media_server_id);

        $this->asAdmin();
        Livewire::test(ListProblemReports::class)->callAction(TestAction::make('reply')->table($report), ['reply' => 'Fixed it, try again', 'close' => true]);
        Notification::assertSentTo($this->anna, MemberNote::class);

        $bo = User::create(['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'x']);
        $this->actingAs($bo);
        Filament::setCurrentPanel('app');
        Livewire::test(Help::class)->assertDontSee('Dune');
        $this->asMember();
        Livewire::test(Help::class)->assertSee('Fixed it, try again');
    }

    public function test_a_member_can_delete_their_account_with_their_password(): void
    {
        Http::fake([
            'jf.local/Users/a1' => Http::sequence()->push(['Id' => 'a1', 'Policy' => ['IsAdministrator' => false]])->push(null, 204),
        ]);
        $this->asMember();
        Livewire::test(Profile::class)->callAction('deleteAccount', ['password' => 'wrong'])->assertHasActionErrors(['password']);
        $this->assertNotNull($this->anna->fresh());

        Livewire::test(Profile::class)->callAction('deleteAccount', ['password' => 'Anna-pass-123']);
        $this->assertNull(User::find($this->anna->id));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_the_weekly_mail_goes_only_to_those_who_want_it_and_only_with_news(): void
    {
        Notification::fake();
        $this->anna->forceFill(['digest' => true, 'digest_sent_at' => now()->subWeek()])->save();
        Cache::put('clubarr:recent:'.$this->server->id.':'.md5((string) json_encode(null)), [
            ['ref' => 'm1', 'title' => 'Dune', 'year' => 2021, 'added' => now()->subDay()->getTimestamp()],
            ['ref' => 'm2', 'title' => 'Old one', 'year' => 1999, 'added' => now()->subWeeks(3)->getTimestamp()],
        ], 3600);

        Artisan::call('clubarr:weekly-new');
        Artisan::call('clubarr:weekly-new'); // nothing new since: no second mail

        Notification::assertSentToTimes($this->anna, WeeklyNew::class, 1);
    }
}
