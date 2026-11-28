<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireAdminTwoFactor;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * /admin: only for admins. Two-factor sign-in is required (set up on the
 * first sign-in) unless turned off under Settings → Security; see
 * RequireAdminTwoFactor.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('admin')
            ->path('admin')
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireAdminTwoFactor::class)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->pages([Dashboard::class])
            ->userMenuItems([
                'members' => Action::make('members')->label(fn () => __('My access'))->icon('heroicon-o-play-circle')->url(fn () => url('/')),
            ])
            ->databaseNotifications(false);
    }
}
