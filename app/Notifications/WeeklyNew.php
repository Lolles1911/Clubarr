<?php

namespace App\Notifications;

use App\Filament\App\Pages\MyAccess;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The weekly "new on the server" mail, for members who turned it on. */
class WeeklyNew extends Notification
{
    /** @param list<array{title: string, year: int|null, server: string}> $items */
    public function __construct(private array $items) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $mail = (new MailMessage)->subject(__('New on :site this week', ['site' => $site]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('This is new since last week:'));
        foreach ($this->items as $item) {
            $mail->line('• '.$item['title'].($item['year'] ? ' ('.$item['year'].')' : ''));
        }

        return $mail->action(__('Go to my page'), MyAccess::getUrl(panel: 'app'))
            ->line(__('You get this because you turned it on. Turn it off on your page.'));
    }
}
