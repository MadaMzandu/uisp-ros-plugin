/* Load before the stylesheet so a saved preference is applied before first paint. */
(function (window, document) {
    'use strict';

    const storageKey = 'ros-panel-theme';
    const allowed = ['dark', 'light', 'system'];
    const normalize = value => allowed.includes(value) ? value : 'dark';
    let preference = 'dark';
    let systemMatches = false;
    let media = null;
    let control = null;

    try {
        preference = normalize(window.localStorage.getItem(storageKey));
    } catch (_) {
        // Preferences remain usable for this page when browser storage is blocked.
    }
    try {
        media = window.matchMedia('(prefers-color-scheme: light)');
        systemMatches = media.matches;
    } catch (_) {
        // Older or restricted browsers keep the existing dark appearance.
    }

    const resolvedTheme = () => preference === 'system'
        ? (systemMatches ? 'light' : 'dark') : preference;

    function apply() {
        const theme = resolvedTheme();
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-theme-preference', preference);
        if (control) control.value = preference;
        if (typeof window.CustomEvent === 'function') {
            window.dispatchEvent(new window.CustomEvent('panel-theme-change', {
                detail: { theme, preference }
            }));
        }
        return theme;
    }

    function setPreference(value) {
        preference = normalize(value);
        try {
            window.localStorage.setItem(storageKey, preference);
        } catch (_) {
            // Storage errors must not prevent changing the visible appearance.
        }
        return apply();
    }

    apply();
    if (media) {
        const onSystemChange = event => {
            systemMatches = event.matches;
            if (preference === 'system') apply();
        };
        if (typeof media.addEventListener === 'function') media.addEventListener('change', onSystemChange);
        else if (typeof media.addListener === 'function') media.addListener(onSystemChange);
    }

    function bindControl() {
        control = document.getElementById('panel-theme');
        if (!control) return;
        control.value = preference;
        control.addEventListener('change', () => setPreference(control.value));
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindControl, { once: true });
    else bindControl();

    window.addEventListener('storage', event => {
        if (event.key !== storageKey && event.key !== null) return;
        preference = normalize(event.newValue);
        apply();
    });
    window.PanelTheme = Object.freeze({
        setPreference,
        getPreference: () => preference,
        getTheme: resolvedTheme
    });
}(window, document));
