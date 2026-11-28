<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A movie or show a member requested through Clubarr (for their weekly
 * limit; Seerr keeps the request itself).
 *
 * @property string $type movie|tv
 * @property int $tmdb_id
 * @property string $title
 */
class MediaRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'tmdb_id', 'title'];

    protected function casts(): array
    {
        return ['tmdb_id' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
