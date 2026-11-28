<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone waiting for a seat. Statuses: waiting, invited (an invitation was
 * mailed, valid 48 hours), joined, expired (the invitation was not used).
 *
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $invited_at
 * @property-read Invitation|null $invitation
 */
class WaitlistEntry extends Model
{
    protected $fillable = ['name', 'email'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime'];
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
