<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Members inviting friends (Settings → Invitations): a number of invitations
 * a month, each for one person, valid 7 days. What they give (servers,
 * libraries, how long, limits) comes only from the admin's template; the
 * member chooses nothing but who and the words.
 */
class MemberInvites
{
    public const VALID_DAYS = 7;

    public static function perMonth(): int
    {
        return max(0, (int) app(SiteSettings::class)->get('member_invites_per_month'));
    }

    public static function template(): ?Invitation
    {
        $id = app(SiteSettings::class)->get('member_invite_template_id');

        return $id ? Invitation::where('is_template', true)->with('mediaServers')->find($id) : null;
    }

    public static function enabled(): bool
    {
        return self::perMonth() > 0 && self::template() !== null;
    }

    public static function usedThisMonth(User $user): int
    {
        return Invitation::where('created_by', $user->id)->where('by_member', true)->where('created_at', '>=', now()->startOfMonth())->count();
    }

    public static function remaining(User $user): int
    {
        return max(0, self::perMonth() - self::usedThisMonth($user));
    }

    public static function allowed(User $user): bool
    {
        return self::enabled() && ! $user->is_admin && $user->hasActiveAccess();
    }

    /**
     * Makes an invitation for a friend and, with an email, mails it.
     *
     * @return array{0: Invitation, 1: string, 2: list<array{ok: bool, text: string}>}
     */
    public function invite(User $member, ?string $email, ?string $greeting): array
    {
        $template = self::template();
        if ($template === null || ! self::allowed($member)) {
            throw new RuntimeException(__('Inviting friends is not open right now.'));
        }
        $maker = app(InvitationMaker::class);
        [$settings, $pivot] = $maker->setupOf($template);
        [$invitation, $code] = DB::transaction(function () use ($member, $email, $greeting, $settings, $pivot, $maker) {
            User::whereKey($member->id)->lockForUpdate()->first(); // one at a time per member: the count stays right
            if (self::remaining($member) < 1) {
                throw new RuntimeException(__('You have used your invitations for this month.'));
            }

            return $maker->create([
                'label' => filled($email) ? (string) $email : __('From :name', ['name' => $member->name]),
                'max_uses' => 1,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'greeting' => filled($greeting) ? mb_substr(trim((string) $greeting), 0, 1000) : null,
            ] + $settings, $pivot, $member, byMember: true);
        });

        if (self::needsApproval()) {
            // Kept encrypted until an admin decides; the link works, and is mailed, only once approved.
            $invitation->forceFill(['awaiting_approval' => true, 'pending_code' => $code, 'pending_email' => filled($email) ? mb_substr((string) $email, 0, 190) : null])->save();
            Audit::log('invitation.awaiting_approval', $invitation, ['by' => $member->name], $member->id);

            return [$invitation, $code, []];
        }

        return [$invitation, $code, filled($email) ? $maker->send($invitation, $code, $email, null, $member) : []];
    }

    public static function needsApproval(): bool
    {
        return app(SiteSettings::class)->get('member_invites_approval') === '1';
    }

    /** An admin approves a member's invitation: it works from now, and the friend gets the mail if one was given. */
    public function approve(Invitation $invitation): void
    {
        if (! $invitation->awaiting_approval) {
            return;
        }
        $code = (string) $invitation->pending_code;
        $email = $invitation->pending_email;
        $invitation->forceFill(['awaiting_approval' => false, 'pending_code' => null, 'pending_email' => null])->save();
        Audit::log('invitation.approved', $invitation);
        $member = $invitation->creator;
        if (filled($email) && $code !== '') {
            app(InvitationMaker::class)->send($invitation, $code, $email, null, $member);
        }
        if ($member !== null) {
            MemberRequests::tell($member, __('Your invitation is approved'), filled($email)
                ? __('Your invitation for :email is approved and mailed to them.', ['email' => $email])
                : __('Your invitation is approved. The link you got works now.'));
        }
    }

    public function decline(Invitation $invitation): void
    {
        $invitation->forceFill(['awaiting_approval' => false, 'pending_code' => null, 'pending_email' => null, 'revoked_at' => now()])->save();
        Audit::log('invitation.declined', $invitation);
        if ($invitation->creator !== null) {
            MemberRequests::tell($invitation->creator, __('About your invitation'), __('Your invitation was not approved this time.'));
        }
    }
}
