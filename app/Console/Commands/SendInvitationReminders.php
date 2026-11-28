<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Notifications\InvitationLink;
use App\Services\Audit;
use App\Services\InvitationMaker;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Hourly: an invitation mailed to someone and still unused after the set
 * number of days gets one reminder with the same link. The link was kept
 * encrypted only for this; it is removed after the reminder, and from
 * invitations that can't be used any more.
 */
class SendInvitationReminders extends Command
{
    protected $signature = 'clubarr:invite-reminders';

    protected $description = 'Remind people of invitations they have not used';

    public function handle(): int
    {
        // Links of invitations that ended (used up, expired, withdrawn) are not kept.
        Invitation::whereNotNull('reminder_link')->whereNot(fn ($q) => Invitation::applyUsable($q))->update(['reminder_link' => null]);

        $days = InvitationMaker::reminderDays();
        if ($days === 0 || app(SiteSettings::class)->get('mail_enabled') !== '1') {
            return self::SUCCESS;
        }
        app(SiteMail::class)->apply();
        $sent = 0;
        $due = Invitation::applyUsable(Invitation::query())->where('uses', 0)->whereNull('reminded_at')
            ->whereNotNull('reminder_link')->whereNotNull('sent_to')->where('sent_at', '<=', now()->subDays($days))->with('mediaServers')->get();
        foreach ($due as $invitation) {
            try {
                Notification::route('mail', (string) $invitation->sent_to)->notify(new InvitationLink((string) $invitation->reminder_link, $invitation, $invitation->creator, reminder: true));
                Audit::log('invitation.reminded', $invitation, ['to' => $invitation->sent_to]);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
            $invitation->forceFill(['reminded_at' => now(), 'reminder_link' => null])->save();
        }
        $this->info('Reminded '.$sent.'.');

        return self::SUCCESS;
    }
}
