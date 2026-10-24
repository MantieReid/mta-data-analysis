/* Renders [mta_ridership] from window.MTA_RIDERSHIP (built daily by the plugin). */
(function () {
  var D = window.MTA_RIDERSHIP;
  if (!D || !window.Chart) return;

  var YEAR_COLORS = ['#2a6fdb', '#e8590c', '#2f9e44', '#ae3ec9', '#f08c00'];
  var SEASON_COLORS = { Winter: '#4c9be8', Spring: '#5cb85c', Summer: '#f0ad4e', Fall: '#d35400' };
  var DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function fmt(n) { return Math.round(n).toLocaleString('en-US'); }
  function short(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1) + 'M';
    if (n >= 1e3) return Math.round(n / 1e3) + 'K';
    return String(n);
  }
  function niceDate(ymd) {
    var p = ymd.split('-');
    return MONTHS[+p[1] - 1] + ' ' + (+p[2]) + ', ' + p[0];
  }
  function stationLabel(name) { return name.replace(/\s*\(.*$/, ''); } // drop "(1,2,3,...)" line list
  function lines(name) { var m = name.match(/\(([^)]*)\)/); return m ? m[1] : ''; }
  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function section(root, title, note) {
    var s = el('section', 'mta-section');
    s.appendChild(el('h3', null, esc(title)));
    if (note) s.appendChild(el('p', 'mta-note', note));
    root.appendChild(s);
    return s;
  }
  function chart(parent, config, tall) {
    var wrap = el('div', 'mta-chart' + (tall ? ' mta-chart-tall' : ''));
    var c = document.createElement('canvas');
    wrap.appendChild(c);
    parent.appendChild(wrap);
    config.options = Object.assign({ responsive: true, maintainAspectRatio: false }, config.options || {});
    return new Chart(c, config);
  }
  var yMillions = { ticks: { callback: function (v) { return short(v); } }, beginAtZero: true };
  var tipRiders = { callbacks: { label: function (c) { return (c.dataset.label ? c.dataset.label + ': ' : '') + fmt(c.parsed.y != null && c.chart.options.indexAxis !== 'y' ? c.parsed.y : c.parsed.x) + ' riders'; } } };

  function topList(stations) {
    var max = stations.length ? stations[0].riders : 1;
    var ol = el('ol', 'mta-top');
    stations.forEach(function (s) {
      var li = el('li');
      li.innerHTML =
        '<div class="mta-top-row"><span class="mta-top-name">' + esc(stationLabel(s.name)) +
        ' <span class="mta-lines">' + esc(lines(s.name)) + '</span></span>' +
        '<span class="mta-top-num">' + fmt(s.riders) + '</span></div>' +
        '<div class="mta-bar"><span style="width:' + (100 * s.riders / max).toFixed(1) + '%"></span></div>' +
        '<div class="mta-borough">' + esc(s.borough) + '</div>';
      ol.appendChild(li);
    });
    return ol;
  }

  var render = {
    top5: function (root) {
      var s = section(root, 'Busiest stations',
        'Latest data from MTA: <strong>' + niceDate(D.latest_day) + '</strong> (' + fmt(D.latest_total) +
        ' subway riders that day). MTA publishes ridership about 1–2 weeks after the fact; this page refreshes daily.');
      var grid = el('div', 'mta-grid');
      var a = el('div', 'mta-card');
      a.appendChild(el('h4', null, 'Top 5 on ' + niceDate(D.latest_day)));
      a.appendChild(topList(D.top_latest));
      var b = el('div', 'mta-card');
      b.appendChild(el('h4', null, 'Top 5, ' + niceDate(D.week_from) + ' – ' + niceDate(D.latest_day)));
      b.appendChild(topList(D.top_week));
      grid.appendChild(a); grid.appendChild(b);
      s.appendChild(grid);
    },

    monthly: function (root) {
      var s = section(root, 'Riders per month', 'Faded bars are months with incomplete data.');
      var colors = D.monthly.map(function (m, i) {
        var yrs = Object.keys(D.hourly);
        var c = YEAR_COLORS[Math.max(0, yrs.indexOf(m.month.slice(0, 4))) % YEAR_COLORS.length];
        return m.partial ? c + '55' : c;
      });
      chart(s, {
        type: 'bar',
        data: {
          labels: D.monthly.map(function (m) { return MONTHS[+m.month.slice(5) - 1] + ' ' + m.month.slice(2, 4); }),
          datasets: [{ data: D.monthly.map(function (m) { return m.riders; }), backgroundColor: colors }]
        },
        options: {
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
            var m = D.monthly[c.dataIndex];
            return fmt(m.riders) + ' riders' + (m.partial ? ' (partial: ' + m.days + ' days)' : '');
          } } } },
          scales: { y: yMillions }
        }
      });
    },

    hourly: function (root) {
      var s = section(root, 'Riders by hour of day', 'Average riders entering the subway in each hour, across all stations.');
      chart(s, {
        type: 'line',
        data: {
          labels: Array.from({ length: 24 }, function (_, h) { return (h % 12 || 12) + (h < 12 ? 'a' : 'p'); }),
          datasets: Object.keys(D.hourly).map(function (y, i) {
            return { label: y, data: D.hourly[y], borderColor: YEAR_COLORS[i % 5], backgroundColor: YEAR_COLORS[i % 5], tension: 0.3, pointRadius: 2 };
          })
        },
        options: { plugins: { tooltip: tipRiders }, scales: { y: yMillions } }
      });
    },

    dow: function (root) {
      var s = section(root, 'Riders by day of week', 'Average daily subway riders.');
      chart(s, {
        type: 'bar',
        data: {
          labels: DAYS,
          datasets: Object.keys(D.day_of_week).map(function (y, i) {
            return { label: y, data: D.day_of_week[y], backgroundColor: YEAR_COLORS[i % 5] };
          })
        },
        options: { plugins: { tooltip: tipRiders }, scales: { y: yMillions } }
      });
    },

    seasons: function (root) {
      var s = section(root, 'Riders per week, by season',
        'Average weekly riders (complete weeks only). Winter is Dec–Feb. Faded bars are seasons with incomplete data.');
      chart(s, {
        type: 'bar',
        data: {
          labels: D.seasons.map(function (x) { return x.season; }),
          datasets: [{
            data: D.seasons.map(function (x) { return x.avg_weekly_riders; }),
            backgroundColor: D.seasons.map(function (x) {
              var c = SEASON_COLORS[x.season.split(' ')[0]] || '#888';
              return x.partial ? c + '55' : c;
            })
          }]
        },
        options: {
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
            var x = D.seasons[c.dataIndex];
            return fmt(x.avg_weekly_riders) + ' riders/week' + (x.partial ? ' (partial season)' : '');
          } } } },
          scales: { y: yMillions }
        }
      });
    },

    topstations: function (root) {
      var s = section(root, 'Top stations by year and season');
      var sel = el('select', 'mta-select');
      sel.setAttribute('aria-label', 'Choose a year or season');
      var opts = [];
      Object.keys(D.top_years).forEach(function (y) {
        opts.push({ label: y + (D.top_years[y].partial ? ' (so far)' : '') + ' — top 10', data: D.top_years[y].stations });
      });
      Object.keys(D.top_seasons).forEach(function (k) {
        opts.push({ label: k + (D.top_seasons[k].partial ? ' (partial)' : '') + ' — top 5', data: D.top_seasons[k].stations });
      });
      opts.forEach(function (o, i) { var op = el('option', null, esc(o.label)); op.value = i; sel.appendChild(op); });
      sel.value = Object.keys(D.top_years).length - 1; // default to the latest year
      s.appendChild(sel);
      var c = chart(s, {
        type: 'bar',
        data: { labels: [], datasets: [{ data: [], backgroundColor: '#5f6caf' }] },
        options: {
          indexAxis: 'y',
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            title: function (it) { return opts[sel.value].data[it[0].dataIndex].name; },
            label: function (it) { return fmt(it.parsed.x) + ' riders'; }
          } } },
          scales: { x: yMillions }
        }
      }, true);
      function update() {
        var d = opts[sel.value].data;
        var counts = {};
        d.forEach(function (x) { counts[stationLabel(x.name)] = (counts[stationLabel(x.name)] || 0) + 1; });
        c.data.labels = d.map(function (x) {  // two "34 St-Penn Station" complexes: add their lines
          var l = stationLabel(x.name);
          return counts[l] > 1 ? l + ' (' + lines(x.name) + ')' : l;
        });
        c.data.datasets[0].data = d.map(function (x) { return x.riders; });
        c.update();
      }
      sel.addEventListener('change', update);
      update();
    }
  };

  document.querySelectorAll('.mta-ridership').forEach(function (root) {
    Chart.defaults.color = getComputedStyle(root).color; // match the theme's text color
    Chart.defaults.borderColor = 'rgba(127,127,127,0.2)';
    (root.getAttribute('data-show') || '').split(',').forEach(function (k) {
      if (render[k]) render[k](root);
    });
    root.appendChild(el('p', 'mta-source',
      'Source: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a>, data.ny.gov. ' +
      'Covers ' + niceDate(D.first_day) + ' – ' + niceDate(D.latest_day) + '. Updated ' + new Date(D.updated).toLocaleDateString('en-US') + '.'));
  });
})();
