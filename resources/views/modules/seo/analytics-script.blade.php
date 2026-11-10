{{--
    Consent first, then Google Analytics. Plain script with no build step, so it only ships on pages that
    need it. Everything it sends comes from the JSON in #analytics-config: the cleaned page address and
    title, and events whose details are limited to the values listed there.
    tests/Feature/Seo/AnalyticsScriptTest runs this file in Node.
--}}
<script>
@verbatim
(function (window, document) {
    'use strict';

    var holder = document.getElementById('analytics-config');

    if (!holder) {
        return;
    }

    var config = JSON.parse(holder.textContent);
    var STORE = 'n360.analytics';
    var KEEP_FOR = 180 * 24 * 60 * 60 * 1000;
    var OFF_SWITCH = 'ga-disable-' + config.id;
    var counting = config.mode === 'pageview';
    var answer = saved();
    var loaded = false;
    var banner = null;
    var waiting = (config.events || []).slice();
    var seen = {};

    window.dataLayer = window.dataLayer || [];

    function gtag() {
        window.dataLayer.push(arguments);
    }

    // Google Consent Mode v2. Everything starts refused. Only analytics can be allowed, and only by the visitor.
    gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied'
    });

    function saved() {
        try {
            var kept = JSON.parse(window.localStorage.getItem(STORE) || 'null');

            if (kept && (kept.choice === 'granted' || kept.choice === 'denied') && Date.now() - kept.at < KEEP_FOR) {
                return kept.choice;
            }
        } catch (error) {
            // Storage is blocked: ask again on each page rather than assume a yes.
        }

        return null;
    }

    function remember(choice) {
        try {
            window.localStorage.setItem(STORE, JSON.stringify({ choice: choice, at: Date.now() }));
        } catch (error) {
            // Nothing to do. The choice still holds for this page.
        }
    }

    // Only the details listed for an event leave the page, and only with a value from that detail's list.
    function clean(name, details) {
        var allowed = config.allowed[name];

        if (!allowed) {
            return null;
        }

        var safe = {};

        for (var key in allowed) {
            if (details && typeof details[key] === 'string' && allowed[key].indexOf(details[key]) !== -1) {
                safe[key] = details[key];
            }
        }

        return safe;
    }

    function send(name, details) {
        var safe = clean(name, details);

        if (safe) {
            gtag('event', name, safe);
        }
    }

    // Where the visitor came from, cut down to the site itself: never the page or its query string.
    function referrer() {
        try {
            return document.referrer ? new URL(document.referrer).origin + '/' : '';
        } catch (error) {
            return window.location.origin + '/';
        }
    }

    function load() {
        if (loaded) {
            return;
        }

        loaded = true;
        window[OFF_SWITCH] = false;
        gtag('consent', 'update', { analytics_storage: 'granted' });
        gtag('js', new Date());
        gtag('config', config.id, {
            send_page_view: counting,
            page_location: config.location,
            page_title: config.title,
            page_referrer: referrer(),
            allow_google_signals: false,
            allow_ad_personalization_signals: false
        });

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.id);
        document.head.appendChild(script);

        while (waiting.length) {
            var next = waiting.shift();
            send(next.name, next.params);
        }
    }

    function track(name, details) {
        if (answer === 'granted' && loaded) {
            send(name, details);
        } else if (answer === null && counting) {
            // Asked but not answered yet: kept for this page only, and sent if the answer is yes.
            waiting.push({ name: name, params: details });
        }
    }

    function show() {
        var template = document.getElementById('analytics-consent');

        if (banner || !template || !document.body) {
            return;
        }

        banner = template.content.firstElementChild.cloneNode(true);
        document.body.appendChild(banner);
    }

    function hide() {
        if (banner && banner.parentNode) {
            banner.parentNode.removeChild(banner);
        }

        banner = null;
    }

    function forgetCookies() {
        var host = window.location.hostname.split('.');

        document.cookie.split(';').forEach(function (part) {
            var name = part.split('=')[0].trim();

            if (name.indexOf('_ga') !== 0) {
                return;
            }

            document.cookie = name + '=; Max-Age=0; path=/';

            for (var from = 0; from < host.length - 1; from++) {
                document.cookie = name + '=; Max-Age=0; path=/; domain=' + host.slice(from).join('.');
            }
        });
    }

    function decide(choice) {
        answer = choice;
        remember(choice);
        hide();

        if (choice === 'granted') {
            window[OFF_SWITCH] = false;

            if (loaded) {
                gtag('consent', 'update', { analytics_storage: 'granted' });
            } else {
                load();
            }

            return;
        }

        waiting = [];
        // Google's own off switch, for a page that had already fetched its code.
        window[OFF_SWITCH] = true;

        if (loaded) {
            gtag('consent', 'update', { analytics_storage: 'denied' });
        }

        forgetCookies();
    }

    function closest(target, selector) {
        return target && target.closest ? target.closest(selector) : null;
    }

    document.addEventListener('click', function (event) {
        var target = closest(event.target, '[data-consent], [data-consent-open]');

        if (!target) {
            return;
        }

        if (target.hasAttribute('data-consent-open')) {
            show();
        } else {
            decide(target.getAttribute('data-consent') === 'accept' ? 'granted' : 'denied');
        }
    });

    // <form data-track="sign_up_start" data-track-plan="free"> sends that event, once, when someone first types in it.
    document.addEventListener('input', function (event) {
        var box = closest(event.target, '[data-track]');

        if (!box) {
            return;
        }

        var name = box.getAttribute('data-track');
        var details = {};

        for (var key in box.dataset) {
            if (key.length > 5 && key.indexOf('track') === 0) {
                details[key.charAt(5).toLowerCase() + key.slice(6)] = box.dataset[key];
            }
        }

        var mark = name + ' ' + JSON.stringify(details);

        if (!seen[mark]) {
            seen[mark] = true;
            track(name, details);
        }
    }, true);

    if (answer === 'granted') {
        load();
    } else if (answer === null && counting) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', show);
        } else {
            show();
        }
    } else {
        // Refused, or an app screen with no answer on record: nothing loads and nothing is kept.
        waiting = [];
    }
})(window, document);
@endverbatim
</script>
