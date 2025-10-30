<?php

namespace App\Http\Controllers;

use App\Exceptions\OidcVerificationException;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AppleService;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Throwable;

class OAuthController extends Controller
{
    public function googleAuth(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('validation_error', $validator->errors()->first(), 422);
        }

        $idToken = (string) $validator->validated()['id_token'];

        try {
            // NOTE: `verifyIdToken` は JWT の署名と Google 側のメタデータを検証するが、他プロジェクトで発行されたトークンの再利用を防ぐために `iss` と `aud` はサーバー側でも検証する。
            $claims = $this->verifyGoogleIdToken($idToken);
        } catch (OidcVerificationException $exception) {
            return $this->errorResponse($exception->errorCode(), $exception->getMessage(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse('server_error', 'Unexpected error occurred during Google ID token verification.', 500);
        }

        $subject = (string) Arr::get($claims, 'sub');
        $issuer = (string) Arr::get($claims, 'iss');

        // Google の `sub` は安定したユーザー ID なので、それをキーに既存アカウントを検索する。
        $user = User::query()
            ->where('oidc_subject', $subject)
            ->whereIn('oidc_issuer', $this->googleIssuers())
            ->first();

        if ($user instanceof User) {
            // 既存アカウントにはモバイルクライアントが後続 API を叩けるようデモ用 API トークンを払い出す。
            $ttl = (int) Config::get('services.google.token_ttl', Config::get('oidc.token_ttl', 3600));
            $issued = ApiToken::issue($user, 'google_oauth', $ttl);

            return response()->json([
                'oauth_service' => 'google',
                'oauth_id' => $subject,
                'is_unregistered' => false,
                'token' => [
                    'value' => $issued['plain'],
                    'expires_in' => $ttl,
                ],
                'user' => $this->transformUser($user),
                'claims' => [
                    'iss' => $issuer,
                    'aud' => Arr::get($claims, 'aud'),
                ],
            ]);
        }

        // 登録済みユーザーが見つからない場合はクライアントに登録フロー継続を知らせる。
        return response()->json([
            'oauth_service' => 'google',
            'oauth_id' => $subject,
            'is_unregistered' => true,
            'claims' => [
                'iss' => $issuer,
                'aud' => Arr::get($claims, 'aud'),
            ],
        ]);
    }

    public function appleAuth(Request $request, AppleService $appleService): JsonResponse
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
            // Apple は元の nonce の SHA256 を返すため、送られてきたハッシュをそのまま検証処理へ渡す。
            $claims = $appleService->verifyIdentityToken($validated['id_token'], $validated['nonce'] ?? null);
        } catch (OidcVerificationException $exception) {
            return $this->errorResponse($exception->errorCode(), $exception->getMessage(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse('server_error', 'Unexpected error occurred during Apple ID token verification.', 500);
        }

        $subject = (string) Arr::get($claims, 'sub');
        $issuer = (string) Arr::get($claims, 'iss');

        $user = User::query()
            ->where('oidc_subject', $subject)
            ->where('oidc_issuer', $issuer)
            ->first();

        if ($user instanceof User) {
            $ttl = (int) Config::get('services.apple.token_ttl', Config::get('oidc.token_ttl', 3600));
            $issued = ApiToken::issue($user, 'apple_oauth', $ttl);

            return response()->json([
                'oauth_service' => 'apple',
                'oauth_id' => $subject,
                'is_unregistered' => false,
                'token' => [
                    'value' => $issued['plain'],
                    'expires_in' => $ttl,
                ],
                'user' => $this->transformUser($user),
                'claims' => [
                    'iss' => $issuer,
                    'aud' => Arr::get($claims, 'aud'),
                ],
            ]);
        }

        // Apple 認証は通ったがユーザー登録が無いので、フロントエンド側でプロフィール収集を促すフラグを返す。
        return response()->json([
            'oauth_service' => 'apple',
            'oauth_id' => $subject,
            'is_unregistered' => true,
            'claims' => [
                'iss' => $issuer,
                'aud' => Arr::get($claims, 'aud'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcVerificationException
     */
    private function verifyGoogleIdToken(string $idToken): array
    {
        $clientId = Config::get('services.google.client_id');
        if (! $clientId) {
            throw new OidcVerificationException('config_missing', 'Google client ID has not been configured.', 500);
        }

        // Google SDK が証明書取得や署名検証などの基本チェックを行う。
        $client = new GoogleClient();
        $client->setClientId($clientId);

        try {
            $payload = $client->verifyIdToken($idToken, $clientId);
        } catch (Throwable $exception) {
            throw new OidcVerificationException('token_invalid', 'Unable to verify Google ID token.', 401, $exception);
        }

        if (! is_array($payload)) {
            throw new OidcVerificationException('token_invalid', 'Unable to verify Google ID token.', 401);
        }

        return $this->validateGoogleClaims($payload);
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @return array<string, mixed>
     *
     * @throws OidcVerificationException
     */
    private function validateGoogleClaims(array $claims): array
    {
        $issuer = Arr::get($claims, 'iss');
        if (! $issuer || ! in_array($issuer, $this->googleIssuers(), true)) {
            // `iss` を固定値で確認することで Google 以外が発行したトークンを拒否する。
            throw new OidcVerificationException('iss_mismatch', 'Issuer claim did not match Google\'s identity provider.');
        }

        $aud = Arr::get($claims, 'aud');
        $allowedAudiences = array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            Arr::wrap(Config::get('services.google.allowed_audiences') ?? Config::get('services.google.client_id'))
        ))));

        if (! $aud || ! in_array((string) $aud, $allowedAudiences, true)) {
            // `aud` をクライアント ID と突き合わせることで他アプリ向けトークンの再利用を防ぐ。
            throw new OidcVerificationException('aud_mismatch', 'Audience claim did not match the configured Google client ID.');
        }

        if (! isset($claims['sub'])) {
            throw new OidcVerificationException('sub_missing', 'Subject claim is required.');
        }

        $expiresAt = Carbon::createFromTimestampUTC((int) ($claims['exp'] ?? 0));
        if (! $expiresAt || $expiresAt->isPast()) {
            throw new OidcVerificationException('token_expired', 'Google ID token has expired.');
        }

        $issuedAt = Carbon::createFromTimestampUTC((int) ($claims['iat'] ?? 0));
        if (! $issuedAt) {
            throw new OidcVerificationException('iat_missing', 'Issued-at claim is missing.');
        }

        return $claims;
    }

    /**
     * @return list<string>
     */
    private function googleIssuers(): array
    {
        return [
            'https://accounts.google.com',
            'accounts.google.com',
        ];
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
