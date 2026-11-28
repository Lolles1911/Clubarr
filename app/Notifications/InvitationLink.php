<?php

namespace App\Notifications;

use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An invitation link sent by an admin, with an optional greeting. */
class InvitationLink extends Notification
{
    public function __construct(private string $link, private ?string $greeting = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $mail = (new MailMessage)->subject(__('You are invited to :site', ['site' => $site]))->greeting(__('Hi,'));
        if (filled($this->greeting)) {
            $mail->line(mb_substr(trim((string) $this->greeting), 0, 1000));
        }

        return $mail->line(__('Open the link to make your account and start watching. It is personal; don\'t share it.'))
            ->action(__('Join :site', ['site' => $site]), $this->link);
    }
}
