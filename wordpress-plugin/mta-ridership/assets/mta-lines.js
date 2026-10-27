/* Charts for [mta_lines] from window.MTA_LINES: on-time trend, delay causes, and the all-lines ranking. */
(function () {
  var D = window.MTA_LINES;
  if (!D || !window.Chart) return;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var root = document.querySelector('.mta-lines');
  Chart.defaults.color = getComputedStyle(root).color;
  Chart.defaults.borderColor = 'rgba(127,127,127,0.2)';

  function slot(k) { return root.querySelector('[data-slot="' + k + '"]'); }
  function chart(parent, config, tall) {
    var wrap = document.createElement('div');
    wrap.className = 'mta-chart' + (tall ? ' mta-chart-tall' : '');
    var c = document.createElement('canvas');
    wrap.appendChild(c);
    parent.appendChild(wrap);
    config.options = Object.assign({ responsive: true, maintainAspectRatio: false }, config.options || {});
    return new Chart(c, config);
  }
  var pct = { ticks: { callback: function (v) { return v + '%'; } } };
  var monthLabels = D.months.map(function (m) { return MONTHS[+m.slice(5) - 1] + ' ' + m.slice(2, 4); });

  // On-time rate by month: this line vs the whole subway.
  var trend = slot('trend');
  if (trend && D.line) {
    chart(trend, {
      type: 'line',
      data: { labels: monthLabels, datasets: [
        { label: D.line.name, data: D.line.series.map(function (x) { return x[0]; }), borderColor: '#2a6fdb', backgroundColor: '#2a6fdb', tension: 0.25 },
        { label: 'Whole subway', data: D.system.series.map(function (x) { return x[0]; }), borderColor: '#999', borderDash: [6, 4], pointRadius: 0, tension: 0.25 }
      ] },
      options: { plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y + '% on time'; } } } }, scales: { y: pct } }
    });
  }

  // Delayed trains by cause (this line, or the whole subway on the ranking page).
  var causesBox = slot('causes');
  if (causesBox) {
    var src = (D.line || D.system).causes, keys = Object.keys(src), total = keys.reduce(function (a, k) { return a + src[k]; }, 0);
    chart(causesBox, {
      type: 'bar',
      data: { labels: keys, datasets: [{ data: keys.map(function (k) { return Math.round(src[k] / total * 1000) / 10; }), backgroundColor: '#e8590c' }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
        return c.parsed.x + '% (' + src[keys[c.dataIndex]].toLocaleString('en-US') + ' delayed trains)'; } } } }, scales: { x: pct } }
    });
  }

  // All lines, least to most on time.
  var rankBox = slot('ranking');
  if (rankBox && D.lines) {
    var ls = D.lines.slice().reverse();
    chart(rankBox, {
      type: 'bar',
      data: { labels: ls.map(function (l) { return l.name.replace(' train', ''); }),
              datasets: [{ data: ls.map(function (l) { return l.otp; }),
                           backgroundColor: ls.map(function (l) { return l.otp < D.system.otp ? '#e8590c' : '#2f9e44'; }) }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.parsed.x + '% on time'; } } } },
                 scales: { x: Object.assign({ min: 50, max: 100 }, pct) } }
    }, true);
  }
})();
