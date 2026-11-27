<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\AccountStatus;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClubStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $soon = User::members()->whereNotNull('access_expires_at')->whereBetween('access_expires_at', [now(), now()->addDays(7)])->count();

        return [
            Stat::make(__('Members'), User::members()->whereNull('disabled_at')->count())
                ->description(__(':count joined in the last 30 days', ['count' => User::members()->where('created_at', '>=', now()->subDays(30))->count()])),
            Stat::make(__('Open invitations'), Invitation::applyUsable(Invitation::query())->count()),
            Stat::make(__('Waiting for the member'), ServerAccount::where('status', AccountStatus::Pending)->count())
                ->description(__('Silo links and Plex shares not taken yet')),
            Stat::make(__('Access ends within 7 days'), $soon)->color($soon > 0 ? 'warning' : 'gray'),
            Stat::make(__('Media servers'), MediaServer::where('is_enabled', true)->count())
                ->description(($bad = MediaServer::whereNotNull('last_error')->count()) > 0 ? __(':count with a problem', ['count' => $bad]) : __('All OK'))
                ->color($bad > 0 ? 'danger' : 'success'),
        ];
    }
}
