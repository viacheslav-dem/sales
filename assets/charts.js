/**
 * Инициализация графиков на основе Chart.js.
 *
 * Любой <canvas data-chart='{...}'> на странице автоматически превращается
 * в график. Конфиг — JSON со схемой:
 *   { type: 'line'|'bar'|'doughnut',
 *     labels: string[],
 *     values: number[],
 *     label?: string,        // подпись dataset
 *     color?: string,        // основной цвет (#hex)
 *     fill?: bool,           // заливка под линией
 *     avg?: bool,            // показать среднее линией
 *     highlightMax?: bool,   // подсветить максимальный столбец
 *     valueSuffix?: string,  // суффикс в тултипе («руб.», «шт.» и т.д.)
 *     colors?: string[] }    // (для doughnut) индивидуальные цвета сегментов
 */
(() => {
    'use strict';
    if (typeof Chart === 'undefined') return;

    // Глобальные дефолты — единый стиль для всех графиков
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#475569';
    Chart.defaults.borderColor = 'rgba(15,23,42,0.06)';

    function hexToRgba(hex, a) {
        const m = String(hex).match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
        if (!m) return hex;
        return `rgba(${parseInt(m[1], 16)},${parseInt(m[2], 16)},${parseInt(m[3], 16)},${a})`;
    }

    function formatNumber(n) {
        if (Math.abs(n) >= 1000) {
            return (n / 1000).toFixed(1).replace(/\.0$/, '') + ' тыс.';
        }
        return Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
    }

    function formatFull(n) {
        return Number(n).toLocaleString('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });
    }

    function tooltipStyle(suffix) {
        return {
            backgroundColor: '#0f172a',
            titleColor: '#f1f5f9',
            bodyColor: '#cbd5e1',
            borderColor: '#1e293b',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 6,
            displayColors: false,
            titleFont: { weight: '600' },
            callbacks: {
                label: (ctx) => {
                    const v = ctx.parsed.y ?? ctx.parsed;
                    return ctx.dataset.label + ': ' + formatFull(v) + (suffix ? ' ' + suffix : '');
                },
            },
        };
    }

    function buildLine(canvas, cfg) {
        const ctx = canvas.getContext('2d');
        const color = cfg.color || '#2563eb';
        const height = canvas.parentElement.offsetHeight || 200;
        const gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, hexToRgba(color, 0.28));
        gradient.addColorStop(1, hexToRgba(color, 0.0));

        const datasets = [{
            label: cfg.label || 'Значение',
            data: cfg.values,
            borderColor: color,
            backgroundColor: cfg.fill !== false ? gradient : 'transparent',
            fill: cfg.fill !== false,
            tension: 0.32,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBackgroundColor: color,
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2,
            borderWidth: 2.2,
        }];

        if (cfg.avg && cfg.values.length > 1) {
            const avg = cfg.values.reduce((a, b) => a + b, 0) / cfg.values.length;
            datasets.push({
                type: 'line',
                label: 'Среднее',
                data: cfg.values.map(() => avg),
                borderColor: '#94a3b8',
                borderDash: [5, 5],
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
            });
        }

        return new Chart(ctx, {
            type: 'line',
            data: { labels: cfg.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: !!cfg.avg,
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 14, boxHeight: 2, padding: 12, usePointStyle: false },
                    },
                    tooltip: tooltipStyle(cfg.valueSuffix || ''),
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkipPadding: 16 },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(15,23,42,0.06)' },
                        border: { display: false },
                        ticks: {
                            callback: (v) => formatNumber(v),
                            maxTicksLimit: 5,
                            padding: 8,
                        },
                    },
                },
            },
        });
    }

    function buildBar(canvas, cfg) {
        const ctx = canvas.getContext('2d');
        const color = cfg.color || '#2563eb';
        const max = Math.max.apply(null, cfg.values);

        // Подсветка максимального столбца, остальные — приглушённый цвет
        const colors = cfg.values.map((v) => {
            if (cfg.highlightMax && v === max && max > 0) return color;
            return hexToRgba(color, 0.55);
        });

        const datasets = [{
            label: cfg.label || 'Значение',
            data: cfg.values,
            backgroundColor: colors,
            hoverBackgroundColor: color,
            borderRadius: 6,
            borderSkipped: false,
            maxBarThickness: 80,
        }];

        if (cfg.avg && cfg.values.length > 1) {
            const avg = cfg.values.reduce((a, b) => a + b, 0) / cfg.values.length;
            datasets.push({
                type: 'line',
                label: 'Среднее',
                data: cfg.values.map(() => avg),
                borderColor: '#94a3b8',
                borderDash: [5, 5],
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
            });
        }

        return new Chart(ctx, {
            type: 'bar',
            data: { labels: cfg.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: !!cfg.avg,
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 14, boxHeight: 2, padding: 12 },
                    },
                    tooltip: tooltipStyle(cfg.valueSuffix || ''),
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { weight: '600' } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(15,23,42,0.06)' },
                        border: { display: false },
                        ticks: {
                            callback: (v) => formatNumber(v),
                            maxTicksLimit: 5,
                            padding: 8,
                        },
                    },
                },
            },
        });
    }

    function buildDoughnut(canvas, cfg) {
        const ctx = canvas.getContext('2d');
        const palette = cfg.colors || [
            '#2563eb', '#16a34a', '#d97706', '#dc2626',
            '#0ea5e9', '#8b5cf6', '#14b8a6', '#f59e0b',
            '#ef4444', '#64748b',
        ];
        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: cfg.labels,
                datasets: [{
                    data: cfg.values,
                    backgroundColor: palette.slice(0, cfg.values.length),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 8,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 10,
                            font: { size: 12 },
                        },
                    },
                    tooltip: {
                        ...tooltipStyle(cfg.valueSuffix || ''),
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const v = ctx.parsed;
                                const pct = total > 0 ? ((v / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + formatFull(v)
                                    + (cfg.valueSuffix ? ' ' + cfg.valueSuffix : '')
                                    + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    }

    function init() {
        document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
            if (canvas.__chartInit) return;
            canvas.__chartInit = true;
            let cfg;
            try { cfg = JSON.parse(canvas.dataset.chart); }
            catch (e) { console.error('Bad chart config', e); return; }

            try {
                if (cfg.type === 'line')          buildLine(canvas, cfg);
                else if (cfg.type === 'bar')      buildBar(canvas, cfg);
                else if (cfg.type === 'doughnut') buildDoughnut(canvas, cfg);
            } catch (e) {
                console.error('Chart init failed', e);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
