<?php

namespace App\Services;

use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The look of every page (Settings → General → Theme): "streaming" (dark,
 * posters behind, glass cards), "midnight" (navy cards with bold colours,
 * like Wizarr) or "classic" (Filament's own, light or dark). Each has its
 * own stylesheet in public/css/themes and a default accent colour; the
 * admin's accent colour always wins.
 */
final class Theme
{
    public const THEMES = ['streaming', 'midnight', 'classic'];

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'streaming' => __('Streaming: dark, posters in the background, glass cards'),
            'midnight' => __('Midnight: navy cards and bold colours (like Wizarr)'),
            'classic' => __('Classic: light or dark, plain'),
        ];
    }

    public static function current(): string
    {
        $theme = (string) app(SiteSettings::class)->get('theme');

        return in_array($theme, self::THEMES, true) ? $theme : 'streaming';
    }

    /** Dark only, except Classic. */
    public static function forcesDark(): bool
    {
        return self::current() !== 'classic';
    }

    /** @return array<string, mixed> Filament colours */
    public static function colors(): array
    {
        $hex = (string) app(SiteSettings::class)->get('brand_color');
        $own = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? Color::hex($hex) : null;

        return match (self::current()) {
            'streaming' => ['primary' => $own ?? Color::Rose, 'gray' => Color::Neutral],
            'midnight' => ['primary' => $own ?? Color::hex('#f43f5e'), 'gray' => Color::Slate],
            default => ['primary' => $own ?? Color::Violet, 'gray' => Color::Zinc],
        };
    }

    /** The accent as #rrggbb, for pages and mails outside Filament: the admin's, or the theme's own. */
    public static function accentHex(): string
    {
        $hex = (string) app(SiteSettings::class)->get('brand_color');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return $hex;
        }

        return match (self::current()) {
            'streaming' => '#e11d48',
            'midnight' => '#f43f5e',
            default => '#7c3aed',
        };
    }

    public static function font(): string
    {
        return match (self::current()) {
            'streaming' => 'Outfit',
            'midnight' => 'Plus Jakarta Sans',
            default => 'Inter',
        };
    }

    /** The theme's stylesheet, or null (Classic has none). */
    public static function stylesheet(): ?string
    {
        return self::current() === 'classic' ? null : asset('css/themes/'.self::current().'.css').'?v='.config('clubarr.version');
    }

    /**
     * Posters behind sign-in and other simple pages (Streaming only): an
     * invitation page's own, or on the other pages the newest ones when the
     * admin shows posters on the front page. Never titles.
     */
    public static function backdrop(): HtmlString|string
    {
        if (self::current() !== 'streaming') {
            return '';
        }
        $keys = (array) session('join.poster_keys', []);
        if ($keys === [] && app(SiteSettings::class)->get('landing_posters') === '1') {
            try {
                $keys = array_column(app(NewOnServers::class)->forVisitors(18), 'key');
            } catch (Throwable) {
                $keys = [];
            }
        }
        if ($keys === []) {
            return '';
        }
        $images = collect($keys)->take(24)->map(fn ($key) => '<img src="'.e(route('filament.app.join-poster', ['key' => $key])).'" alt="" loading="lazy">')->implode('');

        return new HtmlString('<div class="cb-backdrop" aria-hidden="true">'.str_repeat($images, count($keys) < 12 ? 2 : 1).'</div>');
    }

    /** Lists shown as cards rather than tables (Streaming and Midnight). */
    public static function cards(): bool
    {
        return self::current() !== 'classic';
    }
}
