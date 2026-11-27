<?php

namespace Tests\Feature\Flows;

use App\Filament\App\Pages\Setup;
use App\Models\User;
use App\Services\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clubarr.setup_token_path' => storage_path('framework/testing/setup-token-'.uniqid())]);
    }

    public function test_everything_leads_to_setup_until_an_admin_exists(): void
    {
        $this->get('/')->assertRedirect('/setup');
        $this->get('/admin')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy');
    }

    public function test_the_setup_token_is_required_and_setup_disappears_after(): void
    {
        $form = ['token' => 'wrong', 'site_name' => 'Club', 'name' => 'Admin', 'email' => 'a@example.com', 'password' => 'Admin-pass-1234', 'password_confirmation' => 'Admin-pass-1234'];
        Livewire::test(Setup::class)->fillForm($form)->call('finish')->assertHasFormErrors(['token']);
        $this->assertSame(0, User::count());

        $form['token'] = app(SetupState::class)->token();
        Livewire::test(Setup::class)->fillForm($form)->call('finish')->assertHasNoFormErrors()->assertRedirect('/admin');

        $admin = User::sole();
        $this->assertTrue($admin->is_admin);
        $this->assertFalse(File::exists(config('clubarr.setup_token_path')));
        app(SetupState::class)->forget();
        $this->get('/setup')->assertNotFound();
    }
}
