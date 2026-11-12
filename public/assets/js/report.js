// 리포트 차트: canvas[data-chart] 의 JSON 명세로 Chart.js 를 그린다(인라인 스크립트 금지 정책).
(function () {
  'use strict';
  if (typeof Chart === 'undefined') { return; }
  var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  Chart.defaults.color = dark ? '#dee2e6' : '#495057';
  Chart.defaults.borderColor = dark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)';
  var palette = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14', '#0dcaf0', '#6c757d', '#d63384'];
  document.querySelectorAll('canvas[data-chart]').forEach(function (cv) {
    var spec;
    try { spec = JSON.parse(cv.getAttribute('data-chart')); } catch (e) { return; }
    var pie = spec.type === 'doughnut';
    var datasets = spec.datasets.map(function (d, i) {
      var ds = { label: d.label, data: d.data };
      if (pie) {
        ds.backgroundColor = spec.labels.map(function (_, k) { return palette[k % palette.length]; });
      } else {
        ds.backgroundColor = palette[i % palette.length];
        ds.borderColor = palette[i % palette.length];
      }
      return ds;
    });
    var options = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'bottom' } } };
    if (!pie) {
      var ints = spec.datasets.every(function (d) { return d.data.every(Number.isInteger); });
      options.scales = {
        x: { stacked: !!spec.stacked },
        y: { stacked: !!spec.stacked, beginAtZero: true, ticks: ints ? { precision: 0 } : {} }
      };
    }
    new Chart(cv, { type: spec.type, data: { labels: spec.labels, datasets: datasets }, options: options });
  });
})();
