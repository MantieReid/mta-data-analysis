/* Renders the interactive parts of [mta_congestion] from window.MTA_CONGESTION: toll calculator, zone map, charts. */
(function () {
  var C = window.MTA_CONGESTION;
  if (!C) return;
  var H = window.MTA_H2 ? ['h2', 'h3'] : ['h3', 'h4']; // section and sub-heading tags (set by the plugin)
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var YEAR_COLORS = ['#e8590c', '#2a6fdb', '#2f9e44'];

  function fmt(n) { return Math.round(n).toLocaleString('en-US'); }
  function short(n) { return n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? Math.round(n / 1e3) + 'K' : String(n); }
  function hourName(h) { return (h % 12 || 12) + (h < 12 ? ' AM' : ' PM'); }
  function slot(k) { return document.querySelector('.mta-congestion [data-slot="' + k + '"]'); }
  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function chart(parent, config) {
    var wrap = el('div', 'mta-chart');
    var c = document.createElement('canvas');
    wrap.appendChild(c);
    parent.appendChild(wrap);
    config.options = Object.assign({ responsive: true, maintainAspectRatio: false }, config.options || {});
    return new Chart(c, config);
  }
  var root = document.querySelector('.mta-congestion');
  if (window.Chart) {
    Chart.defaults.color = getComputedStyle(root).color;
    Chart.defaults.borderColor = 'rgba(127,127,127,0.2)';
  }
  function isPeak(day, h) { return day === 'weekend' ? h >= 9 && h < 21 : h >= 5 && h < 21; }

  // Toll calculator (E-ZPass rates). Mirrors the rules on mta.info.
  var calc = slot('calc');
  if (calc) {
    var opts = function (pairs) { return pairs.map(function (p) { return '<option value="' + p[0] + '">' + p[1] + '</option>'; }).join(''); };
    var hours = [];
    for (var h = 0; h < 24; h++) hours.push([h, hourName(h)]);
    calc.innerHTML = '<' + H[1] + '>Toll calculator</' + H[1] + '><div class="mta-calc-grid">' +
      '<label>Vehicle<select class="mta-select" name="v">' + opts(Object.keys(C.rates).map(function (k) { return [k, C.rates[k][0]]; })) + '</select></label>' +
      '<label>Day<select class="mta-select" name="d">' + opts([['weekday', 'Weekday'], ['weekend', 'Weekend']]) + '</select></label>' +
      '<label>Time you enter<select class="mta-select" name="h">' + opts(hours) + '</select></label>' +
      '<label>Way in<select class="mta-select" name="e">' + opts([['other', 'A bridge or a street at 60th St'],
        ['tunnel', 'Lincoln, Holland, Queens-Midtown or Carey tunnel']]) + '</select></label>' +
      '</div><p class="mta-calc-out" aria-live="polite"></p>';
    var f = function (n) { return calc.querySelector('[name="' + n + '"]'); };
    f('h').value = 8;
    var update = function () {
      var r = C.rates[f('v').value], peak = isPeak(f('d').value, +f('h').value);
      var toll = peak ? r[1] : r[2], credit = peak && f('e').value === 'tunnel' ? Math.min(r[3], toll) : 0;
      calc.querySelector('.mta-calc-out').innerHTML = (credit ? 'From ' : '') + '<strong>$' + (toll - credit).toFixed(2) + '</strong> ' +
        (peak ? 'peak toll' : 'overnight toll') + (credit ? ' ($' + toll.toFixed(2) + ' minus up to $' + credit.toFixed(2) + ' tunnel credit)' : '') +
        (f('v').value === 'car' || f('v').value === 'moto' ? '. Charged once a day.' : '. Charged each time you enter.') +
        ' <span class="mta-note">With E-ZPass; more without.</span>';
    };
    calc.addEventListener('change', update);
    update();
  }

  // Zone map with the subway stations inside it.
  var mapBox = slot('map');
  if (mapBox && window.L && C.zone && C.zone.length) {
    var map = L.map(mapBox, { scrollWheelZoom: false });
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
    }).addTo(map);
    var shapes = C.zone.map(function (ring) {
      return L.polygon(ring, { color: '#f08c00', weight: 2, fillColor: '#f08c00', fillOpacity: 0.18 }).addTo(map);
    });
    ((C.subway && C.subway.points) || []).forEach(function (p) {
      L.circleMarker(p, { radius: 3, weight: 0, fillColor: '#6ea8fe', fillOpacity: 0.9 }).addTo(map);
    });
    map.fitBounds(L.featureGroup(shapes).getBounds(), { padding: [10, 10] });
  }

  // When vehicles enter: weekday and weekend averages per hour, peak hours shaded.
  var hoursBox = slot('hours');
  if (hoursBox && window.Chart) {
    var labels = C.hours.weekday.map(function (_, h) { return hourName(h); });
    chart(hoursBox, {
      type: 'bar',
      data: { labels: labels, datasets: [
        { label: 'Weekday', data: C.hours.weekday, backgroundColor: C.hours.weekday.map(function (_, h) { return isPeak('weekday', h) ? '#2a6fdb' : '#2a6fdb66'; }) },
        { label: 'Weekend', data: C.hours.weekend, backgroundColor: C.hours.weekend.map(function (_, h) { return isPeak('weekend', h) ? '#e8590c' : '#e8590c66'; }) }
      ] },
      options: { plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(c.parsed.y) + ' vehicles' +
          (isPeak(c.datasetIndex ? 'weekend' : 'weekday', c.dataIndex) ? ' (peak)' : ' (overnight)'); } } } },
        scales: { y: { beginAtZero: true, ticks: { callback: short } } } }
    });
  }

  // This year vs last year, average vehicles a day by month.
  var monthlyBox = slot('monthly');
  if (monthlyBox && window.Chart) {
    var years = Object.keys(C.monthly).sort();
    chart(monthlyBox, {
      type: 'line',
      data: { labels: MONTHS, datasets: years.map(function (y, i) {
        var row = C.monthly[y];
        return { label: y, data: MONTHS.map(function (_, m) { return row[m + 1] || null; }), borderColor: YEAR_COLORS[i % 3],
                 backgroundColor: YEAR_COLORS[i % 3], tension: 0.25, spanGaps: false };
      }) },
      options: { plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(c.parsed.y) + ' vehicles a day'; } } } },
                 scales: { y: { ticks: { callback: short } } } }
    });
  }

  // Who pays: entries by vehicle type, peak vs overnight.
  var vehBox = slot('vehicles');
  if (vehBox && window.Chart) {
    var kinds = Object.keys(C.vehicles);
    chart(vehBox, {
      type: 'bar',
      data: { labels: kinds, datasets: [
        { label: 'Peak', data: kinds.map(function (k) { return C.vehicles[k].peak || 0; }), backgroundColor: '#2a6fdb' },
        { label: 'Overnight', data: kinds.map(function (k) { return C.vehicles[k].overnight || 0; }), backgroundColor: '#2a6fdb66' }
      ] },
      options: { indexAxis: 'y', plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(c.parsed.x) + ' entries'; } } } },
                 scales: { x: { stacked: true, ticks: { callback: short } }, y: { stacked: true } } }
    });
  }
})();
