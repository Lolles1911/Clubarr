<?php

namespace Tests\Feature\Integrations;

use App\Enums\ServerType;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\ApiKey;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\InvitationMaker;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-123']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $member = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $member->forceFill(['admin_note' => 'private note'])->save();
    }

    public function test_without_a_valid_key_nothing_is_answered(): void
    {
        $this->getJson('/api/v1/status')->assertUnauthorized();
        $this->getJson('/api/v1/status', ['Authorization' => 'Bearer clb_'.str_repeat('x', 40)])->assertUnauthorized();
        [, $token] = ApiKey::issue('Test', false, $this->admin);
        $this->getJson('/api/v1/status', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('members.total', 1);
        $this->assertNotNull(ApiKey::first()->last_used_at);
        $this->assertNotSame($token, ApiKey::first()->token_hash);
    }

    public function test_members_are_listed_without_secrets_or_notes(): void
    {
        [, $token] = ApiKey::issue('Test', false, $this->admin);
        $body = $this->getJson('/api/v1/members', ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('data.0.email', 'anna@example.com')->getContent();
        $this->assertStringNotContainsString('private note', (string) $body);
        $this->assertStringNotContainsString('password', (string) $body);
    }

    public function test_invitations_are_made_from_a_template_only_with_a_key_allowed_to(): void
    {
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        [$template] = app(InvitationMaker::class)->create(['label' => 'Friends', 'access_days' => 30], [$server->id => ['library_ids' => null]], $this->admin, template: true);

        [, $reader] = ApiKey::issue('Reader', false, $this->admin);
        $this->postJson('/api/v1/invitations', ['template_id' => $template->id], ['Authorization' => 'Bearer '.$reader])->assertForbidden();

        [, $writer] = ApiKey::issue('Writer', true, $this->admin);
        $this->postJson('/api/v1/invitations', ['template_id' => 999], ['Authorization' => 'Bearer '.$writer])->assertUnprocessable();
        $link = $this->postJson('/api/v1/invitations', ['template_id' => $template->id, 'max_uses' => 2], ['Authorization' => 'Bearer '.$writer])
            ->assertCreated()->json('link');

        $made = Invitation::where('is_template', false)->sole();
        $this->assertSame([2, 30, [$server->id]], [$made->max_uses, $made->access_days, $made->mediaServers->pluck('id')->all()]);
        $this->assertStringNotContainsString(basename((string) $link), json_encode($made->getAttributes()));
        $this->getJson('/api/v1/invitations', ['Authorization' => 'Bearer '.$reader])->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_admin_makes_a_key_and_sees_it_once(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(ManageApiKeys::class)->callAction('create', ['name' => 'Home Assistant'])->assertHasNoActionErrors();

        $key = ApiKey::sole();
        $token = session('clubarr.api_key');
        $this->assertIsString($token);
        $this->assertSame(hash('sha256', $token), $key->token_hash);
        $this->assertFalse($key->can_invite);
    }
}
