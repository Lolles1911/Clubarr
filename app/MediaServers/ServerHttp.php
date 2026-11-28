<?php

namespace App\MediaServers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one way Clubarr talks to a media server: a fixed base address (http or
 * https, no credentials in it), short timeouts, no redirects (so a server
 * can't send the key on to somewhere else), and errors without secrets.
 */
final class ServerHttp
{
    private readonly string $base;

    /** @param array<string, string> $headers sent with every call, e.g. the key */
    public function __construct(string $baseUrl, private readonly array $headers, private readonly string $name)
    {
        $this->base = self::checkedBase($baseUrl);
    }

    /** The address, checked: http(s), a host, no user:password, no query. */
    public static function checkedBase(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MediaServerException(__('The address must start with http:// or https:// and have no user name, password or "?" in it.'));
        }

        return rtrim(trim($url), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $json
     * @param  array<string, string>  $headers  extra headers for this call
     */
    public function send(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): Response
    {
        $url = $this->base.'/'.ltrim($path, '/');
        try {
            $response = $this->pending($headers)->send(strtoupper($method), $url, array_filter([
                'query' => $query ?: null,
                'json' => $json,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException) {
            throw new MediaServerException(__('Could not reach :name at :url.', ['name' => $this->name, 'url' => $this->base]));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new MediaServerException(__(':name refused the key (:status). Check the key, and that it has admin rights.', ['name' => $this->name, 'status' => $response->status()]), $response->status());
        }
        if ($response->status() >= 300) {
            throw new MediaServerException(__(':name answered :status for :method :path: :detail', [
                'name' => $this->name, 'status' => $response->status(), 'method' => strtoupper($method), 'path' => '/'.ltrim($path, '/'), 'detail' => self::detail($response),
            ]), $response->status());
        }

        return $response;
    }

    /** @return array<mixed> */
    public function json(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        $data = $this->send($method, $path, $query, $json, $headers)->json();

        return is_array($data) ? $data : [];
    }

    /** @param array<string, string> $headers */
    private function pending(array $headers): PendingRequest
    {
        return Http::withHeaders($headers + $this->headers + ['Accept' => 'application/json'])
            ->withUserAgent('Clubarr/'.config('clubarr.version'))
            ->timeout((int) config('clubarr.http_timeout', 15))
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /** A short, single-line reason from the answer, without anything that looks like a key. */
    private static function detail(Response $response): string
    {
        $body = $response->json();
        $text = is_array($body) ? (string) ($body['detail'] ?? $body['title'] ?? $body['message'] ?? $body['error'] ?? '') : '';
        $text = $text !== '' ? $text : strip_tags((string) $response->body());
        $text = preg_replace('/[A-Za-z0-9_\-]{24,}/', '…', $text) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 200) ?: __('no reason given');
    }
}
