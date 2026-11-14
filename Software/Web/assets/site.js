/* ============================================================
   ELVOSOLAR — zdieľané skripty (animácie + notifikácie)
   Načítané na každej stránke. Nahrať na FTP do /assets/
   ============================================================ */

/* ---------------- ČISTÁ URL ----------------
   Hosting (InfinityFree / aes.js) pridáva na koniec adresy ?i=1.
   Po načítaní ho z adresného riadka odstránime, aby URL zostala čistá.
   Beží hneď na začiatku, ešte pred zvyškom skriptov. */
(function () {
    try {
        var url = new URL(window.location.href);
        if (url.searchParams.has('i')) {
            url.searchParams.delete('i');
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        }
    } catch (e) { /* staršie prehliadače — URL necháme tak, ako je */ }
})();

(function () {
    'use strict';

    /* ---------------- TOAST NOTIFIKÁCIE ----------------
       elvoNotify({ title, message, kind, duration })
       kind: 'success' | 'error' | 'info'               */
    function ensureRoot() {
        var root = document.getElementById('elvo-notif-root');
        if (!root) {
            root = document.createElement('div');
            root.id = 'elvo-notif-root';
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            document.body.appendChild(root);
        }
        return root;
    }

    function elvoNotify(opts) {
        if (typeof opts === 'string') opts = { message: opts };
        opts = opts || {};
        var root = ensureRoot();
        var kinds = { success: '✅', error: '⚠️', info: '🔔' };
        var kind = kinds[opts.kind] ? opts.kind : 'info';

        var toast = document.createElement('div');
        toast.className = 'elvo-toast';
        toast.setAttribute('data-kind', kind);

        var ico = document.createElement('span');
        ico.className = 'toast-ico';
        ico.textContent = kinds[kind];

        var msg = document.createElement('div');
        msg.className = 'toast-msg';
        if (opts.title) {
            var b = document.createElement('b');
            b.textContent = opts.title;
            msg.appendChild(b);
        }
        msg.appendChild(document.createTextNode(opts.message || ''));

        var x = document.createElement('button');
        x.className = 'toast-x';
        x.setAttribute('aria-label', 'Zavrieť oznámenie');
        x.textContent = '✕';

        var progress = document.createElement('span');
        progress.className = 'toast-progress';
        var duration = Math.max(2500, opts.duration || 5000);
        progress.style.animationDuration = duration + 'ms';

        toast.appendChild(ico);
        toast.appendChild(msg);
        toast.appendChild(x);
        toast.appendChild(progress);
        root.appendChild(toast);

        requestAnimationFrame(function () {
            requestAnimationFrame(function () { toast.classList.add('show'); });
        });

        function close() {
            toast.classList.remove('show');
            toast.classList.add('hide');
            setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 350);
        }
        x.addEventListener('click', close);
        setTimeout(close, duration);
        return close;
    }
    window.elvoNotify = elvoNotify;

    /* ---------------- COOKIES (GDPR) ----------------
       Moderný banner + modal s prepináčmi. Súhlas sa ukladá do
       localStorage (elvo_cookie_consent_v2) na 12 mesiacov.
       Banner sa zobrazí raz za session (bez súhlasu).
       Otvorenie nastavení z pätičky: elvoOpenCookies() */
    var CONSENT_KEY = 'elvo_cookie_consent_v2';
    var EC_PRIVACY_PDF = 'Png/ochrana-osobnych-udajov.pdf';
    var ecReturnFocus = null;

    var COOKIE_I18N = {
        sk: {
            title: 'Vážime si vaše súkromie',
            text: 'Používame cookies na základné fungovanie stránky a — po vašom súhlase — na anonymnú analýzu návštevnosti, aby sme web zlepšovali.',
            settings: 'Nastavenia', reject: 'Len nevyhnutné', accept: 'Prijať všetko',
            modalTitle: '🍪 Nastavenie cookies',
            modalText: 'Vyberte, ktoré kategórie cookies povolíte. Váš výber uložíme na 12 mesiacov, kedykoľvek ho môžete zmeniť.',
            necessary: 'Nevyhnutné', necessaryDesc: 'Zabezpečujú základné funkcie stránky — jazyk, súhlas s cookies. Bez nich web nefunguje.',
            analytics: 'Analytické', analyticsDesc: 'Anonymná štatistika návštevnosti. Neukladáme žiadne osobné údaje.',
            always: 'Vždy aktívne', save: 'Uložiť predvoľby', acceptAll: 'Prijať všetko',
            saved: 'Nastavenie cookies bolo uložené.', footerLink: 'Nastavenia cookies',
            privacy: 'Zásady ochrany osobných údajov', privacyText: 'Viac o ochrane súkromia:'
        },
        en: {
            title: 'We value your privacy',
            text: 'We use cookies for basic site functionality and — with your consent — anonymous traffic analytics to improve our website.',
            settings: 'Settings', reject: 'Necessary only', accept: 'Accept all',
            modalTitle: '🍪 Cookie settings',
            modalText: 'Choose which cookie categories to allow. We store your choice for 12 months; you can change it anytime.',
            necessary: 'Necessary', necessaryDesc: 'Basic site functions — language, cookie consent. The site does not work without them.',
            analytics: 'Analytics', analyticsDesc: 'Anonymous traffic statistics. No personal data is stored.',
            always: 'Always active', save: 'Save preferences', acceptAll: 'Accept all',
            saved: 'Cookie preferences saved.', footerLink: 'Cookie settings',
            privacy: 'Privacy Policy', privacyText: 'More about privacy:'
        },
        de: {
            title: 'Wir schätzen Ihre Privatsphäre',
            text: 'Wir verwenden Cookies für den Grundbetrieb der Website und — mit Ihrer Zustimmung — für anonyme Zugriffsstatistiken zur Verbesserung unseres Webauftritts.',
            settings: 'Einstellungen', reject: 'Nur notwendige', accept: 'Alle akzeptieren',
            modalTitle: '🍪 Cookie-Einstellungen',
            modalText: 'Wählen Sie, welche Cookie-Kategorien Sie zulassen möchten. Ihre Auswahl wird 12 Monate gespeichert und kann jederzeit geändert werden.',
            necessary: 'Notwendig', necessaryDesc: 'Grundfunktionen der Website — Sprache, Cookie-Zustimmung. Ohne sie funktioniert die Website nicht.',
            analytics: 'Analytisch', analyticsDesc: 'Anonyme Zugriffsstatistiken. Es werden keine personenbezogenen Daten gespeichert.',
            always: 'Immer aktiv', save: 'Einstellungen speichern', acceptAll: 'Alle akzeptieren',
            saved: 'Cookie-Einstellungen wurden gespeichert.', footerLink: 'Cookie-Einstellungen',
            privacy: 'Datenschutzerklärung', privacyText: 'Mehr zum Datenschutz:'
        }
    };

    function readStorage(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function writeStorage(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* súkromný režim */ }
    }

    function cookieLang() {
        var l = readStorage('elvo_lang') || document.documentElement.lang || 'sk';
        return COOKIE_I18N[l] ? l : 'sk';
    }

    function elvoGetConsent() {
        try { return JSON.parse(localStorage.getItem(CONSENT_KEY)); } catch (e) { return null; }
    }

    function elvoSaveConsent(analytics) {
        try {
            localStorage.setItem(CONSENT_KEY, JSON.stringify({
                necessary: true,
                analytics: !!analytics,
                v: 2,
                ts: new Date().toISOString()
            }));
        } catch (e) { /* súkromný režim */ }
        var banner = document.getElementById('elvo-cookie-banner');
        if (banner) banner.classList.remove('ec-show');
        closeCookieModalEl();
    }

    /* API pre ďalšie skripty (napr. budúca analytika):
       elvoHasConsent('analytics') → true/false. Bez súhlasu sa
       nesmie načítať žiadny analytický skript. */
    window.elvoHasConsent = function (cat) {
        var c = elvoGetConsent();
        return !!(c && c[cat]);
    };

    function buildCookieUI() {
        if (document.getElementById('elvo-cookie-banner')) return;
        var t = COOKIE_I18N[cookieLang()];

        // Banner
        var banner = document.createElement('div');
        banner.id = 'elvo-cookie-banner';
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', t.title);
        banner.innerHTML =
            '<div class="ec-head"><span class="ec-ico" aria-hidden="true">🍪</span><b>' + t.title + '</b></div>' +
            '<p class="ec-text">' + t.text + '</p>' +
            '<div class="ec-actions">' +
            '<button type="button" class="ec-btn ec-solid" data-ec="accept">' + t.accept + '</button>' +
            '<button type="button" class="ec-btn ec-neutral" data-ec="reject">' + t.reject + '</button>' +
            '</div>' +
            '<div class="ec-foot">' +
            '<button type="button" class="ec-btn ec-ghost" data-ec="settings">' + t.settings + '</button>' +
            '<a class="ec-privacy" href="' + EC_PRIVACY_PDF + '" target="_blank" rel="noopener">' + t.privacy + '</a>' +
            '</div>';
        banner.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-ec]');
            if (!b) return;
            var a = b.getAttribute('data-ec');
            if (a === 'accept') elvoSaveConsent(true);
            else if (a === 'reject') elvoSaveConsent(false);
            else if (a === 'settings') openCookieModalEl();
        });

        // Backdrop + modal s prepináčmi
        var backdrop = document.createElement('div');
        backdrop.id = 'elvo-cookie-backdrop';
        backdrop.addEventListener('click', closeCookieModalEl);

        var modal = document.createElement('div');
        modal.id = 'elvo-cookie-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', t.modalTitle);
        modal.setAttribute('tabindex', '-1');
        modal.innerHTML =
            '<div class="ecm-card">' +
            '<div class="ecm-head"><b>' + t.modalTitle + '</b>' +
            '<button type="button" class="ecm-x" aria-label="Zavrieť">✕</button></div>' +
            '<p class="ecm-text">' + t.modalText + '</p>' +
            '<div class="ecm-row"><div><b>' + t.necessary + '</b><span>' + t.necessaryDesc + '</span></div>' +
            '<span class="ec-tag">' + t.always + '</span></div>' +
            '<div class="ecm-row"><div><b id="ecm-analytics-label">' + t.analytics + '</b><span>' + t.analyticsDesc + '</span></div>' +
            '<label class="ec-switch"><input type="checkbox" id="ec-analytics-toggle" aria-labelledby="ecm-analytics-label"><span class="ec-slider"></span></label></div>' +
            '<div class="ecm-actions">' +
            '<button type="button" class="ec-btn ec-solid" id="ec-accept-all2">' + t.acceptAll + '</button>' +
            '<button type="button" class="ec-btn ec-outline" id="ec-save">' + t.save + '</button>' +
            '</div>' +
            '<p class="ecm-privacy">' + t.privacyText + ' <a href="' + EC_PRIVACY_PDF + '" target="_blank" rel="noopener">' + t.privacy + '</a></p>' +
            '</div></div>';
        modal.querySelector('.ecm-x').addEventListener('click', closeCookieModalEl);
        modal.querySelector('#ec-save').addEventListener('click', function () {
            elvoSaveConsent(modal.querySelector('#ec-analytics-toggle').checked);
            elvoNotify({ kind: 'success', title: '🍪', message: COOKIE_I18N[cookieLang()].saved });
        });
        modal.querySelector('#ec-accept-all2').addEventListener('click', function () { elvoSaveConsent(true); });

        document.body.appendChild(banner);
        document.body.appendChild(backdrop);
        document.body.appendChild(modal);

        /* Esc zatvára modal nastavení (štandard prístupnosti dialógov) */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('ec-open')) closeCookieModalEl();
        });

        // Diskrétny odkaz na nastavenie cookies v pätičke každej stránky
        // (ak stránka má vycentrovaný riadok .ec-footer-row, vložíme ho tam)
        var footer = document.querySelector('footer');
        if (footer && !footer.querySelector('.ec-footer-link')) {
            var link = document.createElement('button');
            link.type = 'button';
            link.className = 'ec-footer-link';
            link.setAttribute('aria-haspopup', 'dialog');
            link.textContent = '🍪 ' + COOKIE_I18N[cookieLang()].footerLink;
            link.addEventListener('click', openCookieModalEl);
            var targetRow = footer.querySelector('.ec-footer-row') || footer;
            targetRow.appendChild(link);
        }
    }

    function openCookieModalEl() {
        var modal = document.getElementById('elvo-cookie-modal');
        var backdrop = document.getElementById('elvo-cookie-backdrop');
        var c = elvoGetConsent();
        var toggle = modal && modal.querySelector('#ec-analytics-toggle');
        /* Bez predchádzajúceho súhlasu je voliteľná kategória VYPNUTÁ
           (žiadne vopred zaškrtnuté boxy — GDPR/ePrivacy). */
        if (toggle) toggle.checked = c ? !!c.analytics : false;
        ecReturnFocus = document.activeElement;
        if (modal) {
            modal.classList.add('ec-open');
            modal.focus();
        }
        if (backdrop) backdrop.classList.add('ec-show');
    }
    function closeCookieModalEl() {
        var modal = document.getElementById('elvo-cookie-modal');
        var backdrop = document.getElementById('elvo-cookie-backdrop');
        if (modal) modal.classList.remove('ec-open');
        if (backdrop) backdrop.classList.remove('ec-show');
        /* Vrátenie fokusu na spúšťajúci prvok (prístupnosť dialógov) */
        if (ecReturnFocus && typeof ecReturnFocus.focus === 'function') {
            try { ecReturnFocus.focus(); } catch (e) { /* element zmizol z DOM */ }
        }
        ecReturnFocus = null;
    }
    window.elvoOpenCookies = openCookieModalEl;

    function initCookies() {
        buildCookieUI();
        if (!elvoGetConsent() && !elvoCookieSeenThisSession()) {
            elvoMarkCookieSeen();
            setTimeout(function () {
                var banner = document.getElementById('elvo-cookie-banner');
                if (banner) banner.classList.add('ec-show');
            }, 900);
        }
    }

    function elvoCookieSeenThisSession() {
        try { return !!sessionStorage.getItem('elvo_cookie_seen_session'); } catch (e) { return false; }
    }
    function elvoMarkCookieSeen() {
        try { sessionStorage.setItem('elvo_cookie_seen_session', '1'); } catch (e) { /* súkromný režim */ }
    }

    /* ---------------- SCROLL-REVEAL ----------------
       .reveal-item sa plynulo objaví pri scrollovaní. */
    function initReveal() {
        var items = document.querySelectorAll('.reveal-item');
        if (!items.length) return;
        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('reveal-in'); });
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('reveal-in');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        items.forEach(function (el) { observer.observe(el); });
    }

    /* ---------------- SMART TLAČIDLÁ ----------------
       .elvo-smart[data-smart="kalkulacka|dopyt|kontakt"] plynulo
       prescrolluje na cieľovú sekciu (náhrada za e-shop tlačidlá). */
    function bindSmartButtons() {
        document.querySelectorAll('.elvo-smart[data-smart]').forEach(function (btn) {
            if (btn.dataset.elvoBound) return;
            btn.dataset.elvoBound = '1';
            btn.addEventListener('click', function (e) {
                var target = document.getElementById(btn.getAttribute('data-smart'));
                if (!target) return;
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
    }

    /* ---------------- DOT-GRID pozadie ----------------
       Sekciam .bg-slate-900 / tmavým hero sekciám pridá jemný
       animovaný bodkovaný vzor bez ručného prepisovania HTML. */
    function applyDotGrid() {
        document.querySelectorAll('section.bg-slate-900, section.bg-brand-dark').forEach(function (sec) {
            if (sec.dataset.elvoDots) return;
            sec.dataset.elvoDots = '1';
            sec.classList.add('dot-grid');
            sec.style.backgroundImage = 'radial-gradient(rgba(16,185,129,.14) 1px, transparent 1px)';
            sec.style.backgroundSize = '16px 16px';
        });
    }

    /* ---------------- DROPDOWN HOVER (MYŠ / PERO) ----------------
       Panel sa otvorí pri prejdení kurzorom nad položku menu a zavrie
       po krátkom odklade po odchode kurzora. Typ vstupu sa vyhodnocuje
       pri každej udalosti (pointerType), nie cez media query — tá na
       hybridných zariadeniach (dotykový notebook, tablet s myšou)
       vychádza false a menu sa vôbec neotváralo. Dotyk hover nespúšťa,
       tam zostáva kliknutie (a kliknutie mimo / Escape zavrie). */
    function initDropdownHover() {
        function isTouch(e) {
            return !!e && (e.pointerType === 'touch' || e.pointerType === 'pen');
        }

        document.querySelectorAll('[data-dropdown-host]').forEach(function (container) {
            var panel = container.querySelector('[id$="-dropdown"]');
            var btn = container.querySelector('button[aria-haspopup="true"]');
            if (!panel) return;
            var timer = null;

            function openPanel() {
                if (timer) { clearTimeout(timer); timer = null; }
                document.querySelectorAll('[id$="-dropdown"]').forEach(function (other) {
                    if (other !== panel) {
                        other.classList.add('hidden');
                        var ob = other.parentElement && other.parentElement.querySelector('button[aria-haspopup="true"]');
                        if (ob) ob.setAttribute('aria-expanded', 'false');
                    }
                });
                panel.classList.remove('hidden');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            }

            function closePanel() {
                if (timer) { clearTimeout(timer); timer = null; }
                panel.classList.add('hidden');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }

            function scheduleClose() {
                if (timer) clearTimeout(timer);
                timer = setTimeout(closePanel, 250);
            }

            function onEnter(e) {
                if (isTouch(e)) return;
                openPanel();
            }

            function onLeave(e) {
                if (isTouch(e)) return;
                scheduleClose();
            }

            /* pointerenter/leave sa nešíria (bubbles: false), preto ich
               vešieme priamo na kontajner; panel je jeho potomok, takže
               prechod myši do panelu nevyvolá pointerleave. */
            if (window.PointerEvent) {
                container.addEventListener('pointerenter', onEnter);
                container.addEventListener('pointerleave', onLeave);
            } else {
                container.addEventListener('mouseenter', onEnter);
                container.addEventListener('mouseleave', onLeave);
            }

            /* Držanie otvoreného panelu, keď je v ňom fokus (klávesnica) */
            container.addEventListener('focusin', function () {
                if (timer) { clearTimeout(timer); timer = null; }
            });
            container.addEventListener('focusout', function (e) {
                if (e.relatedTarget && container.contains(e.relatedTarget)) return;
                scheduleClose();
            });
        });
    }

    /* Fragment linky (`#prehlad`, `#kontakt-formular`, …).
       Na 404 stránke a pri jazykových prefixoch (napr. /sk/kontakt)
       server vloží <base href="/"> — kvôli nemu by prehliadač fragment
       resolvoval voči base a namiesto scrollovania by navigoval na /
       (alebo na inú stránku). Preto default zabránime a scrolneme
       programovo. Delegované na document → funguje aj pre neskôr
       vložené elementy. Bez <base> sa chová rovnako ako štandardný
       fragmentový odkaz. */
    function initFragmentLinks() {
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
            if (!a) return;
            var id = a.getAttribute('href').slice(1);
            if (!id) return;
            var target = document.getElementById(id);
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    /* Práce, ktoré netreba stihnúť pred prvým vykreslením, odložíme
       na voľné hlavné vlákno — skráti to blokovanie (TBT). */
    function whenIdle(fn) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(fn, { timeout: 1200 });
        } else {
            setTimeout(fn, 200);
        }
    }

    function initAll() {
        initReveal();
        bindSmartButtons();
        applyDotGrid();
        initDropdownHover();
        initFragmentLinks();
        /* Cookie UI (banner + modal) nie je potrebné stavať počas
           kritického renderu — stačí o pár stoviek ms neskôr. */
        whenIdle(initCookies);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

/* ---------- NEVIDITEĽNÁ OCHRANA PROTI BOTOM (bez zaškrtávacieho poľa) ----------
   Do každého formulára pridá skryté návnadové pole a k odosielanej požiadavke
   priloží dôkazy, že formulár vyplnil človek v skutočnom prehliadači:
     elvo_hp    – návnada, ktorú vyplnia len boti (musí zostať prázdna),
     form_js    – príznak, že stránka beží s JavaScriptom,
     form_time  – sekundy od načítania stránky (bot odosiela formulár okamžite).
   Overuje to send.php. Používateľ nič nezaškrtáva ani nevypĺňa. */
(function () {
    'use strict';
    var START = Date.now();
    var FIELD = 'elvo_hp';

    function addHoneypots() {
        document.querySelectorAll('form').forEach(function (form) {
            if (form.querySelector('input[name="' + FIELD + '"]')) return;
            var trap = document.createElement('input');
            trap.type = 'text';
            trap.name = FIELD;
            trap.value = '';
            trap.tabIndex = -1;
            trap.autocomplete = 'off';
            trap.setAttribute('aria-hidden', 'true');
            trap.setAttribute('data-lpignore', 'true');
            trap.setAttribute('data-1p-ignore', 'true');
            trap.setAttribute('data-bwignore', 'true');
            trap.style.cssText = 'position:absolute;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none;';
            form.appendChild(trap);
        });
    }

    /* Doplní do payloadu údaje, ktoré server používa na rozlíšenie človeka a bota. */
    window.elvoAntiBot = function (payload) {
        payload = payload || {};
        var trap = document.querySelector('form input[name="' + FIELD + '"]');
        payload[FIELD] = trap ? String(trap.value || '') : '';
        payload.form_js = 1;
        payload.form_time = Math.round((Date.now() - START) / 1000);
        return payload;
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addHoneypots);
    } else {
        addHoneypots();
    }
})();

/* ---------- ODOSIELANIE FORMULÁROV + ANIMÁCIA ODLETU LIETADLA ---------- */
(function () {
    var TITLE = { sk: 'Ďakujeme!', en: 'Thank you!', de: 'Danke!' };
    var TEXT = {
        sk: 'Ozveme sa vám čo najskôr.',
        en: 'We will get back to you as soon as possible.',
        de: 'Wir melden uns so bald wie möglich.'
    };
    var timer = null;

    function pageLang() {
        var lang = 'sk';
        try {
            if (typeof currentLang !== 'undefined' && currentLang) lang = currentLang;
            else lang = localStorage.getItem('elvo_lang') || 'sk';
        } catch (e) { /* ignore */ }
        return TITLE[lang] ? lang : 'sk';
    }

    function elvoPlaneClose() {
        var o = document.getElementById('elvo-plane-overlay');
        if (o) o.classList.remove('is-open');
    }

    /* Jednotné odoslanie na send.php — vracia Promise<{ok, data}> */
    function elvoSendForm(payload) {
        return fetch('send.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify(elvoAntiBot(payload))
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        });
    }

    /* Úspešná animácia: lietadlo odletí → prihláska „Ozveme sa vám čo najskôr.“ */
    function elvoPlaneSuccess(message, title) {
        var overlay = document.getElementById('elvo-plane-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'elvo-plane-overlay';
            overlay.id = 'elvo-plane-overlay';
            overlay.innerHTML =
                '<div class="elvo-plane-card" role="status" aria-live="polite">' +
                    '<span class="elvo-plane-icon" aria-hidden="true">✈️</span>' +
                    '<div class="elvo-plane-check" aria-hidden="true">✓</div>' +
                    '<p class="elvo-plane-title"></p>' +
                    '<p class="elvo-plane-text"></p>' +
                    '<button type="button" class="elvo-plane-close">Zavrieť</button>' +
                '</div>';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay || e.target.classList.contains('elvo-plane-close')) {
                    elvoPlaneClose();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') elvoPlaneClose();
            });
        }
        var lang = pageLang();
        overlay.querySelector('.elvo-plane-title').textContent = title || TITLE[lang];
        var base = message || '';
        overlay.querySelector('.elvo-plane-text').textContent = base ? (base + '\n' + TEXT[lang]) : TEXT[lang];

        // Reinštancia animácií pri opakovanom otvorení
        overlay.classList.remove('is-open');
        void overlay.offsetWidth;
        overlay.classList.add('is-open');

        clearTimeout(timer);
        timer = setTimeout(elvoPlaneClose, 6000);
    }

    window.elvoSendForm = elvoSendForm;
    window.elvoPlaneSuccess = elvoPlaneSuccess;
})();

/* ============================================================
   ELVOSOLAR — ZDIEĽANÝ LOKALIZAČNÝ ENGINE + NAVIGÁCIA
   ------------------------------------------------------------
   Preklady sa načítavajú z preklad/<JAZYK>/:
     spolocne.json      ... nav, pätička, kontaktný blok (každá stránka)
     <stranka>.json     ... len texty tejto konkrétnej stránky
   Stránka deklaruje svoj súbor cez <html data-page="služby">
   (bez prípony a bez diakritiky — zhodné s názvom .json súboru).
   ============================================================ */
(function () {
    'use strict';

    var LANG_FOLDER = { sk: 'SK', en: 'AN', de: 'DE' };
    var LANG_FALLBACK = 'sk';

    var currentLang = 'sk';
    try { currentLang = localStorage.getItem('elvo_lang') || 'sk'; } catch (e) { /* blokované úložisko */ }
    if (!LANG_FOLDER[currentLang]) currentLang = 'sk';
    var currentTranslations = {};

    function pageId() {
        return document.documentElement.getAttribute('data-page') || 'index';
    }

    function getNestedValue(obj, path) {
        return path.split('.').reduce(function (acc, part) {
            return (acc && acc[part] !== undefined) ? acc[part] : undefined;
        }, obj);
    }

    /* hĺbkové zlúčenie: spolocne.json + <stranka>.json */
    function deepMerge(target, src) {
        for (var k in src) {
            if (!Object.prototype.hasOwnProperty.call(src, k)) continue;
            var v = src[k];
            if (v && typeof v === 'object' && !Array.isArray(v)) {
                if (!target[k] || typeof target[k] !== 'object') target[k] = {};
                deepMerge(target[k], v);
            } else {
                target[k] = v;
            }
        }
        return target;
    }

    function fetchJson(url) {
        return fetch(url).then(function (r) {
            return r.ok ? r.json() : null;
        }).catch(function () { return null; });
    }

    function applyTranslations(dict, lang) {
        document.querySelectorAll('[data-translate]').forEach(function (el) {
            var value = getNestedValue(dict, el.getAttribute('data-translate'));
            if (value === undefined) return;
            /* Ak je v HTML už presne ten istý text (typicky slovenčina),
               nerobíme innerHTML — vynechaný zápis = žiadny reflow/layout. */
            if (typeof value === 'string' && el.textContent.trim() === value.trim()) return;
            el.innerHTML = value;
        });
        document.querySelectorAll('[data-translate-placeholder]').forEach(function (el) {
            var raw = el.getAttribute('data-translate-placeholder');
            var value = getNestedValue(dict, raw);
            /* ak atribút neobsahuje kľúč ale rovno text, použijeme jeho */
            if (value === undefined) value = raw;
            if (value) el.setAttribute('placeholder', value);
        });
        document.documentElement.lang = lang;
        var badge = document.getElementById('current-lang');
        if (badge) badge.textContent = lang.toUpperCase();
        try { localStorage.setItem('elvo_lang', lang); } catch (e) { /* blokované úložisko */ }
        currentLang = lang;
    }

    /* Načíta daný jazyk: spolocne.json + <stranka>.json.
       Pri chýbajúcom súbore stránky sa použijú len spoločné texty. */
    function loadLanguage(lang) {
        var folder = LANG_FOLDER[lang] || 'SK';
        var page = pageId();
        return Promise.all([
            fetchJson('preklad/' + folder + '/spolocne.json'),
            fetchJson('preklad/' + folder + '/' + page + '.json')
        ]).then(function (parts) {
            var dict = {};
            if (parts[0]) deepMerge(dict, parts[0]);
            if (parts[1]) deepMerge(dict, parts[1]);

            if (!parts[0] && !parts[1]) {
                /* Server nedorušil JSON (napr. lokálne otvorenie súboru)
                   → aspoň vstavaná slovenská záloha z HTML. */
                var fb = document.getElementById('elvo-sk-fallback');
                if (fb) {
                    try { dict = JSON.parse(fb.textContent); } catch (e) { dict = {}; }
                }
                lang = LANG_FALLBACK;
            }

            currentTranslations = dict;
            window.currentTranslations = dict;
            applyTranslations(dict, lang);
            return dict;
        });
    }

    function changeLang(lang) { loadLanguage(lang); }

    /* ---------- NAVIGÁCIA: hamburger + všetky dropdowny ---------- */
    function initNavigation() {
        /* Mobilné hamburger menu */
        var btn = document.getElementById('mobile-menu-btn');
        var menu = document.getElementById('mobile-menu');
        var icon = document.getElementById('hamburger-icon');
        if (btn && menu) {
            btn.addEventListener('click', function () {
                var open = menu.classList.toggle('hidden') === false;
                if (icon) icon.setAttribute('d', open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16');
                btn.setAttribute('aria-expanded', String(open));
            });
        }

        /* Mobilné akordeóny: button[aria-controls] → panel */
        document.querySelectorAll('#mobile-menu button[aria-controls]').forEach(function (b) {
            var panel = document.getElementById(b.getAttribute('aria-controls'));
            if (!panel) return;
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = panel.classList.toggle('hidden') === false;
                b.setAttribute('aria-expanded', String(open));
            });
        });

        /* Posledný typ vstupu — dotyk má prepínať, myš otvárať. */
        var lastPointer = 'mouse';
        document.addEventListener('pointerdown', function (e) {
            lastPointer = e.pointerType || 'mouse';
        }, true);

        /* Desktop dropdowny: button[aria-haspopup] → najbližší .ec-menu panel */
        var panels = [];
        document.querySelectorAll('button[aria-haspopup="true"]').forEach(function (b) {
            var host = b.closest('[data-dropdown-host]') || b.parentElement;
            var panel = host ? host.querySelector('.ec-menu') : null;
            if (!panel) return;
            panels.push(panel);
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                /* Myš: klik panel otvorí (zavrie ho až odchod/klik mimo).
                   Dotyk: klik prepína, aby sa dalo zavrieť aj bez myši. */
                var willOpen = lastPointer === 'touch'
                    ? panel.classList.contains('hidden')
                    : true;
                closeAll();
                panel.classList.toggle('hidden', !willOpen);
                b.setAttribute('aria-expanded', String(willOpen));
            });
        });

        function closeAll() {
            panels.forEach(function (p) { p.classList.add('hidden'); });
            document.querySelectorAll('button[aria-haspopup="true"]').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
        }

        document.addEventListener('click', closeAll);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll();
        });
    }

    window.ElvoI18n = {
        load: loadLanguage,
        change: changeLang,
        get: function () { return currentTranslations; },
        lang: function () { return currentLang; },
        t: function (key) { return getNestedValue(currentTranslations, key); }
    };

    /* Globály pre staré inline handlery (onclick="changeLang('sk')" atď.) */
    window.changeLang = changeLang;
    window.loadLanguage = loadLanguage;
    window.getNestedValue = getNestedValue;
    window.currentTranslations = currentTranslations;
    window.__elvoSetDict = function (d) { currentTranslations = d; window.currentTranslations = d; };

    /* currentLang musí zostať globálne čitateľný (inline skripty stránok) */
    Object.defineProperty(window, 'currentLang', {
        configurable: true,
        get: function () { return currentLang; }
    });

    /* Spustenie mimo kritickej renderovacej cesty (neblokuje TBT). */
    function whenIdle(fn) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(fn, { timeout: 1500 });
        } else {
            setTimeout(fn, 250);
        }
    }

    function boot() {
        initNavigation();
        if (currentLang === 'sk') {
            /* SK texty sú už priamo v HTML — netreba ich hneď prepisovať.
               Preklady dotiahneme až keď je hlavné vlákno voľné. */
            whenIdle(function () { loadLanguage('sk'); });
        } else {
            /* Iný jazyk: texty treba doplniť čo najskôr, aby nič neblikalo. */
            setTimeout(function () { loadLanguage(currentLang); }, 40);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
