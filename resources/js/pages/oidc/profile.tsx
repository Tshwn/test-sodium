import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { clearDemoToken, loadDemoToken } from '../../lib/oidcStorage';

type UserProfile = {
    id: number;
    name: string;
    email: string;
    oidc_subject: string;
    last_login_at: string | null;
};

export default function Profile() {
    const [user, setUser] = useState<UserProfile | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const token = loadDemoToken();

    const fetchProfile = useCallback(async () => {
        if (!token) {
            setError('No demo token found. Authenticate on the login screen first.');
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const response = await fetch('/api/me', {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                },
            });

            const body = await response.json();

            if (!response.ok) {
                throw new Error(body.error?.message ?? 'Unable to load profile.');
            }

            setUser(body.user);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Unable to load profile.');
            setUser(null);
        } finally {
            setLoading(false);
        }
    }, [token]);

    useEffect(() => {
        void fetchProfile();
    }, [fetchProfile]);

    const handleLogout = useCallback(() => {
        clearDemoToken();
        router.visit('/oidc/login', { replace: true });
    }, []);

    return (
        <>
            <Head title="OIDC Profile" />
            <div className="mx-auto max-w-3xl space-y-6 px-4 py-10">
                <header className="space-y-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Authenticated call
                    </p>
                    <h1 className="text-3xl font-semibold text-slate-900">/api/me response</h1>
                    <p className="text-base text-slate-600">
                        The token returned by <code>/api/auth/oidc/verify</code> is attached as a bearer
                        credential here. In production, issue your own session or httpOnly cookie instead of
                        storing this value in memory or localStorage.
                    </p>
                </header>

                {error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"
                    >
                        {error}
                    </div>
                ) : null}

                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-wrap items-center gap-4">
                        <button
                            type="button"
                            onClick={() => fetchProfile()}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                            disabled={loading}
                        >
                            {loading ? 'Refreshing…' : 'Refresh /api/me'}
                        </button>
                        <button
                            type='button'
                            onClick={handleLogout}
                            className="rounded-md border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-800 transition hover:bg-rose-50"
                        >
                            Clear token &amp; re-login
                        </button>
                    </div>

                    {user ? (
                        <dl className="mt-6 grid grid-cols-1 gap-4 text-sm text-slate-700 sm:grid-cols-2">
                            <div>
                                <dt className="font-medium text-slate-500">Name</dt>
                                <dd className="text-slate-900">{user.name}</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-slate-500">Email</dt>
                                <dd className="text-slate-900">{user.email}</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-slate-500">Subject (sub)</dt>
                                <dd className="font-mono text-xs text-slate-900">{user.oidc_subject}</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-slate-500">Last OIDC login</dt>
                                <dd className="text-slate-900">
                                    {user.last_login_at ?? 'Not recorded yet'}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="mt-6 text-sm text-slate-600">
                            {loading
                                ? 'Loading profile…'
                                : 'Authenticate first to populate this area.'}
                        </p>
                    )}
                </div>

                <p className="text-xs text-slate-500">
                    Nonce storage and rotation must remain on the server (or secure native storage) to guard
                    against replay. Treat this flow as a lightweight sandbox for quickly exercising the API
                    from mobile Safari/Chrome.
                </p>
            </div>
        </>
    );
}
