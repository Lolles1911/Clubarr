<?php

namespace Tests\Feature\Flows;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Admin\Resources\MediaServers\Pages\ListMediaServers;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_importing_a_server_user_works_with_a_cache_that_serializes(): void
    {
        // The array store used in tests keeps objects as they are; a real
        // store (file, database) serializes, and Laravel won't bring objects
        // back. This is the store the container uses.
        config(['cache.default' => 'database']);
        Notification::fake();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        Http::fake(['jf.local/Users' => Http::response([
            ['Id' => 'admin0', 'Name' => 'owner', 'Policy' => ['IsAdministrator' => true]],
            ['Id' => 'old1', 'Name' => 'grandma', 'Policy' => []],
        ])]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        // Twice: the second time the list comes from the cache. The first,
        // a server administrator, is refused.
        Livewire::test(ListMediaServers::class)
            ->callAction(TestAction::make('import')->table($server), ['remote' => 'admin0', 'name' => 'X', 'email' => 'x@example.com']);
        $this->assertSame(0, ServerAccount::count());
        Livewire::test(ListMediaServers::class)
            ->callAction(TestAction::make('import')->table($server), ['remote' => 'old1', 'name' => 'Grandma', 'email' => 'grandma@example.com'])
            ->assertHasNoFormErrors();

        $account = ServerAccount::sole();
        $this->assertSame(['old1', 'grandma', AccountStatus::Active], [$account->remote_id, $account->remote_username, $account->status]);
        $this->assertSame('grandma@example.com', $account->user->email);
        $this->assertFalse($account->user->is_admin);
    }

    public function test_an_email_that_exists_is_refused(): void
    {
        config(['cache.default' => 'database']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        Http::fake(['jf.local/Users' => Http::response([['Id' => 'old1', 'Name' => 'grandma', 'Policy' => []]])]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(ListMediaServers::class)
            ->callAction(TestAction::make('import')->table($server), ['remote' => 'old1', 'name' => 'X', 'email' => 'admin@example.com'])
            ->assertHasFormErrors(['email']);

        $this->assertSame(0, ServerAccount::count());
    }
}
