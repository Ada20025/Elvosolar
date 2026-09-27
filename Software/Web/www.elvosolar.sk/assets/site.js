/* ============================================================
   ELVOSOLAR — zdieľané skripty (animácie + notifikácie)
   Načítané na každej stránke. Nahrať na FTP do /assets/
   ============================================================ */
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
       Otvorenie nastavení z pätičky: elvoOpenCookies() */
    var CONSENT_KEY = 'elvo_cookie_consent_v2';

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
            saved: 'Nastavenie cookies bolo uložené.', footerLink: 'Nastavenia cookies'
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
            saved: 'Cookie preferences saved.', footerLink: 'Cookie settings'
        }
    };

    function cookieLang() {
        var l = localStorage.getItem('elvo_lang') || document.documentElement.lang || 'sk';
        return COOKIE_I18N[l] ? l : 'sk';
    }

    function elvoGetConsent() {
        try { return JSON.parse(localStorage.getItem(CONSENT_KEY)); } catch (e) { return null; }
    }

    function elvoSaveConsent(analytics) {
        localStorage.setItem(CONSENT_KEY, JSON.stringify({ necessary: true, analytics: !!analytics, ts: new Date().toISOString() }));
        var banner = document.getElementById('elvo-cookie-banner');
        if (banner) banner.classList.remove('ec-show');
        closeCookieModalEl();
    }

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
            '<button type="button" class="ec-btn ec-ghost" data-ec="settings">' + t.settings + '</button>' +
            '<button type="button" class="ec-btn ec-ghost" data-ec="reject">' + t.reject + '</button>' +
            '<button type="button" class="ec-btn ec-solid" data-ec="accept">' + t.accept + '</button>' +
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
        modal.innerHTML =
            '<div class="ecm-card">' +
            '<div class="ecm-head"><b>' + t.modalTitle + '</b>' +
            '<button type="button" class="ecm-x" aria-label="Zavrieť">✕</button></div>' +
            '<p class="ecm-text">' + t.modalText + '</p>' +
            '<div class="ecm-row"><div><b>' + t.necessary + '</b><span>' + t.necessaryDesc + '</span></div>' +
            '<span class="ec-tag">' + t.always + '</span></div>' +
            '<div class="ecm-row"><div><b>' + t.analytics + '</b><span>' + t.analyticsDesc + '</span></div>' +
            '<label class="ec-switch"><input type="checkbox" id="ec-analytics-toggle" checked><span class="ec-slider"></span></label></div>' +
            '<div class="ecm-actions">' +
            '<button type="button" class="ec-btn ec-solid" id="ec-save">' + t.save + '</button>' +
            '<button type="button" class="ec-btn ec-outline" id="ec-accept-all2">' + t.acceptAll + '</button>' +
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

        // Diskrétny odkaz na nastavenie cookies v pätičke každej stránky
        var footer = document.querySelector('footer');
        if (footer && !footer.querySelector('.ec-footer-link')) {
            var link = document.createElement('button');
            link.type = 'button';
            link.className = 'ec-footer-link';
            link.textContent = '🍪 ' + COOKIE_I18N[cookieLang()].footerLink;
            link.addEventListener('click', openCookieModalEl);
            footer.appendChild(link);
        }
    }

    function openCookieModalEl() {
        var modal = document.getElementById('elvo-cookie-modal');
        var backdrop = document.getElementById('elvo-cookie-backdrop');
        var c = elvoGetConsent();
        var toggle = modal && modal.querySelector('#ec-analytics-toggle');
        if (toggle) toggle.checked = c ? !!c.analytics : true;
        if (modal) modal.classList.add('ec-open');
        if (backdrop) backdrop.classList.add('ec-show');
    }
    function closeCookieModalEl() {
        var modal = document.getElementById('elvo-cookie-modal');
        var backdrop = document.getElementById('elvo-cookie-backdrop');
        if (modal) modal.classList.remove('ec-open');
        if (backdrop) backdrop.classList.remove('ec-show');
    }
    window.elvoOpenCookies = openCookieModalEl;

    function initCookies() {
        buildCookieUI();
        if (!elvoGetConsent()) {
            setTimeout(function () {
                var banner = document.getElementById('elvo-cookie-banner');
                if (banner) banner.classList.add('ec-show');
            }, 900);
        }
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

    /* ---------------- NOTIFIKAČNÝ PANEL (odber noviniek) ----------------
       Formulár .elvo-notify → send.php action=newsletter → mail() */
    function bindNotifyForms() {
        var forms = document.querySelectorAll('form.elvo-notify');
        forms.forEach(function (form) {
            if (form.dataset.elvoBound) return;
            form.dataset.elvoBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = form.querySelector('input[type="email"]');
                var btn = form.querySelector('button[type="submit"]');
                var email = input ? input.value.trim() : '';
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    elvoNotify({ kind: 'error', title: 'Skontrolujte e-mail', message: 'Zadajte platnú e-mailovú adresu pre odber noviniek.' });
                    if (input) input.focus();
                    return;
                }
                var honeypot = form.querySelector('input[name="website"]');
                var payload = { action: 'newsletter', email: email, page: location.pathname, website: honeypot ? honeypot.value : '' };
                if (btn) { btn.disabled = true; btn.dataset.origText = btn.innerHTML; btn.innerHTML = 'Odosiela sa…'; }
                fetch(form.getAttribute('data-endpoint') || 'send.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json().catch(function () { throw new Error('badjson'); }); }).then(function (res) {
                    if (res && res.success) {
                        elvoNotify({ kind: 'success', title: 'Hotovo!', message: res.message || 'Úspešne ste prihlásený/á na notifikácie e-mailom.' });
                        if (input) input.value = '';
                    } else {
                        elvoNotify({ kind: 'error', title: 'Nepodarilo sa', message: (res && res.message) || 'Server neodoslal e-mail. Skúste to neskôr.' });
                    }
                }).catch(function () {
                    elvoNotify({ kind: 'error', title: 'Chyba siete', message: 'Nepodarilo sa spojiť so serverom. Skúste to prosím znova.' });
                }).finally(function () {
                    if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.origText || 'Prihlásiť na odber'; }
                });
            });
        });
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

    function initAll() {
        initReveal();
        bindNotifyForms();
        bindSmartButtons();
        applyDotGrid();
        initCookies();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
