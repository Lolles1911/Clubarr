<?php

use App\Http\Middleware\RequireSetup;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ShowLandingPage;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([SecurityHeaders::class, RequireSetup::class]);
        // The front page is for visitors: it must run after the session starts, before sign-in is required.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ShowLandingPage::class);
        // Behind a reverse proxy: TRUSTED_PROXIES lists it, so the real
        // client IP (for rate limits and the audit log) and https are seen.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
