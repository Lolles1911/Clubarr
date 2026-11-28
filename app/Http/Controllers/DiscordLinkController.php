<?php

namespace App\Http\Controllers;

use App\Filament\App\Pages\MyAccess;
use App\Integrations\Discord;
use App\MediaServers\MediaServerException;
use App\Models\User;
use App\Services\Audit;
use App\Services\DiscordSync;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Linking a member's Discord account: Discord's OAuth with a one-time state
 * in the session (against forged callbacks), "identify" only.
 */
class DiscordLinkController
{
    public function connect(Request $request): RedirectResponse
    {
        $discord = Discord::fromSettings();
        abort_if($discord === null, 404);
        $state = Str::random(40);
        $request->session()->put('discord.state', $state);

        return redirect()->away($discord->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $discord = Discord::fromSettings();
        abort_if($discord === null, 404);
        $expected = (string) $request->session()->pull('discord.state', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', '')) || ! is_string($request->query('code'))) {
            Notification::make()->title(__('Discord was not linked. Please try again.'))->danger()->send();

            return redirect()->to(MyAccess::getUrl(panel: 'app'));
        }
        /** @var User $user */
        $user = Auth::user();
        try {
            [$id, $name] = $discord->accountFromCode((string) $request->query('code'));
        } catch (MediaServerException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return redirect()->to(MyAccess::getUrl(panel: 'app'));
        }
        if (User::where('discord_id', $id)->whereKeyNot($user->id)->exists()) {
            Notification::make()->title(__('That Discord account is linked to someone else.'))->danger()->send();

            return redirect()->to(MyAccess::getUrl(panel: 'app'));
        }
        $user->forceFill(['discord_id' => $id, 'discord_username' => mb_substr($name, 0, 100)])->save();
        Audit::log('discord.linked', $user, ['discord' => $name]);
        app(DiscordSync::class)->sync($user);
        Notification::make()->title(__('Discord is linked.'))->success()->send();

        return redirect()->to(MyAccess::getUrl(panel: 'app'));
    }

    public function disconnect(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->discord_id !== null) {
            $discord = Discord::fromSettings();
            try {
                if ($discord?->hasRole()) {
                    $discord->removeRole($user->discord_id);
                }
            } catch (MediaServerException) {
                // Unlinked all the same.
            }
            $user->forceFill(['discord_id' => null, 'discord_username' => null])->save();
            Audit::log('discord.unlinked', $user);
        }

        return redirect()->to(MyAccess::getUrl(panel: 'app'));
    }
}
