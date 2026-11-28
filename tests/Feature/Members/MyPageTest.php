<?php

namespace Tests\Feature\Members;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Filament\Pages\Auth\Profile;
use App\Models\MediaServer;
use App\Models\MemberGroup;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** My page: account, numbers and settings in one place, reachable from the menu. */
class MyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_page_is_in_the_menu_and_has_the_account_numbers_and_settings(): void
    {
        Queue::fake();
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        app(SiteSettings::class)->set(['mail_enabled' => '1']);
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $anna->forceFill(['access_expires_at' => now()->addMonth()])->save();
        $anna->groups()->attach(MemberGroup::create(['name' => 'Family', 'color' => 'gray'])->id);
        ServerAccount::create(['user_id' => $anna->id, 'media_server_id' => $server->id, 'remote_id' => 'a1', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
        $this->actingAs($anna);

        $this->get('/')->assertOk()->assertSee('My page');
        $this->get('/profile')->assertOk()->assertSee(['Account', 'My numbers', 'Settings', 'Where you are signed in']);
        $this->get('/profile?tab=numbers')->assertOk()->assertSee(['Your access', 'Family', $anna->access_expires_at->translatedFormat('j F Y')]);

        Filament::setCurrentPanel('app');
        Livewire::test(Profile::class)->callAction('digest');
        $this->assertTrue($anna->fresh()->digest);
    }
}
