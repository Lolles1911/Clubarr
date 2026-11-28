<?php

namespace App\Integrations;

use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Services\SiteSettings;
use Illuminate\Support\Facades\Http;

/**
 * Discord: members link their account (OAuth, "identify" only: their ID and
 * name), and your bot gives them a role while they have access and sends a
 * message when it ends.
 */
class Discord
{
    private const API = 'https://discord.com/api/v10';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $botToken,
        private string $guildId,
        private string $roleId,
    ) {}

    public static function fromSettings(): ?self
    {
        $s = app(SiteSettings::class);
        $values = array_map(fn ($k) => (string) $s->get($k), ['discord_client_id', 'discord_client_secret', 'discord_bot_token', 'discord_guild_id', 'discord_role_id']);

        return in_array('', array_slice($values, 0, 3), true) ? null : new self(...$values);
    }

    public function hasRole(): bool
    {
        return ctype_digit($this->guildId) && ctype_digit($this->roleId);
    }

    public static function redirectUrl(): string
    {
        return url('/discord/callback');
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => $this->clientId, 'response_type' => 'code', 'scope' => 'identify',
            'redirect_uri' => self::redirectUrl(), 'state' => $state, 'prompt' => 'none',
        ]);
    }

    /** The Discord account behind an OAuth code: [id, username]. The token is used once and thrown away. */
    public function accountFromCode(string $code): array
    {
        try {
            $token = Http::asForm()->timeout(10)->withOptions(['allow_redirects' => false])->post(self::API.'/oauth2/token', [
                'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'grant_type' => 'authorization_code',
                'code' => $code, 'redirect_uri' => self::redirectUrl(),
            ]);
        } catch (\Throwable) {
            throw new MediaServerException(__('Discord could not be reached. Please try again.'));
        }
        $access = (string) ($token->json('access_token') ?? '');
        if (! $token->successful() || $access === '') {
            throw new MediaServerException(__('Discord did not accept the sign-in. Please try again.'));
        }
        $me = (new ServerHttp(self::API, ['Authorization' => 'Bearer '.$access], 'Discord'))->json('GET', '/users/@me');
        if (! ctype_digit((string) ($me['id'] ?? ''))) {
            throw new MediaServerException(__('Discord did not tell who you are. Please try again.'));
        }

        return [(string) $me['id'], (string) ($me['global_name'] ?? $me['username'] ?? '')];
    }

    public function addRole(string $userId): void
    {
        $this->bot()->send('PUT', '/guilds/'.$this->id($this->guildId).'/members/'.$this->id($userId).'/roles/'.$this->id($this->roleId));
    }

    public function removeRole(string $userId): void
    {
        try {
            $this->bot()->send('DELETE', '/guilds/'.$this->id($this->guildId).'/members/'.$this->id($userId).'/roles/'.$this->id($this->roleId));
        } catch (MediaServerException $e) {
            if ($e->status !== 404) {
                throw $e; // 404: not on the server, nothing to remove
            }
        }
    }

    public function sendDirectMessage(string $userId, string $text): void
    {
        $channel = $this->bot()->json('POST', '/users/@me/channels', json: ['recipient_id' => $this->id($userId)]);
        $this->bot()->send('POST', '/channels/'.$this->id((string) ($channel['id'] ?? '')).'/messages', json: ['content' => mb_substr($text, 0, 2000)]);
    }

    /** Checks the bot token: the bot's name. */
    public function botName(): string
    {
        return (string) ($this->bot()->json('GET', '/users/@me')['username'] ?? '?');
    }

    private function bot(): ServerHttp
    {
        return new ServerHttp(self::API, ['Authorization' => 'Bot '.$this->botToken], 'Discord');
    }

    /** Discord IDs are numbers; anything else never reaches an address. */
    private function id(string $id): string
    {
        if (! ctype_digit($id)) {
            throw new MediaServerException(__('A Discord ID must be a number.'));
        }

        return $id;
    }
}
