<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\App\Pages\GetStarted;
use App\Filament\App\Pages\Join;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class JoinTest extends TestCase
{
    use RefreshDatabase;

    private MediaServer $jellyfin;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
        $this->jellyfin = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
    }

    private function fakeJellyfin(): void
    {
        Http::fake([
            'jf.local/Users/New' => Http::response(['Id' => 'new1', 'Name' => 'anna']),
            'jf.local/Users/new1' => Http::response(['Id' => 'new1', 'Policy' => []]),
            'jf.local/Users/new1/Policy' => Http::response(null, 204),
        ]);
    }

    private function invitation(array $attributes = []): array
    {
        $invitation = new Invitation($attributes + ['max_uses' => 1, 'access_days' => 30]);
        $code = $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->jellyfin->id, ['library_ids' => json_encode(['lib1'])]);

        return [$invitation, $code];
    }

    private function join(string $code, array $form = [])
    {
        return Livewire::test(Join::class, ['code' => $code])->fillForm($form + [
            'name' => 'Anna', 'email' => 'anna@example.com', 'username' => 'anna', 'password' => 'Anna-pass-123', 'password_confirmation' => 'Anna-pass-123',
        ])->call('join');
    }

    public function test_redeeming_makes_the_account_and_the_server_user(): void
    {
        $this->fakeJellyfin();
        [$invitation, $code] = $this->invitation();

        $this->join($code)->assertHasNoFormErrors()->assertRedirect(GetStarted::getUrl(panel: 'app'));

        $user = User::where('email', 'anna@example.com')->sole();
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->access_expires_at->between(now()->addDays(29), now()->addDays(31)));
        $account = ServerAccount::sole();
        $this->assertSame(['new1', 'anna', AccountStatus::Active, ['lib1']], [$account->remote_id, $account->remote_username, $account->status, $account->library_ids]);
        $this->assertSame(1, $invitation->fresh()->uses);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Policy') && $r['EnabledFolders'] === ['lib1']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_invitations_limits_go_to_the_member_and_the_more_generous_one_stays(): void
    {
        $this->fakeJellyfin();
        [$invitation, $code] = $this->invitation(['max_streams' => 2, 'request_limit' => 5, 'max_uses' => 5]);
        $this->join($code);
        $user = User::where('email', 'anna@example.com')->sole();
        $this->assertSame([2, 5], [$user->max_streams, $user->request_limit]);

        // A second invitation with lower limits does not lower them; "none" lifts them.
        $second = new Invitation(['max_uses' => 1, 'max_streams' => 1, 'request_limit' => null]);
        $secondCode = $second->assignCode();
        $second->save();
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Second', 'url' => 'http://jf2.local', 'api_key' => 'k']);
        $second->mediaServers()->attach($other->id);
        Http::fake(['jf2.local/Users/New' => Http::response(['Id' => 'n2']), 'jf2.local/Users/n2' => Http::response(['Id' => 'n2', 'Policy' => []]), 'jf2.local/Users/n2/Policy' => Http::response(null, 204)]);
        $this->actingAs($user);
        Livewire::test(Join::class, ['code' => $secondCode])->fillForm(['username' => 'anna', 'password' => 'Anna-pass-123', 'password_confirmation' => 'Anna-pass-123'])->call('join');

        $this->assertSame([2, null], [$user->fresh()->max_streams, $user->fresh()->request_limit]);
    }

    public function test_a_used_up_invitation_cannot_be_used_again(): void
    {
        $this->fakeJellyfin();
        [, $code] = $this->invitation();
        $this->join($code);
        auth()->logout();

        $this->join($code, ['email' => 'other@example.com', 'username' => 'other']);
        $this->assertSame(1, User::members()->count());
        $this->assertSame(1, ServerAccount::count());
    }

    public function test_wrong_withdrawn_and_expired_codes_are_refused(): void
    {
        [, $withdrawn] = $this->invitation(['label' => 'w']);
        Invitation::where('label', 'w')->update(['revoked_at' => now()]);
        [, $expired] = $this->invitation(['label' => 'e', 'expires_at' => now()->subMinute()]);

        foreach (['WRONG-CODE-12345', $withdrawn, $expired] as $code) {
            $component = Livewire::test(Join::class, ['code' => $code]);
            $this->assertNull($component->get('invitationId'));
        }
    }

    public function test_the_invitation_id_cannot_be_changed_from_the_browser(): void
    {
        [, $code] = $this->invitation();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Join::class, ['code' => $code])->set('invitationId', 999);
    }

    public function test_a_failing_server_gives_the_use_back(): void
    {
        Http::fake(['jf.local/*' => Http::response('down', 500)]);
        [$invitation, $code] = $this->invitation();

        $this->join($code)->assertNotified();

        $this->assertSame(0, $invitation->fresh()->uses);
        $this->assertSame(0, User::members()->count());
    }

    public function test_a_taken_user_name_is_a_form_error(): void
    {
        Http::fake(['jf.local/Users/New' => Http::response('User with name anna already exists', 400)]);
        [$invitation, $code] = $this->invitation();

        $this->join($code)->assertHasErrors(['data.username']);
        $this->assertSame(0, $invitation->fresh()->uses);
    }

    public function test_an_existing_email_must_sign_in_first(): void
    {
        $this->fakeJellyfin();
        [, $code] = $this->invitation();
        $this->join($code, ['email' => 'admin@example.com'])->assertHasFormErrors(['email']);
    }
}
