<?php

namespace App\Notifications;

use App\Filament\App\Pages\MyAccess;
use App\Models\Announcement;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message from the admins (Admin → Messages). The text is plain: shown as written, never as HTML. */
class MemberMessage extends Notification
{
    public function __construct(private Announcement $announcement) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(app(SiteSettings::class)->get('site_name').': '.$this->announcement->title)
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]));
        foreach (preg_split('/\R{2,}/', trim($this->announcement->body)) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail->action(__('Go to my page'), MyAccess::getUrl(panel: 'app'));
    }
}
