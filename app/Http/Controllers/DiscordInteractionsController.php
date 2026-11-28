<?php

namespace App\Http\Controllers;

use App\Models\Maintenance;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\MemberHistory;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Discord's slash commands (/status, /access, /stats, /password), sent by
 * Discord to this address. Every call is checked with Discord's signature
 * (Ed25519 and the application's public key); answers are only shown to the
 * one who asked, and only to members who linked Discord here.
 */
class DiscordInteractionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) app(SiteSettings::class)->get('discord_public_key');
        $signature = (string) $request->header('X-Signature-Ed25519');
        $timestamp = (string) $request->header('X-Signature-Timestamp');
        if (! self::verified($key, $signature, $timestamp, $request->getContent())) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $type = (int) $request->input('type');
        if ($type === 1) {
            return response()->json(['type' => 1]); // Discord checking the address
        }
        if ($type !== 2) {
            return response()->json(['message' => 'Not handled.'], 400);
        }
        $discordId = (string) ($request->input('member.user.id') ?? $request->input('user.id') ?? '');
        $member = ctype_digit($discordId) ? User::where('discord_id', $discordId)->first() : null;

        return self::reply(match ((string) $request->input('data.name')) {
            'status' => $this->status(),
            'access' => $member ? $this->access($member) : $this->notLinked(),
            'stats' => $member ? $this->stats($member) : $this->notLinked(),
            'password' => $member ? __('Change your password for watching on your page: :url', ['url' => url('/')]) : $this->notLinked(),
            default => __('I don\'t know that one.'),
        });
    }

    public static function verified(string $publicKey, string $signature, string $timestamp, string $body): bool
    {
        if (! preg_match('/^[0-9a-f]{64}$/i', $publicKey) || ! preg_match('/^[0-9a-f]{128}$/i', $signature) || $timestamp === '' || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached((string) hex2bin($signature), $timestamp.$body, (string) hex2bin($publicKey));
        } catch (Throwable) {
            return false;
        }
    }

    private static function reply(string $text): JsonResponse
    {
        // 4: a message; 64: only the one who asked sees it. No mentions.
        return response()->json(['type' => 4, 'data' => ['content' => mb_substr($text, 0, 1900), 'flags' => 64, 'allowed_mentions' => ['parse' => []]]]);
    }

    private function notLinked(): string
    {
        return __('Link your Discord account on your page first: :url', ['url' => Filament::getPanel('app')->getProfileUrl(['tab' => 'settings']) ?? url('/')]);
    }

    private function status(): string
    {
        return MediaServer::where('is_enabled', true)->orderBy('name')->get()->map(fn (MediaServer $s) => (Maintenance::activeFor($s) ? '🛠 ' : ($s->last_error === null ? '🟢 ' : '🔴 ')).$s->name
            .(Maintenance::activeFor($s) ? ' ('.__('maintenance').')' : ($s->last_error === null ? '' : ' ('.__('not answering').')')))->implode("\n") ?: __('No servers.');
    }

    private function access(User $member): string
    {
        $servers = $member->serverAccounts()->with('mediaServer')->get()->map(fn ($a) => $a->mediaServer->name.' ('.$a->status->value.')')->implode(', ');

        return __('Servers: :servers', ['servers' => $servers ?: '—'])."\n"
            .($member->access_expires_at ? __('Your access ends on :date.', ['date' => $member->access_expires_at->translatedFormat(__('j F Y'))]) : __('Your access has no end date.'));
    }

    private function stats(User $member): string
    {
        $stats = app(MemberHistory::class)->stats($member);
        if ($stats === null) {
            return __('No numbers yet.');
        }

        return __('Last 7 days: :h7 h. Last 30 days: :h30 h, :plays plays.', ['h7' => $stats['hours7'], 'h30' => $stats['hours30'], 'plays' => $stats['plays30']])
            .($stats['top'] ? "\n".__('Most watched this year: :title', ['title' => $stats['top']]) : '');
    }
}
