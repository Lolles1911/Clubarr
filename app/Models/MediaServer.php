<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\DriverFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ServerType $type
 * @property string $name
 * @property string $url
 * @property string|null $public_url
 * @property string $api_key
 * @property array<string, mixed>|null $settings
 * @property bool $is_enabled
 * @property int|null $capacity
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class MediaServer extends Model
{
    protected $fillable = ['type', 'name', 'url', 'public_url', 'api_key', 'settings', 'is_enabled', 'capacity'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'api_key' => 'encrypted',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'capacity' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<Library, $this> */
    public function libraries(): HasMany
    {
        return $this->hasMany(Library::class)->orderBy('name');
    }

    /** @return BelongsToMany<Invitation, $this> */
    public function invitations(): BelongsToMany
    {
        return $this->belongsToMany(Invitation::class)->withPivot('library_ids');
    }

    /** @return HasMany<ServerAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ServerAccount::class);
    }

    /** Members on it now (active, or still to finish a Silo link or Plex share). */
    public function seatsTaken(): int
    {
        return $this->accounts()->whereIn('status', [AccountStatus::Active, AccountStatus::Pending])->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->seatsTaken() >= $this->capacity;
    }

    public function driver(): MediaServerDriver
    {
        return app(DriverFactory::class)->for($this);
    }

    /** Where members open the server. */
    public function openUrl(): string
    {
        return $this->public_url ?: $this->url;
    }

    /** Where members watch: Plex opens this server's libraries in app.plex.tv; the others their address. */
    public function watchUrl(): string
    {
        if ($this->type !== ServerType::Plex) {
            return $this->openUrl();
        }
        $machine = (string) $this->setting('machine_id', '');

        return preg_match('/^[A-Za-z0-9]{8,64}$/', $machine) ? 'https://app.plex.tv/desktop/#!/media/'.$machine.'/com.plexapp.plugins.library' : 'https://app.plex.tv';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
