<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A personal link to an existing server user (made by Import users). The
 * code is never stored, only its hash, like invitations.
 *
 * @property int $id
 * @property string $remote_id
 * @property string $remote_username
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property-read MediaServer $mediaServer
 */
class Claim extends Model
{
    public const DAYS = 30;

    protected $fillable = ['media_server_id', 'remote_id', 'remote_username'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }

    /** Sets a new code (same form as invitation codes) and returns it. */
    public function assignCode(): string
    {
        $code = Invitation::newCode();
        $this->code_hash = Invitation::hashCode($code);

        return $code;
    }

    public static function findByCode(string $code): ?self
    {
        $normalized = Invitation::normalize($code);

        return strlen($normalized) < 10 || strlen($normalized) > 64 ? null : self::with('mediaServer')->where('code_hash', Invitation::hashCode($normalized))->first();
    }

    /** Can still be used: not used, not expired, and the server user not linked to anyone meanwhile. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture()
            && ! ServerAccount::where('media_server_id', $this->media_server_id)->where('remote_id', $this->remote_id)->exists();
    }
}
