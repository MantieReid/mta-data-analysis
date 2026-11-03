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

  // Delayed trains by month, stacked by cause (this line, or the whole subway).
  var causeTrend = slot('causes-trend'), CAUSE_COLORS = ['#e8590c', '#f59f00', '#d6336c', '#4c8df6', '#20c997', '#adb5bd'];
  var who = D.line || D.system;
  if (causeTrend && who.cause_series && D.causes) {
    chart(causeTrend, {
      type: 'bar',
      data: { labels: monthLabels, datasets: D.causes.map(function (c, i) {
        return { label: c, data: who.cause_series.map(function (m) { return m[i]; }), backgroundColor: CAUSE_COLORS[i] };
      }) },
      options: { plugins: { tooltip: { mode: 'index', callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y.toLocaleString('en-US'); } } } },
                 scales: { x: { stacked: true }, y: { stacked: true } } }
    }, true);
  }

  // Extra time per trip (waiting + riding, above the schedule) by month, in seconds: this line vs the whole subway.
  var extra = slot('extra');
  if (extra && D.line) {
    var secs = function (s) { return s.series.map(function (x) { return x[2] == null ? null : Math.round(x[2] * 60); }); };
    chart(extra, {
      type: 'line',
      data: { labels: monthLabels, datasets: [
        { label: D.line.name, data: secs(D.line), borderColor: '#2a6fdb', backgroundColor: '#2a6fdb', tension: 0.25 },
        { label: 'Whole subway', data: secs(D.system), borderColor: '#999', borderDash: [6, 4], pointRadius: 0, tension: 0.25 }
      ] },
      options: { plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y + ' sec extra a trip'; } } } },
                 scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v + 's'; } } } } }
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
