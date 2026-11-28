<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MediaServer;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The public front page at the site's address, when the admin turns it on
 * (Settings → General). Off, the address goes to sign-in as before. Signed-in
 * people never see it. Shows only what the admin chose: a text, the ways to
 * join that are open, posters without titles, and the servers' status.
 */
class Landing
{
    public static function enabled(): bool
    {
        return app(SiteSettings::class)->get('landing_enabled') === '1';
    }

    /** @return array<string, mixed> for the view */
    public function data(): array
    {
        $s = app(SiteSettings::class);
        $posters = [];
        if ($s->get('landing_posters') === '1') {
            try {
                $posters = app(NewOnServers::class)->forVisitors();
            } catch (Throwable) {
            }
        }

        return [
            'site' => (string) $s->get('site_name'),
            'logo' => Branding::logoUrl(),
            'color' => Theme::accentHex(),
            'theme' => Theme::current(),
            'font' => Theme::font(),
            'text' => trim((string) $s->get('landing_text')),
            'login' => Filament::getPanel('app')->getLoginUrl(),
            'apply' => Applications::enabled() ? route('filament.app.apply') : null,
            'waitlist' => Waitlist::enabled() ? route('filament.app.waitlist') : null,
            'posters' => $posters,
            'status' => $s->get('landing_status') === '1' ? $this->status() : null,
        ];
    }

    /** @return array{servers: list<array{name: string, state: string}>, maintenance: list<string>} */
    private function status(): array
    {
        /** @var Collection<int, Maintenance> $upcoming */
        $upcoming = Maintenance::upcoming();

        return [
            'servers' => MediaServer::where('is_enabled', true)->orderBy('name')->get()->map(fn (MediaServer $server) => [
                'name' => $server->name,
                'state' => $upcoming->contains(fn (Maintenance $m) => $m->isNow() && ($m->media_server_id === null || $m->media_server_id === $server->id))
                    ? 'maintenance' : ($server->last_error === null ? 'up' : 'down'),
            ])->all(),
            'maintenance' => $upcoming->reject(fn (Maintenance $m) => $m->isNow())->map(fn (Maintenance $m) => $m->describe())->values()->all(),
        ];
    }
}
