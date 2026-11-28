<?php

namespace Tests\Feature\Invitations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\ListInvitations;
use App\Filament\App\Pages\InviteFriend;
use App\Filament\App\Pages\Join;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Notifications\InvitationUsed;
use App\Services\MemberInvites;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class InvitationFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'mail_enabled' => '1']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        Http::fake([
            'jf.local/Users/New' => Http::response(['Id' => 'new1', 'Name' => 'bo']),
            'jf.local/Users/new1' => Http::response(['Id' => 'new1', 'Policy' => []]),
            'jf.local/Users/new1/Policy' => Http::response(null, 204),
        ]);
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    private function template(): Invitation
    {
        $this->asAdmin();
        Livewire::test(CreateInvitation::class)
            ->fillForm(['label' => 'Family', 'servers' => [$this->server->id], 'access_days' => 60, 'greeting' => 'Welcome to the family!', 'is_template' => true])
            ->call('create')->assertHasNoFormErrors()->assertNotified('Template saved.');

        return Invitation::where('is_template', true)->sole();
    }

    private function member(string $email = 'anna@example.com'): User
    {
        $user = User::create(['name' => 'Anna', 'email' => $email, 'password' => 'Anna-pass-123']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $this->server->id, 'remote_id' => 'a'.$user->id, 'remote_username' => 'anna', 'status' => AccountStatus::Active]);

        return $user;
    }

    public function test_a_template_is_never_an_invitation_and_makes_invitations_with_its_set_up(): void
    {
        Notification::fake();
        $template = $this->template();
        $this->assertNotNull($template->problem());
        $this->assertSame(0, Invitation::applyUsable(Invitation::query())->count());

        Livewire::test(ListInvitations::class)->set('activeTab', 'templates')
            ->callAction(TestAction::make('useTemplate')->table($template), ['label' => 'Mum', 'send_to' => 'mum@example.com'])
            ->assertNotified('Here is the invitation link');

        $invitation = Invitation::where('is_template', false)->sole();
        $this->assertSame([60, 'Welcome to the family!', [$this->server->id]], [$invitation->access_days, $invitation->greeting, $invitation->mediaServers->pluck('id')->all()]);
        Notification::assertSentOnDemand(InvitationLink::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'mum@example.com');
        $this->assertNotNull($invitation->fresh()->reminder_link); // kept, encrypted, for the reminder
        $this->assertStringNotContainsString('/join/', (string) $invitation->fresh()->getRawOriginal('reminder_link'));
    }

    public function test_many_at_once_gives_everyone_their_own_single_use_link(): void
    {
        Notification::fake();
        $template = $this->template();

        Livewire::test(ListInvitations::class)
            ->callAction('many', ['template' => $template->id, 'emails' => "a@example.com\nb@example.com, a@example.com\nnot-an-email"])
            ->assertNotified('2 invitations made, 2 mailed.');

        $made = Invitation::where('is_template', false)->get();
        $this->assertSame(['a@example.com', 'b@example.com'], $made->pluck('label')->sort()->values()->all());
        $this->assertTrue($made->every(fn ($i) => $i->max_uses === 1));
        Notification::assertSentOnDemandTimes(InvitationLink::class, 2);
    }

    public function test_members_invite_friends_within_their_monthly_number_and_only_with_the_template(): void
    {
        Notification::fake();
        $template = $this->template();
        $anna = $this->member();
        Filament::setCurrentPanel('app');
        $this->actingAs($anna);
        $this->assertFalse(InviteFriend::canAccess()); // off until the admin turns it on

        app(SiteSettings::class)->set(['member_invites_per_month' => '1', 'member_invite_template_id' => (string) $template->id]);
        $this->assertTrue(InviteFriend::canAccess());
        Livewire::test(InviteFriend::class)
            ->callAction('invite', ['email' => 'friend@example.com', 'greeting' => 'Come watch!'])
            ->assertNotified('Here is the invitation link');

        $invitation = Invitation::where('by_member', true)->sole();
        $this->assertSame([$anna->id, 1, 60, 'Come watch!'], [$invitation->created_by, $invitation->max_uses, $invitation->access_days, $invitation->greeting]);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $this->expectException(RuntimeException::class);
        app(MemberInvites::class)->invite($anna, null, null);
    }

    public function test_the_maker_is_told_when_their_invitation_is_used_and_the_kept_link_goes(): void
    {
        Notification::fake();
        $anna = $this->member();
        $invitation = new Invitation(['max_uses' => 1, 'greeting' => '<b>Hi Bo</b>']);
        $invitation->created_by = $anna->id;
        $invitation->by_member = true;
        $code = $invitation->assignCode();
        $invitation->reminder_link = 'http://x/join/'.$code;
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);

        Filament::setCurrentPanel('app');
        Livewire::test(Join::class, ['code' => $code])
            ->assertSee('&lt;b&gt;Hi Bo&lt;/b&gt;', false)->assertSee('Anna')
            ->fillForm(['name' => 'Bo', 'email' => 'bo@example.com', 'username' => 'bobo', 'password' => 'Bo-pass-12345', 'password_confirmation' => 'Bo-pass-12345'])
            ->call('join')->assertHasNoFormErrors();

        Notification::assertSentTo($anna, InvitationUsed::class);
        $this->assertNull($invitation->fresh()->reminder_link);
    }

    public function test_one_reminder_for_a_mailed_invitation_that_waits(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['invite_reminder_days' => '3']);
        $waiting = new Invitation(['max_uses' => 1, 'expires_at' => now()->addDays(10)]);
        $waiting->assignCode();
        $waiting->forceFill(['sent_to' => 'late@example.com', 'sent_at' => now()->subDays(4), 'reminder_link' => 'http://x/join/ABC'])->save();
        $waiting->mediaServers()->attach($this->server->id);
        $recent = new Invitation(['max_uses' => 1]);
        $recent->assignCode();
        $recent->forceFill(['sent_to' => 'new@example.com', 'sent_at' => now()->subDay(), 'reminder_link' => 'http://x/join/DEF'])->save();
        $recent->mediaServers()->attach($this->server->id);
        $expired = new Invitation(['max_uses' => 1, 'expires_at' => now()->subDay()]);
        $expired->assignCode();
        $expired->forceFill(['sent_to' => 'gone@example.com', 'sent_at' => now()->subDays(9), 'reminder_link' => 'http://x/join/GHI'])->save();

        Artisan::call('clubarr:invite-reminders');
        Artisan::call('clubarr:invite-reminders');

        Notification::assertSentOnDemandTimes(InvitationLink::class, 1);
        Notification::assertSentOnDemand(InvitationLink::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'late@example.com');
        $this->assertNull($waiting->fresh()->reminder_link);
        $this->assertNull($expired->fresh()->reminder_link);
        $this->assertNotNull($recent->fresh()->reminder_link);
    }

    public function test_the_invitation_mail_escapes_what_people_type(): void
    {
        $invitation = new Invitation(['max_uses' => 1, 'greeting' => '<script>alert(1)</script> Hej']);
        $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);

        $html = (string) (new InvitationLink('http://x/join/ABC', $invitation->load('mediaServers')))->toMail(new \stdClass)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('http://x/join/ABC', $html);
        $this->assertStringContainsString('Home (Jellyfin)', $html);
    }

    public function test_an_invitation_whose_servers_are_gone_or_off_cannot_be_used(): void
    {
        $invitation = new Invitation(['max_uses' => 1]);
        $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);
        $this->assertNull($invitation->fresh()->problem());

        $this->server->update(['is_enabled' => false]);
        $this->assertNotNull($invitation->fresh()->problem());
        $this->assertSame(0, Invitation::applyUsable(Invitation::query())->count());
    }

    public function test_invitation_page_posters_are_only_those_it_listed(): void
    {
        $this->get(route('filament.app.join-poster', ['key' => str_repeat('a', 24)]))->assertNotFound();
    }
}
