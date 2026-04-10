/**
 * Общие фронтенд-утилиты. Экспортируется как window.App.
 */
(function () {
    'use strict';

    // ── DOM helpers ─────────────────────────────────────────
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function debounce(fn, ms) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function formatMoney(n, dec = 2) {
        return Number(n).toLocaleString('ru-RU', {
            minimumFractionDigits: dec,
            maximumFractionDigits: dec,
        });
    }

    // ── Scroll lock (iOS-safe) ──────────────────────────────
    let scrollLockY = 0;
    let scrollLockCount = 0;

    function lockBodyScroll() {
        if (scrollLockCount++ > 0) return;
        scrollLockY = window.scrollY || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollLockY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    }

    function unlockBodyScroll() {
        if (--scrollLockCount > 0) return;
        if (scrollLockCount < 0) scrollLockCount = 0;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollLockY);
    }

    // ── Focus trap / modal a11y ─────────────────────────────
    const FOCUSABLE_SELECTOR = [
        'a[href]:not([tabindex="-1"])',
        'button:not([disabled]):not([tabindex="-1"])',
        'input:not([disabled]):not([type="hidden"]):not([tabindex="-1"])',
        'select:not([disabled]):not([tabindex="-1"])',
        'textarea:not([disabled]):not([tabindex="-1"])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    function getFocusable(container) {
        return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR))
            .filter(el => el.offsetParent !== null || el === document.activeElement);
    }

    // Стек открытых модалок; верхний — активный.
    const modalStack = [];

    function openModal(overlay, options = {}) {
        const box = overlay.querySelector('.modal-box') || overlay;
        const prevFocus = document.activeElement;

        // Скрыть фон от screen readers
        const main = document.getElementById('main-content');
        const nav = document.querySelector('nav[role="navigation"]');
        if (main && !main.contains(overlay)) main.setAttribute('inert', '');
        if (nav)  nav.setAttribute('inert', '');

        overlay.classList.add('open');
        lockBodyScroll();

        function onKeydown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                closeModal(overlay);
                return;
            }
            if (e.key !== 'Tab') return;
            const focusables = getFocusable(box);
            if (focusables.length === 0) { e.preventDefault(); return; }
            const first = focusables[0];
            const last  = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        box.addEventListener('keydown', onKeydown);

        modalStack.push({ overlay, box, prevFocus, onKeydown });

        const initial = options.initialFocus
            ? box.querySelector(options.initialFocus)
            : getFocusable(box)[0];
        if (initial) {
            setTimeout(() => initial.focus(), 20);
        }
    }

    function closeModal(overlay) {
        const idx = modalStack.findIndex(m => m.overlay === overlay);
        if (idx === -1) return;
        const entry = modalStack.splice(idx, 1)[0];

        entry.box.removeEventListener('keydown', entry.onKeydown);
        overlay.classList.remove('open');
        unlockBodyScroll();

        // Пересчёт inert: если стек пуст — разблокировать фон;
        // если осталась модалка внутри main — снять inert с main.
        const main = document.getElementById('main-content');
        const nav  = document.querySelector('nav[role="navigation"]');
        if (modalStack.length === 0) {
            main?.removeAttribute('inert');
            nav?.removeAttribute('inert');
        } else {
            const top = modalStack[modalStack.length - 1];
            if (main && top.overlay && main.contains(top.overlay)) {
                main.removeAttribute('inert');
            }
        }

        try { entry.prevFocus?.focus?.(); } catch (e) {}
        overlay.dispatchEvent(new CustomEvent('modal:closed'));
    }

    // Закрытие при клике по overlay вне box
    document.addEventListener('click', (e) => {
        const overlay = e.target.closest('.modal-overlay');
        if (overlay && e.target === overlay && overlay.classList.contains('open')) {
            closeModal(overlay);
        }
    });

    // ── Confirm dialog (замена window.confirm) ─────────────
    let confirmDialogEl = null;

    function ensureConfirmDialog() {
        if (confirmDialogEl) return confirmDialogEl;
        const html = `
            <div class="modal-overlay" id="app-confirm-overlay" role="dialog" aria-modal="true" aria-labelledby="app-confirm-title">
                <div class="modal-box modal-box--form" style="max-width:440px">
                    <div class="modal-header">
                        <h2 class="modal-title" id="app-confirm-title">Подтверждение</h2>
                    </div>
                    <div class="modal-form" style="padding-top:var(--sp-4)">
                        <p id="app-confirm-message" style="margin:0;color:var(--text-secondary);line-height:1.5"></p>
                        <div class="modal-form-footer">
                            <button type="button" class="btn btn-secondary" data-confirm-action="cancel">Отмена</button>
                            <button type="button" class="btn btn-danger" data-confirm-action="ok">Подтвердить</button>
                        </div>
                    </div>
                </div>
            </div>`;
        const wrap = document.createElement('div');
        wrap.innerHTML = html;
        confirmDialogEl = wrap.firstElementChild;
        document.body.appendChild(confirmDialogEl);
        return confirmDialogEl;
    }

    function confirmDialog(message, opts = {}) {
        const el = ensureConfirmDialog();
        el.querySelector('#app-confirm-message').textContent = message;
        el.querySelector('#app-confirm-title').textContent = opts.title || 'Подтверждение';

        const okBtn     = el.querySelector('[data-confirm-action="ok"]');
        const cancelBtn = el.querySelector('[data-confirm-action="cancel"]');
        okBtn.textContent     = opts.okLabel     || 'Подтвердить';
        cancelBtn.textContent = opts.cancelLabel || 'Отмена';
        okBtn.className = 'btn ' + (opts.variant === 'primary' ? 'btn-primary' : 'btn-danger');

        return new Promise(resolve => {
            let settled = false;
            function cleanup(result) {
                if (settled) return;
                settled = true;
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                el.removeEventListener('modal:closed', onExternalClose);
                closeModal(el);
                resolve(result);
            }
            function onOk()             { cleanup(true);  }
            function onCancel()         { cleanup(false); }
            function onExternalClose()  { cleanup(false); }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            el.addEventListener('modal:closed', onExternalClose);
            openModal(el, { initialFocus: '[data-confirm-action="cancel"]' });
        });
    }

    // data-confirm на форме/кнопке — перехват submit с показом confirm
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const trigger = form.__confirmTrigger || form;
        const msg = trigger.dataset?.confirm || form.dataset?.confirm;
        if (!msg || form.dataset.confirmOk === '1') return;
        e.preventDefault();
        confirmDialog(msg, {
            variant: trigger.dataset?.confirmVariant || form.dataset?.confirmVariant,
        }).then(ok => {
            if (ok) {
                form.dataset.confirmOk = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }, true);

    // data-confirm на submit-кнопке внутри формы
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('button[type="submit"][data-confirm]');
        if (!btn) return;
        const form = btn.form;
        if (form) form.__confirmTrigger = btn;
    }, true);

    // ── CSRF ───────────────────────────────────────────────
    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    // ── Toast ───────────────────────────────────────────────
    function toast(msg, type, timeout) {
        type = type || 'success';
        timeout = timeout || 6000;
        let host = document.getElementById('toast-container');
        if (!host) {
            host = document.createElement('div');
            host.id = 'toast-container';
            document.body.appendChild(host);
        }
        const t = document.createElement('div');
        t.className = 'toast toast-' + type;
        t.setAttribute('role', 'status');
        t.textContent = msg;
        host.appendChild(t);
        requestAnimationFrame(() => t.classList.add('is-shown'));
        setTimeout(() => {
            t.classList.remove('is-shown');
            t.addEventListener('transitionend', () => t.remove(), { once: true });
            setTimeout(() => { if (t.parentNode) t.remove(); }, 600);
        }, timeout);
    }

    // ── Auto-submit фильтров ────────────────────────────────
    // <form data-auto-filter>: текстовые поля — debounce 500мс,
    // select/checkbox/radio — мгновенно, Enter — мгновенно.
    const FILTER_DEBOUNCE_MS = 500;

    function setupAutoFilter(form) {
        if (form.__autoFilterReady) return;
        form.__autoFilterReady = true;

        const submit = () => {
            if (form.__submitting) return;
            form.__submitting = true;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                form.submit();
            }
        };
        const submitDebounced = debounce(submit, FILTER_DEBOUNCE_MS);

        form.addEventListener('input', (e) => {
            const el = e.target;
            if (!el || el.disabled) return;
            const type = (el.type || '').toLowerCase();
            if (el.tagName === 'INPUT' && (type === 'text' || type === 'search' || type === 'number')) {
                submitDebounced();
            }
        });
        form.addEventListener('change', (e) => {
            const el = e.target;
            if (!el || el.disabled) return;
            const type = (el.type || '').toLowerCase();
            if (el.tagName === 'SELECT' || type === 'checkbox' || type === 'radio') {
                submit();
            }
            // date: не слушаем change — пикер Windows генерирует его при навигации
        });
        form.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const type = (e.target.type || '').toLowerCase();
                if (type === 'text' || type === 'search' || type === 'number' || type === 'date') {
                    e.preventDefault();
                    submit();
                }
            }
        });
    }

    function initAutoFilters() {
        document.querySelectorAll('form[data-auto-filter]').forEach(setupAutoFilter);
    }

    // [data-auto-submit-form]: auto-submit при change (напр. смена роли в users.php)
    function initAutoSubmitFields() {
        document.addEventListener('change', (e) => {
            const el = e.target;
            if (!el || !el.hasAttribute || !el.hasAttribute('data-auto-submit-form')) return;
            const form = el.closest('form');
            if (!form) return;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                form.submit();
            }
        });
    }

    // ── Защита от двойного сабмита ──────────────────────────
    // Блокируем submit-кнопки после первого POST. Сброс при pageshow (back/forward).
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST') return;
        if (form.hasAttribute('data-auto-filter')) return;
        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
                btn.disabled = true;
            });
        }, 0);
    });
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            document.querySelectorAll('form button[type="submit"]:disabled, form input[type="submit"]:disabled')
                .forEach(btn => { btn.disabled = false; });
        }
    });

    // ── Сохранение scrollY и фокуса при submit ─────────────
    // Восстанавливает позицию и каретку после перезагрузки страницы.
    const SCROLL_KEY = 'scrollY:' + location.pathname;
    const FOCUS_KEY  = 'focus:'   + location.pathname;
    document.addEventListener('submit', () => {
        const y = scrollLockCount > 0 ? scrollLockY : window.scrollY;
        try { sessionStorage.setItem(SCROLL_KEY, String(y)); } catch (_) {}

        const a = document.activeElement;
        if (a && a.id && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA')) {
            const snap = { id: a.id };
            try {
                if (a.selectionStart != null) {
                    snap.start = a.selectionStart;
                    snap.end   = a.selectionEnd;
                }
            } catch (_) {}
            try { sessionStorage.setItem(FOCUS_KEY, JSON.stringify(snap)); } catch (_) {}
        }
    }, true);

    function onReady() {
        try {
            const y = sessionStorage.getItem(SCROLL_KEY);
            if (y !== null) {
                sessionStorage.removeItem(SCROLL_KEY);
                window.scrollTo(0, parseInt(y, 10) || 0);
            }
        } catch (_) {}
        try {
            const raw = sessionStorage.getItem(FOCUS_KEY);
            if (raw) {
                sessionStorage.removeItem(FOCUS_KEY);
                const snap = JSON.parse(raw);
                const el = document.getElementById(snap.id);
                if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
                    el.focus({ preventScroll: true });
                    if (snap.start != null) {
                        try { el.setSelectionRange(snap.start, snap.end); } catch (_) {}
                    }
                }
            }
        } catch (_) {}
        const fd = document.getElementById('flash-data');
        if (fd && fd.dataset.msg) {
            toast(fd.dataset.msg, fd.dataset.type || 'success');
            fd.remove();
        }
        initAutoFilters();
        initAutoSubmitFields();
        initFilterPersistence();
    }

    // ── Persistence фильтров ───────────────────────────────
    // Переписываем href ссылок на фильтр-зависимые страницы,
    // подставляя сохранённые параметры из localStorage.
    const FILTER_KEYS = {
        'history.php': 'history',
        'report.php':  'report',
    };
    const STORAGE_PREFIX = 'filters:';

    function initFilterPersistence() {
        // Сохраняем текущие параметры фильтра
        const meta = document.querySelector('meta[name="filter-key"]');
        const currentKey = meta ? meta.content : null;
        if (currentKey && location.search) {
            const params = location.search.replace(/^\?/, '');
            try { localStorage.setItem(STORAGE_PREFIX + currentKey, params); } catch (_) {}
        }

        // Подставляем сохранённые параметры в ссылки
        function rewriteHref(a) {
            let url;
            try { url = new URL(a.href, location.href); } catch (_) { return; }
            if (url.origin !== location.origin) return;
            if (url.search) return;

            const fname = url.pathname.split('/').pop();
            const key = FILTER_KEYS[fname];
            if (!key) return;

            let saved;
            try { saved = localStorage.getItem(STORAGE_PREFIX + key); } catch (_) { return; }
            if (!saved) return;

            a.href = url.pathname + '?' + saved;
        }

        document.querySelectorAll('a[href]').forEach(rewriteHref);
        document.addEventListener('click', (e) => {
            const a = e.target.closest('a[href]');
            if (a) rewriteHref(a);
        }, true);
    }

    // ── Экспорт ─────────────────────────────────────────────
    window.App = {
        escHtml,
        debounce,
        formatMoney,
        lockBodyScroll,
        unlockBodyScroll,
        openModal,
        closeModal,
        confirmDialog,
        csrfToken,
        getFocusable,
        toast,
        setupAutoFilter,
    };

    // Совместимость с Cloudflare Rocket Loader: если DOM уже готов — вызываем сразу.
    if (document.readyState === 'loading') {
        window.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
