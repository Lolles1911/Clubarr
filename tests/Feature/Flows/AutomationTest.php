<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Filament\App\Pages\Apply;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Notifications\MemberNote;
use App\Services\InvitationMaker;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationTest extends TestCase
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
        Http::fake(['jf.local/Users/*' => Http::response(['Id' => 'x', 'Policy' => ['IsAdministrator' => false]]), 'jf.local/Users/*/Policy' => Http::response(null, 204)]);
    }

    private function member(string $name, array $attributes = []): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
        $user->forceFill($attributes)->save();
        ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $this->server->id, 'remote_id' => strtolower($name), 'remote_username' => strtolower($name), 'status' => AccountStatus::Active]);

        return $user;
    }

    public function test_inactive_members_are_warned_then_paused_and_watching_resets_it(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['inactive_days' => '30']);
        $idle = $this->member('Idle', ['last_seen_at' => now()->subDays(40), 'created_at' => now()->subDays(90)]);
        $busy = $this->member('Busy', ['last_seen_at' => now()->subDay(), 'created_at' => now()->subDays(90)]);

        Artisan::call('clubarr:housekeeping');
        Notification::assertSentTo($idle, MemberNote::class);
        $this->assertNull($idle->fresh()->disabled_at);
        Notification::assertNotSentTo($busy, MemberNote::class);

        $this->travel(7)->days();
        Artisan::call('clubarr:housekeeping');
        $this->assertNotNull($idle->fresh()->disabled_at);
        $this->assertNull($busy->fresh()->disabled_at);
    }

    public function test_server_users_are_deleted_the_set_days_after_access_ended_and_the_account_stays(): void
    {
        app(SiteSettings::class)->set(['cleanup_days' => '14']);
        $old = $this->member('Old', ['access_expires_at' => now()->subDays(20)]);
        $recent = $this->member('Recent', ['access_expires_at' => now()->subDays(3)]);

        Artisan::call('clubarr:housekeeping');

        $this->assertFalse($old->serverAccounts()->exists());
        $this->assertNotNull($old->fresh());
        $this->assertTrue($recent->serverAccounts()->exists());
    }

    public function test_apply_to_join_then_an_admin_invites_from_a_template(): void
    {
        Notification::fake();
        [$template] = app(InvitationMaker::class)->create(['label' => 'Friends', 'access_days' => 30], [$this->server->id => ['library_ids' => null]], $this->admin, template: true);
        Filament::setCurrentPanel('app');
        $this->get('/apply')->assertNotFound(); // closed by default

        app(SiteSettings::class)->set(['apply_enabled' => '1', 'apply_template_id' => (string) $template->id]);
        Livewire::test(Apply::class)->fillForm(['name' => 'Cleo', 'email' => 'Cleo@Example.com', 'message' => 'Friend of Anna'])->call('join');
        Livewire::test(Apply::class)->fillForm(['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'spam'])->call('join');
        $this->assertSame(['cleo@example.com'], AccessRequest::pluck('email')->all());

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(ListAccessRequests::class)->callAction(TestAction::make('approve')->table(AccessRequest::sole()), ['template' => $template->id]);

        $invitation = Invitation::where('is_template', false)->sole();
        $this->assertSame([1, 30], [$invitation->max_uses, $invitation->access_days]);
        Notification::assertSentOnDemand(InvitationLink::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'cleo@example.com');
    }

    public function test_old_data_is_removed_as_set_but_templates_and_used_invitations_stay(): void
    {
        $maker = app(InvitationMaker::class);
        [$template] = $maker->create(['label' => 'T'], [$this->server->id => ['library_ids' => null]], $this->admin, template: true);
        [$old] = $maker->create(['label' => 'Old', 'expires_at' => now()->subDays(100)], [$this->server->id => ['library_ids' => null]], $this->admin);
        [$recent] = $maker->create(['label' => 'Recent', 'expires_at' => now()->subDays(5)], [$this->server->id => ['library_ids' => null]], $this->admin);
        [$waitlist] = $maker->create(['label' => 'Waitlist', 'expires_at' => now()->subDays(100)], [$this->server->id => ['library_ids' => null]], $this->admin);
        app(SiteSettings::class)->set(['waitlist_template_id' => (string) $waitlist->id]);
        AuditLog::query()->update(['created_at' => now()->subDays(400)]);
        $decided = AccessRequest::create(['name' => 'Sam', 'email' => 'sam@example.com']);
        $decided->forceFill(['status' => 'declined', 'decided_at' => now()->subDays(200)])->save();
        $pending = AccessRequest::create(['name' => 'Kim', 'email' => 'kim@example.com']);

        Artisan::call('clubarr:housekeeping');

        $this->assertSame([$template->id, $recent->id, $waitlist->id], Invitation::orderBy('id')->pluck('id')->all());
        $this->assertNull(AccessRequest::find($decided->id));
        $this->assertNotNull(AccessRequest::find($pending->id));
        $this->assertSame(['data.cleaned'], AuditLog::pluck('action')->unique()->values()->all());

        // 0 keeps everything.
        app(SiteSettings::class)->set(['retention_invitations_days' => '0']);
        Invitation::whereKey($recent->id)->update(['expires_at' => now()->subDays(1000)]);
        Artisan::call('clubarr:housekeeping');
        $this->assertNotNull(Invitation::find($recent->id));
    }
}
