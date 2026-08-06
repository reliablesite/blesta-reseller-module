/**
 * ReliableSite bandwidth charts.
 *
 * Two renderers behind one entry point, because the admin panel and the client
 * portal are on different stacks in Blesta 6:
 *
 *   admin  - Paradigm bundles ApexCharts in app.min.js, so nothing is
 *            downloaded. Theme attribute is `data-theme`.
 *   client - Allure bundles no chart library, so the module vendors Chart.js
 *            locally (js/chart.umd.min.js). Theme attribute is
 *            `data-theme-mode`.
 *
 * Both react to a theme switch without a reload. Blesta dispatches no event
 * when the theme changes - setTheme() in Paradigm's app.js just rewrites the
 * attribute - so a MutationObserver on <html> is the only available hook.
 *
 * Neither path fetches anything from a third-party CDN.
 */
(function (w, d) {
    'use strict';

    var SERIES_IN = '#0d6efd';      /* --accent-color  */
    var SERIES_OUT = '#198754';     /* --success-color */

    function isDark(attr) {
        return (d.documentElement.getAttribute(attr) || 'light') === 'dark';
    }

    function palette(dark) {
        return {
            fg: dark ? '#a0a0a0' : '#666666',
            line: dark ? '#2a2a2a' : '#e0e0e0'
        };
    }

    /**
     * Re-render whenever the host theme flips.
     */
    function onThemeChange(attr, fn) {
        new MutationObserver(fn).observe(d.documentElement, {
            attributes: true,
            attributeFilter: [attr]
        });
    }

    /* ---------------------------------------------------------------- *
     * Admin - ApexCharts (bundled by Paradigm)
     * ---------------------------------------------------------------- */
    function apexOptions(series, dark) {
        var p = palette(dark);

        return {
            chart: {
                type: 'area',
                height: 320,
                background: 'transparent',
                foreColor: p.fg,
                fontFamily: 'inherit',
                toolbar: { show: false },
                animations: { enabled: false }
            },
            theme: { mode: dark ? 'dark' : 'light' },
            colors: [SERIES_IN, SERIES_OUT],
            series: [
                { name: 'In (Mbps)', data: series.in_mbps || [] },
                { name: 'Out (Mbps)', data: series.out_mbps || [] }
            ],
            grid: { borderColor: p.line, strokeDashArray: 3 },
            tooltip: { theme: dark ? 'dark' : 'light', shared: true },
            legend: { position: 'top', labels: { colors: p.fg } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.45, opacityTo: 0.05 } },
            xaxis: {
                categories: series.labels || [],
                tickAmount: 12,
                labels: { style: { colors: p.fg }, rotate: -45, hideOverlappingLabels: true },
                axisBorder: { color: p.line },
                axisTicks: { color: p.line }
            },
            yaxis: {
                title: { text: 'Mbps', style: { color: p.fg } },
                labels: {
                    style: { colors: p.fg },
                    formatter: function (v) { return v == null ? '' : v.toFixed(1); }
                }
            }
        };
    }

    function renderApex(el, series) {
        var chart = new ApexCharts(el, apexOptions(series, isDark('data-theme')));
        chart.render();

        onThemeChange('data-theme', function () {
            var o = apexOptions(series, isDark('data-theme'));
            // redrawPaths=false, animate=false - repaint without a flicker
            chart.updateOptions({
                chart: o.chart,
                theme: o.theme,
                grid: o.grid,
                tooltip: o.tooltip,
                legend: o.legend,
                xaxis: o.xaxis,
                yaxis: o.yaxis
            }, false, false);
        });

        return chart;
    }

    /* ---------------------------------------------------------------- *
     * Client - Chart.js (vendored locally)
     * ---------------------------------------------------------------- */
    function renderChartJs(el, series) {
        var attr = 'data-theme-mode';

        function build() {
            var p = palette(isDark(attr));

            return {
                type: 'line',
                data: {
                    labels: series.labels || [],
                    datasets: [
                        {
                            label: 'In (Mbps)', data: series.in_mbps || [],
                            borderColor: SERIES_IN, backgroundColor: 'rgba(13,110,253,0.1)',
                            fill: true, tension: 0.3, pointRadius: 0
                        },
                        {
                            label: 'Out (Mbps)', data: series.out_mbps || [],
                            borderColor: SERIES_OUT, backgroundColor: 'rgba(25,135,84,0.1)',
                            fill: true, tension: 0.3, pointRadius: 0
                        },
                        {
                            label: 'In (GB total)', data: series.in_gb || [],
                            borderColor: '#a855f7', borderDash: [5, 5],
                            tension: 0.3, pointRadius: 0, hidden: true
                        },
                        {
                            label: 'Out (GB total)', data: series.out_gb || [],
                            borderColor: '#f59e0b', borderDash: [5, 5],
                            tension: 0.3, pointRadius: 0, hidden: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { labels: { color: p.fg } } },
                    scales: {
                        x: {
                            ticks: { color: p.fg, maxTicksLimit: 12, autoSkip: true },
                            grid: { color: p.line }
                        },
                        y: {
                            ticks: { color: p.fg },
                            grid: { color: p.line }
                        }
                    }
                }
            };
        }

        var chart = new Chart(el, build());

        onThemeChange(attr, function () {
            var p = palette(isDark(attr));
            chart.options.plugins.legend.labels.color = p.fg;
            ['x', 'y'].forEach(function (axis) {
                chart.options.scales[axis].ticks.color = p.fg;
                chart.options.scales[axis].grid.color = p.line;
            });
            chart.update('none');   // no animation on a theme flip
        });

        return chart;
    }

    /**
     * @param {string} id       Element id of the chart container/canvas
     * @param {Object} series   {labels, in_mbps, out_mbps, in_gb, out_gb} from
     *                          Reliablesite::bandwidthSeries()
     * @param {string} context  'admin' | 'client'
     */
    w.rsBandwidthChart = function (id, series, context) {
        function start() {
            var el = d.getElementById(id);
            if (!el || !series) {
                return;
            }

            if (context === 'admin') {
                if (typeof ApexCharts === 'undefined') {
                    return;     // Paradigm bundles it; nothing sensible to fall back to
                }
                renderApex(el, series);
            } else {
                if (typeof Chart === 'undefined') {
                    return;
                }
                renderChartJs(el, series);
            }
        }

        if (d.readyState === 'loading') {
            d.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    };
})(window, document);
