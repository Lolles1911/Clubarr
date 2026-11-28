<?php

namespace App\Services;

use App\Models\ExtensionRequest;
use App\Models\ProblemReport;
use App\Models\User;
use App\Notifications\MemberNote;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * What members ask of the admins: more time, and "something doesn't work".
 * Each is logged (and can be a Discord alert); answers go to the member by
 * mail when mail is set up.
 */
class MemberRequests
{
    /** Days before the end when a member may ask for more time. */
    public const ASK_DAYS_BEFORE = 14;

    public static function canAskForTime(User $user): bool
    {
        return ! $user->is_admin && $user->access_expires_at !== null
            && $user->access_expires_at->isBefore(now()->addDays(self::ASK_DAYS_BEFORE))
            && ! ExtensionRequest::where('user_id', $user->id)->where('status', 'pending')->exists();
    }

    public function askForTime(User $user, ?string $note): ExtensionRequest
    {
        if (! self::canAskForTime($user)) {
            throw new RuntimeException(__('You can ask for more time in the last :days days of your access, once at a time.', ['days' => self::ASK_DAYS_BEFORE]));
        }
        $request = new ExtensionRequest(['note' => filled($note) ? mb_substr(trim((string) $note), 0, 1000) : null]);
        $request->user_id = $user->id;
        $request->save();
        Audit::log('extension.requested', $user, ['request' => $request->id], $user->id);

        return $request;
    }

    /** Approves: the access lasts :days more (from today, or from its end if later), and paused users come back. */
    public function approve(ExtensionRequest $request, int $days): void
    {
        $user = $request->user;
        $from = $user->access_expires_at !== null && $user->access_expires_at->isFuture() ? $user->access_expires_at : now();
        $user->forceFill(['access_expires_at' => $from->copy()->addDays($days), 'expiry_warned_at' => null])->save();
        $request->forceFill(['status' => 'approved', 'days' => $days, 'decided_by' => Auth::id(), 'decided_at' => now()])->save();
        Audit::log('extension.approved', $user, ['days' => $days]);
        if ($user->disabled_at === null) {
            app(AccessManager::class)->enableAll($user);
        }
        self::tell($user, __('Your access is extended'), __('Good news: your access now lasts until :date.', ['date' => $user->access_expires_at->translatedFormat(__('j F Y'))]));
    }

    public function decline(ExtensionRequest $request, ?string $reason): void
    {
        $request->forceFill(['status' => 'declined', 'decided_by' => Auth::id(), 'decided_at' => now()])->save();
        Audit::log('extension.declined', $request->user);
        self::tell($request->user, __('About your request for more time'), filled($reason) ? (string) $reason : __('Your access will not be extended this time.'));
    }

    public function report(User $user, ?int $serverId, string $subject, string $body): ProblemReport
    {
        if ($serverId !== null && ! $user->serverAccounts()->where('media_server_id', $serverId)->exists()) {
            $serverId = null; // only one of the member's own servers
        }
        $report = new ProblemReport(['media_server_id' => $serverId, 'subject' => mb_substr(trim($subject), 0, 150), 'body' => mb_substr(trim($body), 0, 4000)]);
        $report->user_id = $user->id;
        $report->save();
        Audit::log('problem.reported', $report, ['member' => $user->name, 'subject' => $report->subject], $user->id);

        return $report;
    }

    public function reply(ProblemReport $report, string $reply, bool $close): void
    {
        $report->forceFill(['reply' => mb_substr(trim($reply), 0, 4000), 'replied_at' => now(), 'status' => $close ? 'closed' : 'open'])->save();
        Audit::log('problem.replied', $report, ['closed' => $close]);
        self::tell($report->user, __('Answer: :subject', ['subject' => $report->subject]), $report->reply);
    }

    /** A plain-text note to a member by mail (when mail is set up); never throws. */
    public static function tell(User $user, string $subject, string $text): void
    {
        // Also as a Discord message, for members who linked Discord.
        app(DiscordSync::class)->tell($user, '**'.$subject."**\n".$text);
        if (app(SiteSettings::class)->get('mail_enabled') !== '1') {
            return;
        }
        try {
            $user->notify(new MemberNote($subject, $text));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
