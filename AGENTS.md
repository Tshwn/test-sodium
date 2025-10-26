# Repository Guidelines

## Project Structure & Module Organization
This is a Laravel + Inertia React stack. Backend classes live in `app/` with HTTP, console, and domain services, while configuration sits in `config/` and migrations/seeds inside `database/`. Client modules are under `resources/js` and `resources/css`, with shared UI primitives in `resources/js/components`. Routes are defined in `routes/web.php` (HTTP) and `routes/api.php` for APIs. Public assets compiled by Vite deploy from `public/`. Automated tests live in `tests/Feature` and `tests/Unit`, mirroring the namespaces they cover.

## Build, Test, and Development Commands
- `npm run dev`: launch Vite dev server with hot module reloading alongside Laravel's `php artisan serve`.
- `npm run build`: produce versioned assets in `public/build` using Vite.
- `npm run build:ssr`: build both client and SSR bundles when experimenting with server rendering.
- `php artisan test`: execute the PHP test suite defined in `tests/`.
- `npm run lint` / `npm run format:check`: enforce ESLint and Prettier rules before pushing changes.
- `npm run types`: run `tsc --noEmit` to surface type regressions.

## Coding Style & Naming Conventions
PHP code must follow PSR-12; align namespaces with directory paths and suffix HTTP controllers with `Controller`. React files use PascalCase component names (`UserMenu.tsx`) and colocate hooks under `resources/js/hooks`. Prefer functional components with explicit props interfaces. Tailwind utility stacks should be composed via `clsx`/`cva` helpers. Run Prettier on `resources/` and keep imports auto-sorted (organize-imports plugin). ESLint extends React 19 rules; resolve all warnings before review.

## Testing Guidelines
Create Feature tests for HTTP/inertia flows and Unit tests for domain helpers. Use `test_can_*` style names in PHP and descriptive `it renders profile summary` names in any future front-end tests. Aim to cover new branch logic; note uncovered paths with `@todo` comments or tracking issues. Run `php artisan test` plus `npm run types` before committing.

## Commit & Pull Request Guidelines
Keep commits focused and written in sentence case imperative ("Add invoice filters"), matching the existing log. Reference related issue IDs in the subject or body. Each PR should describe the user-facing change, list steps to reproduce/test, and include screenshots or terminal output for UI-affecting updates. Link design specs or Notion docs when relevant.

## Security & Configuration Tips
Never commit `.env` or credentials; replicate settings via `.env.example`. Clear config caches (`php artisan config:clear`) after changing environment values. Validate third-party packages before installation and document any new secrets in Ops runbooks.
