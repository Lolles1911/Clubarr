<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Jobs\RefreshMemberHistory;
use App\MediaServers\Contracts\ReadsWatchHistory;
use App\MediaServers\Data\RecentItem;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One member's watching, for their own numbers: from Tracearr or Tautulli
 * when set up, otherwise straight from their Jellyfin and Emby users (see
 * ReadsWatchHistory). Kept half an hour, as plain arrays.
 */
class MemberHistory
{
    public function __construct(private Watching $watching) {}

    public function available(User $user): bool
    {
        return $this->watching->enabled() || $this->localAccounts($user) !== [];
    }

    /** @return list<array<string, mixed>> the member's plays of the last 365 days */
    public function yearPlays(User $user): array
    {
        if ($this->watching->enabled()) {
            try {
                return $this->watching->yearPlaysOf($user);
            } catch (Throwable) {
                return [];
            }
        }

        return $this->local($user)['plays'];
    }

    /**
     * The Jellyfin/Emby history as last fetched. The page never waits for the
     * servers: when it is missing or old, a queued job fetches it.
     *
     * @return array{plays: list<array<string, mixed>>, resume: list<array<string, mixed>>}
     */
    private function local(User $user): array
    {
        $cached = Cache::get('clubarr:member-history:'.$user->id);
        if (! is_array($cached) && $this->localAccounts($user) !== [] && Cache::add('clubarr:member-history-busy:'.$user->id, 1, 300)) {
            RefreshMemberHistory::dispatch($user->id);
        }

        return is_array($cached) ? $cached + ['because' => null] : ['plays' => [], 'resume' => [], 'because' => null];
    }

    /** Fetches the member's history and "continue watching" from their Jellyfin/Emby users. */
    public function refresh(User $user): void
    {
        $since = now()->subDays(365)->getTimestamp();
        $plays = $resume = [];
        foreach ($this->localAccounts($user) as $account) {
            try {
                /** @var ReadsWatchHistory $driver */
                $driver = $account->mediaServer->driver();
                foreach ($driver->watched((string) $account->remote_id, 500) as $play) {
                    if ((strtotime((string) $play['started_at']) ?: 0) >= $since) {
                        $plays[] = $play + ['server_type' => $account->mediaServer->type->value, 'username' => (string) $account->remote_username, 'platform' => $account->mediaServer->name];
                    }
                }
                foreach ($driver->resume((string) $account->remote_id) as $item) {
                    /** @var RecentItem $item */
                    $resume[] = ['server_id' => $account->media_server_id, 'server' => $account->mediaServer->name, 'ref' => $item->ref, 'title' => $item->title, 'year' => null, 'percent' => $item->addedAt];
                }
            } catch (Throwable) {
                // a server that does not answer just adds nothing
            }
        }
        Cache::put('clubarr:member-history:'.$user->id, ['plays' => $plays, 'resume' => array_slice($resume, 0, 12), 'because' => $this->findBecause($user, $plays)], 1800);
        Cache::forget('clubarr:member-history-busy:'.$user->id);
    }

    /** @return array{hours7: float, hours30: float, plays30: int, top: ?string}|null */
    public function stats(User $user): ?array
    {
        if (! $this->available($user)) {
            return null;
        }
        $week = now()->subDays(7)->getTimestamp();
        $month = now()->subDays(30)->getTimestamp();
        $hours7 = $hours30 = 0.0;
        $plays30 = 0;
        $plays = $this->yearPlays($user);
        foreach ($plays as $play) {
            $at = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            $hours = max(0, (int) ($play['duration_ms'] ?? $play['watched_ms'] ?? 0)) / 3600000;
            if ($at >= $month) {
                $hours30 += $hours;
                $plays30++;
            }
            if ($at >= $week) {
                $hours7 += $hours;
            }
        }

        return ['hours7' => round($hours7, 1), 'hours30' => round($hours30, 1), 'plays30' => $plays30,
            'top' => Watching::summarize($plays, 365)['titles'][0]['title'] ?? null];
    }

    /** @return list<array{key: string, title: string, year: int|null, server: string, percent: int}> "continue watching" from Jellyfin/Emby */
    public function resume(User $user): array
    {
        $rows = $this->local($user)['resume'];
        $registered = app(NewOnServers::class)->registerRows($rows);

        return array_map(fn (array $out, array $row) => $out + ['percent' => (int) $row['percent']], $registered, $rows);
    }

    /**
     * "Because you watched …": titles like the last one watched on Jellyfin/Emby, without ones already watched.
     *
     * @return array{title: string, items: list<array<string, mixed>>}|null
     */
    public function because(User $user): ?array
    {
        $because = $this->local($user)['because'] ?? null;
        if (! is_array($because) || ($because['items'] ?? []) === []) {
            return null;
        }

        return ['title' => (string) $because['title'], 'items' => app(NewOnServers::class)->registerRows($because['items'])];
    }

    /**
     * @param  list<array<string, mixed>>  $plays
     * @return array{title: string, items: list<array<string, mixed>>}|null
     */
    private function findBecause(User $user, array $plays): ?array
    {
        $seen = array_map(fn ($p) => mb_strtolower((string) (($p['media_type'] ?? '') === 'episode' ? ($p['show_title'] ?? '') : ($p['media_title'] ?? ''))), $plays);
        foreach ($this->localAccounts($user) as $account) {
            $last = collect($plays)->first(fn ($p) => ($p['server_type'] ?? '') === $account->mediaServer->type->value && filled($p['item'] ?? null));
            if ($last === null) {
                continue;
            }
            try {
                /** @var ReadsWatchHistory $driver */
                $driver = $account->mediaServer->driver();
                $items = collect($driver->similar((string) $account->remote_id, (string) $last['item'], 16))
                    ->reject(fn (RecentItem $i) => in_array(mb_strtolower($i->title), $seen, true))->take(12)
                    ->map(fn (RecentItem $i) => ['server_id' => $account->media_server_id, 'server' => $account->mediaServer->name, 'ref' => $i->ref, 'title' => $i->title, 'year' => $i->year])->values()->all();
            } catch (Throwable) {
                continue;
            }
            if ($items !== []) {
                return ['title' => ($last['media_type'] ?? '') === 'episode' ? (string) $last['show_title'] : (string) $last['media_title'], 'items' => $items];
            }
        }

        return null;
    }

    /** @return list<ServerAccount> the member's active users on Jellyfin/Emby */
    private function localAccounts(User $user): array
    {
        return $user->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Active)->whereNotNull('remote_id')->get()
            ->filter(fn (ServerAccount $a) => $a->mediaServer->is_enabled && $a->mediaServer->type->can(ReadsWatchHistory::class))->values()->all();
    }
}
