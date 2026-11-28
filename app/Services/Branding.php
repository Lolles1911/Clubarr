<?php

namespace App\Services;

use App\Models\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The site's own look: an accent colour and a logo (Settings → General).
 * The logo lives in the database (so it is in backups), is only PNG, JPEG
 * or WebP (no SVG, which can carry scripts), and is served by Clubarr.
 */
class Branding
{
    public const MAX_BYTES = 300 * 1024;

    private const LOGO_KEY = 'blob_logo';

    /** @return array<string, mixed> Filament colours for the panels */
    public static function colors(): array
    {
        $hex = (string) app(SiteSettings::class)->get('brand_color');

        return ['primary' => preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? Color::hex($hex) : Color::Violet, 'gray' => Color::Zinc];
    }

    public static function logoUrl(): ?string
    {
        $version = app(SiteSettings::class)->get('internal_logo_version');

        return $version ? route('filament.app.logo', ['v' => $version]) : null;
    }

    /** @return array{type: string, body: string}|null */
    public static function logo(): ?array
    {
        $stored = Cache::remember('clubarr:logo', 3600, fn () => Setting::where('key', self::LOGO_KEY)->value('value') ?? false);
        $logo = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($logo) && isset($logo['type'], $logo['body']) ? ['type' => $logo['type'], 'body' => (string) base64_decode($logo['body'])] : null;
    }

    /** Checks and keeps a new logo; throws with a reason the admin can read. */
    public static function storeLogo(string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException(__('The logo must be at most :kb KB.', ['kb' => self::MAX_BYTES / 1024]));
        }
        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? $info['mime'] : '';
        if (! in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000) {
            throw new RuntimeException(__('The logo must be a PNG, JPEG or WebP picture.'));
        }
        Setting::updateOrCreate(['key' => self::LOGO_KEY], ['value' => json_encode(['type' => $type, 'body' => base64_encode($bytes)])]);
        app(SiteSettings::class)->set(['internal_logo_version' => substr(sha1($bytes), 0, 10)]);
        Cache::forget('clubarr:logo');
    }

    public static function removeLogo(): void
    {
        Setting::where('key', self::LOGO_KEY)->delete();
        app(SiteSettings::class)->set(['internal_logo_version' => '']);
        Cache::forget('clubarr:logo');
    }
}
