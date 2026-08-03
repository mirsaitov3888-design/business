/* PORTAL_RECOVERY_V18 */
(() => {
    'use strict';

    if (window.__portalRecoveryFetchInstalled) return;
    window.__portalRecoveryFetchInstalled = true;

    const originalFetch = window.fetch.bind(window);

    function repairedUrl(input) {
        const raw = typeof input === 'string'
            ? input
            : (input instanceof Request ? input.url : '');
        if (!raw || !raw.includes('client-structure-api.php')) return null;

        try {
            const parsed = new URL(raw, window.location.origin);
            const action = parsed.searchParams.get('action') || '';
            const match = action.match(/^client&client_id=(\d+)$/);
            if (!match) return null;

            parsed.searchParams.set('action', 'client');
            parsed.searchParams.set('client_id', match[1]);
            return parsed.origin === window.location.origin
                ? parsed.pathname + parsed.search
                : parsed.toString();
        } catch (_) {
            return null;
        }
    }

    window.fetch = function portalRecoveryFetch(input, init) {
        const fixed = repairedUrl(input);
        if (!fixed) return originalFetch(input, init);

        if (input instanceof Request) {
            return originalFetch(new Request(fixed, input), init);
        }
        return originalFetch(fixed, init);
    };
})();
