<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\PlainNote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * "Apply to join" (the public /apply page): a visitor asks, an admin
 * approves, and an invitation made from the chosen template is mailed to
 * them. Nothing is given away without an admin's approval.
 */
class Applications
{
    public static function enabled(): bool
    {
        return app(SiteSettings::class)->get('apply_enabled') === '1';
    }

    /**
     * What applicants can choose from: the admin's packages, each made from a
     * template, with the template's servers (they may leave some out).
     *
     * @return list<array{label: string, description: string, template: Invitation}>
     */
    public static function packages(): array
    {
        $rows = json_decode((string) app(SiteSettings::class)->get('apply_packages'), true);
        if (! is_array($rows)) {
            return [];
        }
        $templates = Invitation::with('mediaServers')->where('is_template', true)->whereIn('id', array_map(fn ($r) => (int) ($r['template_id'] ?? 0), $rows))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $template = $templates->get((int) ($row['template_id'] ?? 0));
            if ($template !== null && filled($row['label'] ?? null)) {
                $out[] = ['label' => mb_substr((string) $row['label'], 0, 80), 'description' => mb_substr((string) ($row['description'] ?? ''), 0, 300), 'template' => $template];
            }
        }

        return $out;
    }

    /** @return list<string> the admin's own questions on the form (at most 5) */
    public static function questions(): array
    {
        $lines = preg_split('/\R/', (string) app(SiteSettings::class)->get('apply_questions')) ?: [];

        return array_slice(array_values(array_filter(array_map(fn ($q) => mb_substr(trim($q), 0, 200), $lines), fn ($q) => $q !== '')), 0, 5);
    }

    /**
     * Stores an application; the same answer whether the email is known or not.
     *
     * @param  array<int, mixed>  $answers  by the number of the question
     * @param  list<int>  $serverIds  the package's servers they want (empty: all of them)
     */
    public function apply(string $name, string $email, ?string $message, array $answers = [], ?int $package = null, array $serverIds = []): void
    {
        $email = mb_strtolower(trim($email));
        if (AccessRequest::where('email', $email)->where('status', 'pending')->exists() || User::where('email', $email)->exists()) {
            return; // already asked, or already a member: nothing new
        }
        $request = new AccessRequest(['name' => mb_substr(trim($name), 0, 100), 'email' => $email, 'message' => filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null]);
        $chosen = $package !== null ? (self::packages()[$package] ?? null) : null;
        if ($chosen !== null) {
            $offered = $chosen['template']->mediaServers->pluck('id')->all();
            $wanted = array_values(array_intersect($offered, array_map('intval', $serverIds)));
            $request->package = $chosen['label'];
            $request->template_id = $chosen['template']->id;
            $request->server_ids = $wanted !== [] && count($wanted) < count($offered) ? $wanted : null;
        }
        $request->answers = collect(self::questions())->map(fn (string $q, int $i) => ['q' => $q, 'a' => mb_substr(trim((string) ($answers[$i] ?? '')), 0, 1000)])->all() ?: null;
        $request->save();
        Audit::log('access.requested', $request, ['name' => $request->name], null);
    }

    /**
     * Someone whose invitation ended or was used up asks for a new link. The
     * same answer whether anything was stored or not; an admin approves.
     */
    public function askRenewal(Invitation $old, string $name, string $email, ?string $message): void
    {
        $email = mb_strtolower(trim($email));
        if ($old->is_template || $old->revoked_at !== null || AccessRequest::where('renew_of', $old->id)->where('status', 'pending')->exists()) {
            return;
        }
        $request = new AccessRequest(['name' => mb_substr(trim($name), 0, 100), 'email' => $email, 'message' => filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null]);
        $request->renew_of = $old->id;
        $request->package = __('New link');
        $request->save();
        Audit::log('access.requested', $request, ['name' => $request->name, 'renewal' => true], null);
    }

    /** A new link for the same servers, libraries and limits as the old invitation; mailed to them. */
    public function approveRenewal(AccessRequest $request): void
    {
        $old = $request->renewing;
        if ($request->status !== 'pending' || $old === null) {
            throw new RuntimeException(__('This application is already decided.'));
        }
        $maker = app(InvitationMaker::class);
        [$settings, $pivot] = $maker->setupOf($old);
        $by = Auth::user() instanceof User ? Auth::user() : null;
        [$invitation, $code] = $maker->create(['label' => $old->label ?: $request->name, 'max_uses' => 1, 'expires_at' => now()->addDays(14)] + $settings, $pivot, $by);
        $request->forceFill(['status' => 'approved', 'invitation_id' => $invitation->id, 'decided_by' => $by?->id, 'decided_at' => now()])->save();
        Audit::log('access.approved', $request, ['name' => $request->name, 'renewal' => true]);
        $maker->send($invitation, $code, $request->email, null, $by);
    }

    public function approve(AccessRequest $request, Invitation $template): void
    {
        if ($request->status !== 'pending' || ! $template->is_template) {
            throw new RuntimeException(__('This application is already decided.'));
        }
        $maker = app(InvitationMaker::class);
        [$settings, $pivot] = $maker->setupOf($template);
        // Only the servers they asked for, when they left some out (and the template has them).
        if ($request->server_ids !== null && $request->template_id === $template->id) {
            $pivot = array_intersect_key($pivot, array_flip($request->server_ids)) ?: $pivot;
        }
        $by = Auth::user() instanceof User ? Auth::user() : null;
        [$invitation, $code] = $maker->create(['label' => $request->name, 'max_uses' => 1, 'expires_at' => now()->addDays(14)] + $settings, $pivot, $by);
        $request->forceFill(['status' => 'approved', 'invitation_id' => $invitation->id, 'decided_by' => $by?->id, 'decided_at' => now()])->save();
        Audit::log('access.approved', $request, ['name' => $request->name]);
        $maker->send($invitation, $code, $request->email, null, $by);
    }

    public function decline(AccessRequest $request, ?string $reason): void
    {
        $request->forceFill(['status' => 'declined', 'decided_by' => Auth::id(), 'decided_at' => now()])->save();
        Audit::log('access.declined', $request, ['name' => $request->name]);
        if (filled($reason) && app(SiteSettings::class)->get('mail_enabled') === '1') {
            try {
                Notification::route('mail', $request->email)->notify(new PlainNote(__('About your application'), $request->name, (string) $reason));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
