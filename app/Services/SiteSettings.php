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
    public const SECRET = ['mail_password', 'tracearr_api_key', 'seerr_api_key', 'discord_client_secret', 'discord_bot_token', 'alerts_webhook_url'];

    public const DEFAULTS = [
        'site_name' => 'Clubarr',
        // Accent colour (#rrggbb; empty = violet).
        'brand_color' => '',
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
        // Admins must use two-factor sign-in ('1') or may ('0').
        'admin_2fa_required' => '1',
        // Tracearr: what plays, devices, stream limits, posters.
        'tracearr_url' => '',
        'tracearr_api_key' => '',
        // Overseerr/Jellyseerr: requests.
        'seerr_url' => '',
        'seerr_api_key' => '',
        // Discord: members link their account and get a role.
        'discord_client_id' => '',
        'discord_client_secret' => '',
        'discord_bot_token' => '',
        'discord_guild_id' => '',
        'discord_role_id' => '',
        // Waiting list: the invitation its invitations copy ('' = off).
        'waitlist_template_id' => '',
        // Alerts to the admins' Discord channel: the webhook (secret) and the events, comma separated.
        'alerts_webhook_url' => '',
        'alerts_events' => null,
        // Invitations: the welcome text on the invitation page, a reminder
        // mail after this many days when unused (0 = off), and members
        // inviting friends (invitations a month; 0 = off) from a template.
        'invite_welcome' => '',
        'invite_reminder_days' => '3',
        'member_invites_per_month' => '0',
        'member_invite_template_id' => '',
        // Ask GitHub once a day for a newer Clubarr ('1') or not ('0').
        'update_check' => '1',
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

    /** Reads the settings again on next use (a long-running queue worker between jobs). */
    public function refresh(): void
    {
        $this->values = null;
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
            // Pictures ("blob_" keys) are read on their own (Branding), not with every setting.
            $raw = Cache::remember('clubarr:settings', 60, fn () => Setting::where('key', 'not like', 'blob%')->pluck('value', 'key')->all());
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
