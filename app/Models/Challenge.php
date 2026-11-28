<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A challenge for every member, e.g. "10 films in December": counted from
 * what they watch (Tracearr, Tautulli, Jellyfin/Emby or Silo).
 *
 * @property int $id
 * @property string $title
 * @property string $metric movies|episodes|hours
 * @property int $target
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $note
 */
class Challenge extends Model
{
    public const METRICS = ['movies', 'episodes', 'hours'];

    protected $fillable = ['title', 'metric', 'target', 'starts_at', 'ends_at', 'note'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'target' => 'integer'];
    }

    /** @return Collection<int, Challenge> the ones running now */
    public static function running(): Collection
    {
        return self::query()->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('ends_at')->limit(3)->get();
    }

    /**
     * How far a member is, from their plays (Tracearr's shape).
     *
     * @param  list<array<string, mixed>>  $plays
     */
    public function progress(array $plays): float
    {
        $from = $this->starts_at->getTimestamp();
        $to = $this->ends_at->getTimestamp();
        $count = 0.0;
        $movies = [];
        foreach ($plays as $play) {
            $at = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            if ($at < $from || $at >= $to) {
                continue;
            }
            $type = (string) ($play['media_type'] ?? '');
            match ($this->metric) {
                'movies' => $type === 'movie' ? $movies[strtolower((string) ($play['media_title'] ?? ''))] = true : null,
                'episodes' => $type === 'episode' ? $count++ : null,
                default => $count += max(0, (int) ($play['duration_ms'] ?? $play['watched_ms'] ?? 0)) / 3600000,
            };
        }

        return $this->metric === 'movies' ? (float) count($movies) : round($count, 1);
    }

    public function unit(): string
    {
        return match ($this->metric) {
            'movies' => __('films'),
            'episodes' => __('episodes'),
            default => __('hours'),
        };
    }
}
