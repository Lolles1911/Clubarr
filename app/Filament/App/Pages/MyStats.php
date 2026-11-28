<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Services\MemberHistory;
use App\Services\Watching;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/** A member's own numbers: the last 30 days and "your year". Only their own plays. */
class MyStats extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.app.my-stats';

    protected static ?string $slug = 'my-stats';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('My statistics');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('My statistics');
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(MemberHistory::class)->available($user);
    }

    /** @return array{month: array<string, mixed>, year: array<string, mixed>} */
    #[Unexposed]
    public function numbers(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        try {
            $year = app(MemberHistory::class)->yearPlays($user);
        } catch (Throwable) {
            $year = [];
        }
        $monthAgo = now()->subDays(30)->getTimestamp();

        return [
            'month' => Watching::summarize(array_values(array_filter($year, fn ($p) => (strtotime((string) ($p['started_at'] ?? '')) ?: 0) >= $monthAgo)), 30),
            'year' => Watching::summarize($year, 30),
        ];
    }
}
