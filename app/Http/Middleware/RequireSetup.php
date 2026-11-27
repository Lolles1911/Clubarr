<?php

namespace App\Http\Middleware;

use App\Services\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the first admin exists, everything leads to /setup; after that, /setup is gone. */
class RequireSetup
{
    /** Paths that work before setup: the page itself, Livewire, assets, health. */
    private const OPEN = ['setup', 'livewire*', 'up', 'css/*', 'js/*', 'fonts/*', 'favicon.ico', 'filament/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $done = app(SetupState::class)->isDone();
        if (! $done && ! $request->is(...self::OPEN)) {
            return redirect('/setup');
        }
        if ($done && $request->is('setup')) {
            abort(404);
        }

        return $next($request);
    }
}
