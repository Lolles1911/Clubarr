<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Something doesn't work", from a member, and the admin's answer.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $media_server_id
 * @property string $subject
 * @property string $body
 * @property string $status open|closed
 * @property string|null $reply
 * @property Carbon|null $replied_at
 * @property-read User $user
 * @property-read MediaServer|null $mediaServer
 */
class ProblemReport extends Model
{
    protected $fillable = ['media_server_id', 'subject', 'body'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }
}
