<?php

namespace Tests\Feature\Integrations;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\DiscordSync;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscordTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettings::class)->set(['discord_client_id' => '111', 'discord_client_secret' => 'secret', 'discord_bot_token' => 'bot-token',
            'discord_guild_id' => '222', 'discord_role_id' => '333']);
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $admin->is_admin = true;
        $admin->save();
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'k']);
        $this->anna = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'Member-pass-1']);
        ServerAccount::create(['user_id' => $this->anna->id, 'media_server_id' => $server->id, 'remote_id' => 'a', 'remote_username' => 'anna', 'status' => AccountStatus::Active]);
    }

    public function test_the_callback_needs_the_state_from_this_session(): void
    {
        Http::fake();
        $this->actingAs($this->anna)->get('/discord/callback?code=abc&state=forged')->assertRedirect('/');
        $this->assertNull($this->anna->fresh()->discord_id);
        Http::assertNothingSent();
    }

    public function test_linking_gives_the_role_and_losing_access_takes_it(): void
    {
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'user-token']),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '444', 'username' => 'anna_d']),
            'discord.com/api/v10/guilds/*' => Http::response(null, 204),
        ]);
        $this->actingAs($this->anna)->get('/discord/connect')->assertRedirectContains('discord.com/oauth2/authorize');
        $state = session('discord.state');
        $this->actingAs($this->anna)->get('/discord/callback?code=abc&state='.$state)->assertRedirect('/');

        $this->assertSame(['444', 'anna_d'], [$this->anna->fresh()->discord_id, $this->anna->fresh()->discord_username]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/guilds/222/members/444/roles/333') && $r->header('Authorization')[0] === 'Bot bot-token');

        $this->anna->forceFill(['access_expires_at' => now()->subMinute()])->save();
        app(DiscordSync::class)->sync($this->anna->fresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/members/444/roles/333'));
    }

    public function test_an_account_linked_to_someone_else_is_refused(): void
    {
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x'])->forceFill(['discord_id' => '444'])->save();
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 't']),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '444', 'username' => 'bob_d']),
        ]);
        $this->actingAs($this->anna)->get('/discord/connect');
        $this->actingAs($this->anna)->get('/discord/callback?code=abc&state='.session('discord.state'));
        $this->assertNull($this->anna->fresh()->discord_id);
    }

    public function test_slash_commands_need_discords_signature_and_answer_only_the_member(): void
    {
        $pair = sodium_crypto_sign_keypair();
        app(SiteSettings::class)->set(['discord_public_key' => bin2hex(sodium_crypto_sign_publickey($pair))]);
        $send = function (array $body, bool $sign = true) use ($pair) {
            $json = json_encode($body);
            $time = (string) time();
            $sig = bin2hex(sodium_crypto_sign_detached($time.$json, sodium_crypto_sign_secretkey($pair)));

            return $this->call('POST', '/api/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => $sign ? $sig : str_repeat('0', 128), 'HTTP_X_SIGNATURE_TIMESTAMP' => $time], $json);
        };

        $send(['type' => 1], false)->assertUnauthorized();
        $send(['type' => 1])->assertOk()->assertExactJson(['type' => 1]);

        $this->anna->forceFill(['discord_id' => '444', 'access_expires_at' => now()->addDays(10)])->save();
        $send(['type' => 2, 'data' => ['name' => 'access'], 'member' => ['user' => ['id' => '444']]])->assertOk()
            ->assertJsonPath('data.flags', 64)->assertJsonPath('data.content', fn ($c) => str_contains($c, 'Home') && str_contains($c, 'ends on'));
        $send(['type' => 2, 'data' => ['name' => 'access'], 'member' => ['user' => ['id' => '999']]])
            ->assertJsonPath('data.content', fn ($c) => str_contains($c, 'Link your Discord'));
        $send(['type' => 2, 'data' => ['name' => 'status'], 'user' => ['id' => '999']])->assertJsonPath('data.content', fn ($c) => str_contains($c, 'Home'));
    }
}
