<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\MediaServers\Pages\ListMediaServers;
use App\Filament\App\Pages\ClaimAccount;
use App\Models\Claim;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        Notification::fake();
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        $this->server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        Http::fake(['jf.local/Users' => Http::response([
            ['Id' => 'admin0', 'Name' => 'owner', 'Policy' => ['IsAdministrator' => true]],
            ['Id' => 'u1', 'Name' => 'grandma', 'Policy' => []],
            ['Id' => 'u2', 'Name' => 'grandpa', 'Policy' => []],
        ])]);
    }

    /** Imports the users and returns their claim codes, read from the links shown to the admin. */
    private function import(array $ids): array
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('import')->table($this->server), ['remote' => $ids])->assertNotified();
        auth()->logout();

        // The codes are only in the notification; make fresh ones for the test.
        return Claim::orderBy('id')->get()->mapWithKeys(function (Claim $claim) {
            $code = $claim->assignCode();
            $claim->save();

            return [$claim->remote_username => $code];
        })->all();
    }

    public function test_many_users_at_once_each_get_a_personal_link(): void
    {
        $codes = $this->import(['u1', 'u2']);
        $this->assertSame(['grandma', 'grandpa'], array_keys($codes));
        $this->assertSame(0, ServerAccount::count());
        $this->assertTrue(Claim::first()->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_the_link_makes_the_account_and_links_the_server_user_once(): void
    {
        $code = $this->import(['u1'])['grandma'];

        Livewire::test(ClaimAccount::class, ['code' => $code])
            ->fillForm(['name' => 'Grandma', 'email' => 'grandma@example.com', 'password' => 'Grandma-pass-1', 'password_confirmation' => 'Grandma-pass-1'])
            ->call('claim')->assertHasNoFormErrors();

        $account = ServerAccount::sole();
        $this->assertSame(['u1', 'grandma', 'grandma@example.com'], [$account->remote_id, $account->remote_username, $account->user->email]);
        $this->assertNotNull(Claim::sole()->used_at);
        $this->assertAuthenticatedAs($account->user);

        auth()->logout();
        $this->assertNull(Livewire::test(ClaimAccount::class, ['code' => $code])->get('claimId'), 'used: no second time');
    }

    public function test_a_signed_in_member_adds_the_server_user_to_their_account(): void
    {
        $code = $this->import(['u1'])['grandma'];
        $member = User::create(['name' => 'G', 'email' => 'g@example.com', 'password' => 'G-pass-12345']);
        $this->actingAs($member);

        Livewire::test(ClaimAccount::class, ['code' => $code])->call('claim');

        $this->assertSame($member->id, ServerAccount::sole()->user_id);
        $this->assertSame(1, User::members()->count());
    }

    public function test_wrong_and_expired_links_do_nothing(): void
    {
        $code = $this->import(['u1'])['grandma'];
        Claim::query()->update(['expires_at' => now()->subMinute()]);
        foreach ([$code, 'WRONG-WRONG-WRONG-WRONG'] as $tried) {
            $this->assertNull(Livewire::test(ClaimAccount::class, ['code' => $tried])->get('claimId'));
        }
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ClaimAccount::class, ['code' => $tried])->set('claimId', Claim::sole()->id);
    }

    public function test_clean_up_deletes_only_chosen_unlinked_users_never_admins_or_members(): void
    {
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $member = User::create(['name' => 'Grandpa', 'email' => 'gp@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $member->id, 'media_server_id' => $this->server->id, 'remote_id' => 'u2', 'remote_username' => 'grandpa', 'status' => AccountStatus::Active]);
        $this->server->forceFill(['settings' => ['unlinked' => 1]])->save();
        Http::fake([
            'jf.local/Users/u1' => Http::response(['Id' => 'u1', 'Policy' => ['IsAdministrator' => false]]),
            'jf.local/Users/*' => Http::response(['Id' => 'x', 'Policy' => ['IsAdministrator' => true]]),
        ]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');

        // Members' and administrators' users are not even choices.
        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('cleanUp')->table($this->server), ['remote' => ['u2', 'admin0']])->assertHasActionErrors(['remote.0', 'remote.1']);
        Livewire::test(ListMediaServers::class)->callAction(TestAction::make('cleanUp')->table($this->server), ['remote' => ['u1']])->assertNotified();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/Users/u1'));
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && (str_ends_with($r->url(), '/Users/u2') || str_ends_with($r->url(), '/Users/admin0')));
        $this->assertNotNull(ServerAccount::where('remote_id', 'u2')->first());
    }
}
