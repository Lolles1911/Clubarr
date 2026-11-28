<?php

namespace App\Notifications;

use App\Filament\App\Pages\MyAccess;
use App\Services\MailTexts;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Welcome extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $mail = (new MailMessage)->greeting(__('Hi :name,', ['name' => $notifiable->name]));

        return MailTexts::apply($mail, 'welcome', ['name' => (string) $notifiable->name, 'site' => $site],
            __('Welcome to :site', ['site' => $site]),
            [__('Your account is ready. On your page you see your servers, how to open them, and your user name.')])
            ->action(__('Go to my page'), MyAccess::getUrl(panel: 'app'));
    }
}
