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
    public const SECRET = ['mail_password', 'tracearr_api_key', 'seerr_api_key', 'discord_client_secret', 'discord_bot_token', 'alerts_webhook_url', 'oidc_client_secret', 'tautulli_api_key', 'sonarr_api_key', 'radarr_api_key',
        'alerts_ntfy_url', 'alerts_ntfy_token', 'alerts_telegram_token', 'alerts_gotify_token', 'alerts_hook_url', 'alerts_hook_secret'];

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
        // The public front page (see Landing).
        'landing_enabled' => '0',
        'landing_text' => '',
        'landing_posters' => '0',
        'landing_status' => '1',
        // Admins must use two-factor sign-in ('1') or may ('0').
        'admin_2fa_required' => '1',
        // Mail on a sign-in from a new device ('1') or not ('0').
        'login_alerts' => '1',
        // Tracearr: what plays, devices, stream limits, posters.
        'tracearr_url' => '',
        'tracearr_api_key' => '',
        // Tautulli (Plex), used when Tracearr is not set up.
        'tautulli_url' => '',
        'tautulli_api_key' => '',
        // Sonarr and Radarr: "coming soon" for members.
        'sonarr_url' => '',
        'sonarr_api_key' => '',
        'radarr_url' => '',
        'radarr_api_key' => '',
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
        // Remove old data after these days (0: keep); see Retention.
        'retention_log_days' => '365',
        'retention_invitations_days' => '90',
        'retention_requests_days' => '180',
        // The member mails' words (see MailTexts); empty: Clubarr's own.
        'mail_text_welcome_subject' => '',
        'mail_text_welcome_body' => '',
        'mail_text_ending_soon_subject' => '',
        'mail_text_ending_soon_body' => '',
        'mail_text_ended_subject' => '',
        'mail_text_ended_body' => '',
        // More places for admin alerts (see AlertChannels).
        'alerts_ntfy_url' => '',
        'alerts_ntfy_token' => '',
        'alerts_telegram_token' => '',
        'alerts_telegram_chat' => '',
        'alerts_gotify_url' => '',
        'alerts_gotify_token' => '',
        'alerts_hook_url' => '',
        'alerts_hook_secret' => '',
        // Invitations: the welcome text on the invitation page, a reminder
        // mail after this many days when unused (0 = off), and members
        // inviting friends (invitations a month; 0 = off) from a template.
        'invite_welcome' => '',
        'invite_reminder_days' => '3',
        'member_invites_per_month' => '0',
        'member_invite_template_id' => '',
        // Members' invitations wait for an admin ('1') or work at once ('0').
        'member_invites_approval' => '0',
        // Automation: pause members who watched nothing for this many days (0 = off);
        // delete server users this many days after access ended (0 = off).
        'inactive_days' => '0',
        'cleanup_days' => '0',
        // Public "apply to join" page ('1' on), and the template an approved application's invitation copies.
        'apply_enabled' => '0',
        'apply_template_id' => '',
        'apply_questions' => '',
        // What applicants choose from: JSON [{label, description, template_id}].
        'apply_packages' => '',
        // Members may link a Jellyfin/Emby user they already have ('1') or not ('0').
        'link_existing' => '1',
        // Extra users a member may make for their family (0: off).
        'extra_users_max' => '0',
        // Days a member may pause their own access (0: off).
        'self_pause_days' => '0',
        // Servers members may move to themselves (comma-separated IDs; empty: off).
        'switch_servers' => '',
        // Discord's public key for /commands (interactions).
        'discord_public_key' => '',
        // Single sign-on (OpenID Connect): on ('1'), the provider, the client, the button, and whether members may use it too.
        'oidc_enabled' => '0',
        'oidc_issuer' => '',
        'oidc_client_id' => '',
        'oidc_client_secret' => '',
        'oidc_label' => '',
        'oidc_members' => '0',
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
