<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\Maintenances\Pages\ManageMaintenances;
use App\Filament\App\Pages\Apply;
use App\Jobs\SendAdminAlert;
use App\Jobs\SendAnnouncement;
use App\Models\AccessRequest;
use App\Models\Announcement;
use App\Models\Invitation;
use App\Models\Maintenance;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Services\Audit;
use App\Services\InvitationMaker;
use App\Services\MemberExport;
use App\Services\MemberInvites;
use App\Services\SiteSettings;
use App\Support\AdminRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FrontPageAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'site_name' => 'Waffles']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
    }

    private function member(string $name = 'Anna'): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $this->server->id, 'remote_id' => 'r'.$user->id, 'remote_username' => strtolower($name), 'status' => AccountStatus::Active]);

        return $user;
    }

    public function test_the_front_page_is_off_by_default_and_shows_only_what_is_chosen(): void
    {
        $this->get('/')->assertRedirect();

        app(SiteSettings::class)->set(['landing_enabled' => '1', 'landing_text' => '<script>x</script> Hi friends', 'apply_enabled' => '1']);
        $this->get('/')->assertOk()->assertSee('Waffles')->assertSee('Sign in')->assertSee('Apply to join')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>x', false)
            ->assertSee('Home')->assertSee('Online');

        app(SiteSettings::class)->set(['landing_status' => '0', 'apply_enabled' => '0']);
        $this->get('/')->assertOk()->assertDontSee('Online')->assertDontSee('Apply to join');

        // Signed in: straight on, not the front page.
        $this->actingAs($this->member());
        $this->get('/')->assertDontSee('Hi friends');
    }

    public function test_the_status_says_maintenance_and_down_alerts_wait_meanwhile(): void
    {
        Queue::fake();
        app(SiteSettings::class)->set(['landing_enabled' => '1', 'alerts_webhook_url' => 'https://discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwxyz_-123']);
        Maintenance::create(['media_server_id' => $this->server->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->get('/')->assertSee('Maintenance');
        Audit::log('server.down', $this->server, ['error' => 'x']);
        Queue::assertNotPushed(SendAdminAlert::class);
    }

    public function test_planning_maintenance_tells_the_members(): void
    {
        Queue::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(ManageMaintenances::class)->callAction('create', [
            'media_server_id' => $this->server->id, 'starts_at' => now()->addDay()->format('Y-m-d H:i'), 'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i'),
            'note' => 'New disks', 'tell' => true,
        ])->assertHasNoActionErrors();

        $maintenance = Maintenance::sole();
        $this->assertSame([$this->admin->id, true], [$maintenance->created_by, $maintenance->told]);
        $announcement = Announcement::sole();
        $this->assertTrue($announcement->as_banner && $announcement->by_mail);
        $this->assertStringContainsString('New disks', $announcement->body);
        Queue::assertPushed(SendAnnouncement::class);
    }

    public function test_applications_ask_the_admins_questions(): void
    {
        app(SiteSettings::class)->set(['apply_enabled' => '1', 'apply_questions' => "Who do you know here?\n\nWhat do you watch on?"]);
        Filament::setCurrentPanel('app');
        Livewire::test(Apply::class)->fillForm(['name' => 'Sam', 'email' => 'sam@example.com', 'answers' => [0 => 'Anna']])
            ->call('join')->assertHasFormErrors(['answers.1']);
        Livewire::test(Apply::class)->fillForm(['name' => 'Sam', 'email' => 'sam@example.com', 'answers' => [0 => 'Anna', 1 => 'A TV']])
            ->call('join')->assertHasNoFormErrors();

        $this->assertSame([['q' => 'Who do you know here?', 'a' => 'Anna'], ['q' => 'What do you watch on?', 'a' => 'A TV']], AccessRequest::sole()->answers);
    }

    public function test_a_moderator_handles_members_but_not_settings_servers_or_admins(): void
    {
        $member = $this->member();
        $mod = new User(['name' => 'Mod', 'email' => 'mod@example.com', 'password' => 'Mod-pass-12345']);
        $mod->is_admin = true;
        $mod->admin_role = AdminRole::MODERATOR;
        $mod->save();
        $this->actingAs($mod);

        foreach (['/admin/settings', '/admin/integrations', '/admin/alerts', '/admin/api-keys', '/admin/media-servers', '/admin/member-groups', '/admin/audit-logs', '/admin/maintenances', '/admin/users/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/admin/users')->assertOk()->assertSee('Anna')->assertDontSee('admin@example.com')->assertDontSee('Export (CSV)');
        $this->get('/admin/users/'.$member->id.'/edit')->assertOk();
        $this->get('/admin/users/'.$this->admin->id.'/edit')->assertNotFound();
        $this->get('/admin/invitations')->assertOk();
    }

    public function test_member_invitations_wait_for_approval_when_asked(): void
    {
        Notification::fake();
        [$template] = app(InvitationMaker::class)->create(['label' => 'Friends'], [$this->server->id => ['library_ids' => null]], $this->admin, template: true);
        app(SiteSettings::class)->set(['member_invites_per_month' => '2', 'member_invite_template_id' => (string) $template->id, 'member_invites_approval' => '1', 'mail_enabled' => '1']);
        $anna = $this->member();

        [$invitation, $code] = app(MemberInvites::class)->invite($anna, 'friend@example.com', null);
        $this->assertNotNull($invitation->fresh()->problem());
        $this->assertNull(Invitation::findByCode($code)?->usable()->first());
        Notification::assertNothingSentTo(Notification::route('mail', 'friend@example.com'));
        $this->assertStringNotContainsString('friend@example.com', (string) DB::table('invitations')->where('id', $invitation->id)->value('pending_email'));

        app(MemberInvites::class)->approve($invitation->fresh());
        $this->assertNull($invitation->fresh()->problem());
        Notification::assertSentOnDemand(InvitationLink::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'friend@example.com');
        $this->assertNull($invitation->fresh()->pending_code);

        [$second] = app(MemberInvites::class)->invite($anna, null, null);
        app(MemberInvites::class)->decline($second->fresh());
        $this->assertNotNull($second->fresh()->revoked_at);
    }

    public function test_the_member_export_is_csv_without_formulas(): void
    {
        $this->member('Anna');
        User::create(['name' => '=HYPERLINK("x")', 'email' => 'bad@example.com', 'password' => 'Member-pass-1']);
        $csv = stream_get_contents(app(MemberExport::class)->stream());

        $this->assertStringContainsString('anna@example.com', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('admin@example.com', $csv);
        $this->assertStringNotContainsString('Member-pass', $csv);
    }
}
