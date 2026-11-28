<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A key for the REST API. The key is shown once when made; only its hash
 * and its first characters (to tell keys apart) are stored.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property string $hint
 * @property bool $can_invite
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 */
class ApiKey extends Model
{
    public const PREFIX = 'clb_';

    protected $fillable = ['name', 'can_invite'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['can_invite' => 'boolean', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{0: self, 1: string} the key and its secret (shown once) */
    public static function issue(string $name, bool $canInvite, ?User $by): array
    {
        $token = self::PREFIX.Str::random(40);
        $key = new self(['name' => $name, 'can_invite' => $canInvite]);
        $key->token_hash = hash('sha256', $token);
        $key->hint = substr($token, 0, 8);
        $key->created_by = $by?->id;
        $key->save();

        return [$key, $token];
    }

    public static function findByToken(string $token): ?self
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 40) {
            return null;
        }

        return self::where('token_hash', hash('sha256', $token))->first();
    }
}
