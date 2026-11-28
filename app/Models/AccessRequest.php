<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A visitor asking to join (the public /apply page).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $message
 * @property list<array{q: string, a: string}>|null $answers
 * @property string $status pending|approved|declined
 * @property int|null $invitation_id
 * @property Carbon|null $decided_at
 */
class AccessRequest extends Model
{
    protected $fillable = ['name', 'email', 'message'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'answers' => 'array'];
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
