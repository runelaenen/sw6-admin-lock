const LOCK_SESSION_STORAGE_KEY = 'lae-admin-lock-token';
const LOCK_HEADER_NAME         = 'sw-lae-admin-lock-token';

let interceptorInstalled = false;

function generateToken() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
}

export function getLockSessionToken() {
    try {
        let token = window.sessionStorage.getItem(LOCK_SESSION_STORAGE_KEY);
        if (!token) {
            token = generateToken();
            window.sessionStorage.setItem(LOCK_SESSION_STORAGE_KEY, token);
        }
        return token;
    } catch (e) {
        if (!window.__laeAdminLockToken) {
            window.__laeAdminLockToken = generateToken();
        }
        return window.__laeAdminLockToken;
    }
}

function shouldAttachHeader(config) {
    const url = `${config?.url || ''}`;
    if (url === '') {
        return false;
    }
    // Only attach to admin API requests (relative URLs); never leak to absolute URLs.
    return !/^https?:\/\//i.test(url);
}

export function ensureLockSessionInterceptor() {
    if (interceptorInstalled) {
        return;
    }

    const httpClient = Shopware.Application.getContainer('init')?.httpClient;
    if (!httpClient?.interceptors?.request) {
        return;
    }

    httpClient.interceptors.request.use((config) => {
        if (!shouldAttachHeader(config)) {
            return config;
        }

        const headers = { ...(config.headers || {}) };
        if (!headers[LOCK_HEADER_NAME]) {
            headers[LOCK_HEADER_NAME] = getLockSessionToken();
        }

        return { ...config, headers };
    });

    interceptorInstalled = true;
}

export { LOCK_HEADER_NAME };
