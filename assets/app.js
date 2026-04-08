/**
 * Общие фронтенд-утилиты для всех страниц.
 * Подключается в layout.php через <script src="assets/app.js" defer>.
 *
 * Экспортируется глобально как window.App, чтобы страничные скрипты
 * могли обращаться без модульной системы.
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

    /**
     * Стек открытых модалок. Верхний — активный.
     * Каждая запись: { overlay, prevFocus, onKeydown }
     */
    const modalStack = [];

    function openModal(overlay, options = {}) {
        const box = overlay.querySelector('.modal-box') || overlay;
        const prevFocus = document.activeElement;

        // Спрятать фоновый контент от AT
        const main = document.getElementById('main-content');
        const nav = document.querySelector('nav[role="navigation"]');
        if (main && !main.contains(overlay)) main.setAttribute('inert', '');
        if (nav)  nav.setAttribute('inert', '');

        overlay.classList.add('open');
        lockBodyScroll();

        // Focus trap через keydown
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

        // Начальный фокус
        const initial = options.initialFocus
            ? box.querySelector(options.initialFocus)
            : getFocusable(box)[0];
        if (initial) {
            // setTimeout чтобы фокус не потерялся из-за animation
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

        // Пересчёт inert на фоне.
        //   • стек пуст → main и nav снова кликабельны;
        //   • в стеке осталась модалка ВНУТРИ main (например, мы закрывали
        //     confirm поверх return-modal) → снимаем inert с main, иначе
        //     оставшаяся модалка окажется «замороженной».
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

        // Вернуть фокус
        try { entry.prevFocus?.focus?.(); } catch (e) { /* element might be gone */ }

        // Уведомить подписчиков (например, confirmDialog) о закрытии
        overlay.dispatchEvent(new CustomEvent('modal:closed'));
    }

    /** Закрытие верхней модалки при клике по overlay вне box. */
    document.addEventListener('click', (e) => {
        const overlay = e.target.closest('.modal-overlay');
        if (overlay && e.target === overlay && overlay.classList.contains('open')) {
            closeModal(overlay);
        }
    });

    // ── Confirm dialog (заменяет window.confirm) ────────────
    /**
     * Асинхронный подтверждающий диалог. Возвращает Promise<boolean>.
     * Использует разметку, которая рендерится один раз по требованию.
     */
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
            // Если модалку закрыли через Esc или клик по overlay — расцениваем как «отмена»
            function onExternalClose()  { cleanup(false); }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            el.addEventListener('modal:closed', onExternalClose);
            openModal(el, { initialFocus: '[data-confirm-action="cancel"]' });
        });
    }

    // ── Декларативная разметка: data-confirm="Сообщение?" ──
    // Автоматически перехватывает submit формы/клик по ссылке, показывает confirm.
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
                form.submit();
            }
        });
    }, true);

    // Также поддержать data-confirm на кнопке внутри формы (через submitter)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('button[type="submit"][data-confirm]');
        if (!btn) return;
        const form = btn.form;
        if (form) form.__confirmTrigger = btn;
    }, true);

    // ── CSRF-токен из meta-тега ─────────────────────────────
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
            // fallback на случай, если transitionend не сработает
            setTimeout(() => { if (t.parentNode) t.remove(); }, 600);
        }, timeout);
    }

    // ── Auto-submit фильтров (универсальный) ────────────────
    // Любой <form data-auto-filter> подписывается на change/input всех
    // своих полей и сабмитит форму. Текстовые поля — с debounce 300ms.
    // select / checkbox / radio / date — мгновенно по change.
    function setupAutoFilter(form) {
        if (form.__autoFilterReady) return;
        form.__autoFilterReady = true;

        const submit = () => {
            // Не сабмитим, если страница уже уходит
            if (form.__submitting) return;
            form.__submitting = true;
            form.submit();
        };
        const submitDebounced = debounce(submit, 300);

        form.addEventListener('input', (e) => {
            const el = e.target;
            if (!el || el.disabled) return;
            // input в текстовых/числовых/дата-полях — debounce
            const tag = el.tagName;
            const type = (el.type || '').toLowerCase();
            if (tag === 'INPUT' && (type === 'text' || type === 'search' || type === 'number')) {
                submitDebounced();
            }
        });
        form.addEventListener('change', (e) => {
            const el = e.target;
            if (!el || el.disabled) return;
            const tag = el.tagName;
            const type = (el.type || '').toLowerCase();
            // select / checkbox / radio / date — мгновенно
            if (tag === 'SELECT' || type === 'checkbox' || type === 'radio'
                || type === 'date' || type === 'datetime-local' || type === 'month') {
                submit();
            }
        });
        // Enter в текстовом поле — мгновенный сабмит без ожидания debounce
        form.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const el = e.target;
                const type = (el.type || '').toLowerCase();
                if (type === 'text' || type === 'search' || type === 'number') {
                    e.preventDefault();
                    submit();
                }
            }
        });
    }

    function initAutoFilters() {
        document.querySelectorAll('form[data-auto-filter]').forEach(setupAutoFilter);
    }

    // Одиночный select/checkbox с [data-auto-submit-form] — сразу сабмитит
    // свою ближайшую <form>. Используется для inline-редактирования
    // (например, смена роли в users.php).
    function initAutoSubmitFields() {
        document.addEventListener('change', (e) => {
            const el = e.target;
            if (!el || !el.hasAttribute || !el.hasAttribute('data-auto-submit-form')) return;
            const form = el.closest('form');
            if (form) form.submit();
        });
    }

    // ── Сохранение/восстановление позиции скролла при POST ─
    // Сохраняем scrollY перед каждым сабмитом формы и восстанавливаем
    // после загрузки той же страницы. Ключ — pathname, чтобы не путать
    // позиции разных страниц.
    const SCROLL_KEY = 'scrollY:' + location.pathname;
    document.addEventListener('submit', () => {
        // Если body заблокирован модалкой — реальный scrollY === 0,
        // нужно использовать сохранённый scrollLockY.
        const y = scrollLockCount > 0 ? scrollLockY : window.scrollY;
        try { sessionStorage.setItem(SCROLL_KEY, String(y)); } catch (_) {}
    }, true);
    window.addEventListener('DOMContentLoaded', () => {
        try {
            const y = sessionStorage.getItem(SCROLL_KEY);
            if (y !== null) {
                sessionStorage.removeItem(SCROLL_KEY);
                window.scrollTo(0, parseInt(y, 10) || 0);
            }
        } catch (_) {}
        // Flash-сообщения от сервера → toast
        const fd = document.getElementById('flash-data');
        if (fd && fd.dataset.msg) {
            toast(fd.dataset.msg, fd.dataset.type || 'success');
            fd.remove();
        }
        initAutoFilters();
        initAutoSubmitFields();
        initFilterPersistence();
    });

    // ── Persistence фильтров для history/report ─────────────
    //
    // Best practice без редиректа: переписываем outgoing-ссылки на
    // фильтр-зависимые страницы ДО того, как браузер начнёт навигацию.
    // Браузер делает один HTTP-запрос сразу с нужными query-параметрами —
    // никаких location.replace() и связанных с ними «белых вспышек».
    //
    // Список страниц, которые поддерживают persistence: жёстко зашит ниже
    // (это map имя_файла → ключ_localStorage). Сами страницы декларируют
    // себя через <meta name="filter-key" content="..."> в layout.php.
    const FILTER_KEYS = {
        'history.php': 'history',
        'report.php':  'report',
    };
    const STORAGE_PREFIX = 'filters:';

    function initFilterPersistence() {
        // 1) SAVE: если текущая страница фильтр-зависимая И URL имеет search-параметры,
        //    запоминаем их. Любой submit формы, клик по сортируемому заголовку и сама
        //    пагинация сюда попадают — все они идут с параметризованным URL.
        const meta = document.querySelector('meta[name="filter-key"]');
        const currentKey = meta ? meta.content : null;
        if (currentKey && location.search) {
            const params = location.search.replace(/^\?/, '');
            try { localStorage.setItem(STORAGE_PREFIX + currentKey, params); } catch (_) {}
        }

        // 2) REWRITE: переписываем href всех ссылок, ведущих на фильтр-зависимые
        //    страницы, ДО клика. Браузер делает один запрос сразу с параметрами —
        //    нет location.replace(), нет промежуточного белого экрана.
        //
        //    Делаем это и upfront (при загрузке — для надёжной поддержки middle-click,
        //    Ctrl+click, контекстного меню), и при каждом click (для свежих значений
        //    после сохранения новых фильтров на этой же странице без перезагрузки).
        function rewriteHref(a) {
            let url;
            try { url = new URL(a.href, location.href); } catch (_) { return; }
            if (url.origin !== location.origin) return;
            if (url.search) return; // у ссылки уже есть параметры — явное намерение автора

            const fname = url.pathname.split('/').pop();
            const key = FILTER_KEYS[fname];
            if (!key) return;

            let saved;
            try { saved = localStorage.getItem(STORAGE_PREFIX + key); } catch (_) { return; }
            if (!saved) return;

            a.href = url.pathname + '?' + saved;
        }

        // Upfront rewrite — один проход по всем подходящим ссылкам
        document.querySelectorAll('a[href]').forEach(rewriteHref);

        // На случай если значения в localStorage изменились во время сессии
        // (например, пользователь нажал "Сохранить" в форме, обновилось,
        // потом кликнул в меню) — повторно переписываем при клике.
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
})();
