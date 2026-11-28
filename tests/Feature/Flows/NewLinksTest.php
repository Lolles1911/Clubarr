<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\Join;
use App\Filament\App\Pages\MyAccess;
use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Services\Applications;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** When a link fails: a new invitation link (an admin approves), and a new Silo link (at once). */
class NewLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'mail_enabled' => '1']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        Filament::setCurrentPanel('app');
    }

    public function test_an_expired_invitation_can_ask_for_a_new_link_and_the_admin_sends_one(): void
    {
        Notification::fake();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $old = new Invitation(['label' => 'For Sam', 'max_uses' => 1, 'access_days' => 30, 'expires_at' => now()->subDay()]);
        $code = $old->assignCode();
        $old->save();
        $old->mediaServers()->attach($server->id);

        Livewire::test(Join::class, ['code' => $code])->assertSee('expired')
            ->callAction('askNewLink', ['name' => 'Sam', 'email' => 'sam@example.com'])->assertNotified();
        $request = AccessRequest::sole();
        $this->assertSame($old->id, $request->renew_of);

        $this->actingAs($this->admin);
        app(Applications::class)->approveRenewal($request);
        $new = Invitation::whereKeyNot($old->id)->sole();
        $this->assertSame([[$server->id], 30, null], [$new->mediaServers->pluck('id')->all(), $new->access_days, $new->problem()]);
        Notification::assertSentOnDemand(InvitationLink::class);

        // A withdrawn invitation can't be renewed.
        $old->forceFill(['revoked_at' => now()])->save();
        auth()->logout();
        Livewire::test(Join::class, ['code' => $code])->assertActionHidden('askNewLink');
    }

    public function test_a_member_gets_a_new_silo_link_for_their_own_pending_user(): void
    {
        $silo = MediaServer::create(['type' => ServerType::Silo, 'name' => 'Silo', 'url' => 'http://silo.local', 'api_key' => 'sa_x']);
        Http::fake(['silo.local/api/v2/admin/invitations' => Http::response(['invitation' => ['id' => 77], 'claim_url' => 'http://silo.local/invite/NEWCODE'])]);
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $account = ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $silo->id, 'status' => AccountStatus::Pending, 'claim_url' => 'http://silo.local/invite/OLD', 'claim_id' => '1']);
        $this->actingAs($anna);

        Livewire::test(MyAccess::class)->callAction('newClaim', [], ['account' => $account->id])->assertNotified();
        $this->assertNotSame('http://silo.local/invite/OLD', $account->fresh()->claim_url);
    }
}
