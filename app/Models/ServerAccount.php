<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's user on one media server.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_server_id
 * @property string|null $remote_id
 * @property string|null $remote_username
 * @property AccountStatus $status
 * @property array<int, string>|null $library_ids
 * @property string|null $claim_url
 * @property string|null $claim_id
 * @property-read MediaServer $mediaServer
 * @property-read User $user
 */
class ServerAccount extends Model
{
    protected $fillable = ['user_id', 'media_server_id', 'invitation_id', 'remote_id', 'remote_username', 'status', 'library_ids', 'claim_url', 'claim_id'];

    protected $hidden = ['claim_url'];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'library_ids' => 'array',
            'claim_url' => 'encrypted',
        ];
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

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
