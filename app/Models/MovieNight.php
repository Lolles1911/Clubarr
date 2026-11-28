<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A movie night: a title at a time, for one server's members or everyone.
 * Members say they are coming, and get a reminder before it starts.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $starts_at
 * @property int|null $media_server_id
 * @property string|null $note
 * @property bool $reminded
 */
class MovieNight extends Model
{
    protected $fillable = ['title', 'starts_at', 'media_server_id', 'note'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'reminded' => 'boolean'];
    }

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }

    /** @return BelongsToMany<User, $this> who is coming */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The coming ones a member can see (their servers or everyone), in the next 30 days.
     *
     * @return Collection<int, MovieNight>
     */
    public static function upcomingFor(User $user): Collection
    {
        $servers = $user->serverAccounts()->pluck('media_server_id');

        return self::query()->withCount('attendees')->where('starts_at', '>', now()->subHours(3))->where('starts_at', '<', now()->addDays(30))
            ->where(fn (Builder $q) => $q->whereNull('media_server_id')->orWhereIn('media_server_id', $servers))
            ->orderBy('starts_at')->limit(3)->get();
    }
}
