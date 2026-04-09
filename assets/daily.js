/**
 * Экран «Продажи за день».
 *
 * Модель:
 *   • Каждая продажа = отдельная операция со своим id (журнал, не сводка).
 *     За один день у одного товара может быть несколько строк по разным ценам.
 *   • salesMap ключуется по локальному key:
 *       - "s<id>"  — существующая продажа из БД
 *       - "n<seq>" — новая, ещё не сохранённая (id появится после save+reload)
 *   • Возвраты (returnsMap) ключуются по original_sale_id и оформляются
 *     только текущим днём (canReturn = true).
 */
(() => {
    'use strict';

    const bootstrapEl = document.getElementById('daily-bootstrap');
    if (!bootstrapEl) return;
    const BOOT = JSON.parse(bootstrapEl.textContent);
    const ALL_PRODUCTS = BOOT.products;
    const RETURNABLE_INIT = BOOT.returnable || [];
    const CAN_RETURN = !!BOOT.canReturn;
    const CAN_EDIT   = BOOT.canEdit !== false;

    const { escHtml, formatMoney, debounce, openModal, closeModal, confirmDialog } = window.App;
    const fmt = formatMoney;

    // ── State ───────────────────────────────────────────
    const PRODUCT_BY_ID = Object.fromEntries(ALL_PRODUCTS.map(p => [p.id, p]));

    // Продажи: key -> {key, id|null, pid, name, basePrice, unit_price, qty}
    const salesMap = {};
    // Снимок исходных значений (только для существующих) — чтобы посчитать дельту.
    const ORIG_SALES = {}; // id -> {qty, unit_price}
    let newSeq = 0;
    const newKey = () => 'n' + (++newSeq);
    const idKey  = (id) => 's' + id;

    BOOT.sales.forEach(r => {
        const p = PRODUCT_BY_ID[r.pid];
        const key = idKey(r.id);
        salesMap[key] = {
            key,
            id: r.id,
            pid: r.pid,
            name: r.name || (p ? p.name : `Товар #${r.pid}`),
            basePrice: r.base_price,
            unit_price: r.unit_price,
            qty: r.qty,
            discount: r.discount || 0,
            payment: r.payment || 'cash',
            note: r.note || '',
            sold_at: r.sold_at || null,
        };
        // Снимок исходных значений — заморожен, чтобы случайная мутация
        // dirty-check'а через `ORIG_SALES[id].qty = …` падала в strict mode,
        // а не молча ломала «отправлять только изменённое».
        ORIG_SALES[r.id] = Object.freeze({
            qty: r.qty,
            unit_price: r.unit_price,
            payment: r.payment || 'cash',
            note: r.note || '',
        });
    });
    // Помним id, которые удалили локально, чтобы отправить их в `deleted[]`.
    const DELETED_IDS = new Set();

    // Возвраты: origId -> {origId, pid, name, origDate, basePrice, unitPrice, qty, maxQty}
    // maxQty = origQty - (returned по другим дням); сегодняшний возврат входит в этот лимит.
    const returnsMap = {};
    const ORIG_RETURNS = {};

    // Справочник возвратопригодных продаж (origId -> info).
    //   cap = максимум, который можно вернуть сегодня (origQty − возвраты других дней).
    //   remaining (динамический) = cap − qty в returnsMap.
    const RETURNABLE_BY_ID = {};

    RETURNABLE_INIT.forEach(r => {
        // server.remaining = origQty − ВСЕ возвраты (включая сегодняшний).
        // На момент инициализации сегодняшнего возврата ещё нет в returnsMap,
        // но он может уже существовать в БД — добавим его qty ниже.
        RETURNABLE_BY_ID[r.orig_id] = {
            origId:    r.orig_id,
            pid:       r.pid,
            name:      r.name,
            origDate:  r.orig_date,
            origQty:   r.orig_qty,
            cap:       r.remaining, // временно; пересчитаем после загрузки returns
            basePrice: r.base_price,
            unitPrice: r.unit_price,
        };
    });

    BOOT.returns.forEach(r => {
        let info = RETURNABLE_BY_ID[r.orig_id];
        if (!info) {
            // Возврат уже есть, но исходная продажа не вошла в returnable
            // (например, исходная qty полностью «выбрана» этим самым возвратом).
            info = {
                origId:    r.orig_id,
                pid:       r.pid,
                name:      r.name,
                origDate:  r.orig_date,
                origQty:   r.orig_qty,
                cap:       0,
                basePrice: r.base_price,
                unitPrice: r.unit_price,
            };
            RETURNABLE_BY_ID[r.orig_id] = info;
        }
        // Поднимаем cap, чтобы он включал текущий сегодняшний возврат
        info.cap += r.qty;
        returnsMap[r.orig_id] = {
            origId:    r.orig_id,
            pid:       r.pid,
            name:      r.name,
            origDate:  r.orig_date,
            origQty:   r.orig_qty,
            basePrice: r.base_price,
            unitPrice: r.unit_price,
            qty:       r.qty,
            sold_at:   r.sold_at || null,
        };
        ORIG_RETURNS[r.orig_id] = r.qty;
    });

    const capFor = (origId) => RETURNABLE_BY_ID[origId]?.cap || 0;

    // ── DOM refs ────────────────────────────────────────
    const tbody        = document.getElementById('sales-tbody');
    const table        = document.getElementById('sales-table');
    const emptyEl      = document.getElementById('empty-state');
    const badgeEl      = document.getElementById('badge-count');
    const footQty      = document.getElementById('foot-qty');
    const footSum      = document.getElementById('foot-sum');
    const footDisc     = document.getElementById('foot-discount');
    const footBase     = document.getElementById('foot-base');
    const footRetRow   = document.getElementById('foot-returns-row');
    const footRetQty   = document.getElementById('foot-ret-qty');
    const footRetSum   = document.getElementById('foot-ret-sum');
    const footNetQty   = document.getElementById('foot-net-qty');
    const footNetSum   = document.getElementById('foot-net-sum');
    const totalsBar    = document.getElementById('totals-bar');
    const overlay      = document.getElementById('modal-overlay');
    const modalTitle   = document.getElementById('modal-title');
    const modalSearch  = document.getElementById('modal-search');
    const modalResults = document.getElementById('modal-results');
    const modalCount   = document.getElementById('modal-count');
    const modalAdded   = document.getElementById('modal-added-info');
    const saveStatusEl = document.getElementById('save-status');
    const undoBtn      = document.getElementById('undo-btn');

    const retOverlay   = document.getElementById('return-modal-overlay');
    const retSearch    = document.getElementById('return-modal-search');
    const retResults   = document.getElementById('return-modal-results');
    const retCount     = document.getElementById('return-modal-count');

    // ── Helpers ─────────────────────────────────────────
    const svg = (name, size = 16) =>
        `<svg width="${size}" height="${size}" class="icon icon-${name}" aria-hidden="true" focusable="false"><use href="#icon-${name}"/></svg>`;

    const discountText = (d) => {
        if (Math.abs(d) < 0.005) return '—';
        return (d > 0 ? '−' : '+') + fmt(Math.abs(d));
    };
    const discountClass = (d) => (d > 0 ? 'is-discount' : d < 0 ? 'is-markup' : 'is-zero');

    const fmtDateRu = (iso) => {
        if (!iso) return '';
        const [y, m, d] = iso.split('-');
        return `${d}.${m}.${y}`;
    };
    const fmtTime = (ts) => {
        if (!ts) return '';
        // ts формата 'YYYY-MM-DD HH:MM:SS'
        const m = ts.match(/(\d{2}):(\d{2})/);
        return m ? `${m[1]}:${m[2]}` : '';
    };
    const round2 = (v) => Math.round(v * 100) / 100;
    // Денежное сравнение «равно ли с точностью до копейки»
    const moneyEq = (a, b) => Math.round(a * 100) === Math.round(b * 100);

    const PAYMENT_LABELS = { cash: 'Наличные', card: 'Карта', other: 'Другое' };
    const PAYMENT_TITLES = {
        cash:  'Наличные',
        card:  'Карта',
        other: 'Другое (например, оплата при заказе)',
    };

    // Делит длинное имя товара на «заголовок» и «комплектацию» по первой скобке.
    // Поведение совпадает с PHP-функцией split_product_name() в helpers.php.
    function splitProductName(name) {
        name = (name || '').trim();
        const pos = name.indexOf('(');
        if (pos < 0) return { main: name, meta: '' };
        const main = name.slice(0, pos).replace(/\s+$/, '');
        const meta = name.slice(pos).trim();
        return { main, meta };
    }
    function productNameHtml(name, extraHtml) {
        const parts = splitProductName(name);
        const meta = parts.meta
            ? `<span class="product-name__meta">${escHtml(parts.meta)}</span>`
            : '';
        return `<span class="product-name__main">${escHtml(parts.main)}</span>${meta}${extraHtml || ''}`;
    }

    // Объединённый список для рендера: продажи (по времени) + возвраты.
    function allItems() {
        const sales = Object.values(salesMap)
            .slice()
            .sort((a, b) => {
                // По времени продажи (sold_at): сначала ранние, без времени — в конец.
                // Новые продажи (sold_at === null) всегда внизу, пока сервер
                // не вернёт реальное время после сохранения.
                const ta = a.sold_at || '';
                const tb = b.sold_at || '';
                if (ta !== tb) return ta < tb ? -1 : 1;
                // При одинаковом времени — стабильный порядок по ключу
                return a.key < b.key ? -1 : a.key > b.key ? 1 : 0;
            })
            .map(s => ({ kind: 'sale', key: s.key, ref: s }));
        const returns = Object.values(returnsMap)
            .slice()
            .sort((a, b) => (a.origDate < b.origDate ? 1 : a.origDate > b.origDate ? -1 : a.name.localeCompare(b.name, 'ru')))
            .map(r => ({ kind: 'return', key: 'r:' + r.origId, ref: r }));
        return [...sales, ...returns];
    }

    // ── Рендер строки таблицы ───────────────────────────
    function buildRow(entry, idx) {
        const tr = document.createElement('tr');
        tr.dataset.key = entry.key;
        tr.id = 'row-' + entry.key;

        if (entry.kind === 'sale') {
            const s = entry.ref;
            const sum = round2(s.unit_price * s.qty);
            const discount = s.discount; // суммарная скидка по строке (явное поле)
            const discCls = discount > 0.005 ? 'is-discount' : (discount < -0.005 ? 'is-markup' : 'is-zero');
            const timeLabel = fmtTime(s.sold_at);
            const timeCell = timeLabel
                ? `<span class="row-time">${timeLabel}</span>`
                : '<span class="text-faint">—</span>';
            const nameHtml = productNameHtml(s.name);
            tr.dataset.kind = 'sale';
            tr.dataset.skey = s.key;
            tr.innerHTML = `
                <td class="row-idx">${idx + 1}</td>
                <td class="col-time">${timeCell}</td>
                <td class="row-name">${nameHtml}</td>
                <td class="num row-price">${fmt(s.basePrice)}</td>
                <td class="num">
                    <input type="number" class="uprice-input${s.unit_price === 0 ? ' is-zero' : ''}"
                           value="${s.unit_price.toFixed(2)}"
                           min="0" step="0.01"
                           aria-label="Цена за единицу" title="${s.unit_price === 0 ? 'Цена 0 — продажа «бесплатно»' : ''}"
                           data-action="set-uprice">
                </td>
                <td class="num row-disc ${discCls}">
                    <input type="number" class="disc-input" value="${discount.toFixed(2)}"
                           step="0.01" aria-label="Скидка на строку" data-action="set-discount">
                </td>
                <td class="num">
                    <div class="qty-stepper">
                        <button type="button" class="stepper-btn stepper-minus" data-action="qty-delta" data-delta="-1" aria-label="Уменьшить">${svg('minus', 16)}</button>
                        <input type="number" class="qty-input" value="${s.qty}" min="0" aria-label="Количество" data-action="set-qty">
                        <button type="button" class="stepper-btn" data-action="qty-delta" data-delta="1" aria-label="Увеличить">${svg('plus', 16)}</button>
                    </div>
                </td>
                <td class="num row-sum">${fmt(sum)}</td>
                <td class="col-pay">
                    <select class="pay-select pay-select--${s.payment}" aria-label="Способ оплаты"
                            data-action="set-payment" title="${PAYMENT_TITLES[s.payment]}">
                        ${['cash','card','other'].map(m => `
                            <option value="${m}"${s.payment === m ? ' selected' : ''}>${PAYMENT_LABELS[m]}</option>
                        `).join('')}
                    </select>
                </td>
                <td class="col-note">
                    <input type="text" class="note-input" value="${escHtml(s.note || '')}"
                           maxlength="500" placeholder="—" aria-label="Заметка" data-action="set-note">
                </td>
                <td class="row-act">
                    <button type="button" class="remove-btn" data-action="remove" aria-label="Удалить позицию" title="Удалить">${svg('x', 14)}</button>
                </td>`;
        } else {
            const r = entry.ref;
            const sum = r.unitPrice * r.qty;
            const discount = r.basePrice - r.unitPrice;
            tr.dataset.kind = 'return';
            tr.dataset.orig = r.origId;
            tr.classList.add('is-return-row');
            const partsR = splitProductName(r.name);
            const metaR = partsR.meta
                ? `<span class="product-name__meta">${escHtml(partsR.meta)}</span>`
                : '';
            const nameHtml = `
                <span class="product-name__main">${escHtml(partsR.main)}
                    <span class="badge badge-warning badge-inline">возврат</span>
                </span>
                ${metaR}
                <span class="product-name__meta">из продажи от ${fmtDateRu(r.origDate)} (${r.origQty}&nbsp;шт.)</span>`;
            const retTime = fmtTime(r.sold_at);
            const retTimeCell = retTime
                ? `<span class="row-time">${retTime}</span>`
                : '<span class="text-faint">—</span>';
            tr.innerHTML = `
                <td class="row-idx">${idx + 1}</td>
                <td class="col-time">${retTimeCell}</td>
                <td class="row-name">${nameHtml}</td>
                <td class="num row-price">${fmt(r.basePrice)}</td>
                <td class="num">${fmt(r.unitPrice)}</td>
                <td class="num row-disc ${discountClass(discount * r.qty)}">${discountText(discount * r.qty)}</td>
                <td class="num">
                    <div class="qty-stepper">
                        <button type="button" class="stepper-btn stepper-minus" data-action="qty-delta" data-delta="-1" aria-label="Уменьшить">${svg('minus', 16)}</button>
                        <input type="number" class="qty-input" value="${r.qty}" min="0" max="${capFor(r.origId)}" aria-label="Количество" data-action="set-qty">
                        <button type="button" class="stepper-btn" data-action="qty-delta" data-delta="1" aria-label="Увеличить">${svg('plus', 16)}</button>
                    </div>
                </td>
                <td class="num row-sum">−${fmt(sum)}</td>
                <td class="col-pay text-faint" colspan="2"><em>возврат тем же способом оплаты</em></td>
                <td class="row-act">
                    <button type="button" class="remove-btn" data-action="remove" aria-label="Удалить позицию" title="Удалить">${svg('x', 14)}</button>
                </td>`;
        }
        return tr;
    }

    // ── DOM-обновления ──────────────────────────────────
    //
    // Архитектура:
    //   render()           — полный rebuild, вызывается ОДИН раз при загрузке
    //   insertRowDom()     — вставить новую строку в нужное место по сортировке
    //   removeRowDom()     — убрать строку
    //   softUpdateSaleRow  — обновить значения в существующей строке продажи
    //   softUpdateReturnRow— обновить значения в существующей строке возврата
    //   reindexRows()      — пересчитать номера в колонке #
    //   updateBadge()      — счётчик позиций в шапке
    //   updateEmptyState() — переключить «нет данных» ↔ таблица
    //   renderTotals()     — футер и итоги-бар
    //
    // У всех мутаций есть явный список зависимостей, что обновлять.
    // Это даёт почти такое же поведение, как Angular signals,
    // без машинерии (track/effect/scheduler).

    function render() {
        const items = allItems();
        tbody.textContent = '';
        items.forEach((entry, idx) => tbody.appendChild(buildRow(entry, idx)));
        updateEmptyState();
        updateBadge();
        renderTotals();
        updateModalAddedInfo();
    }

    function updateEmptyState() {
        const has = Object.keys(salesMap).length + Object.keys(returnsMap).length > 0;
        table.hidden = !has;
        emptyEl.hidden = has;
    }

    function updateBadge() {
        const n = Object.keys(salesMap).length + Object.keys(returnsMap).length;
        if (n === 0) { badgeEl.hidden = true; return; }
        badgeEl.textContent = `${n}\u00a0поз.`;
        badgeEl.hidden = false;
    }

    function reindexRows() {
        Array.from(tbody.children).forEach((tr, i) => {
            const cell = tr.querySelector('.row-idx');
            if (cell) cell.textContent = String(i + 1);
        });
    }

    // Вставить новую строку в правильную позицию (сортировка из allItems()).
    function insertRowDom(entry) {
        const items = allItems();
        const idx = items.findIndex(it => it.key === entry.key);
        if (idx < 0) return;
        const tr = buildRow(entry, idx);
        const next = tbody.children[idx];
        if (next) tbody.insertBefore(tr, next);
        else      tbody.appendChild(tr);
    }

    function removeRowDom(key) {
        const tr = tbody.querySelector(`tr[data-key="${cssEsc(key)}"]`);
        if (tr) tr.remove();
    }

    // Селектор data-key содержит ':' (например 'r:42'), его нужно экранировать.
    function cssEsc(s) {
        return (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    function renderTotals() {
        // Считаем продажи и возвраты раздельно: в строках суммы положительные,
        // знак минус появляется только в тех строках футера, которые арифметически
        // вычитаются (возвраты) — там это означает «отняли», а не «продали в минус».
        let salesQty = 0, salesSum = 0, salesDisc = 0, salesBase = 0;
        Object.values(salesMap).forEach(i => {
            salesQty  += i.qty;
            salesSum  += i.unit_price * i.qty;
            salesDisc += i.discount;
            salesBase += i.basePrice * i.qty;
        });
        let retQty = 0, retSum = 0;
        Object.values(returnsMap).forEach(r => {
            retQty += r.qty;
            retSum += r.unitPrice * r.qty;
        });
        const netQty = salesQty - retQty;
        const netSum = salesSum - retSum;

        // Строка «Продано»
        footQty.textContent  = fmt(salesQty, 0);
        footSum.textContent  = fmt(salesSum);
        footDisc.textContent = discountText(salesDisc);
        if (footBase) footBase.textContent = fmt(salesBase);

        // Строка «Возвращено» (показываем только если есть возвраты)
        if (footRetRow) {
            if (retQty > 0) {
                footRetRow.hidden = false;
                if (footRetQty) footRetQty.textContent = fmt(retQty, 0);
                // Знак ставится через CSS .foot-neg::before — здесь только цифра
                if (footRetSum) footRetSum.textContent = fmt(retSum);
            } else {
                footRetRow.hidden = true;
            }
        }

        // Строка «Итого» (чистая выручка)
        if (footNetQty) footNetQty.textContent = fmt(netQty, 0);
        if (footNetSum) footNetSum.textContent = fmt(netSum);

        // Компактная панель внизу POS-блока
        if (Object.keys(salesMap).length + Object.keys(returnsMap).length > 0) {
            const parts = [
                `<strong>${fmt(salesSum)}\u00a0руб.</strong>&nbsp;продано`,
            ];
            if (retQty > 0) {
                parts.push(`<span class="totals-ret">−${fmt(retSum)}\u00a0руб.&nbsp;возвраты</span>`);
            }
            parts.push(`<strong class="totals-net">= ${fmt(netSum)}\u00a0руб.</strong>`);
            totalsBar.innerHTML = parts.join('&nbsp;&nbsp;·&nbsp;&nbsp;');
            totalsBar.classList.add('has-data');
        } else {
            totalsBar.textContent = 'Нет данных';
            totalsBar.classList.remove('has-data');
        }
    }

    // ── Mutations: продажи (по локальному key) ──────────
    //
    // Инвариант: discount = qty * basePrice − qty * unit_price
    // Изменение цены → пересчёт скидки.
    // Изменение скидки → пересчёт цены.
    // Изменение qty   → пересчёт скидки (цена за штуку фиксируется).
    //
    function recalcDiscountFromPrice(s) {
        s.discount = round2(s.qty * s.basePrice - s.qty * s.unit_price);
    }
    function recalcPriceFromDiscount(s) {
        if (s.qty <= 0) { s.unit_price = s.basePrice; return; }
        const up = s.basePrice - s.discount / s.qty;
        s.unit_price = Math.max(0, round2(up));
    }

    // Точечное обновление строки, чтобы не пересоздавать <tbody> и не сбивать
    // фокус с активного инпута. Параметр `skipField` указывает, какой инпут
    // НЕ нужно перезаписывать (тот, в который пользователь сейчас печатает).
    function softUpdateSaleRow(key, skipField) {
        const s = salesMap[key]; if (!s) return;
        const tr = tbody.querySelector(`tr[data-key="${cssEsc(key)}"]`);
        if (!tr) return;

        const upInp = tr.querySelector('.uprice-input');
        if (upInp) {
            if (skipField !== 'uprice') upInp.value = s.unit_price.toFixed(2);
            upInp.classList.toggle('is-zero', s.unit_price === 0);
            upInp.title = s.unit_price === 0 ? 'Цена 0 — продажа «бесплатно»' : '';
        }
        if (skipField !== 'disc') {
            const inp = tr.querySelector('.disc-input');
            if (inp) inp.value = s.discount.toFixed(2);
        }
        if (skipField !== 'qty') {
            const inp = tr.querySelector('.qty-input');
            if (inp) inp.value = String(s.qty);
        }

        const sumCell = tr.querySelector('.row-sum');
        if (sumCell) sumCell.textContent = fmt(round2(s.unit_price * s.qty));

        const discCell = tr.querySelector('.row-disc');
        if (discCell) {
            const cls = s.discount > 0.005 ? 'is-discount'
                      : s.discount < -0.005 ? 'is-markup' : 'is-zero';
            discCell.classList.remove('is-discount', 'is-markup', 'is-zero');
            discCell.classList.add(cls);
        }

        renderTotals();
    }

    // Точечное обновление строки возврата (qty, sum, disc).
    function softUpdateReturnRow(origId, skipField) {
        const r = returnsMap[origId]; if (!r) return;
        const tr = tbody.querySelector(`tr[data-key="${cssEsc('r:' + origId)}"]`);
        if (!tr) return;

        if (skipField !== 'qty') {
            const inp = tr.querySelector('.qty-input');
            if (inp) inp.value = String(r.qty);
        }

        const sumCell = tr.querySelector('.row-sum');
        if (sumCell) sumCell.textContent = '−' + fmt(round2(r.unitPrice * r.qty));

        const discTotal = (r.basePrice - r.unitPrice) * r.qty;
        const discCell = tr.querySelector('.row-disc');
        if (discCell) {
            discCell.textContent = discountText(discTotal);
            discCell.classList.remove('is-discount', 'is-markup', 'is-zero');
            discCell.classList.add(discountClass(discTotal));
        }

        renderTotals();
    }

    function setSaleQty(key, qty) {
        const s = salesMap[key]; if (!s) return;
        qty = Math.max(0, Math.round(qty) || 0);
        if (qty === 0) { removeSale(key); return; }
        s.qty = qty;
        recalcDiscountFromPrice(s);
        softUpdateSaleRow(key, 'qty');
        markDirty();
    }
    function changeSaleQty(key, delta) {
        const s = salesMap[key]; if (!s) return;
        const nq = s.qty + delta;
        if (nq <= 0) { removeSale(key); return; }
        s.qty = nq;
        recalcDiscountFromPrice(s);
        softUpdateSaleRow(key);
        markDirty();
    }
    function setSaleUprice(key, val) {
        const s = salesMap[key]; if (!s) return;
        s.unit_price = Math.max(0, parseFloat(val) || 0);
        recalcDiscountFromPrice(s);
        softUpdateSaleRow(key, 'uprice');
        markDirty();
    }
    function setSaleDiscount(key, val) {
        const s = salesMap[key]; if (!s) return;
        const d = parseFloat(val);
        s.discount = isFinite(d) ? round2(d) : 0;
        recalcPriceFromDiscount(s);
        // После пересчёта цены может «съехать» из-за округления → подтягиваем скидку,
        // но только если поле сейчас не редактируется (иначе перебьём ввод пользователя).
        const stash = s.discount;
        recalcDiscountFromPrice(s);
        // Если разница появилась после округления цены — оставляем то, что ввёл пользователь,
        // окончательная нормализация произойдёт на blur/save.
        if (Math.abs(s.discount - stash) > 0.005) s.discount = stash;
        softUpdateSaleRow(key, 'disc');
        markDirty();
    }
    function setSalePayment(key, payment) {
        const s = salesMap[key]; if (!s) return;
        if (!['cash', 'card', 'other'].includes(payment)) return;
        s.payment = payment;
        // Обновляем класс цвета на самом select, без полного render()
        const tr = tbody.querySelector(`tr[data-skey="${key}"]`);
        const sel = tr && tr.querySelector('.pay-select');
        if (sel) {
            sel.classList.remove('pay-select--cash', 'pay-select--card', 'pay-select--other');
            sel.classList.add('pay-select--' + payment);
            sel.title = PAYMENT_TITLES[payment];
        }
        markDirty();
    }
    function setSaleNote(key, text) {
        const s = salesMap[key]; if (!s) return;
        s.note = (text || '').slice(0, 500);
        // Не вызываем render() — input уже содержит текст, перерисовка только сбила бы фокус.
        markDirty();
    }
    function addSale(pid) {
        const p = PRODUCT_BY_ID[pid]; if (!p) return;
        const key = newKey();
        salesMap[key] = {
            key,
            id: null,
            pid,
            name: p.name,
            basePrice: p.price,
            unit_price: p.price,
            qty: 1,
            discount: 0,
            payment: 'cash',
            note: '',
            sold_at: null,
        };
        insertRowDom({ kind: 'sale', key, ref: salesMap[key] });
        reindexRows();
        updateEmptyState();
        updateBadge();
        renderTotals();
        updateModalAddedInfo();
        pushUndo({ type: 'add-sale', key });
        markDirty();
    }
    // Удаление сохранённой продажи — с подтверждением (защита от случайного клика
    // и от «обнулил qty в инпуте». Черновики удаляются молча.
    function removeSale(key) {
        const s = salesMap[key]; if (!s) return;
        if (s.id) {
            confirmDialog(`Удалить продажу «${s.name}» (${s.qty} шт., ${fmt(round2(s.unit_price * s.qty))} руб.)?`, {
                okLabel:     'Удалить',
                cancelLabel: 'Отмена',
            }).then(ok => {
                if (ok) doRemoveSale(key);
                else    softUpdateSaleRow(key); // вернуть исходный qty в инпут
            });
            return;
        }
        doRemoveSale(key);
    }
    function doRemoveSale(key) {
        const s = salesMap[key]; if (!s) return;
        // Снимок до удаления — для undo. Сохраняем перед мутацией.
        pushUndo({ type: 'remove-sale', key, snapshot: { ...s } });
        if (s.id) DELETED_IDS.add(s.id);
        delete salesMap[key];
        removeRowDom(key);
        reindexRows();
        updateEmptyState();
        updateBadge();
        renderTotals();
        updateModalAddedInfo();
        markDirty();
    }

    // ── Mutations: возвраты ─────────────────────────────
    function addReturn(origId) {
        const info = RETURNABLE_BY_ID[origId]; if (!info) return;
        if (returnsMap[origId]) { changeReturnQty(origId, 1); return; }
        if (capFor(origId) <= 0) return;
        returnsMap[origId] = {
            origId,
            pid: info.pid,
            name: info.name,
            origDate: info.origDate,
            origQty: info.origQty,
            basePrice: info.basePrice,
            unitPrice: info.unitPrice,
            qty: 1,
        };
        insertRowDom({ kind: 'return', key: 'r:' + origId, ref: returnsMap[origId] });
        reindexRows();
        updateEmptyState();
        updateBadge();
        renderTotals();
        refreshReturnModalRow(origId);
        pushUndo({ type: 'add-return', origId });
        markDirty();
    }
    function changeReturnQty(origId, delta) {
        const r = returnsMap[origId]; if (!r) return;
        const nq = r.qty + delta;
        if (nq <= 0) { removeReturn(origId); return; }
        if (nq > capFor(origId)) return;
        r.qty = nq;
        softUpdateReturnRow(origId);
        refreshReturnModalRow(origId);
        markDirty();
    }
    function setReturnQty(origId, qty) {
        const r = returnsMap[origId]; if (!r) return;
        qty = Math.max(0, Math.round(qty) || 0);
        if (qty === 0) { removeReturn(origId); return; }
        const cap = capFor(origId);
        if (qty > cap) qty = cap;
        r.qty = qty;
        softUpdateReturnRow(origId, 'qty');
        refreshReturnModalRow(origId);
        markDirty();
    }
    function removeReturn(origId) {
        const r = returnsMap[origId]; if (!r) return;
        // Если возврат уже был сохранён (есть в ORIG_RETURNS) — переспросить.
        if (ORIG_RETURNS[origId]) {
            confirmDialog(`Удалить возврат «${r.name}» (${r.qty} шт.)?`, {
                okLabel:     'Удалить',
                cancelLabel: 'Отмена',
            }).then(ok => {
                if (ok) doRemoveReturn(origId);
                else    softUpdateReturnRow(origId);
            });
            return;
        }
        doRemoveReturn(origId);
    }
    function doRemoveReturn(origId) {
        const r = returnsMap[origId]; if (!r) return;
        pushUndo({ type: 'remove-return', origId, snapshot: { ...r } });
        delete returnsMap[origId];
        removeRowDom('r:' + origId);
        reindexRows();
        updateEmptyState();
        updateBadge();
        renderTotals();
        refreshReturnModalRow(origId);
        markDirty();
    }

    // ── Делегирование событий на таблицу ────────────────
    tbody.addEventListener('click', (e) => {
        if (!CAN_EDIT) return;
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const tr = btn.closest('tr');
        if (!tr) return;
        const action = btn.dataset.action;
        const kind = tr.dataset.kind;
        if (kind === 'sale') {
            const skey = tr.dataset.skey;
            if (action === 'qty-delta')   changeSaleQty(skey, +btn.dataset.delta);
            else if (action === 'remove') removeSale(skey);
        } else if (kind === 'return') {
            const orig = +tr.dataset.orig;
            if (action === 'qty-delta') changeReturnQty(orig, +btn.dataset.delta);
            else if (action === 'remove') removeReturn(orig);
        }
    });
    // change — для <select> способа оплаты
    tbody.addEventListener('change', (e) => {
        if (!CAN_EDIT) return;
        const t = e.target;
        if (t.dataset.action !== 'set-payment') return;
        const tr = t.closest('tr'); if (!tr) return;
        if (tr.dataset.kind !== 'sale') return;
        setSalePayment(tr.dataset.skey, t.value);
    });
    // input — перерисовка ячеек, которые зависят от значения. Для текстовой
    // заметки render() не вызываем (сбило бы фокус ввода) — обновление состояния
    // достаточно для последующего save.
    tbody.addEventListener('input', (e) => {
        if (!CAN_EDIT) return;
        const t = e.target;
        const tr = t.closest('tr'); if (!tr) return;
        const kind = tr.dataset.kind;
        if (kind === 'sale') {
            const skey = tr.dataset.skey;
            // Защита от промежуточных NaN: пока пользователь набирает число,
            // инпут может содержать '-', '' или '.', которые дают NaN при +val.
            // Такие состояния игнорируем — обработка произойдёт на blur или
            // когда появится валидное число.
            if (t.matches('.qty-input')) {
                const v = +t.value;
                if (!isNaN(v)) setSaleQty(skey, v);
            }
            else if (t.matches('.uprice-input')) {
                const v = +t.value;
                if (!isNaN(v)) setSaleUprice(skey, v);
            }
            else if (t.matches('.disc-input'))   setSaleDiscount(skey, t.value);
            else if (t.matches('.note-input'))   setSaleNote(skey, t.value);
        } else if (kind === 'return') {
            const orig = +tr.dataset.orig;
            if (t.matches('.qty-input')) {
                const v = +t.value;
                if (!isNaN(v)) setReturnQty(orig, v);
            }
        }
    });

    // ── Модалка продажи ─────────────────────────────────
    // Модель «журнал операций»: каждый клик = новая отдельная продажа.
    // Если того же товара уже есть продажи в этом дне, под товаром
    // показывается подсказка с их количеством.
    function openSalesModal() {
        modalTitle.textContent = 'Добавить продажу';
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

    function salesOfProduct(pid) {
        return Object.values(salesMap).filter(s => s.pid === pid);
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
        // Сначала товары, которые уже добавляли в этот день (быстрый повторный пробив).
        const withSales = list.filter(p => salesOfProduct(p.id).length > 0);
        const others    = list.filter(p => salesOfProduct(p.id).length === 0);
        const frag = document.createDocumentFragment();
        [...withSales, ...others].forEach(p => {
            const div = document.createElement('div');
            const has = salesOfProduct(p.id).length > 0;
            div.className = 'modal-row' + (has ? ' modal-row--added' : '');
            div.id = `mrow-${p.id}`;
            div.dataset.pid = p.id;
            div.innerHTML = modalRowHtml(p);
            frag.appendChild(div);
        });
        modalResults.appendChild(frag);
    }
    function modalRowHtml(p) {
        const existing = salesOfProduct(p.id);
        const totalQty = existing.reduce((acc, s) => acc + s.qty, 0);
        const hint = existing.length
            ? `<span class="modal-other-mode" title="Уже пробито за этот день">в этом дне: ${existing.length}&nbsp;${existing.length === 1 ? 'продажа' : 'продаж'}, ${totalQty}&nbsp;шт.</span>`
            : '';
        const controls = `
            <button type="button" class="btn btn-primary btn-sm" data-modal-action="add">
                ${svg('plus', 16)}Пробить
            </button>`;
        return `
            <div class="modal-row-name">${productNameHtml(p.name)}</div>
            <div class="modal-row-meta">
                <span class="modal-row-price">${fmt(p.price)}&nbsp;руб.</span>
                ${hint}
            </div>
            <div class="modal-row-controls">${controls}</div>`;
    }
    function refreshModalRowFor(pid) {
        const el = document.getElementById('mrow-' + pid);
        if (!el) return;
        const p = PRODUCT_BY_ID[pid]; if (!p) return;
        el.className = 'modal-row' + (salesOfProduct(pid).length ? ' modal-row--added' : '');
        el.innerHTML = modalRowHtml(p);
    }
    function updateModalAddedInfo() {
        const cnt = Object.keys(salesMap).length;
        if (modalAdded) modalAdded.textContent = cnt ? `Продаж за день: ${cnt}` : '';
    }
    modalResults.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-modal-action]');
        if (!btn) return;
        const row = btn.closest('[data-pid]');
        if (!row) return;
        const pid = +row.dataset.pid;
        if (btn.dataset.modalAction === 'add') {
            addSale(pid);
            refreshModalRowFor(pid);
        }
    });

    // ── Модалка возврата ────────────────────────────────
    function openReturnModal() {
        if (!CAN_RETURN || !retOverlay) return;
        retSearch.value = '';
        renderReturnResults(allReturnableEntries());
        openModal(retOverlay, { initialFocus: '#return-modal-search' });
    }
    function allReturnableEntries() {
        return Object.values(RETURNABLE_BY_ID).filter(info => info.cap > 0);
    }
    const filterReturnModal = debounce((q) => {
        q = (q || '').trim().toLowerCase();
        let list = allReturnableEntries();
        if (q) list = list.filter(i => i.name.toLowerCase().includes(q));
        renderReturnResults(list, q);
    }, 120);
    if (retSearch) retSearch.addEventListener('input', e => filterReturnModal(e.target.value));

    function renderReturnResults(list, q = '') {
        retCount.textContent = q ? `Найдено: ${list.length}` : `Доступно: ${list.length}`;
        retResults.textContent = '';
        if (list.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'modal-empty';
            empty.innerHTML = `
                <div class="modal-empty-icon">${svg('search', 28)}</div>
                <div class="modal-empty-title">Нет продаж, доступных для возврата</div>`;
            retResults.appendChild(empty);
            return;
        }
        // Сортировка: сначала уже добавленные сегодня, затем по убыванию даты продажи
        list = list.slice().sort((a, b) => {
            const aIn = !!returnsMap[a.origId], bIn = !!returnsMap[b.origId];
            if (aIn !== bIn) return aIn ? -1 : 1;
            if (a.origDate !== b.origDate) return a.origDate < b.origDate ? 1 : -1;
            return a.name.localeCompare(b.name, 'ru');
        });
        const frag = document.createDocumentFragment();
        list.forEach(info => {
            const div = document.createElement('div');
            const inCart = !!returnsMap[info.origId];
            div.className = 'modal-row' + (inCart ? ' modal-row--added' : '');
            div.id = `rmrow-${info.origId}`;
            div.dataset.orig = info.origId;
            div.innerHTML = returnModalRowHtml(info);
            frag.appendChild(div);
        });
        retResults.appendChild(frag);
    }
    function returnModalRowHtml(info) {
        const current = returnsMap[info.origId];
        const cap = info.cap;
        let controls;
        if (current) {
            const atMax = current.qty >= cap;
            controls = `
                <div class="modal-row-stepper">
                    <button type="button" class="stepper-btn stepper-minus" data-rmodal-action="dec" aria-label="Убрать одну">${svg('minus', 16)}</button>
                    <span class="modal-qty-label">${current.qty}&nbsp;из&nbsp;${cap}&nbsp;шт.</span>
                    <button type="button" class="stepper-btn" data-rmodal-action="inc" aria-label="Добавить одну" ${atMax ? 'disabled' : ''}>${svg('plus', 16)}</button>
                </div>`;
        } else {
            controls = `
                <button type="button" class="btn btn-outline-warning btn-sm" data-rmodal-action="add">
                    ${svg('refresh-ccw', 14)}Возврат
                </button>`;
        }
        return `
            <div class="modal-row-name">${productNameHtml(info.name)}</div>
            <div class="modal-row-meta">
                <span>продажа от <strong>${fmtDateRu(info.origDate)}</strong></span>
                &nbsp;·&nbsp;
                <span>${info.origQty}&nbsp;шт. по ${fmt(info.unitPrice)}&nbsp;руб.</span>
                &nbsp;·&nbsp;
                <span class="text-faint">доступно к возврату: <strong>${cap}</strong></span>
            </div>
            <div class="modal-row-controls">${controls}</div>`;
    }
    function refreshReturnModalRow(origId) {
        const el = document.getElementById('rmrow-' + origId);
        if (!el) return;
        const info = RETURNABLE_BY_ID[origId]; if (!info) return;
        el.className = 'modal-row' + (returnsMap[origId] ? ' modal-row--added' : '');
        el.innerHTML = returnModalRowHtml(info);
    }
    if (retResults) retResults.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-rmodal-action]');
        if (!btn) return;
        const row = btn.closest('[data-orig]');
        if (!row) return;
        const origId = +row.dataset.orig;
        const action = btn.dataset.rmodalAction;
        if (action === 'add' || action === 'inc') addReturn(origId);
        else if (action === 'dec') changeReturnQty(origId, -1);
    });

    // ── Общий обработчик data-action ────────────────────
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        if (action === 'open-modal') {
            if (!CAN_EDIT) return;
            const mode = +btn.dataset.mode || 0;
            if (mode === 1) openReturnModal(); else openSalesModal();
        } else if (action === 'close-modal') {
            closeModal(overlay);
        } else if (action === 'close-return-modal') {
            if (retOverlay) closeModal(retOverlay);
        } else if (action === 'undo') {
            performUndo();
        }
    });

    // ════════════════════════════════════════════════════════
    //                    AUTOSAVE QUEUE
    // ════════════════════════════════════════════════════════
    //
    // После любой мутации зовём scheduleSave() — оно дебаунсит запросы.
    // Через DEBOUNCE_MS после последней мутации шлём батч на сервер.
    // Если запрос в полёте — следующий стартует только после ответа.
    // Если сеть отвалилась — ретраим с экспоненциальной задержкой.
    //
    // Дельта вычисляется по одному принципу: текущий state vs ORIG_SALES/ORIG_RETURNS.
    // После успеха ORIG_* обновляются, чтобы повторный flush ничего не слал.

    // Дебаунс автосохранения. Подобран так, чтобы:
    //  • продавец напечатал короткую цену/qty/заметку и всё ушло одним батчем;
    //  • после остановки активности «✓ Сохранено» появлялось без долгого ожидания.
    // Типичные autosave-приложения живут в диапазоне 1500–2000 мс.
    const DEBOUNCE_MS  = 2000;
    const RETRY_MS     = 5000;
    const REQUEST_TO   = 20000;  // 20s — после этого считаем «нет связи»
    const CSRF         = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const SAVE_DATE    = BOOT.selDate;

    let pendingTimer  = null;
    let inFlight      = false;
    let dirty         = false;     // в очереди есть несохранённые изменения
    let networkError  = false;     // последний запрос — сетевая ошибка (можно ретраить)
    let fatalError    = '';        // фатальная ошибка (4xx, сессия истекла) — НЕ ретраим

    let statusEverShown = false;

    function setStatus(state, text, title) {
        if (!saveStatusEl) return;
        saveStatusEl.dataset.status = state;
        saveStatusEl.textContent = text;
        saveStatusEl.title = title || '';
        saveStatusEl.hidden = false;
    }

    function refreshStatus() {
        if (!CAN_EDIT || !saveStatusEl) return;
        // До первой мутации статус скрыт — на свежезагруженной странице
        // зелёное «Сохранено» не имеет смысла.
        if (!statusEverShown && !dirty && !inFlight && !networkError && !fatalError) {
            saveStatusEl.hidden = true;
            return;
        }
        statusEverShown = true;
        if (fatalError)   return setStatus('error',   '⚠ ' + fatalError, fatalError);
        if (inFlight)     return setStatus('saving',  '⏳ Сохраняется…');
        if (networkError) return setStatus('error',   '⚠ Нет связи, повторим');
        if (dirty)        return setStatus('pending', '✎ Есть изменения');
        setStatus('ok', '✓ Сохранено');
    }

    function markDirty() {
        if (!CAN_EDIT) return;
        dirty = true;
        refreshStatus();
        // При фатальной ошибке (истёкшая сессия и т.п.) очередь
        // остановлена до перезагрузки страницы — таймер бесполезен
        // и только засоряет event loop при каждом нажатии клавиши.
        if (fatalError) return;
        scheduleSave();
    }

    function scheduleSave() {
        if (pendingTimer) clearTimeout(pendingTimer);
        pendingTimer = setTimeout(flushQueue, DEBOUNCE_MS);
    }

    // Собирает payload из текущего состояния (только дельта).
    function buildPayload() {
        const ops = [];
        Object.values(salesMap).forEach(s => {
            if (s.id != null) {
                const orig = ORIG_SALES[s.id];
                if (orig
                    && s.qty === orig.qty
                    && moneyEq(s.unit_price, orig.unit_price)
                    && (s.payment || 'cash') === orig.payment
                    && (s.note || '') === (orig.note || '')) {
                    return; // не менялась
                }
            }
            ops.push({
                local_key: s.key,
                id:        s.id,
                pid:       s.pid,
                qty:       s.qty,
                uprice:    s.unit_price.toFixed(2),
                payment:   s.payment || 'cash',
                note:      s.note || '',
            });
        });

        const deleted = Array.from(DELETED_IDS);

        const returns = {};
        const allOrigs = new Set([
            ...Object.keys(returnsMap).map(Number),
            ...Object.keys(ORIG_RETURNS).map(Number),
        ]);
        allOrigs.forEach(origId => {
            const cur = returnsMap[origId];
            const oq  = ORIG_RETURNS[origId] || 0;
            const qty = cur ? cur.qty : 0;
            if (qty === oq) return;
            returns[origId] = qty;
        });

        return { date: SAVE_DATE, ops, deleted, returns };
    }

    // Делать ли запрос вообще? Может быть так, что dirty=true,
    // но дельта пустая (например, изменили qty туда-обратно).
    function payloadIsEmpty(p) {
        return p.ops.length === 0 && p.deleted.length === 0
            && Object.keys(p.returns).length === 0;
    }

    /**
     * Классификация ошибок:
     *   { kind: 'transient', message } — сеть/таймаут/5xx → можно ретраить
     *   { kind: 'fatal',     message } — потеря сессии, 4xx, 403 → НЕ ретраим
     *
     * Как «теряется» сессия в нашем проекте:
     *   • cookie живёт пока браузер открыт (lifetime=0 в auth.php), но
     *   • файл сессии на сервере удаляется PHP GC после ~24 минут простоя
     *     (session.gc_maxlifetime по умолчанию). После этого require_login()
     *     делает 302 → login.php. fetch следует за редиректом и получает HTML —
     *     resp.json() падает SyntaxError. Это и есть сигнал «потеряли сессию».
     *   • 419 (CSRF mismatch) — отдельный случай, daily_save.php возвращает его сам.
     */
    async function performRequest(payload) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), REQUEST_TO);
        let resp;
        try {
            resp = await fetch('daily_save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
                signal: ctrl.signal,
            });
        } catch (err) {
            clearTimeout(timer);
            // AbortError = наш таймаут, всё остальное = сеть/CORS/etc
            const isTimeout = err && err.name === 'AbortError';
            throw {
                kind: 'transient',
                message: isTimeout ? 'Сервер не отвечает' : 'Нет связи с сервером',
            };
        }
        clearTimeout(timer);

        // 419 — daily_save.php возвращает явно при несовпадении CSRF.
        // Бывает при потере сессии (старый токен на свежей сессии).
        if (resp.status === 419) {
            throw { kind: 'fatal', message: 'Нужно войти заново' };
        }
        if (resp.status === 403) {
            throw { kind: 'fatal', message: 'Недостаточно прав для сохранения' };
        }

        let data;
        try {
            data = await resp.json();
        } catch (_) {
            // Не JSON — на 99% HTML страницы логина после редиректа require_login().
            // Реже: WAF/proxy вернул HTML-ошибку, что для нас функционально то же самое.
            throw { kind: 'fatal', message: 'Нужно войти заново' };
        }

        if (resp.status >= 500) {
            throw {
                kind: 'transient',
                message: (data && data.error) || `HTTP ${resp.status}`,
            };
        }
        if (!resp.ok || !data.ok) {
            throw {
                kind: 'fatal',
                message: (data && data.error) || `HTTP ${resp.status}`,
            };
        }
        return data;
    }

    async function flushQueue(opts = {}) {
        if (!CAN_EDIT) return;
        if (fatalError) return; // не пытаемся, пока не перезагрузят страницу
        if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
        if (inFlight) return; // подождём ответа, потом ещё раз вызовем по факту

        const payload = buildPayload();
        if (payloadIsEmpty(payload)) {
            dirty = false;
            networkError = false;
            refreshStatus();
            return;
        }

        // Снимок того, что отправляем — нужен, чтобы после успеха
        // обновить ORIG_* (и не «потерять» правки, прилетевшие во время запроса).
        const sentSnapshot = snapshotCurrent();

        inFlight = true;
        refreshStatus();
        try {
            const data = await performRequest(payload);

            // Подмена локальных ключей на серверные id для новых продаж.
            // Если за время полёта запроса пользователь успел удалить эту
            // продажу — нужно «догнать» удаление на сервере, иначе в БД
            // останется призрачная запись.
            if (data.id_map) {
                Object.entries(data.id_map).forEach(([localKey, newId]) => {
                    const s = salesMap[localKey];
                    if (s) {
                        s.id = newId;
                        // Сервер присваивает sold_at в момент INSERT — подхватываем его,
                        // чтобы в строке вместо прочерка появилось реальное время.
                        const soldAt = data.times && data.times[localKey];
                        if (soldAt) {
                            s.sold_at = soldAt;
                            const tr = tbody.querySelector(`tr[data-key="${cssEsc(localKey)}"]`);
                            const cell = tr && tr.querySelector('.col-time');
                            if (cell) {
                                const t = fmtTime(soldAt);
                                cell.innerHTML = t
                                    ? `<span class="row-time">${t}</span>`
                                    : '<span class="text-faint">—</span>';
                            }
                        }
                        // ВАЖНО: ключ объекта в salesMap менять не будем (это сломало бы DOM-привязку).
                        // s.id теперь !== null — следующий buildPayload пошлёт UPDATE по id.
                    } else {
                        // Запись удалена локально, пока летел INSERT.
                        // Догоняем: помечаем серверный id на удаление,
                        // следующий flushQueue отправит DELETE.
                        DELETED_IDS.add(newId);
                    }
                });
            }
            // Сервер присваивает sold_at при INSERT возврата — подхватываем,
            // чтобы в строке вместо прочерка появилось реальное время.
            if (data.ret_times) {
                Object.entries(data.ret_times).forEach(([origId, soldAt]) => {
                    origId = Number(origId);
                    const r = returnsMap[origId];
                    if (r && soldAt) {
                        r.sold_at = soldAt;
                        const tr = tbody.querySelector(`tr[data-key="${cssEsc('r:' + origId)}"]`);
                        const cell = tr && tr.querySelector('.col-time');
                        if (cell) {
                            const t = fmtTime(soldAt);
                            cell.innerHTML = t
                                ? `<span class="row-time">${t}</span>`
                                : '<span class="text-faint">—</span>';
                        }
                    }
                });
            }
            commitSnapshot(sentSnapshot);
            networkError = false;

            // Если за время запроса что-то ещё накопилось — флашим снова.
            const newPayload = buildPayload();
            dirty = !payloadIsEmpty(newPayload);
            refreshStatus();
            if (dirty) scheduleSave();
        } catch (err) {
            const e = (err && err.kind) ? err : { kind: 'transient', message: String(err) };
            console.error('autosave failed', e);
            if (e.kind === 'fatal') {
                fatalError = e.message;
                networkError = false;
                refreshStatus();
                if (typeof window.App?.toast === 'function') {
                    window.App.toast(
                        'Не удалось сохранить: ' + e.message + '. Обновите страницу.',
                        'error'
                    );
                }
            } else {
                networkError = true;
                refreshStatus();
                if (!opts.manual) {
                    if (pendingTimer) clearTimeout(pendingTimer);
                    pendingTimer = setTimeout(flushQueue, RETRY_MS);
                }
            }
        } finally {
            inFlight = false;
            refreshStatus();
        }
    }

    // Снимок «что мы отправили серверу» — чтобы после успешного ответа
    // обновить ORIG_SALES/ORIG_RETURNS только для отправленных значений
    // и не затереть параллельные правки пользователя.
    function snapshotCurrent() {
        const sales = {};
        Object.values(salesMap).forEach(s => {
            sales[s.key] = {
                qty: s.qty,
                unit_price: s.unit_price,
                payment: s.payment || 'cash',
                note: s.note || '',
            };
        });
        const rets = {};
        // Важно: включаем и origId из ORIG_RETURNS, иначе удалённый возврат
        // (qty=0) не попадёт в снимок и ORIG_RETURNS не сбросится — buildPayload
        // будет бесконечно слать ту же дельту.
        const allRetIds = new Set([
            ...Object.keys(returnsMap).map(Number),
            ...Object.keys(ORIG_RETURNS).map(Number),
        ]);
        allRetIds.forEach(id => { rets[id] = returnsMap[id] ? returnsMap[id].qty : 0; });
        const dels = new Set(DELETED_IDS);
        return { sales, rets, dels };
    }

    function commitSnapshot(snap) {
        // Продажи: для всех, что были в snap, обновляем ORIG_SALES по их новому id.
        Object.entries(snap.sales).forEach(([key, frozen]) => {
            const s = salesMap[key];
            if (!s || s.id == null) return;
            ORIG_SALES[s.id] = Object.freeze({ ...frozen });
        });
        // Возвраты: то же самое
        Object.entries(snap.rets).forEach(([id, qty]) => {
            if (qty === 0) delete ORIG_RETURNS[id];
            else ORIG_RETURNS[id] = qty;
        });
        // Удалённые: то, что было отправлено, можно вычеркнуть из DELETED_IDS
        snap.dels.forEach(id => {
            DELETED_IDS.delete(id);
            delete ORIG_SALES[id];
        });
    }

    // ════════════════════════════════════════════════════════
    //                       UNDO STACK
    // ════════════════════════════════════════════════════════
    //
    // Структурные операции (добавил/удалил продажу или возврат) кладутся
    // в стек. Кнопка «Отменить» снимает верхнюю и применяет обратное действие.
    // Изменения значений (qty/цена/скидка/заметка/оплата) в стек НЕ кладутся —
    // вариант А по требованию.
    //
    // После undo нужный state применяется через те же мутации (addSale/removeSale/...),
    // что автоматически триггерит markDirty() → autosave разошлёт изменения.

    const undoStack = [];
    const UNDO_LIMIT = 30;
    let undoSuppress = false; // когда true — мутации не пишутся в стек

    function pushUndo(entry) {
        if (undoSuppress) return;
        undoStack.push(entry);
        if (undoStack.length > UNDO_LIMIT) undoStack.shift();
        refreshUndoBtn();
    }

    function refreshUndoBtn() {
        if (!undoBtn) return;
        undoBtn.disabled = undoStack.length === 0;
    }

    function performUndo() {
        const entry = undoStack.pop();
        refreshUndoBtn();
        if (!entry) return;
        undoSuppress = true;
        try {
            switch (entry.type) {
                case 'add-sale':
                    // Откат добавления = удалить. doRemoveSale внутри
                    // позвал бы pushUndo, но undoSuppress это глушит.
                    if (salesMap[entry.key]) doRemoveSale(entry.key);
                    break;
                case 'remove-sale': {
                    // Откат удаления. Два сценария:
                    //  (а) DELETE ещё в локальной очереди (id в DELETED_IDS, запрос
                    //      ещё не отправлен) — снимаем из очереди, восстанавливаем как было.
                    //  (б) Запрос с DELETE уже улетел (inFlight) или ушёл (commitSnapshot
                    //      стёр id) — в БД записи может уже не быть, поэтому восстанавливаем
                    //      как НОВУЮ продажу с новым локальным ключом и id=null.
                    //      Сервер получит INSERT и создаст её заново.
                    const stillPendingDelete = entry.snapshot.id
                        && DELETED_IDS.has(entry.snapshot.id)
                        && !inFlight;
                    if (stillPendingDelete) {
                        DELETED_IDS.delete(entry.snapshot.id);
                        salesMap[entry.key] = { ...entry.snapshot };
                        insertRowDom({ kind: 'sale', key: entry.key, ref: salesMap[entry.key] });
                    } else {
                        const k = newKey();
                        salesMap[k] = { ...entry.snapshot, key: k, id: null };
                        insertRowDom({ kind: 'sale', key: k, ref: salesMap[k] });
                    }
                    reindexRows();
                    updateEmptyState();
                    updateBadge();
                    renderTotals();
                    updateModalAddedInfo();
                    markDirty();
                    break;
                }
                case 'add-return':
                    if (returnsMap[entry.origId]) doRemoveReturn(entry.origId);
                    break;
                case 'remove-return':
                    returnsMap[entry.origId] = { ...entry.snapshot };
                    insertRowDom({ kind: 'return', key: 'r:' + entry.origId, ref: returnsMap[entry.origId] });
                    reindexRows();
                    updateEmptyState();
                    updateBadge();
                    renderTotals();
                    refreshReturnModalRow(entry.origId);
                    markDirty();
                    break;
            }
        } finally {
            undoSuppress = false;
        }
    }

    // ════════════════════════════════════════════════════════
    //                      ИНТЕГРАЦИЯ
    // ════════════════════════════════════════════════════════

    // Предупреждение при закрытии вкладки / обновлении страницы.
    // По современной спецификации HTML достаточно вызвать preventDefault() —
    // браузер сам покажет стандартный диалог «Покинуть страницу?».
    // Свойство event.returnValue и return-строка из обработчика — legacy.
    window.addEventListener('beforeunload', (e) => {
        if (CAN_EDIT && (dirty || inFlight) && !fatalError) {
            e.preventDefault();
        }
    });

    // Перехват внутренней навигации (клики по ссылкам в шапке/боковой панели).
    // beforeunload не показывает диалог, если переход — по <a href> внутри
    // того же домена, поэтому делаем это сами: спрашиваем подтверждение,
    // ждём фактической отправки очереди и только потом переходим.
    document.addEventListener('click', (e) => {
        if (!CAN_EDIT) return;
        if (!dirty && !inFlight) return;
        if (fatalError) return; // фатальная ошибка — пусть уходит, всё равно сломано

        const a = e.target.closest('a[href]');
        if (!a) return;
        // Игнорируем якоря, новые вкладки, ссылки с download / mailto / target
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('mailto:')) return;
        if (a.target && a.target !== '_self') return;
        if (a.hasAttribute('download')) return;
        // Модификаторы (Ctrl/⌘+click открывают новую вкладку — не блокируем)
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        // Внешние ссылки
        try {
            const url = new URL(a.href, window.location.href);
            if (url.origin !== window.location.origin) return;
        } catch (_) { return; }

        e.preventDefault();
        confirmDialog(
            'Есть несохранённые изменения. Сохранить перед переходом?',
            { okLabel: 'Сохранить и перейти', cancelLabel: 'Остаться' }
        ).then(async ok => {
            if (!ok) return;
            // Принудительный сброс очереди. Если таймер — отменяем; если запрос
            // в полёте — ждём, пока inFlight снимется; затем последний flushQueue.
            if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
            // Цикл ожидания не более REQUEST_TO + небольшой запас
            const deadline = Date.now() + REQUEST_TO + 2000;
            while (inFlight && Date.now() < deadline) {
                await new Promise(r => setTimeout(r, 100));
            }
            if (inFlight) {
                // Запрос так и не отвис → данные не сохранены, не уходим.
                window.App?.toast?.('Сервер не отвечает. Переход отменён, данные не сохранены.', 'error');
                return;
            }
            if (!fatalError) await flushQueue({ manual: true });
            // После flushQueue убеждаемся, что всё реально ушло на сервер.
            // Если осталась грязь / network / fatal — переход отменяем,
            // иначе пользователь молча потеряет данные после клика «Сохранить и перейти».
            if (fatalError || networkError || dirty) {
                window.App?.toast?.('Не удалось сохранить. Переход отменён.', 'error');
                return;
            }
            window.location.href = a.href;
        });
    }, true);

    render();
    refreshStatus();
    refreshUndoBtn();
})();
