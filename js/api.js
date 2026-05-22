/**
 * API helpers for Telaris (fetch with API key).
 */

export async function apiFetch(url, options = {}) {
    const apiKey = window.TELARIS_API_KEY;
    if (!apiKey) {
        throw new Error('TELARIS_API_KEY_NOT_LOADED');
    }
    const headers = {
        'X-API-Key': apiKey,
        ...(options.headers || {})
    };
    return fetch(url, {
        ...options,
        headers
    });
}
