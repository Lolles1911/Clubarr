<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySiteLocale
{
    public const LOCALES = ['en' => 'English', 'da' => 'Dansk'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = app(SiteSettings::class)->get('locale');
        if (isset(self::LOCALES[$locale])) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
