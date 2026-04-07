/**
 * Экран «Отчёт»: после смены группировки/категории — скроллим к таблице.
 * Сам auto-submit формы выполняется глобально через [data-auto-filter] в app.js.
 *
 *  - data-scroll-table на поле — после сабмита формы прокручиваем страницу
 *    к #report-table. Флаг кладётся в sessionStorage до submit, считывается
 *    на следующей загрузке.
 */
(() => {
    'use strict';

    const SCROLL_FLAG = 'report:scroll-to-table';

    document.querySelectorAll('[data-scroll-table]').forEach((el) => {
        el.addEventListener('change', () => {
            try {
                sessionStorage.setItem(SCROLL_FLAG, '1');
                // Подавляем глобальное восстановление scrollY из app.js,
                // чтобы не было двойного скролла.
                sessionStorage.removeItem('scrollY:' + location.pathname);
            } catch (_) {}
        });
    });

    window.addEventListener('DOMContentLoaded', () => {
        try {
            if (sessionStorage.getItem(SCROLL_FLAG) === '1') {
                sessionStorage.removeItem(SCROLL_FLAG);
                const table = document.getElementById('report-table');
                if (table) {
                    requestAnimationFrame(() => {
                        table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                }
            }
        } catch (_) {}
    });
})();
