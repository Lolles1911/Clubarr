<?php

namespace App\Services;

use App\Integrations\Discord;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Making and sending invitations, in one place for the admin form,
 * templates, "many at once" and members inviting friends. A code is
 * returned once and never stored (only its hash); when the link is mailed
 * and reminders are on, it is kept encrypted until the reminder is sent or
 * the invitation is used.
 */
class InvitationMaker
{
    public const SETTINGS = ['label', 'expires_at', 'max_uses', 'access_days', 'max_streams', 'request_limit', 'greeting', 'seerr_access', 'member_group_id'];

    public static function link(string $code): string
    {
        return route('filament.app.join', ['code' => $code]);
    }

    /**
     * @param  array<string, mixed>  $settings  see SETTINGS
     * @param  array<int, array{library_ids: ?string}>  $pivot  server ID => libraries
     * @return array{0: Invitation, 1: string} the invitation and its code
     */
    public function create(array $settings, array $pivot, ?User $by, bool $byMember = false, bool $template = false): array
    {
        return DB::transaction(function () use ($settings, $pivot, $by, $byMember, $template) {
            $invitation = new Invitation(collect($settings)->only(self::SETTINGS)->all());
            $invitation->created_by = $by?->id;
            $invitation->by_member = $byMember;
            $invitation->is_template = $template;
            $code = $invitation->assignCode();
            $invitation->save();
            $invitation->mediaServers()->sync($pivot);
            Audit::log($template ? 'invitation.template_saved' : 'invitation.created', $invitation, [
                'label' => $invitation->label, 'servers' => array_keys($pivot), 'by_member' => $byMember ?: null,
            ], $by?->id);

            return [$invitation, $code];
        });
    }

    /**
     * A template's (or any invitation's) set-up, to make a new one from.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{library_ids: ?string}>}
     */
    public function setupOf(Invitation $source): array
    {
        $settings = $source->only(['max_uses', 'access_days', 'max_streams', 'request_limit', 'greeting', 'seerr_access', 'member_group_id']);
        $pivot = [];
        foreach ($source->mediaServers as $server) {
            $ids = $source->libraryIdsFor($server);
            $pivot[$server->id] = ['library_ids' => $ids === null ? null : json_encode($ids)];
        }

        return [$settings, $pivot];
    }

    /**
     * Sends the link by mail and/or Discord. Returns what happened, for the admin.
     *
     * @return list<array{ok: bool, text: string}>
     */
    public function send(Invitation $invitation, string $code, ?string $email, ?string $discordId, ?User $from = null): array
    {
        $done = [];
        $link = self::link($code);
        if (filled($email)) {
            if (app(SiteSettings::class)->get('mail_enabled') !== '1') {
                $done[] = ['ok' => false, 'text' => __('The link was not mailed: set up mail under Settings first.')];
            } else {
                try {
                    app(SiteMail::class)->apply();
                    Notification::route('mail', (string) $email)->notify(new InvitationLink($link, $invitation, $from));
                    $invitation->forceFill([
                        'sent_to' => mb_substr((string) $email, 0, 190), 'sent_at' => now(),
                        'reminder_link' => self::reminderDays() > 0 ? $link : null,
                    ])->save();
                    Audit::log('invitation.mailed', $invitation, ['to' => $email], $from?->id);
                    $done[] = ['ok' => true, 'text' => __('The link was mailed to :email.', ['email' => $email])];
                } catch (Throwable $e) {
                    report($e);
                    $done[] = ['ok' => false, 'text' => __('The link could not be mailed.')];
                }
            }
        }
        if (filled($discordId)) {
            $discord = Discord::fromSettings();
            if ($discord === null || ! preg_match('/^\d{15,22}$/', (string) $discordId)) {
                $done[] = ['ok' => false, 'text' => $discord === null ? __('Set up Discord under Settings first.') : __('That is not a Discord user ID (only digits).')];
            } else {
                try {
                    $discord->sendDirectMessage((string) $discordId, self::discordText($invitation, $link));
                    Audit::log('invitation.discord_sent', $invitation, ['discord_id' => $discordId], $from?->id);
                    $done[] = ['ok' => true, 'text' => __('Sent as a Discord message.')];
                } catch (Throwable) {
                    $done[] = ['ok' => false, 'text' => __('Discord did not deliver it. The person must share a Discord server with your bot and allow direct messages.')];
                }
            }
        }

        return $done;
    }

    public static function reminderDays(): int
    {
        return max(0, (int) app(SiteSettings::class)->get('invite_reminder_days'));
    }

    private static function discordText(Invitation $invitation, string $link): string
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $text = __('You are invited to :site!', ['site' => $site]);
        if (filled($invitation->greeting)) {
            $text .= "\n\n> ".str_replace("\n", "\n> ", trim((string) $invitation->greeting));
        }

        return $text."\n\n".__('Open the link to make your account and start watching:')."\n".$link;
    }
}
