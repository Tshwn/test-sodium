<?php

namespace App\Services;

use App\Exceptions\OidcVerificationException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class AppleService
{
    private readonly string $issuer;

    /**
     * @var list<string>
     */
    private readonly array $audiences;

    /**
     * @var list<string>
     */
    private readonly array $allowedAlgs;

    private readonly int $leeway;

    private readonly OidcJwksProvider $jwksProvider;

    public function __construct(?OidcJwksProvider $jwksProvider = null)
    {
        $this->issuer = (string) Config::get('services.apple.issuer', 'https://appleid.apple.com');
        $this->audiences = $this->resolveAudiences();
        $this->allowedAlgs = $this->resolveAllowedAlgorithms();
        $this->leeway = (int) Config::get('services.apple.leeway', 120);

        $discovery = Config::get('services.apple.discovery', 'https://appleid.apple.com/.well-known/openid-configuration');
        $cacheTtl = Config::get('services.apple.cache_ttl');

        $this->jwksProvider = $jwksProvider ?? new OidcJwksProvider($discovery, $this->issuer, $cacheTtl);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcVerificationException
     */
    public function verifyIdentityToken(string $idToken, ?string $nonce = null): array
    {
        $token = trim($idToken);
        if ($token === '') {
            throw new OidcVerificationException('id_token_missing', 'ID token is required.', 422);
        }

        // ヘッダーを展開して Apple の JWKS から対応する鍵を選び、アルゴリズムが想定通りか確かめる。
        $header = $this->decodeHeader($token);
        $alg = isset($header['alg']) ? strtoupper((string) $header['alg']) : null;
        if (! $alg || ! in_array($alg, $this->allowedAlgs, true)) {
            throw new OidcVerificationException('alg_not_allowed', 'Unsupported signing algorithm provided by Apple.');
        }

        $key = $this->selectKey($header['kid'] ?? null, $alg);

        JWT::$leeway = $this->leeway;

        try {
            $claims = (array) JWT::decode($token, $key);
        } catch (ExpiredException) {
            throw new OidcVerificationException('token_expired', 'Apple ID token has expired.');
        } catch (BeforeValidException) {
            throw new OidcVerificationException('token_not_yet_valid', 'Apple ID token is not yet valid.');
        } catch (SignatureInvalidException $exception) {
            throw new OidcVerificationException('signature_invalid', 'Apple ID token signature failed verification.', 401, $exception);
        } catch (InvalidArgumentException|RuntimeException|UnexpectedValueException $exception) {
            throw new OidcVerificationException('token_invalid', 'Apple ID token could not be decoded.', 422, $exception);
        }

        $this->validateClaims($claims, $nonce);

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeHeader(string $token): array
    {
        [$encodedHeader] = explode('.', $token);
        $decoded = JWT::jsonDecode(JWT::urlsafeB64Decode($encodedHeader));

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_object($decoded)) {
            return (array) $decoded;
        }

        return [];
    }

    private function selectKey(?string $kid, string $alg): Key
    {
        $jwks = $this->jwksProvider->getJwks();

        try {
            $parsed = JWK::parseKeySet(['keys' => $jwks], $alg);
        } catch (Throwable $exception) {
            throw new OidcVerificationException('jwks_parse_error', 'Unable to parse Apple JWKS document.', 500, $exception);
        }

        $selected = $kid && array_key_exists($kid, $parsed)
            ? $parsed[$kid]
            : (count($parsed) === 1 ? reset($parsed) : null);

        if (! $selected instanceof Key) {
            throw new OidcVerificationException('jwks_key_missing', 'Unable to locate matching signing key for Apple ID token.');
        }

        if (strtoupper($selected->getAlgorithm()) !== $alg) {
            throw new OidcVerificationException('alg_mismatch', 'Signing key algorithm does not match Apple token header.');
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws OidcVerificationException
     */
    private function validateClaims(array $claims, ?string $nonce): void
    {
        $issuer = Arr::get($claims, 'iss');
        if ($issuer !== $this->issuer) {
            // Apple の発行者識別子は固定なので、ここで一致確認することで第三者による偽造トークンを排除する。
            throw new OidcVerificationException('iss_mismatch', 'Issuer claim did not match Apple\'s identity provider.');
        }

        // SDK によって `aud` が文字列または配列になるため、文字列に正規化して設定値と突き合わせる。
        $audience = array_map(static fn ($aud) => (string) $aud, Arr::wrap($claims['aud'] ?? []));
        $validAudience = count(array_intersect($audience, $this->audiences)) > 0;
        if (! $validAudience) {
            // `aud` の一致を確認することでトークンがこのアプリ (サービス ID / バンドル ID) 向けであることを保証する。
            throw new OidcVerificationException('aud_mismatch', 'Audience claim was missing or did not match the configured client ID.');
        }

        if (! isset($claims['sub'])) {
            throw new OidcVerificationException('sub_missing', 'Subject claim is required.');
        }

        // `exp` と `iat` を検証して期限切れや時計ずれ、改ざんされたトークンを弾く。
        $expiresAt = Carbon::createFromTimestampUTC((int) ($claims['exp'] ?? 0));
        if (! $expiresAt || $expiresAt->isPast()) {
            throw new OidcVerificationException('token_expired', 'Apple ID token has expired.');
        }

        $issuedAt = Carbon::createFromTimestampUTC((int) ($claims['iat'] ?? 0));
        if (! $issuedAt) {
            throw new OidcVerificationException('iat_missing', 'Issued-at claim is missing.');
        }

        if ($nonce !== null) {
            $tokenNonce = Arr::get($claims, 'nonce');
            if (! $tokenNonce) {
                throw new OidcVerificationException('nonce_missing', 'Nonce claim was expected but missing.');
            }

            if (! hash_equals($nonce, (string) $tokenNonce)) {
                throw new OidcVerificationException('nonce_mismatch', 'Nonce claim did not match the expected value.');
            }

            // NOTE: Apple は nonce の SHA256 ハッシュを返すため、サーバー側でもハッシュ値を保存して照合する必要がある。
        }
    }

    /**
     * @return list<string>
     */
    private function resolveAudiences(): array
    {
        $configured = Config::get('services.apple.client_ids') ?? Config::get('services.apple.client_id');

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            Arr::wrap($configured)
        ))));
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedAlgorithms(): array
    {
        $configured = Config::get('services.apple.allowed_algs', ['RS256']);

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtoupper(trim((string) $value)),
            Arr::wrap($configured)
        ))));
    }
}
