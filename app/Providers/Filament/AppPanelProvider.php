<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\ClaimAccount;
use App\Filament\App\Pages\Join;
use App\Filament\App\Pages\JoinWaitlist;
use App\Filament\App\Pages\MyAccess;
use App\Filament\App\Pages\Setup;
use App\Http\Controllers\DiscordLinkController;
use App\Http\Controllers\MediaImageController;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\Route;

/**
 * The members' side, at the site's root: sign-in, My access, profile (with
 * optional two-factor sign-in), plus the public join and setup pages. There
 * is no open registration: accounts are made by redeeming an invitation.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->default()
            ->id('app')
            ->path('')
            ->profile(EditProfile::class, isSimple: false)
            ->userMenuItems([
                'admin' => Action::make('admin')->label(fn () => __('Admin'))->icon('heroicon-o-shield-check')->url(fn () => url('/admin'))
                    ->visible(fn () => (bool) auth()->user()?->is_admin),
            ])
            ->pages([MyAccess::class])
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->routes(function () {
                Route::get('/join/{code?}', Join::class)->name('join')->where('code', '[A-Za-z0-9\-]{1,80}');
                Route::get('/setup', Setup::class)->name('setup');
                Route::get('/waitlist', JoinWaitlist::class)->name('waitlist');
                Route::get('/claim/{code}', ClaimAccount::class)->name('claim')->where('code', '[A-Za-z0-9\-]{1,80}');
            })
            ->authenticatedRoutes(function () {
                Route::get('/posters/{key}', [MediaImageController::class, 'poster'])->name('poster');
                Route::get('/tmdb/{file}', [MediaImageController::class, 'tmdb'])->name('tmdb');
                Route::get('/discord/connect', [DiscordLinkController::class, 'connect'])->name('discord.connect');
                Route::get('/discord/callback', [DiscordLinkController::class, 'callback'])->name('discord.callback');
                Route::post('/discord/disconnect', [DiscordLinkController::class, 'disconnect'])->name('discord.disconnect');
            })
            ->topNavigation();
    }
}
