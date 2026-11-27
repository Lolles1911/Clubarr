<?php

namespace App\Services;

use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\Drivers\PlexDriver;
use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;

/**
 * "Sign in with Plex" (plex.tv's PIN flow): Clubarr asks plex.tv for a PIN,
 * the member approves it on app.plex.tv, and Clubarr reads the member's
 * account with the token plex.tv then gives. The PIN lives in the session;
 * the token is only used for the request that needs it.
 */
class PlexLogin
{
    private function http(?string $token = null): ServerHttp
    {
        return new ServerHttp('https://plex.tv', PlexDriver::plexHeaders() + ($token !== null ? ['X-Plex-Token' => $token] : []), 'plex.tv');
    }

    /** @return array{id: string, code: string, url: string} */
    public function start(): array
    {
        $pin = $this->http()->json('POST', '/api/v2/pins', ['strong' => 'true']);
        if (empty($pin['id']) || empty($pin['code'])) {
            throw new MediaServerException(__('plex.tv did not answer as expected. Please try again.'));
        }
        $query = http_build_query(['clientID' => app(SiteSettings::class)->plexClientId(), 'code' => $pin['code'], 'context' => ['device' => ['product' => 'Clubarr']]]);

        return ['id' => (string) $pin['id'], 'code' => (string) $pin['code'], 'url' => 'https://app.plex.tv/auth#?'.$query];
    }

    /** The member's token once they approved the PIN, else null. */
    public function token(string $pinId, string $code): ?string
    {
        $pin = $this->http()->json('GET', '/api/v2/pins/'.rawurlencode($pinId), ['code' => $code]);
        $token = $pin['authToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function identity(string $token): PlexIdentity
    {
        $user = $this->http($token)->json('GET', '/api/v2/user');
        if (empty($user['id']) || empty($user['username'])) {
            throw new MediaServerException(__('plex.tv did not tell who you are. Please try again.'));
        }

        return new PlexIdentity((string) $user['id'], (string) $user['username'], isset($user['email']) ? (string) $user['email'] : null);
    }
}
