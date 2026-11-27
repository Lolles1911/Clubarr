<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation: a code that lets someone make an account and get users on
 * the media servers it names. The code itself is never stored, only its hash.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $code_hint
 * @property string|null $label
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property int $uses
 * @property int|null $access_days
 * @property Carbon|null $revoked_at
 * @property-read Collection<int, MediaServer> $mediaServers
 */
class Invitation extends Model
{
    /** Letters and digits that can't be mistaken for each other. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = ['label', 'expires_at', 'max_uses', 'access_days'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
            'access_days' => 'integer',
        ];
    }

    /** A new code: 20 characters, about 99 bits. */
    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 5));
    }

    /** The form codes are compared in: upper case, without spaces and dashes. */
    public static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper($code)) ?? '';
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** Sets a new code and returns it; it can't be read back later. */
    public function assignCode(?string $code = null): string
    {
        $code ??= self::newCode();
        $this->code_hash = self::hashCode($code);
        $this->code_hint = substr(self::normalize($code), 0, 5);

        return $code;
    }

    public static function findByCode(string $code): ?self
    {
        $normalized = self::normalize($code);
        if (strlen($normalized) < 10 || strlen($normalized) > 64) {
            return null;
        }

        return self::where('code_hash', self::hashCode($normalized))->first();
    }

    /** Why the invitation can't be used now, or null when it can. */
    public function problem(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => __('This invitation has been withdrawn.'),
            $this->expires_at !== null && $this->expires_at->isPast() => __('This invitation has expired.'),
            $this->max_uses !== null && $this->uses >= $this->max_uses => __('This invitation has already been used.'),
            default => null,
        };
    }

    public function isUsable(): bool
    {
        return $this->problem() === null;
    }

    /** @param Builder<Invitation> $query */
    public function scopeUsable(Builder $query): void
    {
        self::applyUsable($query);
    }

    /**
     * Limits a query to invitations that can be used now.
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public static function applyUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** @return BelongsToMany<MediaServer, $this> */
    public function mediaServers(): BelongsToMany
    {
        return $this->belongsToMany(MediaServer::class)->withPivot('library_ids');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int, string>|null the pivot's library IDs for a server */
    public function libraryIdsFor(MediaServer $server): ?array
    {
        $ids = $server->pivot->library_ids ?? null;
        $ids = is_string($ids) ? json_decode($ids, true) : $ids;

        return is_array($ids) && $ids !== [] ? array_values(array_map('strval', $ids)) : null;
    }
}
