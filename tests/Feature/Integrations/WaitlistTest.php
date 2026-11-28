<?php

namespace Tests\Feature\Integrations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\Join;
use App\Filament\App\Pages\JoinWaitlist;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistInvitation;
use App\Services\SiteSettings;
use App\Services\Waitlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private MediaServer $server;

    private Invitation $template;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k', 'capacity' => 1]);
        $this->template = new Invitation(['label' => 'template', 'access_days' => 30, 'max_streams' => 2]);
        $this->template->assignCode();
        $this->template->save();
        $this->template->mediaServers()->attach($this->server->id, ['library_ids' => json_encode(['lib1'])]);
        app(SiteSettings::class)->set(['waitlist_template_id' => (string) $this->template->id]);
    }

    private function seatTaken(): ServerAccount
    {
        $user = User::create(['name' => 'M', 'email' => 'm'.uniqid().'@example.com', 'password' => 'x']);

        return ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $this->server->id, 'remote_id' => uniqid(), 'remote_username' => 'm', 'status' => AccountStatus::Active]);
    }

    public function test_a_full_server_refuses_invitations_and_points_to_the_waiting_list(): void
    {
        $this->seatTaken();
        $invitation = new Invitation(['max_uses' => 1]);
        $code = $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);
        Http::fake();

        Livewire::test(Join::class, ['code' => $code])->fillForm([
            'name' => 'Anna', 'email' => 'anna@example.com', 'username' => 'anna', 'password' => 'Anna-pass-123', 'password_confirmation' => 'Anna-pass-123',
        ])->call('join')->assertNotified();

        Http::assertNothingSent();
        $this->assertSame(0, $invitation->fresh()->uses);
    }

    public function test_the_first_in_line_is_invited_when_a_seat_frees_and_the_seat_is_held(): void
    {
        $taken = $this->seatTaken();
        $waitlist = app(Waitlist::class);
        $first = $waitlist->join('First', 'first@example.com');
        $waitlist->join('Second', 'second@example.com');

        $this->assertSame(0, $waitlist->tick(), 'full: nobody invited');
        $taken->delete();
        $this->assertSame(1, $waitlist->tick(), 'one seat: one invitation');
        $this->assertSame(0, $waitlist->tick(), 'the seat is held for the first');

        $first->refresh();
        $this->assertSame('invited', $first->status);
        $this->assertTrue($first->invitation->from_waitlist);
        $this->assertSame([1, 30, 2], [$first->invitation->max_uses, $first->invitation->access_days, $first->invitation->max_streams]);
        $this->assertSame(['lib1'], $first->invitation->load('mediaServers')->libraryIdsFor($first->invitation->mediaServers->first()));
        Notification::assertSentOnDemand(WaitlistInvitation::class);

        // Not used in 48 hours: it goes to the next.
        $this->travel(49)->hours();
        $this->assertSame(1, $waitlist->tick());
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame('invited', WaitlistEntry::where('email', 'second@example.com')->value('status'));
    }

    public function test_the_public_page_says_the_same_for_a_known_email_and_ignores_bots(): void
    {
        Livewire::test(JoinWaitlist::class)->fillForm(['name' => 'A', 'email' => 'a@example.com'])->call('join')->assertSet('done', true);
        Livewire::test(JoinWaitlist::class)->fillForm(['name' => 'A', 'email' => 'A@example.com'])->call('join')->assertSet('done', true);
        Livewire::test(JoinWaitlist::class)->fillForm(['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam'])->call('join');
        $this->assertSame(['a@example.com'], WaitlistEntry::pluck('email')->all());
    }

    public function test_the_page_is_gone_without_a_template(): void
    {
        app(SiteSettings::class)->set(['waitlist_template_id' => '']);
        $this->get('/waitlist')->assertNotFound();
    }
}
