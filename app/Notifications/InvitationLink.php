<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\Branding;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation mail: the site's logo and colour, who invites, the
 * greeting, the servers and a big button. Everything typed by people is
 * escaped (Blade {{ }}), never sent as HTML.
 */
class InvitationLink extends Notification
{
    public function __construct(private string $link, private ?Invitation $invitation = null, private ?User $from = null, private bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $color = (string) app(SiteSettings::class)->get('brand_color');
        $data = [
            'site' => $site,
            'link' => $this->link,
            'logo' => Branding::logoUrl() !== null ? url(Branding::logoUrl()) : null,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#7c3aed',
            'from' => $this->from?->is_admin === false ? $this->from->name : null,
            'greeting' => $this->invitation?->greeting,
            'servers' => $this->invitation?->mediaServers->filter(fn (MediaServer $s) => $s->is_enabled)->map(fn (MediaServer $s) => $s->name.' ('.$s->type->label().')')->values()->all() ?? [],
            'days' => $this->invitation?->access_days,
            'expires' => $this->invitation?->expires_at?->translatedFormat(__('j F Y')),
            'reminder' => $this->reminder,
        ];
        $subject = $this->reminder
            ? __('Reminder: your invitation to :site', ['site' => $site])
            : ($data['from'] ? __(':name invites you to :site', ['name' => $data['from'], 'site' => $site]) : __('You are invited to :site', ['site' => $site]));

        return (new MailMessage)->subject($subject)->view(['html' => 'mail.invitation', 'text' => 'mail.invitation-text'], $data);
    }
}
