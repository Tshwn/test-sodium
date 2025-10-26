export type MockIdTokenResponse = {
    idToken: string;
    nonce?: string;
};

/**
 * Pretend to talk to a mobile OIDC SDK by prompting the user for an ID token.
 * Replace this with the actual provider SDK in production.
 */
export async function getMockIdToken(): Promise<MockIdTokenResponse> {
    if (typeof window === 'undefined') {
        throw new Error('Browser environment required for OIDC demo.');
    }

    const idToken = window.prompt(
        'Paste the ID token returned by your OIDC provider (mobile SDK, etc.)',
    );

    if (!idToken) {
        throw new Error('ID token is required to continue.');
    }

    const nonce = window
        .prompt('Enter the nonce that was used when requesting the ID token (optional)')
        ?.trim();

    return {
        idToken: idToken.trim(),
        nonce: nonce || undefined,
    };
}
