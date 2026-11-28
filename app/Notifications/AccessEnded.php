<?php

namespace App\Notifications;

use App\Services\MailTexts;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessEnded extends Notification
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

        return MailTexts::apply($mail, 'ended', ['name' => (string) $notifiable->name, 'site' => $site],
            __('Your access to :site has ended', ['site' => $site]),
            [__('Your access has ended and your users on the servers are paused. If you get a new invitation, open it while signed in and you are back.')]);
    }
}
