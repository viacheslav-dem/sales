/**
 * Экран «Товары»: модалка редактирования и делегированный обработчик кнопок.
 */
(() => {
    'use strict';

    const overlay    = document.getElementById('edit-modal-overlay');
    const addOverlay = document.getElementById('add-modal-overlay');
    if (!overlay) return;

    const { openModal, closeModal } = window.App;

    const idInput       = document.getElementById('edit-id');
    const nameInput     = document.getElementById('edit-name');
    const catSelect     = document.getElementById('edit-category');
    const priceInput    = document.getElementById('edit-price');
    const validFromInput= document.getElementById('edit-valid-from');
    const curPriceLabel = document.getElementById('edit-current-price');

    function openEditModal(product) {
        idInput.value       = product.id;
        nameInput.value     = product.name;
        catSelect.value     = (product.category_id ?? '') + '';
        priceInput.value    = '';
        // valid_from по умолчанию = дата действующей цены (а не сегодня),
        // чтобы случайный сабмит без изменения цены не сбрасывал valid_from.
        if (validFromInput && product.price_since) {
            validFromInput.value = product.price_since;
        }
        curPriceLabel.textContent = Number(product.price).toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        openModal(overlay, { initialFocus: '#edit-name' });
    }

    // ── Массовый выбор и редактирование ──────────────────
    const bulkBar      = document.getElementById('bulk-action-bar');
    const bulkCount    = document.getElementById('bulk-count');
    const bulkOverlay  = document.getElementById('bulk-modal-overlay');
    const bulkRows     = document.getElementById('bulk-rows');
    const bulkTpl      = document.getElementById('bulk-row-template');
    const bulkMaster   = document.getElementById('bulk-master');

    function getCheckedBoxes() {
        return Array.from(document.querySelectorAll('.bulk-check:checked'));
    }

    function updateBulkBar() {
        const n = getCheckedBoxes().length;
        if (!bulkBar) return;
        if (n > 0) {
            bulkBar.hidden = false;
            bulkCount.textContent = String(n);
        } else {
            bulkBar.hidden = true;
        }
        // Состояние master-чекбокса
        if (bulkMaster) {
            const all = document.querySelectorAll('.bulk-check');
            const checked = getCheckedBoxes().length;
            bulkMaster.checked = checked > 0 && checked === all.length;
            bulkMaster.indeterminate = checked > 0 && checked < all.length;
        }
    }

    function openBulkModal() {
        const checked = getCheckedBoxes();
        if (checked.length === 0) return;
        bulkRows.innerHTML = '';
        checked.forEach((cb, idx) => {
            let p;
            try { p = JSON.parse(cb.dataset.product); }
            catch (_) { return; }
            const node = bulkTpl.content.cloneNode(true);
            const tr = node.querySelector('tr');
            tr.querySelector('.bulk-row-num').textContent = String(idx + 1);
            tr.querySelector('[data-name="id"]').value   = p.id;
            tr.querySelector('[data-name="id"]').name    = 'id[]';
            tr.querySelector('[data-name="name"]').value = p.name;
            tr.querySelector('[data-name="name"]').name  = 'name[]';
            const cat = tr.querySelector('[data-name="cat"]');
            cat.value = (p.category_id ?? '') + '';
            cat.name  = 'cat[]';
            const price = tr.querySelector('[data-name="price"]');
            price.name = 'price[]';
            tr.querySelector('.bulk-row-current').textContent =
                'Текущая: ' + Number(p.price).toLocaleString('ru-RU', {
                    minimumFractionDigits: 2, maximumFractionDigits: 2,
                }) + ' руб.';
            bulkRows.appendChild(node);
        });
        openModal(bulkOverlay, { initialFocus: '.bulk-table textarea' });
    }

    function clearBulkSelection() {
        document.querySelectorAll('.bulk-check').forEach(cb => { cb.checked = false; });
        updateBulkBar();
    }

    // Master-чекбокс
    if (bulkMaster) {
        bulkMaster.addEventListener('change', () => {
            const v = bulkMaster.checked;
            document.querySelectorAll('.bulk-check').forEach(cb => { cb.checked = v; });
            updateBulkBar();
        });
    }
    // Делегирование change для строковых чекбоксов
    document.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('bulk-check')) {
            updateBulkBar();
        }
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        if (action === 'edit-product') {
            try {
                openEditModal(JSON.parse(btn.dataset.product));
            } catch (err) {
                console.error('Bad product payload', err);
            }
        } else if (action === 'close-edit-modal') {
            closeModal(overlay);
        } else if (action === 'open-add-modal' && addOverlay) {
            openModal(addOverlay, { initialFocus: '#add-name' });
        } else if (action === 'close-add-modal' && addOverlay) {
            closeModal(addOverlay);
        } else if (action === 'bulk-edit-open') {
            openBulkModal();
        } else if (action === 'bulk-modal-close') {
            closeModal(bulkOverlay);
        } else if (action === 'bulk-clear') {
            clearBulkSelection();
        } else if (action === 'bulk-row-remove') {
            const tr = btn.closest('tr');
            if (tr) {
                tr.remove();
                // Перенумеровать
                bulkRows.querySelectorAll('tr').forEach((r, i) => {
                    r.querySelector('.bulk-row-num').textContent = String(i + 1);
                });
                if (bulkRows.children.length === 0) closeModal(bulkOverlay);
            }
        }
    });
})();
