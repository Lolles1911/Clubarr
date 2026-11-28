<?php

namespace App\Services;

use App\Enums\ServerType;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\User;
use App\Models\WizardStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which wizard steps someone sees: an invitation's bundle when it has one,
 * otherwise the standard steps; each for one of the invitation's servers or
 * for every server. Also the ready-made steps per server type (presets).
 */
class WizardSteps
{
    /**
     * @param  list<int>  $serverIds
     * @return Collection<int, WizardStep>
     */
    public function for(array $serverIds, ?int $bundleId, string $phase): Collection
    {
        return WizardStep::with('mediaServer')->where('phase', $phase)
            ->when($bundleId !== null, fn (Builder $q) => $q->where('wizard_bundle_id', $bundleId), fn (Builder $q) => $q->whereNull('wizard_bundle_id'))
            ->where(fn (Builder $q) => $q->whereNull('media_server_id')->orWhereIn('media_server_id', $serverIds))
            ->orderBy('position')->orderBy('id')->get();
    }

    /** @return Collection<int, WizardStep> */
    public function forInvitation(Invitation $invitation, string $phase): Collection
    {
        return $this->for($invitation->mediaServers->pluck('id')->all(), $invitation->wizard_bundle_id, $phase);
    }

    /**
     * The steps after joining, for a member: each server with the bundle of
     * the invitation that gave it (only these servers, when given).
     *
     * @param  list<int>|null  $serverIds
     * @return Collection<int, WizardStep>
     */
    public function forMember(User $member, ?array $serverIds = null): Collection
    {
        $steps = collect();
        foreach ($member->serverAccounts()->with('invitation')->when($serverIds !== null, fn ($q) => $q->whereIn('media_server_id', $serverIds))->get() as $account) {
            $steps = $steps->concat($this->for([$account->media_server_id], $account->invitation?->wizard_bundle_id, 'after'));
        }

        return $steps->unique('id')->sortBy([['position', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * Ready-made steps for a server type, in English (the admin can change them).
     *
     * @return list<array{phase: string, title: string, body: string, button_label?: string, button_url?: string}>
     */
    public static function preset(ServerType $type, MediaServer $server): array
    {
        $name = $type->label();
        $steps = [[
            'phase' => 'before',
            'title' => __('What is :app?', ['app' => $name]),
            'body' => match ($type) {
                ServerType::Plex => __('Plex is like your own Netflix: films and series, on your TV, phone, tablet and in the browser. You use your own free Plex account, and our server shows up in it.'),
                ServerType::Silo => __('Silo is like your own Netflix: films and series in the browser and on your devices. You choose your own user name and password.'),
                default => __(':app is like your own Netflix: films and series on your TV, phone, tablet and in the browser. You get your own user with a password.', ['app' => $name]),
            },
        ]];
        $apps = $type->apps();
        $steps[] = [
            'phase' => 'after',
            'title' => __('Get the :app app', ['app' => $name]),
            'body' => collect($apps)->map(fn (array $app) => '- ['.$app[0].']('.$app[1].')')->push('- '.__('On a TV: search for ":app" in the TV\'s app store.', ['app' => $name]))->implode("\n"),
        ];
        $steps[] = [
            'phase' => 'after',
            'title' => __('Tips for the best experience'),
            'body' => __("- Use the app on your TV rather than casting from the phone.\n- Choose \"original\" or the highest quality if your internet allows it.\n- Something doesn't play? Tell us under Help."),
            'button_label' => __('Open :server', ['server' => $server->name]),
            'button_url' => $server->watchUrl(),
        ];

        return $steps;
    }
}
