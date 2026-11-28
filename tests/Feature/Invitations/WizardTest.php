<?php

namespace Tests\Feature\Invitations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\WizardSteps\Pages\ManageWizardSteps;
use App\Filament\App\Pages\Join;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Models\WizardBundle;
use App\Models\WizardStep;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
    }

    private function invitation(?int $bundle = null): array
    {
        $invitation = new Invitation(['max_uses' => 1, 'wizard_bundle_id' => $bundle]);
        $code = $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);

        return [$invitation, $code];
    }

    public function test_ready_made_steps_are_added_and_shown_before_and_after_joining(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(ManageWizardSteps::class)->callAction('preset', ['server' => $this->server->id])->assertHasNoActionErrors();
        $this->assertSame(['before', 'after', 'after'], WizardStep::orderBy('position')->pluck('phase')->all());
        auth()->logout();

        [, $code] = $this->invitation();
        Filament::setCurrentPanel('app');
        Livewire::test(Join::class, ['code' => $code])->assertSee('What is Jellyfin?');

        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $this->server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        $this->actingAs($anna)->get('/get-started')->assertOk()->assertSee('Get the Jellyfin app')->assertSee('Tips for the best experience');
        $this->get('/help')->assertOk()->assertSee('Guides')->assertSee('Get the Jellyfin app');
    }

    public function test_a_bundle_replaces_the_standard_steps_and_text_is_never_html(): void
    {
        $bundle = WizardBundle::create(['name' => 'Family']);
        WizardStep::create(['phase' => 'before', 'title' => 'Standard step', 'body' => 'x']);
        WizardStep::create(['phase' => 'before', 'title' => 'Family step', 'wizard_bundle_id' => $bundle->id, 'body' => '**Hi** <script>alert(1)</script> [bad](javascript:alert(1))',
            'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']);

        [, $code] = $this->invitation($bundle->id);
        Filament::setCurrentPanel('app');
        $html = Livewire::test(Join::class, ['code' => $code])->assertSee('Family step')->assertDontSee('Standard step')->html();
        $this->assertStringContainsString('<strong>Hi</strong>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    public function test_get_started_after_joining_shows_only_that_invitations_servers(): void
    {
        $silo = MediaServer::create(['type' => ServerType::Silo, 'name' => 'Silo', 'url' => 'http://silo.local', 'api_key' => 'k']);
        WizardStep::create(['phase' => 'after', 'title' => 'Jellyfin tips', 'media_server_id' => $this->server->id]);
        WizardStep::create(['phase' => 'after', 'title' => 'Silo tips', 'media_server_id' => $silo->id]);
        [$invitation] = $this->invitation();
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $this->server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $silo->id, 'invitation_id' => $invitation->id, 'remote_id' => 's1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);

        $this->actingAs($anna)->get('/get-started?invitation='.$invitation->id)->assertOk()->assertSee('Silo tips')->assertDontSee('Jellyfin tips')->assertDontSee('Open Home');
        $this->get('/get-started')->assertOk()->assertSee(['Silo tips', 'Jellyfin tips']);
    }
}
