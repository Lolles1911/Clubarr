<?php

namespace App\Services;

use App\MediaServers\ServerHttp;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Where admin alerts go: a Discord webhook, ntfy, Telegram, Gotify and/or a
 * webhook of your own. Addresses and tokens are read when sending (they are
 * never queued), calls never follow redirects, and a failure says only which
 * channel failed, never an address or a token.
 */
final class AlertChannels
{
    public const NAMES = ['discord', 'ntfy', 'telegram', 'gotify', 'webhook'];

    public const TELEGRAM_TOKEN = '~^\d{5,15}:[A-Za-z0-9_-]{30,60}$~';

    public const TELEGRAM_CHAT = '~^(-?\d{1,20}|@[A-Za-z0-9_]{5,64})$~';

    /** @return array<string, string> name => label */
    public static function labels(): array
    {
        return ['discord' => 'Discord', 'ntfy' => 'ntfy', 'telegram' => 'Telegram', 'gotify' => 'Gotify', 'webhook' => __('Webhook')];
    }

    /** @return list<string> the channels that are set up */
    public static function configured(): array
    {
        $s = app(SiteSettings::class);
        $set = fn (string $key) => filled($s->get($key));

        return array_values(array_filter(self::NAMES, fn (string $name) => match ($name) {
            'discord' => AdminAlerts::webhook() !== null,
            'ntfy' => $set('alerts_ntfy_url'),
            'telegram' => preg_match(self::TELEGRAM_TOKEN, (string) $s->get('alerts_telegram_token')) === 1 && preg_match(self::TELEGRAM_CHAT, (string) $s->get('alerts_telegram_chat')) === 1,
            'gotify' => $set('alerts_gotify_url') && $set('alerts_gotify_token'),
            'webhook' => $set('alerts_hook_url'),
        }));
    }

    /** Throws RuntimeException naming only the channel. */
    public static function send(string $channel, string $text): void
    {
        $s = app(SiteSettings::class);
        $site = (string) $s->get('site_name');
        try {
            match ($channel) {
                'discord' => Http::timeout(10)->withoutRedirecting()->post((string) AdminAlerts::webhook(), [
                    'content' => mb_substr($text, 0, 1900),
                    'allowed_mentions' => ['parse' => []], // a name can't ping @everyone
                ])->throw(),
                'ntfy' => self::ntfy((string) $s->get('alerts_ntfy_url'), (string) $s->get('alerts_ntfy_token'), $site, $text),
                'telegram' => (new ServerHttp('https://api.telegram.org', [], 'Telegram'))->send('POST', '/bot'.$s->get('alerts_telegram_token').'/sendMessage',
                    json: ['chat_id' => (string) $s->get('alerts_telegram_chat'), 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true]),
                'gotify' => (new ServerHttp((string) $s->get('alerts_gotify_url'), ['X-Gotify-Key' => (string) $s->get('alerts_gotify_token')], 'Gotify'))
                    ->send('POST', '/message', json: ['title' => $site, 'message' => mb_substr($text, 0, 4000), 'priority' => 5]),
                'webhook' => self::webhook((string) $s->get('alerts_hook_url'), (string) $s->get('alerts_hook_secret'), $site, $text),
            };
        } catch (Throwable $e) {
            throw new RuntimeException(__(':channel did not take the message.', ['channel' => self::labels()[$channel] ?? $channel]).' ('.class_basename($e).')');
        }
    }

    /** The topic is the last part of the address, e.g. https://ntfy.sh/my-topic. */
    private static function ntfy(string $url, string $token, string $site, string $text): void
    {
        $url = rtrim(trim($url), '/');
        $cut = strrpos($url, '/');
        $topic = $cut === false ? '' : substr($url, $cut + 1);
        if ($cut === false || preg_match('/^[\w-]{1,64}$/', $topic) !== 1) {
            throw new RuntimeException('ntfy topic');
        }
        $headers = $token !== '' ? ['Authorization' => 'Bearer '.$token] : [];
        (new ServerHttp(substr($url, 0, $cut), $headers, 'ntfy'))->send('POST', '/', json: ['topic' => $topic, 'title' => $site, 'message' => mb_substr($text, 0, 4000)]);
    }

    /** JSON {site, text, time}; with a secret, X-Clubarr-Signature is sha256=HMAC of the body. */
    private static function webhook(string $url, string $secret, string $site, string $text): void
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('webhook address');
        }
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $payload = ['site' => $site, 'text' => $text, 'time' => now()->toIso8601String()];
        $headers = $secret !== '' ? ['X-Clubarr-Signature' => 'sha256='.hash_hmac('sha256', (string) json_encode($payload), $secret)] : [];
        (new ServerHttp($base, $headers, 'Webhook'))->send('POST', $parts['path'] ?? '/', json: $payload);
    }
}
