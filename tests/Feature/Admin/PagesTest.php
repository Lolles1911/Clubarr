<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every admin and member page opens. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_opens(): void
    {
        app(SiteSettings::class)->set(['admin_2fa_required' => '0']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $admin->is_admin = true;
        $admin->save();
        $member = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        $this->actingAs($admin);

        foreach (['/admin', '/admin/settings', '/admin/integrations', '/admin/alerts', '/admin/mail-texts', '/admin/stats', '/admin/api-keys',
            '/admin/maintenances', '/admin/wizard', '/admin/wizard-bundles', '/admin/users', '/admin/users/'.$member->id, '/admin/member-groups', '/admin/invitations', '/admin/media-servers', '/admin/audit-logs'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->actingAs($member);
        foreach (['/', '/profile', '/my-stats', '/help'] as $path) {
            $this->followingRedirects()->get($path)->assertOk();
        }
        $this->assertContains($this->get('/admin/users')->status(), [302, 403]);
    }
}
