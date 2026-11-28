<?php

namespace App\Filament\Admin\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;

/** Members over the last 12 weeks: how many in all, and how many joined each week. */
class MembersChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '240px';

    public function getHeading(): ?string
    {
        return __('Members, last 12 weeks');
    }

    protected function getData(): array
    {
        $weeks = collect(range(11, 0))->map(fn ($ago) => now()->startOfWeek()->subWeeks($ago));
        $created = User::members()->where('created_at', '>=', $weeks->first())->pluck('created_at');
        $before = User::members()->where('created_at', '<', $weeks->first())->count();
        $joined = $weeks->map(fn ($start) => $created->filter(fn ($at) => $at >= $start && $at < $start->copy()->addWeek())->count());
        $total = [];
        $running = $before;
        foreach ($joined as $n) {
            $running += $n;
            $total[] = $running;
        }

        return [
            'datasets' => [
                ['label' => __('Members'), 'data' => $total, 'tension' => 0.3],
                ['label' => __('Joined'), 'data' => $joined->all(), 'type' => 'bar'],
            ],
            'labels' => $weeks->map(fn ($w) => $w->translatedFormat('j M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
