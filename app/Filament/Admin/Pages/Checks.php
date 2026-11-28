<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Services\Checks as ChecksService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

/** Checks: what may be wrong or waiting, worst first, with where to fix it. */
class Checks extends Page
{
    use FullAdminsOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected string $view = 'filament.admin.checks';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Checks');
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Checks');
    }

    #[Unexposed]
    public static function getNavigationBadge(): ?string
    {
        // Kept five minutes: the menu is drawn on every admin page.
        $bad = (int) Cache::remember('clubarr:checks-badge', 300, fn () => collect(app(ChecksService::class)->all())->whereIn('level', ['bad', 'warn'])->count());

        return $bad > 0 ? (string) $bad : null;
    }

    #[Unexposed]
    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<array{level: string, title: string, detail: string, url: ?string}> */
    #[Unexposed]
    public function rows(): array
    {
        return app(ChecksService::class)->all();
    }
}
