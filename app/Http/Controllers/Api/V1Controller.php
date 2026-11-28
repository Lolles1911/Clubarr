<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Models\ApiKey;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\Audit;
use App\Services\InvitationMaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The REST API, version 1 (see docs/api.md). Read-only, except making an
 * invitation from a template, which needs a key allowed to. Codes, keys and
 * notes are never returned; an invitation link only once, when made.
 */
class V1Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'version' => config('clubarr.version'),
            'members' => [
                'total' => User::members()->count(),
                'with_access' => User::members()->whereNull('disabled_at')->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
                    ->whereHas('serverAccounts', fn ($q) => $q->where('status', AccountStatus::Active))->count(),
                'paused' => User::members()->whereNotNull('disabled_at')->count(),
            ],
            'servers' => MediaServer::orderBy('name')->get()->map(fn (MediaServer $s) => [
                'id' => $s->id, 'name' => $s->name, 'type' => $s->type->value, 'enabled' => (bool) $s->is_enabled,
                'online' => $s->last_error === null, 'last_checked_at' => $s->last_checked_at?->toIso8601String(),
            ])->all(),
            'open_invitations' => Invitation::usable()->where('is_template', false)->count(),
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $page = User::members()->with(['serverAccounts.mediaServer', 'groups'])->orderBy('id')->paginate(100);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'paused' => $u->disabled_at !== null,
                'access_expires_at' => $u->access_expires_at?->toIso8601String(),
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                'joined_at' => $u->created_at?->toIso8601String(),
                'groups' => $u->groups->pluck('name')->all(),
                'servers' => $u->serverAccounts->map(fn (ServerAccount $a) => [
                    'server' => $a->mediaServer->name, 'username' => $a->remote_username, 'status' => $a->status->value,
                ])->all(),
            ])->all(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function invitations(): JsonResponse
    {
        // Open invitations, and the templates new ones can be made from.
        $open = Invitation::with('mediaServers')->usable()->latest()->limit(500)->get();
        $templates = Invitation::with('mediaServers')->where('is_template', true)->orderBy('label')->get();

        return response()->json(['data' => $templates->concat($open)->unique('id')->values()
            ->map(fn (Invitation $i) => [
                'id' => $i->id, 'label' => $i->label, 'template' => (bool) $i->is_template,
                'uses' => $i->uses, 'max_uses' => $i->max_uses, 'access_days' => $i->access_days,
                'expires_at' => $i->expires_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String(),
                'servers' => $i->mediaServers->pluck('name')->all(),
            ])->all()]);
    }

    public function createInvitation(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        if (! $key->can_invite) {
            return response()->json(['message' => 'This key may not make invitations.'], 403);
        }
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:80'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        $template = Invitation::where('is_template', true)->find($data['template_id']);
        if ($template === null) {
            return response()->json(['message' => 'No template with that ID.'], 422);
        }
        $maker = app(InvitationMaker::class);
        [$settings, $pivot] = $maker->setupOf($template);
        $settings['label'] = $data['label'] ?? $template->label;
        $settings['max_uses'] = $data['max_uses'] ?? 1;
        $settings['expires_at'] = now()->addDays((int) ($data['expires_in_days'] ?? 7));
        $by = $key->creator;
        [$invitation, $code] = $maker->create($settings, $pivot, $by);
        Audit::log('api.invitation_created', $invitation, ['key' => $key->name], $by?->id);
        $sent = filled($data['email'] ?? null) ? $maker->send($invitation, $code, (string) $data['email'], null, $by) : [];

        return response()->json([
            'id' => $invitation->id,
            'link' => InvitationMaker::link($code),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'sent' => collect($sent)->map(fn ($r) => ['ok' => $r['ok'], 'text' => $r['text']])->all(),
        ], 201);
    }
}
