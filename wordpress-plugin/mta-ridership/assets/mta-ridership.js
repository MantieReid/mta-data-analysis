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

  function times(r) { return r >= 1 ? r.toFixed(1) + '× normal' : Math.round(r * 100) + '% of normal'; }
  function range(e) {
    return e.days > 1 ? niceDate(e.first_day) + ' – ' + niceDate(e.last_day) + ' (' + e.days + ' days)' : niceDate(e.peak_day);
  }

  // A clickable list of unusual days, with a chart of the selected station around that day.
  function eventList(parent, events, chartBox) {
    var ol = el('ol', 'mta-events');
    events.forEach(function (e, i) {
      var li = el('li');
      var btn = el('button', 'mta-event', null);
      btn.type = 'button';
      btn.innerHTML =
        '<span class="mta-top-name">' + esc(stationLabel(e.name)) + ' <span class="mta-lines">' + esc(lines(e.name)) + '</span></span>' +
        '<span class="mta-ratio">' + times(e.ratio) + '</span>' +
        '<span class="mta-borough">' + esc(range(e)) + ' · ' + fmt(e.riders) + ' riders vs normal ' + fmt(e.baseline) + '</span>';
      btn.addEventListener('click', function () { showEvent(chartBox, e, ol, li); });
      li.appendChild(btn);
      ol.appendChild(li);
    });
    parent.appendChild(ol);
    if (events.length) showEvent(chartBox, events[0], ol, ol.firstChild);
  }

  function showEvent(box, e, ol, li) {
    Array.prototype.forEach.call(ol.children, function (x) { x.classList.toggle('on', x === li); });
    box.innerHTML = '';
    box.appendChild(el('p', 'mta-note', '<strong>' + esc(stationLabel(e.name)) + '</strong>: daily riders around ' + niceDate(e.peak_day) +
      '. Dashed line = its normal level for a ' + ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][new Date(e.peak_day + 'T00:00:00Z').getUTCDay()] +
      ' (other days of the week have their own normal).'));
    var labels = [], start = new Date(e.series_start + 'T00:00:00Z');
    e.series.forEach(function (_, i) {
      var d = new Date(start.getTime() + i * 86400000);
      labels.push(MONTHS[d.getUTCMonth()] + ' ' + d.getUTCDate());
    });
    var peakIdx = Math.round((new Date(e.peak_day + 'T00:00:00Z') - start) / 86400000);
    chart(box, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'Riders', data: e.series, borderColor: '#2a6fdb', backgroundColor: '#2a6fdb', tension: 0.2,
            pointRadius: e.series.map(function (_, i) { return i === peakIdx ? 6 : 1.5; }),
            pointBackgroundColor: e.series.map(function (_, i) { return i === peakIdx ? '#e8590c' : '#2a6fdb'; }) },
          { label: 'Normal', data: e.series.map(function () { return e.baseline; }), borderColor: '#999', borderDash: [6, 4], pointRadius: 0 }
        ]
      },
      options: { plugins: { tooltip: tipRiders }, scales: { y: yMillions } }
    });
  }

  function eventsSection(root, title, note, byYear, recent) {
    var s = section(root, title, note);
    var grid = el('div', 'mta-grid mta-grid-events');
    var left = el('div');
    var box = el('div', 'mta-card');
    var sel = el('select', 'mta-select');
    sel.setAttribute('aria-label', 'Choose a time frame');
    var groups = [];
    if (recent) groups.push({ label: 'Last 14 days of data', events: recent });
    Object.keys(byYear).reverse().forEach(function (y) { groups.push({ label: 'Biggest in ' + y, events: byYear[y] }); });
    groups.forEach(function (g, i) { var o = el('option', null, esc(g.label)); o.value = i; sel.appendChild(o); });
    var listBox = el('div');
    function update() {
      listBox.innerHTML = '';
      box.innerHTML = '';
      var g = groups[sel.value];
      if (!g.events.length) {
        listBox.appendChild(el('p', 'mta-note', 'Nothing unusual in this time frame.'));
        return;
      }
      eventList(listBox, g.events, box);
    }
    sel.addEventListener('change', update);
    left.appendChild(sel);
    left.appendChild(listBox);
    grid.appendChild(left);
    grid.appendChild(box);
    s.appendChild(grid);
    // open on the first time frame that has something in it
    for (var i = 0; i < groups.length; i++) { if (groups[i].events.length) { sel.value = i; break; } }
    update();
  }

  function barList(parent, items, value, label) {
    chart(parent, {
      type: 'bar',
      data: { labels: items.map(function (x) { return stationLabel(x.name); }),
              datasets: [{ data: items.map(value), backgroundColor: '#5f6caf' }] },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false }, tooltip: { callbacks: {
          title: function (it) { return items[it[0].dataIndex].name; },
          label: function (it) { return label(items[it.dataIndex]); }
        } } },
        scales: { x: { beginAtZero: true, ticks: { callback: function (v) { return value === pct ? Math.round(v * 100) + '%' : short(v); } } } }
      }
    }, true);
  }
  function pct(x) { return x.share; }

  var T = D.trends;
  var trendsMissing = function (root, title) {
    section(root, title, 'This trend is still being calculated. Check back later today.');
  };

  var X = D.extras;
  var hourName = function (h) { return (h % 12 || 12) + (h < 12 ? ' AM' : ' PM'); };

  // Diverging horizontal bar chart (used for weekend vs weekday and year-over-year).
  function diverging(parent, items, value, colorPos, colorNeg, tipLines) {
    var wrap = el('div', 'mta-chart');
    wrap.style.height = (items.length * 26 + 40) + 'px';
    var c = document.createElement('canvas');
    wrap.appendChild(c);
    parent.appendChild(wrap);
    new Chart(c, {
      type: 'bar',
      data: {
        labels: items.map(function (x) { return stationLabel(x.name) + (lines(x.name) ? ' (' + lines(x.name) + ')' : ''); }),
        datasets: [{ data: items.map(value), borderRadius: 3, barPercentage: 0.8,
                     backgroundColor: items.map(function (x) { return value(x) >= 0 ? colorPos : colorNeg; }) }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: {
          title: function (it) { return items[it[0].dataIndex].name; },
          label: function (it) { return tipLines(items[it.dataIndex]); }
        } } },
        scales: {
          x: { ticks: { callback: function (v) { return (v > 0 ? '+' : '') + v + '%'; } },
               grid: { color: function (ctx) { return ctx.tick && ctx.tick.value === 0 ? 'rgba(127,127,127,0.8)' : 'rgba(127,127,127,0.2)'; },
                       lineWidth: function (ctx) { return ctx.tick && ctx.tick.value === 0 ? 2 : 1; } } },
          y: { grid: { display: false }, ticks: { autoSkip: false } }
        }
      }
    });
  }

  function legend(parent, pairs) {
    parent.appendChild(el('p', 'mta-legend', pairs.map(function (p) {
      return '<span><i style="background:' + p[0] + '"></i>' + esc(p[1]) + '</span>';
    }).join('')));
  }

  var render = {
    map: function (root) {
      if (!X || !window.L) return section(root, 'A day on the subway', 'The map is still being prepared. Check back later today.');
      var s = section(root, 'A day on the subway',
        'Each circle is a station, sized by how many riders enter it in that hour on an average day ' +
        '(last 8 weeks of data: ' + niceDate(X.map_from) + ' – ' + niceDate(X.to) + '). Press play to watch a day go by.');
      var st = X.stations;
      var sys = [];
      for (var h = 0; h < 24; h++) sys.push(st.reduce(function (a, x) { return a + x.hours[h]; }, 0));
      var maxV = Math.max.apply(null, st.map(function (x) { return Math.max.apply(null, x.hours); }));

      // controls
      var bar = el('div', 'mta-map-bar');
      var play = el('button', 'mta-play', '▶');
      play.type = 'button';
      play.setAttribute('aria-label', 'Play');
      var clock = el('div', 'mta-clock');
      var slider = document.createElement('input');
      slider.type = 'range'; slider.min = 0; slider.max = 23; slider.value = 8;
      slider.className = 'mta-slider';
      slider.setAttribute('aria-label', 'Hour of day');
      bar.appendChild(play); bar.appendChild(clock); bar.appendChild(slider);
      s.appendChild(bar);

      var box = el('div', 'mta-map');
      s.appendChild(box);
      var strip = el('div', 'mta-strip');
      var sysMax = Math.max.apply(null, sys);
      var cells = sys.map(function (v, h) {
        var b = el('button', null, '<span style="height:' + Math.max(4, 100 * v / sysMax) + '%"></span>');
        b.type = 'button';
        b.title = hourName(h) + ': ' + fmt(v) + ' riders';
        b.addEventListener('click', function () { stop(); setHour(h); });
        strip.appendChild(b);
        return b;
      });
      s.appendChild(strip);
      s.appendChild(el('div', 'mta-strip-labels', '<span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span>'));

      var map = L.map(box, { preferCanvas: true, scrollWheelZoom: false, zoomSnap: 0.25 });
      // Esri's dark gray canvas: free to use with attribution, no API key.
      var esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/';
      L.tileLayer(esri + 'World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
      }).addTo(map);
      // Frame every station as tightly as possible (fractional zoom fits the city to the box).
      map.fitBounds(st.map(function (x) { return [x.lat, x.lon]; }), { padding: [12, 12] });

      var maxR = Math.max(12, Math.min(28, box.clientWidth / 22)); // smaller circles on phones
      function radius(v) { return v <= 0 ? 0 : Math.max(1.5, maxR * Math.sqrt(v / maxV)); }
      function color(v) { // blue for quiet, through cyan, to warm yellow for the busiest
        var t = Math.sqrt(Math.min(1, v / maxV));
        return 'hsl(' + Math.round(210 - 170 * t) + ',90%,' + Math.round(55 + 5 * t) + '%)';
      }
      var hour = 8;
      var markers = st.map(function (x) {   // stations come sorted busiest first, so small ones draw on top
        var m = L.circleMarker([x.lat, x.lon], { weight: 0.6, color: 'rgba(255,255,255,0.5)', fillOpacity: 0.75 });
        m.bindTooltip(function () {
          var peak = x.hours.indexOf(Math.max.apply(null, x.hours));
          return '<strong>' + esc(stationLabel(x.name)) + '</strong> <span class="mta-tt-lines">' + esc(lines(x.name)) + '</span><br>' +
            fmt(x.hours[hour]) + ' riders at ' + hourName(hour) + '<br>Busiest at ' + hourName(peak) +
            ' · ' + fmt(x.hours.reduce(function (a, b) { return a + b; }, 0)) + '/day';
        }, { direction: 'top', className: 'mta-tt' });
        m.addTo(map);
        return m;
      });

      function setHour(h) {
        hour = h;
        slider.value = h;
        clock.innerHTML = '<strong>' + hourName(h) + '</strong><span>' + fmt(sys[h]) + ' riders this hour</span>';
        markers.forEach(function (m, i) {
          var v = st[i].hours[h];
          m.setRadius(radius(v));
          m.setStyle({ fillColor: color(v), opacity: v > 0 ? 1 : 0, fillOpacity: v > 0 ? 0.75 : 0 });
        });
        cells.forEach(function (c, i) { c.classList.toggle('on', i === h); });
      }
      var timer = null, touched = false;
      function stop() { touched = true; clearInterval(timer); timer = null; play.textContent = '▶'; play.setAttribute('aria-label', 'Play'); }
      function start() {
        if (timer) return;
        play.textContent = '❚❚'; play.setAttribute('aria-label', 'Pause');
        timer = setInterval(function () { setHour((hour + 1) % 24); }, 800);
      }
      play.addEventListener('click', function () { touched = true; timer ? stop() : start(); });
      slider.addEventListener('input', function () { stop(); setHour(+slider.value); });
      setHour(8);

      // Start playing once, the first time the map scrolls into view (unless the visitor prefers less motion).
      var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!calm && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (ents) {
          if (!ents[0].isIntersecting) return;
          io.disconnect();
          if (!touched) { setHour(0); start(); } // don't override an hour the visitor already picked
        }, { threshold: 0.5 });
        io.observe(box);
      }
    },

    yoy: function (root) {
      if (!X || !X.yoy) return;
      var y = X.yoy;
      var period = 'Jan 1 – ' + MONTHS[+y.through.slice(0, 2) - 1] + ' ' + (+y.through.slice(3));
      var s = section(root, y.year + ' vs ' + y.prev_year,
        'Riders from ' + period + ' each year. Very large changes at a single station usually mean it was closed ' +
        'or had a service change for part of a year, not that riders moved.');
      var tiles = el('div', 'mta-tiles mta-tiles-yoy');
      tiles.appendChild(el('div', 'mta-tile', '<strong>' + (y.total_pct > 0 ? '+' : '') + y.total_pct + '%</strong><span>all subway riders</span>'));
      y.boroughs.forEach(function (b) {
        tiles.appendChild(el('div', 'mta-tile mta-tile-sm' + (b.pct < 0 ? ' neg' : ''),
          '<strong>' + (b.pct > 0 ? '+' : '') + b.pct + '%</strong><span>' + esc(b.borough) + '</span>'));
      });
      s.appendChild(tiles);
      s.appendChild(el('h4', 'mta-subhead', 'Biggest changes by station'));
      legend(s, [['#2f9e44', 'More riders in ' + y.year], ['#e8590c', 'Fewer riders in ' + y.year]]);
      diverging(s, y.up.concat(y.down.slice().reverse()), function (x) { return x.pct; }, '#2f9e44', '#e8590c', function (x) {
        return [(x.pct > 0 ? '+' : '') + x.pct + '%', y.prev_year + ': ' + fmt(x.prev) + ' riders', y.year + ': ' + fmt(x.cur) + ' riders'];
      });
    },

    fares: function (root) {
      if (!X || !X.fares) return;
      var s = section(root, 'How people pay their fare',
        'Share of entries by fare type, ' + niceDate(X.from) + ' – ' + niceDate(X.to) + '.');
      // "OMNY - Students" and "Metrocard - Students" are the same fare type paid two ways: combine them.
      var byType = {};
      X.fares.forEach(function (f) {
        var t = f.category.replace(/^[^-]+-\s*/, '').replace(/^Unlimited.*/, 'Unlimited pass');
        byType[t] = byType[t] || { name: t, share: 0, riders: 0 };
        byType[t].share += f.share;
        byType[t].riders += f.riders;
      });
      var types = Object.keys(byType).map(function (k) { return byType[k]; }).sort(function (a, b) { return b.riders - a.riders; });
      barList(s, types, pct,
        function (x) { return Math.round(x.share * 1000) / 10 + '% (' + fmt(x.riders) + ' entries)'; });
    },

    payment: function (root) {
      if (!X || !X.payment) return;
      var p = X.payment;
      var s = section(root, 'The MetroCard fades out',
        'Share of subway entries paid with a MetroCard each month. MetroCard sales ended Dec 31, 2025; ' +
        'everyone else taps with OMNY.');
      chart(s, {
        type: 'line',
        data: {
          labels: p.map(function (m) { return MONTHS[+m.month.slice(5) - 1] + ' ' + m.month.slice(2, 4); }),
          datasets: [{ data: p.map(function (m) { return Math.round((1 - m.omny_share) * 1000) / 10; }),
                       borderColor: '#f5b041', backgroundColor: 'rgba(245,176,65,0.18)', fill: true, tension: 0.3, pointRadius: 2 }]
        },
        options: {
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
            var m = p[c.dataIndex];
            return c.parsed.y + '% MetroCard (' + fmt(m.metrocard) + ' entries)' + (m.partial ? ' · partial month' : '');
          } } } },
          scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v + '%'; } } } }
        }
      });
    },

    holidays: function (root) {
      var C = D.calendar;
      if (!C || !C.days) return section(root, 'Holidays, storms and big events', 'This chart is still being prepared. Check back later today.');
      var WD = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      var narrow = root.clientWidth < 560;
      var col = function (x) {
        return x == null ? '#1f2937' : x < 0.45 ? '#b91c1c' : x < 0.6 ? '#ea580c' : x < 0.75 ? '#f59e0b' :
          x < 0.85 ? '#facc15' : x < 1.15 ? '#334155' : x < 1.25 ? '#22c55e' : '#16a34a';
      };
      var dateOf = function (s) { return new Date(s + 'T00:00:00Z'); };
      var wday = function (s) { return (dateOf(s).getUTCDay() + 6) % 7; };
      var shortLabel = function (l) {
        return (l || '').replace("Columbus / Indigenous Peoples' Day", 'Columbus Day').replace(' (cleanup day)', '')
          .replace(' (observed)', ' (obs.)').replace('UN General Assembly week', 'UN week').replace('Day after Thanksgiving', 'Black Friday');
      };

      var s = section(root, 'Holidays, storms and big events',
        'Each square is one day, colored by how busy the subway was compared with a normal day for that weekday ' +
        '(the median of the 26 weeks before). Outlined squares are holidays and events. Hover or tap a day.');
      var tip = el('div', 'mta-cal-tip');
      document.body.appendChild(tip);
      var hideTip = function () { tip.style.display = 'none'; };
      var byYear = {};
      C.days.forEach(function (d) { (byYear[d.d.slice(0, 4)] = byYear[d.d.slice(0, 4)] || []).push(d); });
      Object.keys(byYear).reverse().forEach(function (y, yi) {
        var list = byYear[y], pad = wday(list[0].d);
        var box = el('div', 'mta-cal-year', '<h4>' + esc(y) + (yi === 0 ? ' (so far)' : '') + '</h4>');
        var wrap = el('div', 'mta-cal-wrap');
        var months = el('div', 'mta-cal-months');
        var row = el('div', 'mta-cal-row');
        row.appendChild(el('div', 'mta-cal-days', WD.map(function (w, i) { return '<span>' + (i % 2 ? '' : w) + '</span>'; }).join('')));
        var grid = el('div', 'mta-cal');
        for (var i = 0; i < pad; i++) grid.appendChild(el('i', 'mta-cal-pad'));
        var lastM = -1;
        list.forEach(function (d, i) {
          var cell = el('i', d.l ? 'lab' : null);
          cell.style.background = col(d.x);
          var m = dateOf(d.d).getUTCMonth();
          if (m !== lastM) {
            lastM = m;
            var lab = el('span', null, MONTHS[m]);
            lab.style.left = (Math.floor((i + pad) / 7) * 16) + 'px';
            months.appendChild(lab);
          }
          var show = function (e) {
            tip.innerHTML = '<strong>' + WD[wday(d.d)] + ' ' + esc(niceDate(d.d)) + '</strong>' + (d.l ? '<br>' + esc(d.l) : '') +
              '<br>' + fmt(d.r) + ' riders' + (d.x ? '<br>' + Math.round(d.x * 100) + '% of a normal ' + WD[wday(d.d)] : '');
            tip.style.display = 'block';
            var p = e.touches ? e.touches[0] : e;
            tip.style.left = Math.min(p.clientX + 12, window.innerWidth - 270) + 'px';
            tip.style.top = (p.clientY + 12) + 'px';
          };
          cell.addEventListener('mousemove', show);
          cell.addEventListener('click', show);
          cell.addEventListener('mouseleave', hideTip);
          grid.appendChild(cell);
        });
        row.appendChild(grid);
        wrap.appendChild(months);
        wrap.appendChild(row);
        box.appendChild(wrap);
        s.appendChild(box);
        // Phones: start scrolled to the most recent months.
        if (narrow) setTimeout(function () { wrap.scrollLeft = wrap.scrollWidth; }, 0);
      });
      window.addEventListener('scroll', hideTip, { passive: true });
      s.appendChild(el('div', 'mta-cal-legend', 'Fewer riders <span class="mta-cal-swatches">' +
        ['#b91c1c', '#ea580c', '#f59e0b', '#facc15', '#334155', '#22c55e', '#16a34a'].map(function (c) {
          return '<i style="background:' + c + '"></i>';
        }).join('') + '</span> More riders'));

      var low = C.days.filter(function (d) { return d.x; }).sort(function (a, b) { return a.x - b.x; }).slice(0, 12);
      s.appendChild(el('h4', 'mta-subhead', 'Biggest drops in ridership'));
      var cbox = el('div', 'mta-chart');
      cbox.style.height = (low.length * 30 + 40) + 'px';
      var cv = document.createElement('canvas');
      cbox.appendChild(cv);
      s.appendChild(cbox);
      new Chart(cv, {
        type: 'bar',
        data: {
          labels: low.map(function (d) {
            var dt = dateOf(d.d);
            return narrow ? (shortLabel(d.l) || 'Unlabeled') + ' ' + (dt.getUTCMonth() + 1) + '/' + dt.getUTCDate() + '/' + String(dt.getUTCFullYear()).slice(2)
                          : (d.l || 'Unlabeled day') + ' · ' + MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCDate() + ', ' + dt.getUTCFullYear();
          }),
          datasets: [{ data: low.map(function (d) { return Math.round(d.x * 100); }), backgroundColor: low.map(function (d) { return col(d.x); }), borderRadius: 3 }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            title: function (it) { var d = low[it[0].dataIndex]; return (d.l || 'Unlabeled day') + ' · ' + niceDate(d.d); },
            label: function (it) { return it.parsed.x + '% of a normal day · ' + fmt(low[it.dataIndex].r) + ' riders'; }
          } } },
          scales: { x: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, y: { grid: { display: false } } }
        }
      });
    },

    hero: function (root) {
      // Title + three headline numbers, all computed from the latest data.
      var full = D.monthly.filter(function (m) { return !m.partial; });
      var perDay = full.reduce(function (a, m) { return a + m.riders; }, 0) /
                   Math.max(1, full.reduce(function (a, m) { return a + m.days; }, 0));
      var years = Object.keys(D.top_years);
      var topStation = D.top_years[years[years.length - 1]].stations[0];
      var hrs = D.hourly[Object.keys(D.hourly).slice(-1)[0]];
      var peak = hrs.indexOf(Math.max.apply(null, hrs));
      var tiles = [
        [(perDay / 1e6).toFixed(1) + 'M', 'riders a day'],
        [stationLabel(topStation.name).replace(/-42 St.*$/, '').replace(/\/.*$/, ''), 'busiest station'],
        [(peak % 12 || 12) + (peak < 12 ? ' AM' : ' PM'), 'busiest hour']
      ];
      var hero = el('header', 'mta-hero');
      hero.appendChild(el('h1', null, 'NYC Subway Ridership'));
      var row = el('div', 'mta-tiles');
      tiles.forEach(function (t) {
        row.appendChild(el('div', 'mta-tile', '<strong>' + esc(t[0]) + '</strong><span>' + esc(t[1]) + '</span>'));
      });
      hero.appendChild(row);
      root.appendChild(hero);
    },

    spikes: function (root) {
      if (!T) return trendsMissing(root, 'Unusually busy days');
      eventsSection(root, 'Unusually busy days',
        'Days when a station had far more riders than usual: at least ' + T.settings.spike_ratio + '× its normal level for that weekday ' +
        '(the median of the 26 weeks before), after allowing for how busy the whole system was. Back-to-back days are grouped together. Click one to see it.',
        T.spikes, T.recent);
    },

    quiet: function (root) {
      if (!T) return trendsMissing(root, 'Unusually quiet days');
      eventsSection(root, 'Unusually quiet days',
        'Days when a station had far fewer riders than usual (' + Math.round(T.settings.quiet_ratio * 100) + '% of normal or less), ' +
        'compared with the rest of the system that day. Often a station closure or service change.', T.quiet, null);
    },

    weekend: function (root) {
      if (!T) return trendsMissing(root, 'Weekend vs weekday stations');
      var s = section(root, 'Weekend vs weekday stations',
        'Average daily riders on weekends compared with weekdays, ' + niceDate(T.year_from) + ' – ' + niceDate(T.latest_day) + '.');
      // One diverging chart: only truly weekend-heavy stations on the right, the most weekday-heavy on the left.
      var WEEKEND = '#2f9e44', WEEKDAY = '#e8590c';
      var items = T.weekend.filter(function (x) { return x.ratio > 1; })
        .concat(T.weekday.slice().reverse());
      var pctOf = function (x) { return Math.round((x.ratio - 1) * 100); };
      s.appendChild(el('p', 'mta-legend',
        '<span><i style="background:' + WEEKEND + '"></i>More riders on weekends</span>' +
        '<span><i style="background:' + WEEKDAY + '"></i>More riders on weekdays</span>'));
      var wrap = el('div', 'mta-chart');
      wrap.style.height = (items.length * 26 + 40) + 'px';
      var c = document.createElement('canvas');
      wrap.appendChild(c);
      s.appendChild(wrap);
      new Chart(c, {
        type: 'bar',
        data: {
          labels: items.map(function (x) { return stationLabel(x.name) + ' (' + lines(x.name) + ')'; }),
          datasets: [{
            data: items.map(pctOf),
            backgroundColor: items.map(function (x) { return x.ratio > 1 ? WEEKEND : WEEKDAY; }),
            borderRadius: 3, barPercentage: 0.8
          }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            title: function (it) { return items[it[0].dataIndex].name; },
            label: function (it) {
              var x = items[it.dataIndex];
              return [(pctOf(x) > 0 ? '+' : '') + pctOf(x) + '% on weekends vs weekdays',
                      'Weekend: ' + fmt(x.weekend_avg) + ' riders/day',
                      'Weekday: ' + fmt(x.weekday_avg) + ' riders/day'];
            }
          } } },
          scales: {
            x: { ticks: { callback: function (v) { return (v > 0 ? '+' : '') + v + '%'; } },
                 grid: { color: function (ctx) { return ctx.tick && ctx.tick.value === 0 ? 'rgba(127,127,127,0.8)' : 'rgba(127,127,127,0.2)'; },
                         lineWidth: function (ctx) { return ctx.tick && ctx.tick.value === 0 ? 2 : 1; } } },
            y: { grid: { display: false }, ticks: { autoSkip: false } }
          }
        }
      });
    },

    latenight: function (root) {
      if (!T) return trendsMissing(root, 'Late-night stations');
      var s = section(root, 'Late-night stations',
        'Share of each station’s riders who enter between midnight and 5 AM, over the last year (stations with 2,000+ riders a day).');
      barList(s, T.late_night, pct, function (x) { return Math.round(x.share * 1000) / 10 + '% (' + fmt(x.night_riders) + ' late-night riders)'; });
    },

    transfers: function (root) {
      if (!T) return trendsMissing(root, 'Most transfers');
      var s = section(root, 'Most transfers',
        'Entries MTA counts as transfers (for example, a free transfer from a bus) over the last year.');
      barList(s, T.transfers, function (x) { return x.transfers; },
        function (x) { return fmt(x.transfers) + ' transfers (' + Math.round(x.share * 100) + '% of entries)'; });
    },

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
      // The server writes a slot per section, in order; some (hero, top5) are already filled in
      // as plain HTML so search engines can read them. Only render the empty ones.
      var slot = root.querySelector('[data-slot="' + k + '"]');
      if (slot && slot.hasAttribute('data-ssr')) return;
      if (render[k]) render[k](slot || root);
    });
    if (root.querySelector('.mta-source')) return;
    root.appendChild(el('p', 'mta-source',
      'Source: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a>, data.ny.gov. ' +
      'Covers ' + niceDate(D.first_day) + ' – ' + niceDate(D.latest_day) + '. Updated ' + new Date(D.updated).toLocaleDateString('en-US') + '.'));
  });
})();
