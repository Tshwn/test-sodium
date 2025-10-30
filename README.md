# Laravel + React Starter Kit

## Introduction

Our React starter kit provides a robust, modern starting point for building Laravel applications with a React frontend using [Inertia](https://inertiajs.com).

Inertia allows you to build modern, single-page React applications using classic server-side routing and controllers. This lets you enjoy the frontend power of React combined with the incredible backend productivity of Laravel and lightning-fast Vite compilation.

This React starter kit utilizes React 19, TypeScript, Tailwind, and the [shadcn/ui](https://ui.shadcn.com) and [radix-ui](https://www.radix-ui.com) component libraries.

## OIDC ID Token Verification Demo

This repo includes a minimal OpenID Connect (OIDC) demo tailored for mobile browsers where an ID token is collected via a native SDK and POSTed to Laravel for validation.

- **Endpoints**
  - `POST /api/auth/oidc/verify` validates the ID token signature (via JWKS), checks `iss/aud/exp/iat/nonce`, upserts an application user by `sub`, and issues a short-lived demo API token.
  - `GET /api/me` returns the authenticated user when the demo token is supplied as `Authorization: Bearer <token>` (handled by `App\Http\Middleware\AuthToken`).
- **Frontend pages**
  - `/oidc` or `/oidc/login` renders `resources/js/pages/oidc/login.tsx`, a mobile-friendly mock that either prompts for an ID token or accepts a pasted token.
  - `/oidc/profile` renders `resources/js/pages/oidc/profile.tsx` and exercises `/api/me` with the saved demo token.
- **Security notes**
  - Production apps should use Authorization Code + PKCE so ID/refresh tokens never pass through the SPA; this flow is for labs/demos only.
  - Nonces must be generated and stored server-side to block replay. The sample accepts an optional nonce and validates it against the token.
  - Issue session cookies or signed, httpOnly cookies in production. Returning bearer tokens in JSON + storing them in localStorage is for demos only.

### Environment Variables

Configure these in `.env` (see `.env.example` for defaults):

```
OIDC_ISSUER=https://example-issuer.com
OIDC_DISCOVERY=https://example-issuer.com/.well-known/openid-configuration
OIDC_CLIENT_ID=your_client_id
OIDC_EXPECTED_AUD=your_client_id
OIDC_CACHE_TTL=300
OIDC_LEEWAY=60
OIDC_ALLOWED_ALGS=RS256,ES256
OIDC_TOKEN_TTL=3600
```

`OidcJwksProvider` fetches the discovery document + JWKS via Laravel's HTTP client and caches both the live and "last good" responses so verification continues even if the provider temporarily responds with 5xx.

## Mobile OAuth ID Token Endpoints

Native mobile clients that sign in with Google or Apple can POST the raw ID token to dedicated endpoints:

- `POST /api/auth/google` leverages [`google/apiclient`](https://github.com/googleapis/google-api-php-client) to run `verifyIdToken`, then double-checks the `iss` and `aud` claims for `https://accounts.google.com` and your configured OAuth client ID before issuing an API token.
- `POST /api/auth/apple` uses a bespoke `App\Services\AppleService` built on `firebase/php-jwt` + Apple's JWKS document to validate the signature and claims, returning either an issued API token or a marker that registration is required.

Both endpoints respond with `{ oauth_service, oauth_id, is_unregistered }`, and when a matching user exists they also include an issued API token plus the normalized claims used for verification.

### Claim Validation Notes

- `iss`: Locked to the provider's documented issuer (`https://accounts.google.com` or `https://appleid.apple.com`) so tokens minted for other relying parties are rejected.
- `aud`: Compared against your configured client IDs. This blocks replaying an ID token that was issued to another mobile app.
- `exp` / `iat`: Checked using UTC timestamps; expired or future-dated tokens are turned away.
- `nonce`: You can pass the nonce hash used on iOS—Apple returns the SHA256 value and the service compares it when supplied.

### Additional Environment Variables

```
GOOGLE_OAUTH_CLIENT_ID=your-google-client-id
GOOGLE_ALLOWED_AUDIENCES=client-id-1,client-id-2
GOOGLE_TOKEN_TTL=3600

APPLE_OAUTH_CLIENT_ID=com.example.service
APPLE_OAUTH_CLIENT_IDS=com.example.service,com.example.app
APPLE_OAUTH_ISSUER=https://appleid.apple.com
APPLE_OIDC_DISCOVERY=https://appleid.apple.com/.well-known/openid-configuration
APPLE_ALLOWED_ALGS=RS256
APPLE_OIDC_LEEWAY=120
APPLE_OIDC_CACHE_TTL=300
APPLE_TOKEN_TTL=3600
```

### Running Locally

1. **Install dependencies**
   ```bash
   composer install
   npm install
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   ```
2. **Populate OIDC settings** in `.env` (issuer, discovery URL, client ID, etc.).
3. **Start the stack**
   ```bash
   php artisan serve
   npm run dev
   ```
4. Visit `http://localhost:8000/oidc` on mobile Safari/Chrome (or desktop) and paste an ID token from your provider, then open `/oidc/profile` to fetch `/api/me`.

### Testing & Quality

Run the PHP feature suite (covers success + failure cases and JWKS caching) and type-check the React code before committing:

```bash
php artisan test
npm run types
```

## Official Documentation

Documentation for all Laravel starter kits can be found on the [Laravel website](https://laravel.com/docs/starter-kits).

## Contributing

Thank you for considering contributing to our starter kit! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## License

The Laravel + React starter kit is open-sourced software licensed under the MIT license.
