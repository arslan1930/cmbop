/**
 * Teal Link chart helpers — keep Chart.js colors on-logo.
 * Exposes window.SlbCharts for dashboard blades.
 */
(function (global) {
  const palette = {
    primary: '#185054',
    live: '#0ea5e9',
    soft: '#3faeb2',
    muted: '#75787B',
    success: '#0f766e',
    wash: '#b8e4e4',
    grid: '#e2e8f0',
    cancelled: '#94a3b8',
  };

  /** Doughnut/status series: pending → processing → completed → cancelled */
  const statusColors = [palette.muted, palette.live, palette.success, palette.cancelled];

  /** Multi-series fallback */
  const series = [
    palette.primary,
    palette.live,
    palette.soft,
    palette.muted,
    palette.success,
    palette.wash,
    palette.cancelled,
  ];

  function tickOpts() {
    return { color: palette.muted };
  }

  function gridOpts() {
    return { color: palette.grid };
  }

  global.SlbCharts = {
    palette,
    statusColors,
    series,
    tickOpts,
    gridOpts,
  };
})(typeof window !== 'undefined' ? window : globalThis);
