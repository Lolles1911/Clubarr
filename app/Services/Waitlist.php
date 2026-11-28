<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The waiting list: when a server with a member limit has a free seat, the
 * first in line gets an invitation by mail (a copy of the template invitation
 * chosen under Settings, for one person, valid 48 hours). Unused, it goes to
 * the next. A waiting-list invitation keeps its seat while it is valid.
 */
class Waitlist
{
    public const HOURS = 48;

    public static function template(): ?Invitation
    {
        $id = (int) app(SiteSettings::class)->get('waitlist_template_id');

        return $id > 0 ? Invitation::with('mediaServers')->find($id) : null;
    }

    public static function enabled(): bool
    {
        return self::template() !== null;
    }

    /** Seats held by waiting-list invitations that are still valid. */
    public static function held(MediaServer $server): int
    {
        return Invitation::where('from_waitlist', true)->whereNull('revoked_at')->where('uses', 0)->where('expires_at', '>', now())
            ->whereHas('mediaServers', fn ($q) => $q->whereKey($server->id))->count();
    }

    /** Full for a normal invitation: members plus held seats at the limit. */
    public static function isFull(MediaServer $server): bool
    {
        return $server->capacity !== null && $server->seatsTaken() + self::held($server) >= $server->capacity;
    }

    public function join(string $name, string $email): WaitlistEntry
    {
        $entry = WaitlistEntry::firstOrNew(['email' => mb_strtolower(trim($email))]);
        if (! $entry->exists) {
            $entry->name = trim($name);
            $entry->save();
            Audit::log('waitlist.joined', $entry, ['email' => $entry->email], null);
        }

        return $entry;
    }

    /** Runs every 10 minutes: closes used or expired invitations, then invites while there are seats. Returns how many were invited. */
    public function tick(): int
    {
        foreach (WaitlistEntry::with('invitation')->where('status', 'invited')->get() as $entry) {
            if ($entry->invitation === null) {
                $entry->forceFill(['status' => 'expired'])->save();
            } elseif ($entry->invitation->uses > 0) {
                $entry->forceFill(['status' => 'joined'])->save();
            } elseif ($entry->invitation->expires_at?->isPast()) {
                $entry->forceFill(['status' => 'expired'])->save();
                Audit::log('waitlist.expired', $entry, ['email' => $entry->email]);
            }
        }
        $template = self::template();
        if ($template === null || $template->mediaServers->isEmpty()) {
            return 0;
        }
        $invited = 0;
        while ($template->mediaServers->every(fn (MediaServer $s) => ! self::isFull($s))) {
            $entry = WaitlistEntry::where('status', 'waiting')->orderBy('id')->first();
            if ($entry === null) {
                break;
            }
            try {
                $this->invite($entry, $template);
                $invited++;
            } catch (Throwable $e) {
                report($e);
                break;
            }
            if ($template->mediaServers->every(fn (MediaServer $s) => $s->capacity === null)) {
                break; // no limits at all: one per run
            }
        }

        return $invited;
    }

    /** Sends the entry an invitation copied from the template. */
    public function invite(WaitlistEntry $entry, Invitation $template): void
    {
        $code = DB::transaction(function () use ($entry, $template) {
            $invitation = new Invitation([
                'label' => __('Waiting list: :email', ['email' => $entry->email]),
                'expires_at' => now()->addHours(self::HOURS), 'max_uses' => 1,
                'access_days' => $template->access_days, 'max_streams' => $template->max_streams, 'request_limit' => $template->request_limit,
            ]);
            $invitation->from_waitlist = true;
            $code = $invitation->assignCode();
            $invitation->save();
            $invitation->mediaServers()->sync($template->mediaServers->mapWithKeys(fn (MediaServer $s) => [$s->id => ['library_ids' => ($ids = $template->libraryIdsFor($s)) === null ? null : json_encode($ids)]])->all());
            $entry->forceFill(['status' => 'invited', 'invitation_id' => $invitation->id, 'invited_at' => now()])->save();

            return $code;
        });
        Notification::route('mail', $entry->email)->notify(new WaitlistInvitation($entry->name, route('filament.app.join', ['code' => $code])));
        Audit::log('waitlist.invited', $entry, ['email' => $entry->email]);
    }
}
