<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The API's only sign-in: "Authorization: Bearer clb_…". No sessions, no cookies. */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findByToken((string) $request->bearerToken());
        if ($key === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
