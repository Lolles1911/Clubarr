<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\ApplySiteLocale;
use App\Services\Branding;
use App\Services\SingleSignOn;
use App\Services\SiteSettings;
use App\Support\InitialsAvatar;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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
            // QR codes and sharing for invitation links (made in the browser; see public/js).
            // Single sign-on button on the sign-in pages, when set up.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => SingleSignOn::enabled()
                ? new HtmlString(Blade::render('<div style="margin-bottom:1.25rem"><x-filament::button tag="a" :href="$href" color="gray" icon="heroicon-m-key" class="w-full">{{ $label }}</x-filament::button></div>',
                    ['href' => route('filament.app.sso.redirect'), 'label' => SingleSignOn::label()])) : '')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<script src="'.e(asset('js/qrcode.js')).'?v='.e((string) config('clubarr.version')).'" defer></script>'
                .'<script src="'.e(asset('js/clubarr-share.js')).'?v='.e((string) config('clubarr.version')).'" defer></script>'))
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->login(Login::class)
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
