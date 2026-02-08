/**
 * LiteCMS Cookie Consent
 *
 * - Checks for existing consent cookie on load.
 * - If no consent cookie: shows the banner.
 * - Accept: sets cookie, hides banner, loads GA (if configured).
 * - Decline: sets cookie, hides banner, does NOT load GA.
 * - Returning visitor with consent: loads GA silently (if accepted).
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'litecms_consent';
    var COOKIE_DAYS = 365;

    function getCookie(name) {
        var match = document.cookie.match(
            new RegExp('(^|;\\s*)' + name + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        document.cookie =
            name +
            '=' +
            encodeURIComponent(value) +
            ';expires=' +
            d.toUTCString() +
            ';path=/;SameSite=Lax';
    }

    function loadGA() {
        var gaId = document.body.getAttribute('data-ga-id');
        if (!gaId) return;

        // Prevent double-loading
        if (document.querySelector('script[src*="googletagmanager"]')) return;

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
        document.head.appendChild(script);

        window.dataLayer = window.dataLayer || [];
        function gtag() {
            window.dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', gaId);
    }

    function hideBanner() {
        var banner = document.getElementById('cookie-consent');
        if (banner) {
            banner.style.display = 'none';
        }
    }

    function showBanner() {
        var banner = document.getElementById('cookie-consent');
        if (banner) {
            banner.style.display = 'block';
        }
    }

    function init() {
        var consent = getCookie(COOKIE_NAME);

        if (consent === 'accepted') {
            hideBanner();
            loadGA();
            return;
        }

        if (consent === 'declined') {
            hideBanner();
            return;
        }

        // No consent yet — show the banner
        showBanner();

        var acceptBtn = document.getElementById('cookie-accept');
        var declineBtn = document.getElementById('cookie-decline');

        if (acceptBtn) {
            acceptBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'accepted', COOKIE_DAYS);
                hideBanner();
                loadGA();
            });
        }

        if (declineBtn) {
            declineBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'declined', COOKIE_DAYS);
                hideBanner();
            });
        }
    }

    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
