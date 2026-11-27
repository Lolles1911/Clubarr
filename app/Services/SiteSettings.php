<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * The site's settings (name, mail, ...), kept in the database. The keys in
 * SECRET are stored encrypted. Read through a short cache.
 */
class SiteSettings
{
    public const SECRET = ['mail_password'];

    public const DEFAULTS = [
        'site_name' => 'Clubarr',
        'locale' => 'en',
        'mail_enabled' => '0',
        'mail_host' => '',
        'mail_port' => '587',
        'mail_encryption' => 'tls',
        'mail_username' => '',
        'mail_password' => '',
        'mail_from_address' => '',
        'mail_from_name' => '',
        'help_text' => '',
    ];

    private ?array $values = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->all()[$key] ?? null;

        return $value === null || $value === '' ? ($default ?? (self::DEFAULTS[$key] ?? null)) : $value;
    }

    /** @param array<string, string|null> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) && ! str_starts_with($key, 'internal_')) {
                continue; // only known settings
            }
            $value = $value === null ? null : (string) $value;
            Setting::updateOrCreate(['key' => $key], ['value' => $value !== null && $value !== '' && in_array($key, self::SECRET, true) ? Crypt::encryptString($value) : $value]);
        }
        $this->forget();
    }

    public function forget(): void
    {
        $this->values = null;
        Cache::forget('clubarr:settings');
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        try {
            $raw = Cache::remember('clubarr:settings', 60, fn () => Setting::pluck('value', 'key')->all());
        } catch (Throwable) {
            return []; // before the database exists
        }
        foreach (self::SECRET as $key) {
            if (! empty($raw[$key])) {
                try {
                    $raw[$key] = Crypt::decryptString($raw[$key]);
                } catch (Throwable) {
                    $raw[$key] = null; // made with another APP_KEY
                }
            }
        }

        return $this->values = $raw;
    }

    /** This installation's ID towards plex.tv, made once. */
    public function plexClientId(): string
    {
        $id = $this->get('internal_plex_client_id');
        if ($id === null) {
            $id = 'clubarr-'.Str::uuid();
            $this->set(['internal_plex_client_id' => $id]);
        }

        return $id;
    }
}
