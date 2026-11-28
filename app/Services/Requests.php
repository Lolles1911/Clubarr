<?php

namespace App\Services;

use App\Enums\ServerType;
use App\Integrations\Seerr;
use App\MediaServers\MediaServerException;
use App\Models\MediaRequest;
use App\Models\User;

/**
 * Members' requests through Overseerr/Jellyseerr, with the limit from their
 * invitation (requests per 7 days). The request is made as the member's own
 * Seerr user, found by their Jellyfin/Emby or Plex user.
 */
class Requests
{
    public const WINDOW_DAYS = 7;

    public function __construct(private ?Seerr $seerr = null)
    {
        $this->seerr ??= Seerr::fromSettings();
    }

    public function enabled(): bool
    {
        return $this->seerr !== null;
    }

    /** How many more the member may request now; null = no limit. */
    public function remaining(User $user): ?int
    {
        if ($user->request_limit === null) {
            return null;
        }

        return max(0, $user->request_limit - $user->mediaRequests()->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))->count());
    }

    /** The member's Seerr user (looked up once, then kept). */
    public function seerrUser(User $user): int
    {
        if (! $user->seerr_access) {
            throw new MediaServerException(__('Requests are not part of your access.'));
        }
        if ($user->seerr_user_id !== null) {
            return $user->seerr_user_id;
        }
        $kind = $this->seerr->mediaServerType();
        $types = match ($kind) {
            'plex' => [ServerType::Plex],
            'jellyfin', 'emby' => [ServerType::Jellyfin, ServerType::Emby],
            default => [],
        };
        $account = $user->serverAccounts()->with('mediaServer')->whereNotNull('remote_id')->get()
            ->first(fn ($a) => in_array($a->mediaServer->type, $types, true));
        $id = $account !== null ? $this->seerr->userFor($kind === 'plex' ? 'plex' : 'jellyfin', (string) $account->remote_id) : null;
        if ($id === null) {
            throw new MediaServerException(__('Your user could not be found in the request service. Ask the person who invited you.'));
        }
        $user->forceFill(['seerr_user_id' => $id])->saveQuietly();

        return $id;
    }

    /** @return list<array> */
    public function search(User $user, string $query): array
    {
        $query = trim(mb_substr($query, 0, 100));

        return $query === '' ? [] : $this->seerr->search($this->seerrUser($user), $query);
    }

    /** Requests a title; throws with a reason the member can read. */
    public function request(User $user, string $type, int $tmdb): void
    {
        if (! in_array($type, ['movie', 'tv'], true) || $tmdb <= 0) {
            throw new MediaServerException(__('That title can\'t be requested.'));
        }
        if (! $user->hasActiveAccess() || ! $user->seerr_access) {
            throw new MediaServerException(__('Requests need active access.'));
        }
        if (($left = $this->remaining($user)) !== null && $left <= 0) {
            throw new MediaServerException(__('You have used your :count requests for this week.', ['count' => (int) $user->request_limit]));
        }
        $title = $this->seerr->title($type, $tmdb);
        if ($title === null) {
            throw new MediaServerException(__('That title can\'t be requested.'));
        }
        $this->seerr->request($this->seerrUser($user), $type, $tmdb);
        MediaRequest::create(['user_id' => $user->id, 'type' => $type, 'tmdb_id' => $tmdb, 'title' => mb_substr($title['title'], 0, 250)]);
        Audit::log('request.made', $user, ['title' => $title['title'], 'type' => $type]);
    }

    /** @return list<array> the member's recent requests with title and status */
    public function recent(User $user): array
    {
        $mine = $user->mediaRequests()->latest('created_at')->limit(20)->get()->keyBy(fn ($r) => $r->type.$r->tmdb_id);
        try {
            $rows = $this->seerr->requestsOf($this->seerrUser($user));
        } catch (MediaServerException) {
            $rows = [];
        }
        foreach ($rows as &$row) {
            $row['title'] = $mine[$row['type'].$row['tmdb']]->title ?? ($this->seerr->title($row['type'], $row['tmdb'])['title'] ?? '#'.$row['tmdb']);
        }

        return $rows;
    }
}
