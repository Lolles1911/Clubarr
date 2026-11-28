<?php

namespace App\Http\Controllers;

use App\Services\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where plex.tv sends the sign-in window after the PIN is approved. A popup
 * closes itself (the page that opened it is still waiting); a phone tab has
 * no opener, so it goes back to the page that started the sign-in. That page
 * is a path on this site, kept in the session.
 */
class PlexReturnController
{
    public function __invoke(Request $request): Response
    {
        $back = (string) $request->session()->get('plex.return', '/');

        return response()->view('plex-return', [
            'back' => url(str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/'),
            'site' => app(SiteSettings::class)->get('site_name') ?: 'Clubarr',
        ]);
    }
}
