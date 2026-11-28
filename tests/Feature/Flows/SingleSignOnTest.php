<?php

namespace Tests\Feature\Flows;

use App\Models\User;
use App\Services\SingleSignOn;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SingleSignOnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Admin-pass-1234']);
        $this->admin->is_admin = true;
        $this->admin->save();
        app(SiteSettings::class)->set(['oidc_enabled' => '1', 'oidc_issuer' => 'https://auth.example.com/o/clubarr', 'oidc_client_id' => 'clubarr', 'oidc_client_secret' => 'shh']);
    }

    private function provider(array $userinfo): void
    {
        Http::fake([
            'auth.example.com/o/clubarr/.well-known/openid-configuration' => Http::response(['issuer' => 'https://auth.example.com/o/clubarr',
                'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'userinfo_endpoint' => 'https://auth.example.com/userinfo']),
            'auth.example.com/token' => Http::response(['access_token' => 'at-123', 'token_type' => 'Bearer']),
            'auth.example.com/userinfo' => Http::response($userinfo),
        ]);
    }

    private function signIn(?string $state = null)
    {
        $location = $this->get('/sso/redirect')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);

        return $this->get('/sso/callback?'.http_build_query(['state' => $state ?? $query['state'], 'code' => 'the-code']));
    }

    public function test_an_admin_signs_in_by_verified_email_and_is_linked(): void
    {
        $this->provider(['sub' => 'abc', 'email' => 'Admin@Example.com', 'email_verified' => true]);

        $this->signIn()->assertRedirect(url('/admin'));

        $this->assertSame($this->admin->id, Auth::id());
        $this->assertSame('abc', $this->admin->fresh()->oidc_sub);
        Http::assertSent(fn ($r) => $r->url() === 'https://auth.example.com/token' && $r['client_secret'] === 'shh' && isset($r['code_verifier']));
    }

    public function test_a_wrong_state_unverified_email_or_unknown_person_gets_nowhere(): void
    {
        $this->provider(['sub' => 'abc', 'email' => 'admin@example.com', 'email_verified' => true]);
        $this->signIn('forged-state');
        $this->assertGuest();

        Http::swap(new Factory);
        $this->provider(['sub' => 'xyz', 'email' => 'admin@example.com', 'email_verified' => false]);
        $this->signIn();
        $this->assertGuest();
        $this->assertNull($this->admin->fresh()->oidc_sub);

        Http::swap(new Factory);
        $this->provider(['sub' => 'new', 'email' => 'stranger@example.com', 'email_verified' => true]);
        $this->signIn();
        $this->assertGuest();
        $this->assertSame(1, User::count()); // nobody was made
    }

    public function test_members_only_when_turned_on(): void
    {
        $member = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'x']);
        $this->provider(['sub' => 'm1', 'email' => 'anna@example.com', 'email_verified' => true]);
        $this->signIn();
        $this->assertGuest();

        app(SiteSettings::class)->set(['oidc_members' => '1']);
        $this->signIn()->assertRedirect(url('/'));
        $this->assertSame($member->id, Auth::id());
    }

    public function test_a_provider_must_be_https(): void
    {
        app(SiteSettings::class)->set(['oidc_issuer' => 'http://auth.example.com']);
        $this->assertFalse(SingleSignOn::enabled());
        $this->get('/sso/redirect')->assertNotFound();
    }
}
