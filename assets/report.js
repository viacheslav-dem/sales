/**
 * Экран «Отчёт»: после сабмита формы фильтров — скроллим к таблице.
 *
 * Флаг кладётся в sessionStorage до submit, считывается на следующей загрузке.
 */
(() => {
    'use strict';

    const SCROLL_FLAG = 'report:scroll-to-table';

    const form = document.getElementById('report-form');
    if (form) {
        form.addEventListener('submit', () => {
            try {
                sessionStorage.setItem(SCROLL_FLAG, '1');
                sessionStorage.removeItem('scrollY:' + location.pathname);
            } catch (_) {}
        });
    }

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
