<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Once a day: is there a newer Clubarr release on GitHub? Only the public
 * releases list is read; nothing about this installation is sent. Can be
 * turned off under Settings → General.
 */
class UpdateCheck
{
    public const RELEASES = 'https://api.github.com/repos/Lolles1911/Clubarr/releases?per_page=20';

    /** The newest release when it is newer than this one: [version, url], else null. */
    public static function available(): ?array
    {
        $s = app(SiteSettings::class);
        $latest = (string) $s->get('internal_latest_version');

        return $latest !== '' && version_compare($latest, (string) config('clubarr.version'), '>')
            ? ['version' => $latest, 'url' => (string) $s->get('internal_latest_url')] : null;
    }

    public function run(): ?array
    {
        $s = app(SiteSettings::class);
        if ($s->get('update_check') === '0') {
            return null;
        }
        try {
            $releases = Http::timeout(10)->withoutRedirecting()->acceptJson()->get(self::RELEASES)->throw()->json();
        } catch (Throwable) {
            return null;
        }
        $newest = null;
        foreach (is_array($releases) ? $releases : [] as $release) {
            $version = ltrim((string) ($release['tag_name'] ?? ''), 'vV');
            if (! is_array($release) || ! empty($release['draft']) || ! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)) {
                continue;
            }
            if ($newest === null || version_compare($version, $newest['version'], '>')) {
                $url = (string) ($release['html_url'] ?? '');
                $newest = ['version' => $version, 'url' => str_starts_with($url, 'https://github.com/') ? $url : 'https://github.com/Lolles1911/Clubarr/releases'];
            }
        }
        if ($newest === null || $newest['version'] === $s->get('internal_latest_version')) {
            return self::available();
        }
        $s->set(['internal_latest_version' => $newest['version'], 'internal_latest_url' => $newest['url']]);
        if (version_compare($newest['version'], (string) config('clubarr.version'), '>')) {
            Audit::log('clubarr.new_version', null, $newest);
        }

        return self::available();
    }
}
