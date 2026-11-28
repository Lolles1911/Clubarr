<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member asking for more time.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $note
 * @property string $status pending|approved|declined
 * @property int|null $days
 * @property Carbon|null $decided_at
 * @property-read User $user
 */
class ExtensionRequest extends Model
{
    protected $fillable = ['note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'days' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
