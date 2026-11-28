<?php

namespace App\Http\Controllers;

use App\Services\Watching;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pictures for members, served by Clubarr so their browsers never talk to
 * Tracearr, the media servers or TMDB: posters Watching listed, and TMDB
 * posters by their exact file name.
 */
class MediaImageController
{
    public function poster(string $key, Watching $watching): Response
    {
        abort_unless(preg_match('/^[a-f0-9]{20}$/', $key) === 1, 404);
        $image = $watching->poster($key);
        abort_if($image === null, 404);

        return $this->image($image['body'], $image['type']);
    }

    public function tmdb(string $file): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]{5,64}\.(jpg|png)$/', $file) === 1, 404);
        $image = Cache::remember('clubarr:tmdb:'.$file, 86400 * 7, function () use ($file) {
            try {
                $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->get('https://image.tmdb.org/t/p/w300/'.$file);
            } catch (Throwable) {
                return false;
            }
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            return $response->status() === 200 && in_array($type, ['image/jpeg', 'image/png'], true) ? ['type' => $type, 'body' => base64_encode($response->body())] : false;
        });
        abort_unless(is_array($image), 404);

        return $this->image(base64_decode($image['body']), $image['type']);
    }

    private function image(string $body, string $type): Response
    {
        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
