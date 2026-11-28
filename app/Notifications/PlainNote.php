<?php

namespace App\Notifications;

use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short plain-text mail to someone who is not a member (yet). */
class PlainNote extends Notification
{
    public function __construct(private string $subject, private string $name, private string $text) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(app(SiteSettings::class)->get('site_name').': '.$this->subject)
            ->greeting(__('Hi :name,', ['name' => $this->name]));
        foreach (preg_split('/\R{2,}/', trim($this->text)) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail;
    }
}
