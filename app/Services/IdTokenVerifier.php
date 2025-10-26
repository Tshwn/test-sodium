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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class IdTokenVerifier
{
    public function __construct(private readonly OidcJwksProvider $jwksProvider)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcVerificationException
     */
    public function verify(string $idToken, ?string $nonce = null): array
    {
        $trimmed = trim($idToken);
        if ($trimmed === '') {
            throw new OidcVerificationException('id_token_missing', 'ID token is required.', 422);
        }

        $header = $this->decodeHeader($trimmed);
        $alg = isset($header['alg']) ? strtoupper((string) $header['alg']) : null;
        $kid = $header['kid'] ?? null;

        $configuredAlgs = Config::get('oidc.allowed_algs', []);
        $allowedAlgs = Collection::make(is_array($configuredAlgs) ? $configuredAlgs : explode(',', (string) $configuredAlgs))
            ->filter()
            ->map(fn ($value) => strtoupper(trim($value)))
            ->values()
            ->all();

        if (! $alg || ! in_array($alg, $allowedAlgs, true)) {
            throw new OidcVerificationException('alg_not_allowed', 'ID token was signed with an unsupported algorithm.');
        }

        $key = $this->selectKey($kid, $alg);

        JWT::$leeway = (int) Config::get('oidc.leeway', 60);

        try {
            $claims = (array) JWT::decode($trimmed, $key);
        } catch (ExpiredException) {
            throw new OidcVerificationException('token_expired', 'ID token has expired.');
        } catch (BeforeValidException) {
            throw new OidcVerificationException('token_not_yet_valid', 'ID token is not yet valid.');
        } catch (SignatureInvalidException $exception) {
            throw new OidcVerificationException('signature_invalid', 'ID token signature failed verification.', 401, $exception);
        } catch (InvalidArgumentException|RuntimeException|UnexpectedValueException $exception) {
            throw new OidcVerificationException('token_invalid', 'ID token could not be decoded.', 422, $exception);
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
        $keys = JWK::parseKeySet(['keys' => $jwks]);

        $selected = $kid && array_key_exists($kid, $keys)
            ? $keys[$kid]
            : (count($keys) === 1 ? reset($keys) : null);

        if (! $selected instanceof Key) {
            throw new OidcVerificationException('jwks_key_missing', 'Unable to locate matching signing key for ID token.');
        }

        if (strtoupper($selected->getAlgorithm()) !== $alg) {
            throw new OidcVerificationException('alg_mismatch', 'Signing key algorithm does not match token header.');
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
        $issuer = Config::get('oidc.issuer');
        $expectedAud = Config::get('oidc.expected_aud') ?? Config::get('oidc.client_id');

        if (! $issuer || Arr::get($claims, 'iss') !== $issuer) {
            throw new OidcVerificationException('iss_mismatch', 'Issuer claim did not match configuration.');
        }

        $audience = Arr::wrap($claims['aud'] ?? []);
        if (! $audience || ! $expectedAud || ! in_array($expectedAud, $audience, true)) {
            throw new OidcVerificationException('aud_mismatch', 'Audience claim was missing or unexpected.');
        }

        if (! isset($claims['sub'])) {
            throw new OidcVerificationException('sub_missing', 'Subject claim is required.');
        }

        $expiresAt = Carbon::createFromTimestampUTC((int) ($claims['exp'] ?? 0));
        if (! $expiresAt || $expiresAt->isPast()) {
            throw new OidcVerificationException('token_expired', 'ID token has expired.');
        }

        $issuedAt = Carbon::createFromTimestampUTC((int) ($claims['iat'] ?? 0));
        if (! $issuedAt) {
            throw new OidcVerificationException('iat_missing', 'Issued-at claim is missing.');
        }

        if ($issuedAt->greaterThan(Carbon::now()->addSeconds(Config::get('oidc.leeway', 60)))) {
            throw new OidcVerificationException('iat_invalid', 'ID token appears to be issued in the future.');
        }

        if ($nonce !== null) {
            $tokenNonce = Arr::get($claims, 'nonce');
            if (! $tokenNonce) {
                throw new OidcVerificationException('nonce_missing', 'Nonce claim was expected but missing.');
            }

            if (! hash_equals($nonce, (string) $tokenNonce)) {
                throw new OidcVerificationException('nonce_mismatch', 'Nonce claim did not match the stored value.');
            }

            // NOTE: Store and delete nonce server-side per PKCE best practices.
        }
    }
}
