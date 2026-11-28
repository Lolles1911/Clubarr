<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\NavigationGroup;
use App\Models\ServerAccount;
use App\Services\Watching;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;

/** What is watched: hours per day, peak hours, devices, members and titles (Tracearr or Tautulli). */
class Stats extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Insights;

    protected string $view = 'filament.admin.stats';

    public int $days = 30;

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Statistics');
    }

    #[Unexposed]
    public function enabled(): bool
    {
        return app(Watching::class)->enabled();
    }

    /** @return array<string, mixed> */
    #[Unexposed]
    public function summary(): array
    {
        $days = in_array($this->days, [7, 30, 90, 365], true) ? $this->days : 30;

        return Cache::remember('clubarr:stats:'.$days, 600, function () use ($days) {
            $since = now()->subDays($days)->getTimestamp();
            $plays = array_values(array_filter(app(Watching::class)->yearPlays(), fn ($p) => (strtotime((string) ($p['started_at'] ?? '')) ?: 0) >= $since));
            $summary = Watching::summarize($plays, min($days, 90));
            // Members' names for "server type|user name".
            $names = ServerAccount::with(['user', 'mediaServer'])->whereNotNull('remote_username')->get()
                ->mapWithKeys(fn ($a) => [$a->mediaServer->type->value.'|'.strtolower((string) $a->remote_username) => $a->user->name])->all();
            $summary['people'] = collect($summary['people'])->take(10)->mapWithKeys(fn ($h, $key) => [($names[$key] ?? explode('|', $key)[1] ?? $key) => $h])->all();

            return $summary;
        });
    }
}
