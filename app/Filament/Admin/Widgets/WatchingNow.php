<?php

namespace App\Filament\Admin\Widgets;

use App\Models\AuditLog;
use App\Services\Watching;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** The dashboard's lists: who watches now and the most watched (Tracearr), and the latest events. */
class WatchingNow extends Widget
{
    protected string $view = 'filament.admin.watching-now';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /** @return array{enabled: bool, now: list<array{who: string, title: string, player: string}>, top: list<array{title: string, plays: int, people: int}>} */
    public function watching(): array
    {
        $watching = app(Watching::class);

        return ['enabled' => $watching->enabled(), 'now' => $watching->everyoneNow(), 'top' => $watching->topTitles(8)];
    }

    /** @return Collection<int, AuditLog> */
    public function events(): Collection
    {
        return AuditLog::with('actor')->latest('id')->limit(8)->get();
    }
}
