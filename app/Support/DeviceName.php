<?php

namespace App\Support;

/** A short, plain name for a browser's user agent ("Firefox on Windows"), for people to recognise their devices. */
final class DeviceName
{
    private const BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser/' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];

    private const SYSTEMS = ['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'Mac',
        'Macintosh' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'];

    public static function from(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = self::first(self::BROWSERS, $ua);
        $system = self::first(self::SYSTEMS, $ua);
        if ($browser === null && $system === null) {
            return __('Unknown device');
        }

        return $browser !== null && $system !== null ? __(':browser on :system', ['browser' => $browser, 'system' => $system]) : (string) ($browser ?? $system);
    }

    /** @param array<string, string> $names */
    private static function first(array $names, string $ua): ?string
    {
        foreach ($names as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
