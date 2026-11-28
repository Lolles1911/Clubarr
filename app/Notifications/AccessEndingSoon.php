<?php

namespace App\Notifications;

use App\Filament\App\Pages\MyAccess;
use App\Services\MailTexts;
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
        $site = (string) app(SiteSettings::class)->get('site_name');
        $date = (string) $notifiable->access_expires_at?->translatedFormat(__('j F Y'));
        $mail = (new MailMessage)->greeting(__('Hi :name,', ['name' => $notifiable->name]));

        return MailTexts::apply($mail, 'ending_soon', ['name' => (string) $notifiable->name, 'site' => $site, 'date' => $date],
            __('Your access to :site ends soon', ['site' => $site]),
            [__('Your access ends on :date. Want to keep watching? Ask the person who invited you.', ['date' => $date])])
            ->action(__('Go to my page'), MyAccess::getUrl(panel: 'app'));
    }
}
