<?php

namespace App\Notifications;

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
        return (new MailMessage)
            ->subject(__('Your access to :site has ended', ['site' => app(SiteSettings::class)->get('site_name')]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('Your access has ended and your users on the servers are paused. If you get a new invitation, open it while signed in and you are back.'));
    }
}
