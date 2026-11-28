<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\ExtensionRequest;
use App\Models\Invitation;
use App\Models\KnownDevice;
use App\Models\ProblemReport;
use App\Models\ServerCheck;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Removes old data, as set under Settings → Automation (0 keeps it): the
 * log, invitations that ended or were used up (never templates or ones a
 * setting uses), and decided requests, answered reports, decided
 * applications and old waiting-list entries.
 */
class Retention
{
    /** @return array<string, int> what was removed, per kind */
    public function run(): array
    {
        $settings = app(SiteSettings::class);
        $removed = [];
        $days = fn (string $key) => max(0, (int) $settings->get($key));

        if (($log = $days('retention_log_days')) > 0) {
            $removed['log'] = AuditLog::where('created_at', '<', now()->subDays($log))->delete();
        }
        if (($inv = $days('retention_invitations_days')) > 0) {
            $kept = array_filter(array_map('intval', [$settings->get('waitlist_template_id'), $settings->get('member_invite_template_id'), $settings->get('apply_template_id')]));
            $before = now()->subDays($inv);
            $removed['invitations'] = Invitation::where('is_template', false)->whereNotIn('id', $kept)
                ->where(fn (Builder $q) => $q->where('expires_at', '<', $before)
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('max_uses')->whereColumn('uses', '>=', 'max_uses')->where('updated_at', '<', $before)))
                ->limit(5000)->get()->each(fn (Invitation $i) => $i->delete())->count();
        }
        if (($req = $days('retention_requests_days')) > 0) {
            $before = now()->subDays($req);
            $removed['requests'] = ExtensionRequest::where('status', '!=', 'pending')->where('decided_at', '<', $before)->delete()
                + AccessRequest::where('status', '!=', 'pending')->where('decided_at', '<', $before)->delete()
                + ProblemReport::where('status', 'closed')->where('updated_at', '<', $before)->delete()
                + WaitlistEntry::whereIn('status', ['joined', 'expired', 'removed'])->where('updated_at', '<', $before)->delete();
        }
        ServerCheck::where('checked_at', '<', now()->subDays(90))->delete();
        // Devices not signed in on for over a year: a sign-in there is "new" again.
        KnownDevice::where('last_used_at', '<', now()->subDays(400))->delete();

        $removed = array_filter($removed);
        if ($removed !== []) {
            Audit::log('data.cleaned', null, $removed, null);
        }

        return $removed;
    }
}
