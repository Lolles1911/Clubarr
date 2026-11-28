<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Find Plex on the network": asks every address of one private /24 network
 * (the one the admin opened Clubarr on, e.g. 192.168.1.x) on Plex's ports
 * for /identity, which a Plex server answers without a token. Only private
 * IPv4 networks are scanned, nothing is sent but the GET, and only the
 * machine ID and version are read from the answer.
 */
class PlexFinder
{
    public const PORTS = [32400, 32401];

    /** The network to scan: "192.168.1" from a private IPv4 address, else null. */
    public static function networkOf(?string $host): ?string
    {
        if ($host === null || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) !== false) {
            return null; // not IPv4, or a public address
        }

        return implode('.', array_slice(explode('.', $host), 0, 3));
    }

    /** @return list<array{uri: string, version: string}> Plex servers found, in address order */
    public function scan(string $network): array
    {
        $targets = [];
        foreach (range(1, 254) as $last) {
            foreach (self::PORTS as $port) {
                $targets[] = 'http://'.$network.'.'.$last.':'.$port;
            }
        }
        $found = [];
        foreach (array_chunk($targets, 254) as $chunk) {
            $answers = Http::pool(fn (Pool $pool) => array_map(
                fn (string $uri) => $pool->as($uri)->connectTimeout(1)->timeout(2)->withoutRedirecting()
                    ->accept('application/json')->get($uri.'/identity'),
                $chunk,
            ));
            foreach ($answers as $uri => $answer) {
                if ($answer instanceof Response && $answer->successful()) {
                    $info = $answer->json('MediaContainer');
                    if (is_array($info) && ! empty($info['machineIdentifier'])) {
                        $found[] = ['uri' => (string) $uri, 'version' => mb_substr((string) ($info['version'] ?? ''), 0, 40)];
                    }
                }
            }
        }

        return $found;
    }
}
