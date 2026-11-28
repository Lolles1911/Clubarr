<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplySiteLocale;
use App\Services\Branding;
use App\Services\SiteSettings;
use App\Support\InitialsAvatar;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** What the admin panel and the members' panel have in common. */
final class PanelDefaults
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->brandName(fn () => app(SiteSettings::class)->get('site_name'))
            ->colors(fn () => Branding::colors())
            ->brandLogo(fn () => Branding::logoUrl())
            ->brandLogoHeight('2.25rem')
            ->darkMode(true)
            ->favicon(asset('clubarr-icon.svg'))
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->login()
            ->passwordReset()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->spa(false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                ApplySiteLocale::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
