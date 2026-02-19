(function() {
    'use strict';

    var STORAGE_KEY = 'litecms_theme_mode';
    var ATTR = 'data-theme-mode';

    // Get page-level override (set by server as data attribute)
    var pageOverride = document.body.getAttribute('data-theme-override') || '';

    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY); } catch(e) { return null; }
    }

    function setStoredTheme(theme) {
        try { localStorage.setItem(STORAGE_KEY, theme); } catch(e) {}
        // Also set cookie for server-side access
        document.cookie = STORAGE_KEY + '=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute(ATTR, theme);
        document.body.setAttribute(ATTR, theme);
        // Update toggle button icon
        var toggles = document.querySelectorAll('.theme-toggle-btn');
        toggles.forEach(function(btn) {
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            btn.innerHTML = theme === 'dark'
                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
        });
    }

    function getActiveTheme() {
        // Priority: page override > stored preference > site default
        if (pageOverride) return pageOverride;
        return getStoredTheme() || (document.body.getAttribute('data-default-theme') || 'light');
    }

    // Apply theme immediately (before paint)
    var activeTheme = getActiveTheme();
    applyTheme(activeTheme);

    // Toggle handler
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.theme-toggle-btn');
        if (!btn) return;
        // Don't allow toggle if page has forced override
        if (pageOverride) return;
        var current = document.documentElement.getAttribute(ATTR) || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        setStoredTheme(next);
    });
})();
