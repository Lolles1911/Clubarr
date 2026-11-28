<?php

namespace App\Notifications;

use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** "Your account was signed in to from a new device." */
class NewSignIn extends Notification
{
    public function __construct(private string $device, private string $ip, private Carbon $at) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(SiteSettings::class)->get('site_name');

        return (new MailMessage)
            ->subject(__('New sign-in to your :site account', ['site' => $site]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('Your account was signed in to from a device we have not seen before:'))
            ->line($this->device.' · '.$this->at->translatedFormat(__('j F Y H:i')).' · IP '.$this->ip)
            ->line(__('Was it you? Then all is well. If not, change your password now and sign out everywhere else on your profile page.'))
            ->action(__('Go to my profile'), Filament::getPanel('app')->getProfileUrl() ?? url('/'));
    }
}
