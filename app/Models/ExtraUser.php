<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra user a member made for their family on a server. It follows the
 * member: paused, let back in and removed with them.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_server_id
 * @property string $remote_id
 * @property string $remote_username
 * @property string $label
 * @property AccountStatus $status
 */
class ExtraUser extends Model
{
    protected $fillable = ['user_id', 'media_server_id', 'remote_id', 'remote_username', 'label', 'status'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class];
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
