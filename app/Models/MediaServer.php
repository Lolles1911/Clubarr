<?php

namespace App\Models;

use App\Enums\ServerType;
use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\DriverFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class MediaServer extends Model
{
    protected $fillable = ['type', 'name', 'url', 'public_url', 'api_key', 'settings', 'is_enabled'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'api_key' => 'encrypted',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<Library, $this> */
    public function libraries(): HasMany
    {
        return $this->hasMany(Library::class)->orderBy('name');
    }

    /** @return HasMany<ServerAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ServerAccount::class);
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

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
