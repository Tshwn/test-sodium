<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (JsonResponse|\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $plainToken = $request->bearerToken() ?? $request->cookie('api_token');

        if (! $plainToken) {
            return $this->error('token_missing', 'Authentication token is missing.');
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token', hash('sha256', $plainToken))
            ->first();

        if (! $token || ! $token->user) {
            return $this->error('token_invalid', 'Authentication token is invalid.');
        }

        if ($token->isExpired()) {
            return $this->error('token_expired', 'Authentication token has expired.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->setUserResolver(fn () => $token->user);
        Auth::setUser($token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function error(string $code, string $message, int $status = 401): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
