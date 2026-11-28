<?php

namespace App\Services;

use App\MediaServers\Drivers\PlexDriver;
use App\MediaServers\ServerHttp;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Find Plex": with the owner's token, plex.tv lists the account's servers
 * and every address it knows for them (like Tracearr does), and Clubarr
 * tries each one. Without a token, the private /24 network Clubarr is opened
 * on (e.g. 192.168.1.x) is asked on Plex's ports. Either way an address is
 * only asked for /identity, which Plex answers without a token: the token
 * goes to plex.tv only, and only the server's ID and version are read.
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

    /**
     * The servers the token's account owns, each address tried from here;
     * the ones that answer first, the fastest first.
     *
     * @return list<array{name: string, uri: string, local: bool, ok: bool, ms: int|null, version: string}>
     */
    public function fromAccount(string $token): array
    {
        $plexTv = new ServerHttp('https://plex.tv', PlexDriver::plexHeaders() + ['X-Plex-Token' => $token], 'plex.tv');
        $rows = [];
        foreach ($plexTv->json('GET', '/api/v2/resources', ['includeHttps' => 1, 'includeRelay' => 0]) as $resource) {
            if (! is_array($resource) || ! str_contains((string) ($resource['provides'] ?? ''), 'server') || empty($resource['owned'])) {
                continue;
            }
            foreach ($resource['connections'] ?? [] as $connection) {
                $uri = is_array($connection) ? (string) ($connection['uri'] ?? '') : '';
                if (empty($connection['relay']) && preg_match('~^https?://[^/@\s]+$~', $uri)) {
                    $rows[$uri] = ['name' => mb_substr((string) ($resource['name'] ?? 'Plex'), 0, 60), 'uri' => $uri, 'local' => ! empty($connection['local']),
                        'version' => mb_substr((string) ($resource['productVersion'] ?? ''), 0, 40)];
                }
            }
        }
        $answers = $this->probe(array_keys($rows), 4);
        $rows = array_map(fn (array $row) => $row + ['ok' => isset($answers[$row['uri']]), 'ms' => $answers[$row['uri']]['ms'] ?? null], array_values($rows));
        usort($rows, fn ($a, $b) => [! $a['ok'], ! $a['local'], $a['ms'] ?? PHP_INT_MAX] <=> [! $b['ok'], ! $b['local'], $b['ms'] ?? PHP_INT_MAX]);

        return $rows;
    }

    /** @return list<array{uri: string, version: string}> Plex servers found on the network, in address order */
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
            foreach ($this->probe($chunk, 2) as $uri => $answer) {
                $found[] = ['uri' => $uri, 'version' => $answer['version']];
            }
        }

        return $found;
    }

    /**
     * Asks each address for Plex's /identity at the same time.
     *
     * @param  list<string>  $uris
     * @return array<string, array{version: string, ms: int}> the addresses where Plex answered
     */
    private function probe(array $uris, int $timeout): array
    {
        if ($uris === []) {
            return [];
        }
        $answers = Http::pool(fn (Pool $pool) => array_map(
            fn (string $uri) => $pool->as($uri)->connectTimeout(min(2, $timeout))->timeout($timeout)->withoutRedirecting()
                ->accept('application/json')->get($uri.'/identity'),
            $uris,
        ));
        $found = [];
        foreach ($answers as $uri => $answer) {
            if ($answer instanceof Response && $answer->successful()) {
                $info = $answer->json('MediaContainer');
                if (is_array($info) && ! empty($info['machineIdentifier'])) {
                    $found[(string) $uri] = ['version' => mb_substr((string) ($info['version'] ?? ''), 0, 40),
                        'ms' => (int) round(((float) ($answer->handlerStats()['total_time'] ?? 0)) * 1000)];
                }
            }
        }

        return $found;
    }
}
