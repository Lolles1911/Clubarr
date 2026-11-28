<?php

namespace App\Notifications;

use App\Services\SiteSettings;
use App\Services\Waitlist;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistInvitation extends Notification
{
    public function __construct(private string $name, private string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');

        return (new MailMessage)
            ->subject(__('A seat is free on :site', ['site' => $site]))
            ->greeting(__('Hi :name,', ['name' => $this->name]))
            ->line(__('You were on the waiting list, and now there is room. The invitation is yours for :hours hours; after that it goes to the next in line.', ['hours' => Waitlist::HOURS]))
            ->action(__('Join :site', ['site' => $site]), $this->link);
    }
}
