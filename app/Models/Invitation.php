<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int|null $max_streams
 * @property int|null $request_limit
 * @property bool $from_waitlist
 * @property string|null $greeting
 * @property bool $is_template
 * @property string|null $sent_to
 * @property Carbon|null $sent_at
 * @property Carbon|null $reminded_at
 * @property string|null $reminder_link
 * @property bool $awaiting_approval a member's invitation not approved yet
 * @property string|null $pending_code
 * @property string|null $pending_email
 * @property bool $by_member
 * @property bool $seerr_access
 * @property int|null $member_group_id
 * @property int|null $created_by
 * @property Carbon|null $revoked_at
 * @property-read Collection<int, MediaServer> $mediaServers
 */
class Invitation extends Model
{
    /** Letters and digits that can't be mistaken for each other. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = ['label', 'expires_at', 'max_uses', 'access_days', 'max_streams', 'request_limit', 'greeting', 'seerr_access', 'member_group_id'];

    protected $hidden = ['code_hash', 'reminder_link', 'pending_code', 'pending_email'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
            'access_days' => 'integer',
            'max_streams' => 'integer',
            'request_limit' => 'integer',
            'from_waitlist' => 'boolean',
            'is_template' => 'boolean',
            'by_member' => 'boolean',
            'seerr_access' => 'boolean',
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'reminder_link' => 'encrypted',
            'pending_code' => 'encrypted',
            'pending_email' => 'encrypted',
            'awaiting_approval' => 'boolean',
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
            $this->is_template => __('This invitation code is not valid.'),
            $this->revoked_at !== null => __('This invitation has been withdrawn.'),
            $this->awaiting_approval => __('This invitation waits for an admin to approve it. Try again later.'),
            $this->expires_at !== null && $this->expires_at->isPast() => __('This invitation has expired.'),
            $this->max_uses !== null && $this->uses >= $this->max_uses => __('This invitation has already been used.'),
            $this->exists && $this->mediaServers->where('is_enabled', true)->isEmpty() => __('This invitation has no servers right now. Ask the person who invited you.'),
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
        return $query->whereNull('revoked_at')->where('is_template', false)->where('awaiting_approval', false)
            ->whereHas('mediaServers', fn ($q) => $q->where('is_enabled', true))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** @return BelongsToMany<MediaServer, $this> */
    public function mediaServers(): BelongsToMany
    {
        return $this->belongsToMany(MediaServer::class)->withPivot('library_ids');
    }

    /** @return HasMany<ServerAccount, $this> the users made with this invitation */
    public function accounts(): HasMany
    {
        return $this->hasMany(ServerAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int, string>|null the pivot's library IDs for a server */
    public function libraryIdsFor(MediaServer $server): ?array
    {
        return self::libraryIds($server->pivot->library_ids ?? null);
    }

    /** @return array<int, string>|null library IDs as stored on the pivot (JSON), null = all */
    public static function libraryIds(mixed $raw): ?array
    {
        $ids = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($ids) && $ids !== [] ? array_values(array_map('strval', $ids)) : null;
    }
}
