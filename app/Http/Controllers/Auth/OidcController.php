<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\OidcVerificationException;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\IdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class OidcController extends Controller
{
    public function verify(Request $request, IdTokenVerifier $verifier): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string'],
            'nonce' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('validation_error', $validator->errors()->first(), 422);
        }

        $validated = $validator->validated();

        try {
            $claims = $verifier->verify($validated['id_token'], $validated['nonce'] ?? null);
        } catch (OidcVerificationException $exception) {
            return $this->errorResponse($exception->errorCode(), $exception->getMessage(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse('server_error', 'Unexpected error occurred during ID token verification.', 500);
        }

        $user = $this->upsertUserFromClaims($claims);

        // NOTE: Production deployments should favor Authorization Code + PKCE so that
        // tokens never transit through the mobile browser. This demo accepts an ID token post instead.
        $ttl = (int) Config::get('oidc.token_ttl', 3600);
        $issued = ApiToken::issue($user, 'oidc_demo', $ttl);

        // NOTE: Tokens should be stored in secure, httpOnly cookies served over HTTPS; plaintext response is demo-only.
        return response()->json([
            'token' => [
                'value' => $issued['plain'],
                'expires_in' => $ttl,
            ],
            'user' => $this->transformUser($user),
            'claims' => [
                'iss' => Arr::get($claims, 'iss'),
                'sub' => Arr::get($claims, 'sub'),
                'aud' => Arr::get($claims, 'aud'),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->errorResponse('unauthenticated', 'Authentication is required.', 401);
        }

        return response()->json([
            'user' => $this->transformUser($user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function upsertUserFromClaims(array $claims): User
    {
        $identifier = [
            'oidc_subject' => Arr::get($claims, 'sub'),
            'oidc_issuer' => Arr::get($claims, 'iss'),
        ];

        $user = User::query()->firstOrNew($identifier);

        $user->fill([
            'name' => Arr::get($claims, 'name')
                ?? Arr::get($claims, 'preferred_username')
                ?? 'OIDC User',
            'email' => Arr::get($claims, 'email') ?? $this->fallbackEmail($identifier['oidc_subject'] ?? 'user', $identifier['oidc_issuer'] ?? 'issuer'),
        ]);

        if (! $user->exists) {
            $user->password = Hash::make(Str::random(64));
        }

        if (array_key_exists('email_verified', $claims)) {
            $emailVerified = filter_var(Arr::get($claims, 'email_verified'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $user->email_verified_at = $emailVerified ? now() : null;
        }

        $user->last_oidc_login_at = now();
        $user->save();

        return $user;
    }

    private function fallbackEmail(string $subject, string $issuer): string
    {
        $host = parse_url($issuer, PHP_URL_HOST) ?? 'oidc-demo';

        return sprintf('%s@%s', $subject, $host);
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'oidc_subject' => $user->oidc_subject,
            'last_login_at' => optional($user->last_oidc_login_at)->toIso8601String(),
        ];
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
