<?php

namespace App\Http\Middleware;

use App\Services\Landing;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Visitors who are not signed in see the front page at "/" when it is on; everyone else goes on as before. */
class ShowLandingPage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->path() === '/' && ! Filament::auth()->check() && Landing::enabled()) {
            // It runs before ApplySiteLocale, so in the site's language here too.
            return (new ApplySiteLocale)->handle($request, fn () => response()->view('landing', app(Landing::class)->data()));
        }

        return $next($request);
    }
}
