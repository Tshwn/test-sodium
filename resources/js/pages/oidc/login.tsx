import { Head, router } from '@inertiajs/react';
import { FormEvent, useCallback, useMemo, useState } from 'react';
import { getMockIdToken } from '../../lib/mockOidcClient';
import { loadDemoToken, saveDemoToken } from '../../lib/oidcStorage';

type VerifyResponse = {
    token: {
        value: string;
        expires_in: number;
    };
    user: {
        name: string;
        email: string;
    };
    error?: {
        code: string;
        message: string;
    };
};

const buttonClasses =
    'rounded-md bg-slate-900 px-4 py-2 text-white shadow-sm outline-1 outline-offset-2 transition enabled:hover:bg-slate-800 disabled:opacity-40';

export default function OidcLogin() {
    const [manualToken, setManualToken] = useState('');
    const [nonce, setNonce] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const existingToken = useMemo(() => loadDemoToken(), []);

    const handleVerify = useCallback(
        async (tokenValue: string, nonceValue?: string) => {
            setLoading(true);
            setError(null);
            setNotice(null);

            try {
                const payload: Record<string, string> = {
                    id_token: tokenValue.trim(),
                };

                if (nonceValue) {
                    payload.nonce = nonceValue.trim();
                }

                const response = await fetch('/api/auth/oidc/verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const body = (await response.json()) as VerifyResponse;

                if (!response.ok) {
                    throw new Error(body.error?.message ?? 'Verification failed.');
                }

                saveDemoToken(body.token.value);
                setNotice(
                    `Authenticated as ${body.user.name} (${body.user.email}). Token cached for demo requests.`,
                );
                router.visit('/oidc/profile', { replace: true });
            } catch (e) {
                setError(e instanceof Error ? e.message : 'Unable to verify ID token.');
            } finally {
                setLoading(false);
            }
        },
        [],
    );

    const handleManualSubmit = useCallback(
        (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            if (!manualToken.trim()) {
                setError('Paste an ID token before sending it to the API.');
                return;
            }

            void handleVerify(manualToken, nonce || undefined);
        },
        [handleVerify, manualToken, nonce],
    );

    const handleMockClick = useCallback(async () => {
        try {
            const mock = await getMockIdToken();
            await handleVerify(mock.idToken, mock.nonce);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Mock client cancelled.');
        }
    }, [handleVerify]);

    return (
        <>
            <Head title="OIDC Login" />
            <div className="mx-auto max-w-3xl space-y-6 px-4 py-10">
                <header className="space-y-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Mobile OIDC demo
                    </p>
                    <h1 className="text-3xl font-semibold text-slate-900">
                        Paste a mobile ID token and let Laravel verify it
                    </h1>
                    <p className="text-base text-slate-600">
                        This screen mimics a smartphone web view that already obtained an ID token via an
                        SDK (Code + PKCE is still the production recommendation). Provide the ID token,
                        optionally include the nonce you stored during the authorization request, and the
                        backend will validate <code>iss</code>, <code>aud</code>, <code>exp</code>,{' '}
                        <code>iat</code>, and the signature using JWKS.
                    </p>
                </header>

                {existingToken ? (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                        A demo API token already exists in localStorage. Head over to the profile screen
                        or clear storage if you would like to start over.
                    </div>
                ) : null}

                {notice ? (
                    <div className="rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        {notice}
                    </div>
                ) : null}

                {error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"
                    >
                        {error}
                    </div>
                ) : null}

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">
                        1. Use the mock OIDC client (prompts)
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Replace this helper with your provider&apos;s mobile SDK. You&apos;ll typically
                        hydrate PKCE code verifier + nonce in secure storage rather than requesting tokens
                        directly inside the SPA.
                    </p>
                    <button
                        type="button"
                        className={`${buttonClasses} mt-4`}
                        onClick={handleMockClick}
                        disabled={loading}
                    >
                        Launch mock SDK prompt
                    </button>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">
                        2. Or paste an ID token manually
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        If you already captured an ID token via a mobile build, paste it below. When a
                        nonce was issued alongside the request, persist it server-side and supply it so the
                        backend can block replay attempts.
                    </p>

                    <form className="mt-4 space-y-4" onSubmit={handleManualSubmit}>
                        <label className="block text-sm font-medium text-slate-700">
                            ID token (JWT)
                            <textarea
                                className="mt-1 w-full rounded-md border border-slate-300 bg-white p-2 font-mono text-xs text-slate-900"
                                rows={6}
                                spellCheck={false}
                                value={manualToken}
                                onChange={(event) => setManualToken(event.target.value)}
                                placeholder="eyJhbGciOiJSUzI1NiIsInR5..."
                            />
                        </label>

                        <label className="block text-sm font-medium text-slate-700">
                            Nonce (optional but recommended)
                            <input
                                className="mt-1 w-full rounded-md border border-slate-300 bg-white p-2 text-slate-900"
                                value={nonce}
                                onChange={(event) => setNonce(event.target.value)}
                                placeholder="nonce-used-when-requesting-token"
                            />
                        </label>

                        <div className="flex items-center justify-between text-sm text-slate-500">
                            <p>
                                HTTPS only. Prefer HTTP-only cookies for tokens instead of localStorage.
                            </p>
                            <button
                                type="submit"
                                className={buttonClasses}
                                disabled={loading}
                            >
                                Send to /api/auth/oidc/verify
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </>
    );
}
