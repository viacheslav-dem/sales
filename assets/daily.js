/**
 * Экран «Продажи за день».
 *
 * Данные с сервера передаются через <script type="application/json"> —
 * безопасный способ, не выполняется как код (CSP-friendly) и корректно
 * парсится JSON.parse вне зависимости от содержимого.
 */
(() => {
    'use strict';

    const bootstrapEl = document.getElementById('daily-bootstrap');
    if (!bootstrapEl) return; // страница без bootstrap — ничего не делаем
    const { products: ALL_PRODUCTS, rows: INITIAL_ROWS } = JSON.parse(bootstrapEl.textContent);

    const { escHtml, formatMoney, debounce, openModal, closeModal } = window.App;
    const fmt = formatMoney;

    // ── State ───────────────────────────────────────────
    const PRODUCT_BY_ID = Object.fromEntries(ALL_PRODUCTS.map(p => [p.id, p]));
    const salesMap = {};
    const ORIGINAL = {};
    let currentMode = 0;    // 0 = продажа, 1 = возврат

    const rowKey = (pid, isReturn) => `${pid}:${isReturn ? 1 : 0}`;

    INITIAL_ROWS.forEach(r => {
        const p = PRODUCT_BY_ID[r.pid];
        const name  = p ? p.name : `Товар #${r.pid}`;
        const price = r.base_price || (p ? p.price : 0);
        const k = rowKey(r.pid, r.is_return);
        salesMap[k] = {
            id: r.pid, name, price,
            unit_price: r.unit_price, qty: r.qty,
            isReturn: r.is_return === 1,
        };
        ORIGINAL[k] = { qty: r.qty, unit_price: r.unit_price };
    });

    // ── DOM refs ────────────────────────────────────────
    const tbody        = document.getElementById('sales-tbody');
    const table        = document.getElementById('sales-table');
    const emptyEl      = document.getElementById('empty-state');
    const badgeEl      = document.getElementById('badge-count');
    const footQty      = document.getElementById('foot-qty');
    const footSum      = document.getElementById('foot-sum');
    const footDisc     = document.getElementById('foot-discount');
    const totalsBar    = document.getElementById('totals-bar');
    const overlay      = document.getElementById('modal-overlay');
    const modalBox     = overlay.querySelector('.modal-box');
    const modalTitle   = document.getElementById('modal-title');
    const modalSearch  = document.getElementById('modal-search');
    const modalResults = document.getElementById('modal-results');
    const modalCount   = document.getElementById('modal-count');
    const modalAdded   = document.getElementById('modal-added-info');
    const saveForm     = document.getElementById('save-form');
    const hiddenInputs = document.getElementById('hidden-inputs');

    // ── Вспомогательные ─────────────────────────────────
    const svg = (name, size = 16) =>
        `<svg width="${size}" height="${size}" class="icon icon-${name}" aria-hidden="true" focusable="false"><use href="#icon-${name}"/></svg>`;

    const discountText = (d) => {
        if (Math.abs(d) < 0.005) return '—';
        return (d > 0 ? '−' : '+') + fmt(Math.abs(d));
    };
    const discountClass = (d) => {
        if (d > 0) return 'is-discount';
        if (d < 0) return 'is-markup';
        return 'is-zero';
    };
    const sortedItems = () => Object.values(salesMap).slice().sort((a, b) => {
        if (a.isReturn !== b.isReturn) return a.isReturn ? 1 : -1;
        return a.name.localeCompare(b.name, 'ru');
    });

    // ── Рендер строки ───────────────────────────────────
    function buildRow(item, idx) {
        const k = rowKey(item.id, item.isReturn);
        const discount = item.price - item.unit_price;
        const sum = item.unit_price * item.qty;
        const sign = item.isReturn ? '−' : '';
        const nameSuffix = item.isReturn
            ? '<span class="badge badge-warning badge-inline">возврат</span>'
            : '';

        const tr = document.createElement('tr');
        tr.id = `row-${k}`;
        tr.dataset.key = k;
        tr.dataset.pid = item.id;
        tr.dataset.ret = item.isReturn ? 1 : 0;
        if (item.isReturn) tr.classList.add('is-return-row');

        tr.innerHTML = `
            <td class="row-idx">${idx + 1}</td>
            <td class="row-name">${escHtml(item.name)}${nameSuffix}</td>
            <td class="num row-price">${fmt(item.price)}</td>
            <td class="num">
                <input type="number" class="uprice-input" value="${item.unit_price.toFixed(2)}"
                       min="0" step="0.01" aria-label="Цена за единицу" data-action="set-uprice">
            </td>
            <td class="num row-disc ${discountClass(discount)}" id="disc-${k}">
                ${discountText(discount)}
            </td>
            <td class="num">
                <div class="qty-stepper">
                    <button type="button" class="stepper-btn stepper-minus" data-action="qty-delta" data-delta="-1" aria-label="Уменьшить">${svg('minus', 16)}</button>
                    <input type="number" class="qty-input" value="${item.qty}" min="0" aria-label="Количество" data-action="set-qty">
                    <button type="button" class="stepper-btn" data-action="qty-delta" data-delta="1" aria-label="Увеличить">${svg('plus', 16)}</button>
                </div>
            </td>
            <td class="num row-sum" id="sum-${k}">${sign}${fmt(sum)}</td>
            <td class="row-act">
                <button type="button" class="remove-btn" data-action="remove" aria-label="Удалить позицию" title="Удалить">${svg('x', 14)}</button>
            </td>`;
        return tr;
    }

    function render() {
        const items = sortedItems();
        tbody.textContent = '';
        if (items.length === 0) {
            table.hidden = true;
            emptyEl.hidden = false;
            badgeEl.hidden = true;
        } else {
            emptyEl.hidden = true;
            table.hidden = false;
            badgeEl.textContent = `${items.length}\u00a0поз.`;
            badgeEl.hidden = false;
            const frag = document.createDocumentFragment();
            items.forEach((item, idx) => frag.appendChild(buildRow(item, idx)));
            tbody.appendChild(frag);
        }
        renderTotals();
        updateModalAddedInfo();
    }

    function reindexRows() {
        Array.from(tbody.children).forEach((tr, idx) => {
            const cell = tr.querySelector('.row-idx');
            if (cell) cell.textContent = idx + 1;
        });
    }

    function renderTotals() {
        let totalQty = 0, totalSum = 0, totalDisc = 0;
        Object.values(salesMap).forEach(i => {
            const sign = i.isReturn ? -1 : 1;
            totalQty  += sign * i.qty;
            totalSum  += sign * i.unit_price * i.qty;
            totalDisc += sign * (i.price - i.unit_price) * i.qty;
        });
        footQty.textContent  = fmt(totalQty, 0);
        footSum.textContent  = fmt(totalSum);
        footDisc.textContent = discountText(totalDisc);

        if (Object.keys(salesMap).length > 0) {
            const discPart = Math.abs(totalDisc) > 0.005
                ? `&nbsp;&nbsp;·&nbsp;&nbsp;<span class="totals-disc">${totalDisc > 0 ? 'скидка' : 'наценка'}&nbsp;${fmt(Math.abs(totalDisc))}&nbsp;руб.</span>`
                : '';
            totalsBar.innerHTML = `<strong>${fmt(totalQty, 0)}\u00a0шт.</strong>&nbsp;&nbsp;·&nbsp;&nbsp;<strong>${fmt(totalSum)}\u00a0руб.</strong>${discPart}`;
            totalsBar.classList.add('has-data');
        } else {
            totalsBar.textContent = 'Нет данных';
            totalsBar.classList.remove('has-data');
        }
    }

    // ── Точечное обновление ячеек ──────────────────────
    function updateRowSum(k) {
        const item = salesMap[k];
        if (!item) return;
        const cell = document.getElementById('sum-' + k);
        if (cell) {
            const sign = item.isReturn ? '−' : '';
            cell.textContent = sign + fmt(item.unit_price * item.qty);
        }
    }
    function updateRowDiscount(k) {
        const item = salesMap[k];
        if (!item) return;
        const cell = document.getElementById('disc-' + k);
        if (!cell) return;
        const d = item.price - item.unit_price;
        cell.textContent = discountText(d);
        cell.classList.remove('is-discount', 'is-markup', 'is-zero');
        cell.classList.add(discountClass(d));
    }

    // ── Mutations ───────────────────────────────────────
    function setUnitPrice(pid, isReturn, val) {
        const k = rowKey(pid, isReturn);
        if (!salesMap[k]) return;
        salesMap[k].unit_price = Math.max(0, parseFloat(val) || 0);
        updateRowDiscount(k);
        updateRowSum(k);
        renderTotals();
        refreshModalRow(pid);
    }
    function setQty(pid, isReturn, qty) {
        qty = Math.max(0, Math.round(qty) || 0);
        const k = rowKey(pid, isReturn);
        if (qty === 0) { removeItem(pid, isReturn); return; }
        if (!salesMap[k]) return;
        salesMap[k].qty = qty;
        updateRowSum(k);
        renderTotals();
    }
    function changeQty(pid, isReturn, delta) {
        const k = rowKey(pid, isReturn);
        if (!salesMap[k]) return;
        const nq = salesMap[k].qty + delta;
        if (nq <= 0) { removeItem(pid, isReturn); return; }
        salesMap[k].qty = nq;
        const inp = tbody.querySelector(`tr[data-key="${k}"] .qty-input`);
        if (inp) inp.value = nq;
        updateRowSum(k);
        renderTotals();
        updateModalAddedInfo();
    }
    function removeItem(pid, isReturn) {
        const k = rowKey(pid, isReturn);
        if (!salesMap[k]) return;
        delete salesMap[k];
        const tr = tbody.querySelector(`tr[data-key="${k}"]`);
        if (tr) tr.remove();
        reindexRows();
        if (Object.keys(salesMap).length === 0) {
            table.hidden = true;
            emptyEl.hidden = false;
            badgeEl.hidden = true;
        } else {
            badgeEl.textContent = `${Object.keys(salesMap).length}\u00a0поз.`;
        }
        renderTotals();
        updateModalAddedInfo();
        refreshModalRow(pid);
    }
    function addItem(pid, isReturn) {
        const p = PRODUCT_BY_ID[pid];
        if (!p) return;
        const k = rowKey(pid, isReturn);
        if (salesMap[k]) {
            changeQty(pid, isReturn, 1);
            return;
        }
        salesMap[k] = {
            id: p.id, name: p.name, price: p.price,
            unit_price: p.price, qty: 1, isReturn: isReturn === 1,
        };
        const items = sortedItems();
        const idx = items.findIndex(i => rowKey(i.id, i.isReturn) === k);
        const tr = buildRow(salesMap[k], idx);
        const next = tbody.children[idx];
        if (next) tbody.insertBefore(tr, next); else tbody.appendChild(tr);
        reindexRows();
        emptyEl.hidden = true;
        table.hidden = false;
        badgeEl.textContent = `${Object.keys(salesMap).length}\u00a0поз.`;
        badgeEl.hidden = false;
        renderTotals();
        updateModalAddedInfo();
        refreshModalRow(pid);
    }

    // ── Event delegation на tbody ───────────────────────
    tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const tr = btn.closest('tr');
        if (!tr) return;
        const pid = +tr.dataset.pid;
        const ret = +tr.dataset.ret;
        const action = btn.dataset.action;
        if (action === 'qty-delta') changeQty(pid, ret, +btn.dataset.delta);
        else if (action === 'remove') removeItem(pid, ret);
    });
    tbody.addEventListener('input', (e) => {
        const target = e.target;
        const tr = target.closest('tr');
        if (!tr) return;
        const pid = +tr.dataset.pid;
        const ret = +tr.dataset.ret;
        if (target.matches('.qty-input'))         setQty(pid, ret, +target.value);
        else if (target.matches('.uprice-input')) setUnitPrice(pid, ret, +target.value);
    });

    // ── Модалка ─────────────────────────────────────────
    function setMode(mode) {
        currentMode = mode ? 1 : 0;
        overlay.querySelectorAll('.mode-switch-btn').forEach(btn => {
            const active = (+btn.dataset.mode) === currentMode;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        modalBox.classList.toggle('modal-box--return', currentMode === 1);
        modalTitle.textContent = currentMode === 1 ? 'Оформить возврат' : 'Добавить продажу';
        filterModal(modalSearch.value);
    }

    function openSalesModal(mode = 0) {
        setMode(mode);
        modalSearch.value = '';
        renderModalResults(ALL_PRODUCTS);
        openModal(overlay, { initialFocus: '#modal-search' });
    }

    const filterModal = debounce((q) => {
        q = (q || '').trim().toLowerCase();
        const list = q
            ? ALL_PRODUCTS.filter(p => p.name.toLowerCase().includes(q))
            : ALL_PRODUCTS;
        renderModalResults(list, q);
    }, 120);

    modalSearch.addEventListener('input', (e) => filterModal(e.target.value));

    function isInCurrentMode(pid) {
        return !!salesMap[rowKey(pid, currentMode)];
    }

    function renderModalResults(list, q = '') {
        modalCount.textContent = q ? `Найдено: ${list.length}` : `Всего: ${list.length}`;
        modalResults.textContent = '';

        if (list.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'modal-empty';
            empty.innerHTML = `
                <div class="modal-empty-icon">${svg('search', 28)}</div>
                <div class="modal-empty-title">Ничего не найдено</div>`;
            modalResults.appendChild(empty);
            return;
        }
        const added    = list.filter(p =>  isInCurrentMode(p.id));
        const notAdded = list.filter(p => !isInCurrentMode(p.id));
        const frag = document.createDocumentFragment();
        [...added, ...notAdded].forEach(p => {
            const div = document.createElement('div');
            div.className = 'modal-row' + (isInCurrentMode(p.id) ? ' modal-row--added' : '');
            div.id = `mrow-${p.id}`;
            div.dataset.pid = p.id;
            div.innerHTML = modalRowHtml(p);
            frag.appendChild(div);
        });
        modalResults.appendChild(frag);
    }

    function modalRowHtml(p) {
        const current = salesMap[rowKey(p.id, currentMode)];
        const other   = salesMap[rowKey(p.id, currentMode === 1 ? 0 : 1)];
        const otherLabel = currentMode === 1 ? 'в продажах' : 'в возвратах';
        const otherHint = other
            ? `<span class="modal-other-mode" title="Эта же позиция уже есть в другом режиме">${otherLabel}: ${other.qty}&nbsp;шт.</span>`
            : '';
        let controls;
        if (current) {
            controls = `
                <div class="modal-row-stepper">
                    <button type="button" class="stepper-btn stepper-minus" data-modal-action="dec" aria-label="Убрать одну">${svg('minus', 16)}</button>
                    <span class="modal-qty-label">${current.qty}&nbsp;шт.</span>
                    <button type="button" class="stepper-btn" data-modal-action="inc" aria-label="Добавить одну">${svg('plus', 16)}</button>
                </div>`;
        } else {
            const btnClass = currentMode === 1 ? 'btn btn-outline-warning btn-sm' : 'btn btn-primary btn-sm';
            const btnLabel = currentMode === 1 ? 'Возврат' : 'Добавить';
            const btnIcon  = currentMode === 1 ? svg('refresh-ccw', 14) : svg('plus', 16);
            controls = `
                <button type="button" class="${btnClass}" data-modal-action="add">
                    ${btnIcon}${btnLabel}
                </button>`;
        }
        return `
            <div class="modal-row-name">${escHtml(p.name)}</div>
            <div class="modal-row-meta">
                <span class="modal-row-price">${fmt(p.price)}&nbsp;руб.</span>
                ${otherHint}
            </div>
            <div class="modal-row-controls">${controls}</div>`;
    }

    function refreshModalRow(pid) {
        const el = document.getElementById('mrow-' + pid);
        if (!el) return;
        const p = PRODUCT_BY_ID[pid];
        if (!p) return;
        el.className = 'modal-row' + (isInCurrentMode(pid) ? ' modal-row--added' : '');
        el.innerHTML = modalRowHtml(p);
    }

    function updateModalAddedInfo() {
        const cnt = Object.keys(salesMap).length;
        modalAdded.textContent = cnt ? `Позиций: ${cnt}` : '';
    }

    // Delegation на списке модалки
    modalResults.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-modal-action]');
        if (!btn) return;
        const row = btn.closest('[data-pid]');
        if (!row) return;
        const pid = +row.dataset.pid;
        const action = btn.dataset.modalAction;
        if (action === 'add' || action === 'inc') {
            addItem(pid, currentMode);
        } else if (action === 'dec') {
            const k = rowKey(pid, currentMode);
            const item = salesMap[k];
            if (!item) return;
            if (item.qty <= 1) removeItem(pid, currentMode);
            else changeQty(pid, currentMode, -1);
        }
    });

    // ── Общий обработчик data-action вне tbody ──────────
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        if (action === 'open-modal')       openSalesModal(+btn.dataset.mode || 0);
        else if (action === 'close-modal') closeModal(overlay);
        else if (action === 'set-mode')    setMode(+btn.dataset.mode || 0);
    });

    // ── Сохранение: дельта (qty, uprice) ───────────────
    saveForm.addEventListener('submit', (e) => {
        try {
            hiddenInputs.textContent = '';
            const allKeys = new Set([...Object.keys(salesMap), ...Object.keys(ORIGINAL)]);
            allKeys.forEach(k => {
                const item = salesMap[k];
                const orig = ORIGINAL[k];
                const qty  = item ? item.qty : 0;
                const up   = item ? item.unit_price : (orig ? orig.unit_price : 0);
                const origQty = orig ? orig.qty : 0;
                const origUp  = orig ? orig.unit_price : 0;
                if (qty === origQty && Math.round(up * 100) === Math.round(origUp * 100)) return;

                const [pid, isRet] = k.split(':');
                const mk = (name, val) => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = name; inp.value = val;
                    hiddenInputs.appendChild(inp);
                };
                mk(`qty[${pid}][${isRet}]`, qty);
                mk(`uprice[${pid}][${isRet}]`, up.toFixed(2));
            });
        } catch (err) {
            e.preventDefault();
            console.error('buildFormInputs failed', err);
            alert('Не удалось подготовить данные к отправке. Обновите страницу и попробуйте снова.');
        }
    });

    render();
})();
