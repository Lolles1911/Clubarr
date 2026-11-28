<?php

namespace App\Notifications;

use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the one who made an invitation (an admin or a member): it was used. */
class InvitationUsed extends Notification
{
    public function __construct(private string $who, private ?string $label) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');

        return (new MailMessage)
            ->subject(__(':name joined :site', ['name' => $this->who, 'site' => $site]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(filled($this->label)
                ? __(':name has used your invitation (":label") and is now a member.', ['name' => $this->who, 'label' => $this->label])
                : __(':name has used your invitation and is now a member.', ['name' => $this->who]));
    }
}
