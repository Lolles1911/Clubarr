<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\App\Pages\Join;
use App\Filament\App\Pages\MyAccess;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Users that already exist on a server: linked by the member (with its password), by an admin, or on an invitation. */
class LinkExistingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
    }

    private function fakeLogin(): void
    {
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/Users/AuthenticateByName')) {
                return match ($r['Username'].'|'.$r['Pw']) {
                    'anna|right-pass' => Http::response(['User' => ['Id' => 'j9', 'Name' => 'anna', 'Policy' => ['IsAdministrator' => false]], 'AccessToken' => 'tok']),
                    'boss|right-pass' => Http::response(['User' => ['Id' => 'j1', 'Name' => 'boss', 'Policy' => ['IsAdministrator' => true]], 'AccessToken' => 'tok']),
                    default => Http::response(null, 401),
                };
            }
            if (str_ends_with($r->url(), '/Users/j9')) {
                return Http::response(['Id' => 'j9', 'Policy' => ['IsAdministrator' => false]]);
            }

            return Http::response(null, 204);
        });
    }

    public function test_a_member_links_their_own_user_with_its_password_never_an_admin(): void
    {
        $this->fakeLogin();
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        Filament::setCurrentPanel('app');
        $this->actingAs($anna);

        Livewire::test(MyAccess::class)->callAction('linkExisting', ['server' => $this->server->id, 'username' => 'anna', 'password' => 'wrong']);
        Livewire::test(MyAccess::class)->callAction('linkExisting', ['server' => $this->server->id, 'username' => 'boss', 'password' => 'right-pass']);
        $this->assertSame(0, ServerAccount::count());

        Livewire::test(MyAccess::class)->callAction('linkExisting', ['server' => $this->server->id, 'username' => 'anna', 'password' => 'right-pass']);
        $this->assertSame(['j9', 'anna', AccountStatus::Active], [ServerAccount::sole()->remote_id, ServerAccount::sole()->remote_username, ServerAccount::sole()->status]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Sessions/Logout'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/AuthenticateByName') && str_contains((string) json_encode($r->headers()), 'Token="k"'));

        // Taken now: nobody else can link it.
        $bo = User::create(['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'Member-pass-1']);
        $this->actingAs($bo);
        Livewire::test(MyAccess::class)->callAction('linkExisting', ['server' => $this->server->id, 'username' => 'anna', 'password' => 'right-pass']);
        $this->assertSame(1, ServerAccount::count());
    }

    public function test_an_admin_links_a_server_user_to_a_member(): void
    {
        Http::fake(['jf.local/Users' => Http::response([['Id' => 'j5', 'Name' => 'grandma', 'Policy' => []], ['Id' => 'j1', 'Name' => 'boss', 'Policy' => ['IsAdministrator' => true]]])]);
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('linkServerUser')->table($anna), ['server' => $this->server->id, 'remote' => 'j5'])->assertHasNoActionErrors();
        $this->assertSame(['j5', 'grandma'], [ServerAccount::sole()->remote_id, ServerAccount::sole()->remote_username]);
    }

    public function test_an_invitation_can_take_a_user_they_already_have_and_never_deletes_it(): void
    {
        $this->fakeLogin();
        $invitation = new Invitation(['max_uses' => 1]);
        $code = $invitation->assignCode();
        $invitation->save();
        $invitation->mediaServers()->attach($this->server->id);
        Filament::setCurrentPanel('app');

        Livewire::test(Join::class, ['code' => $code])
            ->fillForm(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Anna-pass-12345', 'password_confirmation' => 'Anna-pass-12345',
                'existing' => true, 'existing_username' => 'anna', 'existing_password' => 'right-pass'])
            ->call('join')->assertHasNoFormErrors();

        $account = ServerAccount::sole();
        $this->assertSame(['j9', $invitation->id], [$account->remote_id, $account->invitation_id]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/Users/New'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }
}
