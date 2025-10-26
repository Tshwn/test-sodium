<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;
use Firebase\JWT\JWT;

class OidcTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://example-issuer.com';
    private const DISCOVERY = self::ISSUER.'/.well-known/openid-configuration';
    private const JWKS = self::ISSUER.'/jwks.json';
    private const CLIENT_ID = 'demo-client-id';
    private const KID = 'test-key';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('oidc', [
            'issuer' => self::ISSUER,
            'discovery' => self::DISCOVERY,
            'client_id' => self::CLIENT_ID,
            'expected_aud' => self::CLIENT_ID,
            'cache_ttl' => 60,
            'allowed_algs' => ['RS256'],
            'token_ttl' => 600,
        ]);

        $this->assertSame(['RS256'], Config::get('oidc.allowed_algs'));

        Cache::flush();
        $this->fakeOidcEndpoints();
    }

    public function test_user_can_verify_id_token_and_fetch_profile(): void
    {
        $nonce = 'nonce-1234';
        $token = $this->makeIdToken(['nonce' => $nonce]);

        $response = $this->postJson('/api/auth/oidc/verify', [
            'id_token' => $token,
            'nonce' => $nonce,
        ]);

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('token.value')
                ->where('claims.iss', self::ISSUER)
                ->where('user.oidc_subject', 'user-123')
                ->where('user.email', 'demo@example.com'),
            );

        $plainToken = $response->json('token.value');

        $this->assertDatabaseHas('users', [
            'oidc_subject' => 'user-123',
            'oidc_issuer' => self::ISSUER,
        ]);

        $me = $this->getJson('/api/me', [
            'Authorization' => 'Bearer '.$plainToken,
        ]);

        $me->assertOk()
            ->assertJsonPath('user.email', 'demo@example.com');
    }

    public function test_rejects_expired_token(): void
    {
        $token = $this->makeIdToken([
            'exp' => now()->subMinutes(5)->timestamp,
        ]);

        $this->postJson('/api/auth/oidc/verify', ['id_token' => $token])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_expired');
    }

    public function test_rejects_issuer_mismatch(): void
    {
        $token = $this->makeIdToken(['iss' => 'https://evil.example.com']);

        $this->postJson('/api/auth/oidc/verify', ['id_token' => $token])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'iss_mismatch');
    }

    public function test_rejects_tokens_with_invalid_signature(): void
    {
        $token = $this->makeIdToken();
        [$header, $payload, $signature] = explode('.', $token);
        $badSignature = substr($signature, 0, -2).'AA';
        $tampered = implode('.', [$header, $payload, $badSignature]);

        $this->postJson('/api/auth/oidc/verify', ['id_token' => $tampered])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'signature_invalid');
    }

    public function test_cached_jwks_are_used_when_remote_is_down(): void
    {
        // Warm up the cache.
        $this->postJson('/api/auth/oidc/verify', [
            'id_token' => $this->makeIdToken(),
        ])->assertOk();

        $issuerHash = sha1(self::ISSUER);
        Cache::forget("oidc:{$issuerHash}:jwks");
        Cache::forget("oidc:{$issuerHash}:discovery");

        Http::fake([
            self::DISCOVERY => Http::response([], 503),
            self::JWKS => Http::response([], 503),
        ]);

        $this->postJson('/api/auth/oidc/verify', [
            'id_token' => $this->makeIdToken(['nonce' => 'second']),
        ])->assertOk();
    }

    private function fakeOidcEndpoints(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response([
                'jwks_uri' => self::JWKS,
                'issuer' => self::ISSUER,
            ], 200),
            self::JWKS => Http::response([
                'keys' => [$this->jwk()],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIdToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'sub' => 'user-123',
            'aud' => self::CLIENT_ID,
            'exp' => now()->addMinutes(10)->timestamp,
            'iat' => now()->subMinute()->timestamp,
            'nonce' => 'nonce-value',
            'email' => 'demo@example.com',
            'email_verified' => true,
        ], $overrides);

        return JWT::encode($claims, $this->privateKey(), 'RS256', self::KID);
    }

    /**
     * @return array<string, string>
     */
    private function jwk(): array
    {
        return [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => '4YRXVazG7zXUU54IOXTIQCSemBKc8zEhlbfeJClY53rJf5e7gn8vT12Rsc0K287HaKTjgad-BxnAxMRkWdvh6UqAIq8JtO-Q3SV6AfbRbnc8ugP2nkCrgKy5ztKmB8YJ_Z2GJZoV0kzwGchV4RgGG5U79phrNmyg1_Kew1Hm6x2_S9biQdAQl-EO6K_ppluHqN15tdwL6yXXC1HShaw10vPryAxlRnI3OVt1yxCOepa8YlKmYpvOvX1iIhkq5E5Rl4n05D65dIv5yYJWag_5yYe0Ue8UT2YYwLBJRRYBwPX8zChwQ2R8gLzS8YdeRoUBA7HlrCcrLjnFHqexmSAFdQ',
            'e' => 'AQAB',
        ];
    }

    private function privateKey(): string
    {
        return <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQDhhFdVrMbvNdRT
ngg5dMhAJJ6YEpzzMSGVt94kKVjnesl/l7uCfy9PXZGxzQrbzsdopOOBp34HGcDE
xGRZ2+HpSoAirwm075DdJXoB9tFudzy6A/aeQKuArLnO0qYHxgn9nYYlmhXSTPAZ
yFXhGAYblTv2mGs2bKDX8p7DUebrHb9L1uJB0BCX4Q7or+mmW4eo3Xm13AvrJdcL
UdKFrDXS8+vIDGVGcjc5W3XLEI56lrxiUqZim869fWIiGSrkTlGXifTkPrl0i/nJ
glZqD/nJh7RR7xRPZhjAsElFFgHA9fzMKHBDZHyAvNLxh15GhQEDseWsJysuOcUe
p7GZIAV1AgMBAAECggEAIBwDAFqdp5UbQn2bj6y6T3G3WBE8Yh8CeGoJ2c2+UBUq
R/2/b8TypEL/GdkHPQVL3LEviHgj6FhpG0sYO7gkSh52sJmKEQZUMryhitKM/sTA
835ZeK5eDO/q89EH3U78AK2TWlq+VSdpv04INkjAo+BDfai1iTX9z8mGg+pvLdYV
xrEpnHBf/QE94yYCh3eJSNbmnRKOk1VCz2/wAZIy42jbi+jvxG6gwE3u8XVkoyri
p7K8Gb+hCThODwWsKliKRoHDz2IcEAWSz9a5EbzD71dz2ijsS/w6F3aUxLhN57JC
TGkAk1y28Z6bZTXsdNqPew1Y1el0codu2f2/dunTqQKBgQD0ARL6dOzSsoj0jVIU
eqf3FwsVcfIheeP398iLtvy/xR/NaM67zEIwJ18v295D9z9R4E1A1E8Idb5BLboU
+9jfZaJSHv9UI0TRmDWBko5X3lOpoL4BzhbQUrPdBbXlyG45vmzSORldRwaKuN6s
xnXywAanDrBzxOp5e+nUaUKK+QKBgQDsmphUsxey6/9jk4V1Dcu6UJ5Cmoi7LqvC
LqDIZtppSXxGIZv6RqzLq0yWm+qDLYEprWs/JdKBBogmGLIK/tG7qex0pFCCeFkc
V9vPUlmJ8Bp85lM8jSImlzAtzEVrMgIkMfyHOaGDJatq5Zy77ngimOLExT/yar8a
Gr0AQ8ERXQKBgQCKWyWQwMYcfsGrsYpuNFKTiAxv34mFM+FxFJ4xotPURYlP5vL6
h8qsFVcjAxAYB9Vurrn/XaNmz6TOvof6KAgEPFP7Lrpm0gzOr/j+/MQbzOQxlgTH
bz1+aLa2R+upXKorse9wkJHyUzjBZixVWb89o2biSTECpBC0S+/90qW5uQKBgQCx
RuP8EnQfS1P/d/j2y29qGh16Ke9o0H9A707o2KetW7IRmf0UeP/fWmn4Lrp3rxCn
+ZfxqJUgt5QrdzVvp53dzmOswbDREPszkWDQ5hLQl2ZBTxHuvJBp+b3Pks4wkzen
hwx/BV9OtFrLrV7SMMsyoPrIFELlj7XACWizWhC+wQKBgQCqy0Yu94hS33Je0qpW
b8VHjQqVoVajq38BP5znyi0lrD3163WF+AnsedgfzFPSR8jIH4uY5FrhHJLuMznt
CtiQs/zrZQDCadVKOe7tZLuT1I9vhSNh/4/ZG9jWNDBNHGrD015CVAAhihHTCJRE
rP7s131i/MsgR07VvJ7deiRBqA==
-----END PRIVATE KEY-----
KEY;
    }
}
