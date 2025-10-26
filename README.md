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
