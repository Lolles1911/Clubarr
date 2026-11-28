<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * A member (or admin) of the club. Members are made only by redeeming an
 * invitation; admins in the setup or by another admin.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property string|null $admin_role null: a full admin; 'moderator' (see AdminRole)
 * @property Carbon|null $self_paused_at
 * @property Carbon|null $self_pause_until
 * @property Carbon|null $access_expires_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $expiry_warned_at
 * @property Carbon|null $last_login_at
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 * @property int|null $max_streams
 * @property int|null $request_limit
 * @property int|null $seerr_user_id
 * @property string|null $discord_id
 * @property string|null $discord_username
 * @property bool $digest
 * @property Carbon|null $digest_sent_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $inactive_warned_at
 * @property string|null $oidc_sub
 * @property bool $seerr_access
 * @property string|null $admin_note
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Only what a person may set about themselves; is_admin etc. are set explicitly. */
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'oidc_sub', 'admin_note'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'access_expires_at' => 'datetime',
            'self_paused_at' => 'datetime',
            'self_pause_until' => 'datetime',
            'expiry_warned_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'max_streams' => 'integer',
            'request_limit' => 'integer',
            'seerr_user_id' => 'integer',
            'digest' => 'boolean',
            'seerr_access' => 'boolean',
            'digest_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'inactive_warned_at' => 'datetime',
        ];
    }

    /** @return HasMany<ServerAccount, $this> */
    public function serverAccounts(): HasMany
    {
        return $this->hasMany(ServerAccount::class);
    }

    /** @return HasMany<ExtraUser, $this> extra users for the member's family */
    public function extraUsers(): HasMany
    {
        return $this->hasMany(ExtraUser::class);
    }

    /** @return BelongsToMany<MovieNight, $this> movie nights they are coming to */
    public function movieNights(): BelongsToMany
    {
        return $this->belongsToMany(MovieNight::class);
    }

    /** @return HasMany<KnownDevice, $this> */
    public function knownDevices(): HasMany
    {
        return $this->hasMany(KnownDevice::class);
    }

    /** @return BelongsToMany<MemberGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MemberGroup::class);
    }

    /** @return HasMany<MediaRequest, $this> */
    public function mediaRequests(): HasMany
    {
        return $this->hasMany(MediaRequest::class);
    }

    /** Has access now: not disabled, not ended, and at least one user on a server. */
    public function hasActiveAccess(): bool
    {
        return $this->disabled_at === null && ! $this->hasAccessExpired()
            && $this->serverAccounts()->where('status', AccountStatus::Active)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->disabled_at !== null) {
            return false;
        }

        // (bool): a user made in this request has no is_admin attribute loaded yet.
        return $panel->getId() === 'admin' ? (bool) $this->is_admin : true;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function hasAccessExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    /** @param Builder<User> $query */
    public function scopeMembers(Builder $query): void
    {
        $query->where('is_admin', false);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
