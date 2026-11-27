<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Two-factor sign-in for admins, unless turned off under Settings →
 * Security. Read on every request: Filament fixes its own "required" when
 * the routes are made (and the container caches routes), so the panel always
 * has it on and this decides.
 */
class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (app(SiteSettings::class)->get('admin_2fa_required') === '0') {
            return $next($request);
        }

        return app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle($request, $next);
    }
}
