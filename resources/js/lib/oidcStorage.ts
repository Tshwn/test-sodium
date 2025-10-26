const TOKEN_STORAGE_KEY = 'oidc_demo_token';

export function saveDemoToken(token: string) {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
}

export function loadDemoToken(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.localStorage.getItem(TOKEN_STORAGE_KEY);
}

export function clearDemoToken() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.removeItem(TOKEN_STORAGE_KEY);
}
