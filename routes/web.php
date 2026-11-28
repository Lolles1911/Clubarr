<?php

use App\Models\Maintenance;
use App\Models\MediaServer;
use App\Models\ServerCheck;
use App\Services\Branding;
use App\Services\SiteSettings;
use App\Services\Theme;
use Illuminate\Support\Facades\Route;

// Every page belongs to a Filament panel (app/Providers/Filament); except these few.

// The web app manifest: Clubarr on the home screen, with the site's name, colour and logo.
Route::get('/manifest.webmanifest', function () {
    $name = (string) app(SiteSettings::class)->get('site_name');
    $logo = Branding::logo();
    $icons = $logo !== null && in_array($logo['type'], ['image/png', 'image/webp'], true)
        ? [['src' => Branding::logoUrl(), 'sizes' => 'any', 'type' => $logo['type'], 'purpose' => 'any']]
        : [];
    $icons[] = ['src' => asset('clubarr-icon.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
    $icons[] = ['src' => asset('clubarr-icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];

    return response()->json([
        'name' => $name, 'short_name' => mb_substr($name, 0, 12), 'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
        'background_color' => Theme::current() === 'midnight' ? '#0f172a' : '#07070a', 'theme_color' => Theme::accentHex(), 'icons' => $icons,
    ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
})->name('manifest');

// The public status page, when it is on (Settings → Members): servers, uptime, planned maintenance.
Route::get('/status', function () {
    abort_unless(app(SiteSettings::class)->get('status_page') === '1', 404);
    $upcoming = Maintenance::upcoming();
    $servers = MediaServer::where('is_enabled', true)->orderBy('name')->get()->map(fn (MediaServer $s) => [
        'name' => $s->name,
        'state' => $upcoming->contains(fn (Maintenance $m) => $m->isNow() && ($m->media_server_id === null || $m->media_server_id === $s->id)) ? 'maintenance' : ($s->last_error === null ? 'up' : 'down'),
        'day' => ServerCheck::uptime($s, 1), 'month' => ServerCheck::uptime($s, 30),
    ])->all();

    return response()->view('status', ['site' => (string) app(SiteSettings::class)->get('site_name'), 'color' => Theme::accentHex(), 'font' => Theme::font(),
        'theme' => Theme::current(), 'servers' => $servers, 'maintenance' => $upcoming->reject(fn (Maintenance $m) => $m->isNow())->map(fn (Maintenance $m) => $m->describe())->values()->all()]);
})->name('status');
