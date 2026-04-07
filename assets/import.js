/**
 * Экран «Импорт»: отображение спиннера на время запроса.
 */
(() => {
    'use strict';

    const form = document.getElementById('import-form');
    const btn  = document.getElementById('import-btn');
    if (!form || !btn) return;

    form.addEventListener('submit', () => {
        btn.disabled = true;
        btn.innerHTML = `
            <svg width="16" height="16" class="icon-spin" aria-hidden="true"><use href="#icon-spinner"/></svg>
            &nbsp;Идёт импорт…`;
    });
})();
