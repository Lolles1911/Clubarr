<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Planned maintenance on one server, or all (media_server_id null). While
 * it lasts, "server down" alerts for it are not sent, and the status shows
 * maintenance instead of down.
 *
 * @property int $id
 * @property int|null $media_server_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $note
 * @property bool $told
 * @property int|null $created_by
 */
class Maintenance extends Model
{
    protected $fillable = ['media_server_id', 'starts_at', 'ends_at', 'note'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'told' => 'boolean'];
    }

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }

    public function isNow(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }

    /** Maintenance that is going on now, or starts in the next 14 days. @return Collection<int, self> */
    public static function upcoming(): Collection
    {
        return self::with('mediaServer')->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(14))->orderBy('starts_at')->limit(10)->get();
    }

    public static function activeFor(MediaServer $server): bool
    {
        return self::where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->where(fn (Builder $q) => $q->whereNull('media_server_id')->orWhere('media_server_id', $server->id))->exists();
    }

    public function describe(): string
    {
        return __(':server: :from to :to', [
            'server' => $this->mediaServer->name ?? __('All servers'),
            'from' => $this->starts_at->translatedFormat(__('j F H:i')),
            'to' => $this->ends_at->isSameDay($this->starts_at) ? $this->ends_at->format('H:i') : $this->ends_at->translatedFormat(__('j F H:i')),
        ]);
    }
}
