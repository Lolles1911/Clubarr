<?php

namespace App\Services;

use App\Jobs\SendAdminAlert;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Messages to the admins' Discord channel (a webhook), for the events they
 * turned on under Settings → Notifications. Fed from the audit log, so every
 * event that is logged can become an alert in one place. Sent on the queue:
 * a slow Discord never holds up the member.
 */
class AdminAlerts
{
    /** Event => the audit actions that make it. */
    public const EVENTS = [
        'member_joined' => ['invitation.redeemed'],
        'server_down' => ['server.down', 'server.up'],
        'waitlist_joined' => ['waitlist.joined'],
        'stream_limit' => ['stream.limit'],
        'new_version' => ['clubarr.new_version'],
    ];

    /** Only Discord's own webhook addresses are accepted (nothing else is ever called). */
    public const WEBHOOK_PATTERN = '~^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d{5,30}/[\w-]{20,200}$~';

    /** @return array<string, string> event => label, for the settings */
    public static function labels(): array
    {
        return [
            'member_joined' => __('New member (an invitation is used)'),
            'server_down' => __('A media server stops answering, and when it is back'),
            'waitlist_joined' => __('Someone joins the waiting list'),
            'stream_limit' => __('A stream is stopped for being over the limit'),
            'new_version' => __('A new Clubarr version is out'),
        ];
    }

    public static function webhook(): ?string
    {
        $url = (string) app(SiteSettings::class)->get('alerts_webhook_url');

        return preg_match(self::WEBHOOK_PATTERN, $url) ? $url : null;
    }

    public static function enabled(string $event): bool
    {
        $on = app(SiteSettings::class)->get('alerts_events');
        $on = $on === null ? array_keys(self::EVENTS) : array_filter(explode(',', $on));

        return self::webhook() !== null && in_array($event, $on, true);
    }

    /** Called for every audit log line; most are not alerts. */
    public static function fromAudit(string $action, ?Model $subject, array $meta, ?int $actorId): void
    {
        $event = collect(self::EVENTS)->search(fn (array $actions) => in_array($action, $actions, true));
        if ($event === false || ! self::enabled($event)) {
            return;
        }
        $locale = (string) app(SiteSettings::class)->get('locale');
        $text = match ($action) {
            'invitation.redeemed' => __('New member: :name (:servers)', ['name' => User::find($actorId)->name ?? '?', 'servers' => implode(', ', (array) ($meta['servers'] ?? []))], $locale),
            'server.down' => __(':server does not answer: :error', ['server' => $subject?->getAttribute('name'), 'error' => (string) ($meta['error'] ?? '')], $locale),
            'server.up' => __(':server answers again.', ['server' => $subject?->getAttribute('name')], $locale),
            'waitlist.joined' => __('On the waiting list: :name', ['name' => $subject instanceof WaitlistEntry ? ($subject->name ?: $subject->email) : '?'], $locale),
            'stream.limit' => __(':name was over :limit streams; Clubarr stopped ":title".', ['name' => $subject?->getAttribute('name'), 'limit' => $meta['limit'] ?? '?', 'title' => (string) ($meta['title'] ?? '')], $locale),
            'clubarr.new_version' => __('Clubarr :version is out (you have :current): :url', ['version' => $meta['version'] ?? '?', 'current' => config('clubarr.version'), 'url' => $meta['url'] ?? ''], $locale),
            default => null,
        };
        if ($text !== null) {
            SendAdminAlert::dispatch(app(SiteSettings::class)->get('site_name').': '.$text);
        }
    }
}
