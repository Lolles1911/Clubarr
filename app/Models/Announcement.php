<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property int|null $media_server_id
 * @property bool $by_mail
 * @property bool $by_discord
 * @property bool $as_banner
 * @property Carbon|null $banner_until
 * @property int $mailed
 * @property int $discorded
 * @property Carbon|null $sent_at
 */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'media_server_id', 'by_mail', 'by_discord', 'as_banner', 'banner_until'];

    protected function casts(): array
    {
        return ['by_mail' => 'boolean', 'by_discord' => 'boolean', 'as_banner' => 'boolean', 'banner_until' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }

    /**
     * The members it is for: active members, of one server or all.
     *
     * @return Builder<User>
     */
    public function recipients(): Builder
    {
        return User::members()->whereNull('disabled_at')
            ->when($this->media_server_id !== null, fn (Builder $q) => $q->whereHas('serverAccounts', fn (Builder $a) => $a->where('media_server_id', $this->media_server_id)));
    }

    /** Banners a member sees now, newest first. */
    public static function bannersFor(User $user): Collection
    {
        $servers = $user->serverAccounts()->pluck('media_server_id');

        return self::where('as_banner', true)
            ->where(fn (Builder $q) => $q->whereNull('banner_until')->orWhere('banner_until', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('media_server_id')->orWhereIn('media_server_id', $servers))
            ->latest()->limit(3)->get();
    }
}
