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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
