<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerType;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\App\Pages\MyAccess;
use App\Models\Announcement;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\MemberMessage;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['admin_2fa_required' => '0', 'mail_enabled' => '1']);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
    }

    private function member(string $name, ?MediaServer $server, bool $disabled = false): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
        if ($disabled) {
            $user->forceFill(['disabled_at' => now()])->save();
        }
        if ($server) {
            ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $server->id, 'remote_id' => 'r'.$user->id, 'remote_username' => $name]);
        }

        return $user;
    }

    public function test_a_message_to_one_servers_members_is_mailed_and_shown_as_a_banner_to_them_only(): void
    {
        Notification::fake();
        $home = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $other = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Other', 'url' => 'http://jf2.local', 'api_key' => 'k']);
        $anna = $this->member('Anna', $home);
        $bo = $this->member('Bo', $other);
        $paused = $this->member('Paused', $home, disabled: true);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'Down tonight', 'body' => "From 22 to 23.\n\n<b>Sorry</b>", 'media_server_id' => $home->id,
                'by_mail' => true, 'as_banner' => true, 'banner_until' => now()->addDay()])
            ->call('create')->assertHasNoFormErrors();

        Notification::assertSentTo($anna, MemberMessage::class);
        Notification::assertNotSentTo([$bo, $paused, $this->admin], MemberMessage::class);
        $this->assertSame(1, Announcement::sole()->mailed);

        Filament::setCurrentPanel('app');
        $this->actingAs($anna);
        Livewire::test(MyAccess::class)->assertSee('Down tonight')->assertSee('&lt;b&gt;Sorry&lt;/b&gt;', false)->assertDontSee('<b>Sorry</b>', false);
        $this->actingAs($bo);
        Livewire::test(MyAccess::class)->assertDontSee('Down tonight');

        $this->travel(2)->days();
        $this->actingAs($anna);
        Livewire::test(MyAccess::class)->assertDontSee('Down tonight');
    }

    public function test_mail_is_off_when_mail_is_not_set_up_whatever_the_browser_sends(): void
    {
        Notification::fake();
        app(SiteSettings::class)->set(['mail_enabled' => '0']);
        $this->member('Anna', null);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'Hi', 'body' => 'Hello', 'as_banner' => true])
            ->set('data.by_mail', true)
            ->call('create')->assertHasNoFormErrors();

        $this->assertFalse(Announcement::sole()->by_mail);
        Notification::assertNothingSent();
    }
}
