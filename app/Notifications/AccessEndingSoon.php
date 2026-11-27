<?php

namespace App\Notifications;

use App\Filament\App\Pages\MyAccess;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessEndingSoon extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your access to :site ends soon', ['site' => app(SiteSettings::class)->get('site_name')]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('Your access ends on :date. Want to keep watching? Ask the person who invited you.', ['date' => $notifiable->access_expires_at?->translatedFormat(__('j F Y'))]))
            ->action(__('Go to my page'), MyAccess::getUrl(panel: 'app'));
    }
}
