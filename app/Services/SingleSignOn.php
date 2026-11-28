<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sign in with an OpenID Connect provider (Authentik, Authelia, Keycloak,
 * ...). Authorization code flow with PKCE and a one-time state, as a
 * confidential client: the code is exchanged server to server over https
 * with the client secret, and who signed in comes from the provider's
 * userinfo endpoint. Only existing Clubarr accounts can sign in (matched by
 * the provider's subject once linked, else by a verified email); SSO never
 * makes accounts. Providers must be on https.
 */
class SingleSignOn
{
    public static function enabled(): bool
    {
        $s = app(SiteSettings::class);

        return $s->get('oidc_enabled') === '1' && self::issuer() !== null && filled($s->get('oidc_client_id')) && filled($s->get('oidc_client_secret'));
    }

    public static function label(): string
    {
        return (string) (app(SiteSettings::class)->get('oidc_label') ?: __('Sign in with SSO'));
    }

    public static function forMembers(): bool
    {
        return app(SiteSettings::class)->get('oidc_members') === '1';
    }

    public static function redirectUri(): string
    {
        return route('filament.app.sso.callback');
    }

    private static function issuer(): ?string
    {
        $issuer = rtrim((string) app(SiteSettings::class)->get('oidc_issuer'), '/');

        return preg_match('~^https://[^\s/?#@]+(/[^\s?#]*)?$~', $issuer) ? $issuer : null;
    }

    /** The provider's endpoints, from its discovery document (kept an hour). @return array{authorization: string, token: string, userinfo: string} */
    public function endpoints(): array
    {
        $issuer = self::issuer() ?? throw new RuntimeException(__('Single sign-on is not set up.'));

        return Cache::remember('clubarr:oidc:'.md5($issuer), 3600, function () use ($issuer) {
            $doc = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            $endpoints = ['authorization' => $doc['authorization_endpoint'] ?? '', 'token' => $doc['token_endpoint'] ?? '', 'userinfo' => $doc['userinfo_endpoint'] ?? ''];
            if (rtrim((string) ($doc['issuer'] ?? ''), '/') !== $issuer) {
                throw new RuntimeException(__('The provider says it is someone else (issuer mismatch).'));
            }
            foreach ($endpoints as $url) {
                if (! str_starts_with((string) $url, 'https://')) {
                    throw new RuntimeException(__('The provider\'s addresses must be https.'));
                }
            }

            return $endpoints;
        });
    }

    /** Where to send the browser; the state, nonce and PKCE verifier stay in the session. */
    public function start(): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        session()->put('sso', ['state' => $state, 'verifier' => $verifier, 'at' => now()->getTimestamp()]);

        return $this->endpoints()['authorization'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => app(SiteSettings::class)->get('oidc_client_id'),
            'redirect_uri' => self::redirectUri(),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => Str::random(32),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Checks the answer and returns the Clubarr user who signed in; throws with a reason people can read. */
    public function finish(?string $state, ?string $code): User
    {
        $saved = session()->pull('sso');
        if (! is_array($saved) || ! is_string($state) || ! hash_equals((string) $saved['state'], $state) || now()->getTimestamp() - (int) $saved['at'] > 600 || ! is_string($code) || $code === '') {
            throw new RuntimeException(__('The sign-in was not started here, or took too long. Please try again.'));
        }
        $s = app(SiteSettings::class);
        $endpoints = $this->endpoints();
        try {
            $token = Http::asForm()->timeout(10)->withoutRedirecting()->acceptJson()->post($endpoints['token'], [
                'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
                'client_id' => $s->get('oidc_client_id'), 'client_secret' => $s->get('oidc_client_secret'), 'code_verifier' => $saved['verifier'],
            ])->throw()->json('access_token');
            $info = Http::timeout(10)->withoutRedirecting()->acceptJson()->withToken((string) $token)->get($endpoints['userinfo'])->throw()->json();
        } catch (Throwable) {
            throw new RuntimeException(__('The provider did not accept the sign-in. Please try again.'));
        }
        $sub = (string) ($info['sub'] ?? '');
        $email = mb_strtolower((string) ($info['email'] ?? ''));
        if ($sub === '') {
            throw new RuntimeException(__('The provider did not say who you are.'));
        }
        $user = User::where('oidc_sub', $sub)->first();
        if ($user === null && $email !== '' && ($info['email_verified'] ?? false) === true) {
            $user = User::where('email', $email)->whereNull('oidc_sub')->first();
            $user?->forceFill(['oidc_sub' => $sub])->save();
        }
        if ($user === null) {
            throw new RuntimeException(__('There is no account here for you. Members join with an invitation.'));
        }
        if ($user->disabled_at !== null || (! $user->is_admin && ! self::forMembers())) {
            throw new RuntimeException(__('Your account can\'t sign in this way.'));
        }
        Audit::log('auth.sso', $user, [], $user->id);

        return $user;
    }
}
