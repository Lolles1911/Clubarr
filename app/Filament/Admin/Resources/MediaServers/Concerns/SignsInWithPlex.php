<?php

namespace App\Filament\Admin\Resources\MediaServers\Concerns;

use App\Services\Audit;
use App\Services\PlexLogin;
use Filament\Notifications\Notification;
use Throwable;

/**
 * "Sign in with Plex" on the server form, like Tracearr: the owner approves
 * Clubarr on plex.tv, and the token it gives is kept in the (encrypted)
 * session until the server is saved; it is never put in the form, so it
 * never reaches the browser. While a sign-in waits, the form asks every few
 * seconds (on a phone: come back to this tab after approving).
 */
trait SignsInWithPlex
{
    public const PLEX_PIN_MINUTES = 15;

    public function plexCheck(): void
    {
        $pin = session('admin.plex.pin');
        if (! is_array($pin)) {
            return;
        }
        if (now()->getTimestamp() - (int) ($pin['at'] ?? 0) > self::PLEX_PIN_MINUTES * 60) {
            session()->forget('admin.plex.pin');
            Notification::make()->title(__('The Plex sign-in timed out. Please try again.'))->warning()->send();

            return;
        }
        try {
            $login = app(PlexLogin::class);
            $token = $login->token((string) $pin['id'], (string) $pin['code']);
            if ($token === null) {
                return; // not approved yet
            }
            $who = $login->identity($token);
        } catch (Throwable) {
            return; // plex.tv busy: the next check tries again
        }
        session()->forget('admin.plex.pin');
        session()->put(['admin.plex.token' => $token, 'admin.plex.who' => $who->username]);
        Audit::log('server.plex_signed_in', null, ['plex_user' => $who->username]);
        $this->js('window.clubarrPlex && window.clubarrPlex.close()');
        Notification::make()->title(__('Signed in with Plex as :name.', ['name' => $who->username]))
            ->body(__('Now press Find Plex and choose your server.'))->success()->send();
    }

    /** The token from "Sign in with Plex", used when the key field is left empty; then forgotten. */
    protected function withPlexToken(array $data): array
    {
        $token = session('admin.plex.token');
        if (is_string($token) && $token !== '' && blank($data['api_key'] ?? null) && ($data['type'] ?? $this->record?->type?->value ?? null) === 'plex') {
            $data['api_key'] = $token;
        }

        return $data;
    }

    protected function forgetPlexSignIn(): void
    {
        session()->forget(['admin.plex.token', 'admin.plex.who', 'admin.plex.pin', 'admin.plex.found', 'admin.plex.how', 'admin.plex.network']);
    }
}
