/* [mta_buses] from window.MTA_BUSES: "find your bus route" search on the ranking page, riders-per-hour chart on a route page. */
(function () {
  var D = window.MTA_BUSES;
  var root = document.querySelector('.mta-buses');
  if (!D || !root) return;
  function slot(k) { return root.querySelector('[data-slot="' + k + '"]'); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function hourName(h) { return (h % 12 || 12) + (h < 12 ? ' AM' : ' PM'); }

  // Search routes by number or by the streets/places in their name. Rows: [label, name, slug, riders, mph, group].
  var find = slot('find');
  if (find && D.routes) {
    if (!find.querySelector('.mta-find')) find.innerHTML ='<div class="mta-find"><input type="search" class="mta-find-input" placeholder="e.g. M15, Bx12, Fordham, Flatbush…" ' +
      'aria-label="Search for a bus route" autocomplete="off"><ul class="mta-find-results"></ul></div>';
    var input = find.querySelector('input'), list = find.querySelector('ul');
    var norm = function (t) { return String(t).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); };
    var base = location.pathname.replace(/\/+$/, '') + '/';
    input.addEventListener('input', function () {
      var q = norm(input.value), qs = q.replace(/ /g, '');
      list.innerHTML = '';
      if (!q) return;
      var hits = D.routes.filter(function (r) {
        var id = norm(r[0]).replace(/ /g, '');
        return id.indexOf(qs) === 0 || norm(r[1]).indexOf(q) >= 0;
      }).slice(0, 8);
      if (!hits.length) list.innerHTML = '<li class="mta-find-none">No route matches “' + esc(input.value) + '”</li>';
      hits.forEach(function (r) {
        var li = document.createElement('li');
        li.innerHTML = '<a class="mta-find-pick" href="' + base + r[2] + '/">' + esc(r[0]) + ' <span class="mta-lines">' + esc(r[1]) + '</span></a> ' +
          '<span class="mta-borough">' + Math.round(r[3]).toLocaleString('en-US') + ' riders a day' + (r[4] ? ' · ' + r[4] + ' mph' : '') + '</span>';
        list.appendChild(li);
      });
    });
    input.addEventListener('keydown', function (e) {
      var first = list.querySelector('a');
      if (e.key === 'Enter' && first) { e.preventDefault(); location.href = first.href; }
    });
  }

  // Riders per hour on an average weekday.
  var hoursBox = slot('hours');
  if (hoursBox && D.route && window.Chart) {
    Chart.defaults.color = getComputedStyle(root).color;
    Chart.defaults.borderColor = 'rgba(127,127,127,0.2)';
    var h = D.route.hours, peak = h.indexOf(Math.max.apply(null, h));
    var wrap = document.createElement('div');
    wrap.className = 'mta-chart';
    var c = document.createElement('canvas');
    wrap.appendChild(c);
    hoursBox.appendChild(wrap);
    new Chart(c, {
      type: 'bar',
      data: { labels: h.map(function (_, i) { return hourName(i); }),
              datasets: [{ data: h, backgroundColor: h.map(function (_, i) { return i === peak ? '#e8590c' : '#2a6fdb'; }) }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false },
        tooltip: { callbacks: { label: function (x) { return x.parsed.y.toLocaleString('en-US') + ' riders'; } } } },
        scales: { y: { beginAtZero: true } } }
    });
  }

  // Stop heat map: people getting on at each stop (rows, in route order) in each hour (columns). The file
  // ({dirs: [{to, stops: [[name, {wk, sa, su: [24 values or null]}]]}]}) loads only when the chart is near the screen.
  var heat = slot('stopheat');
  if (heat && heat.dataset.src) {
    var drawn = false, go = function () {
      if (drawn) return;
      drawn = true;
      heat.innerHTML = '<p class="mta-note">Loading the stops…</p>';
      fetch(heat.dataset.src).then(function (r) { if (!r.ok) throw r; return r.json(); })
        .then(function (data) { stopHeat(heat, data); })
        .catch(function () { heat.innerHTML = '<p class="mta-note">The stop chart couldn’t load. Try reloading the page.</p>'; });
    };
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); go(); } }, { rootMargin: '700px' });
      io.observe(heat);
    } else go();
  }

  var all = slot('busall');
  if (all && all.dataset.src && window.L) {
    var started = false, begin = function () { if (!started) { started = true; busAll(all, all.dataset.src, all.dataset.v); } };
    if ('IntersectionObserver' in window) {
      // Only once it's actually on screen: it sits near the top and its files are big, so don't fetch them for visitors who never scroll.
      var io2 = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io2.disconnect(); begin(); } });
      io2.observe(all);
    } else begin();
  }

  function stopHeat(box, data) {
    var DAYS = [['wk', 'Weekdays', 'weekday'], ['sa', 'Saturdays', 'Saturday'], ['su', 'Sundays', 'Sunday']];
    var dir = 0, day = 'wk', hour = 8, max = 0, pick = null;
    var ramp = [[34, 160, 70], [250, 204, 21], [220, 38, 38]]; // green (fewest) → yellow (middle) → red (most)
    // Square-root scale so quieter stops still show some colour next to the busiest one.
    function share(v) { return max ? Math.sqrt(Math.min(1, v / max)) : 0; }
    function colour(v) {
      var x = share(v) * (ramp.length - 1), i = Math.min(ramp.length - 2, Math.floor(x)), f = x - i;
      return 'rgb(' + ramp[i].map(function (c, k) { return Math.round(c + (ramp[i + 1][k] - c) * f); }).join(',') + ')';
    }
    function num(v) { return v < 1 ? 'fewer than 1' : 'about ' + Math.round(v).toLocaleString('en-US'); }
    function people(v) { return num(v) + (v < 1.5 ? ' person gets on' : ' people get on'); }
    function span(h) { return hourName(h) + '–' + hourName((h + 1) % 24); }
    function total(a) { var s = 0, any = false; a.forEach(function (v) { if (v !== null) { s += v; any = true; } }); return any ? s : null; }
    function dayWord() { return DAYS.filter(function (d) { return d[0] === day; })[0][2]; }
    function view() { return data.dirs[dir].stops; }
    function stopLine(s) { // a stop's whole day: total and busiest hour
      var a = s[1][day], t = total(a), best = -1;
      a.forEach(function (v, h) { if (v !== null && (best < 0 || v > a[best])) best = h; });
      return t === null ? 'no MTA estimate on ' + dayWord() + 's'
        : people(t) + ' during an average ' + dayWord() + (best >= 0 && a[best] >= 1 ? '; busiest ' + span(best) + ' (' + num(a[best]) + ')' : '');
    }

    var dirBtns = data.dirs.length > 1 ? '<div class="mta-view-switch" role="group" aria-label="Direction">' + data.dirs.map(function (d, i) {
      return '<button type="button" data-dir="' + i + '" aria-pressed="' + (i === 0) + '">Toward ' + esc(d.to) + '</button>';
    }).join('') + '</div> ' : '';
    var hasMap = !!window.L && data.dirs.some(function (d) { return d.stops.some(function (s) { return s.length > 2; }); });
    box.innerHTML = '<div class="mta-heat-controls">' + dirBtns + '<div class="mta-view-switch" role="group" aria-label="Day">' + DAYS.map(function (d) {
      return '<button type="button" data-day="' + d[0] + '" aria-pressed="' + (d[0] === day) + '">' + d[1] + '</button>';
    }).join('') + '</div></div>' +
      (hasMap ? '<div class="mta-map-bar"><button type="button" class="mta-play" aria-label="Play">▶</button><div class="mta-clock"></div>' +
        '<input type="range" class="mta-slider" min="0" max="23" value="8" aria-label="Hour of day"></div><div class="mta-map mta-heat-map"></div>' +
        '<div class="mta-strip"></div><div class="mta-strip-labels"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span></div>' : '') +
      '<div class="mta-heat-key"><span>Fewer</span><i></i><span>More people getting on</span><b class="mta-heat-none"></b><span>No estimate</span></div>' +
      '<h3 class="mta-heat-sub">Every stop, hour by hour</h3><p class="mta-heat-info" aria-live="polite"></p>' +
      '<div class="mta-heat-scroll"><table class="mta-heat"></table></div>';
    var info = box.querySelector('.mta-heat-info'), table = box.querySelector('table');
    function say(html) { info.innerHTML = html; }

    // ---- the map: each stop a circle, coloured and sized by people getting on in the chosen hour ----
    var map, layer, markers = [], strip, clock, slider, play, timer = null, touched = false;
    if (hasMap) {
      clock = box.querySelector('.mta-clock'); slider = box.querySelector('.mta-slider'); play = box.querySelector('.mta-play');
      strip = box.querySelector('.mta-strip');
      map = L.map(box.querySelector('.mta-heat-map'), { preferCanvas: true, scrollWheelZoom: false, zoomSnap: 0.25 });
      // Esri's dark gray canvas: free to use with attribution, no API key (same as the subway map).
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
      }).addTo(map);
      layer = L.layerGroup().addTo(map);
      play.addEventListener('click', function () { touched = true; timer ? stopPlay() : startPlay(); });
      slider.addEventListener('input', function () { stopPlay(); setHour(+slider.value); });
      strip.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { stopPlay(); setHour(+b.dataset.h); } });
    }
    function stopPlay() { touched = true; clearInterval(timer); timer = null; if (play) { play.textContent = '▶'; play.setAttribute('aria-label', 'Play'); } }
    function startPlay() {
      if (timer || !play) return;
      play.textContent = '❚❚'; play.setAttribute('aria-label', 'Pause');
      timer = setInterval(function () { setHour((hour + 1) % 24); }, 800);
    }
    function radius(v) { var r = Math.max(13, Math.min(22, map.getContainer().clientWidth / 38)); return v === null ? 3 : Math.max(4, r * share(v)); }
    function tip(s) {
      var v = s[1][day][hour];
      return '<strong>' + esc(s[0]) + '</strong><br>' + span(hour) + ': ' + (v === null ? 'no MTA estimate' : people(v)) + '<br>' +
        '<span class="mta-tt-lines">' + esc(stopLine(s)) + '</span>';
    }
    function drawMap() {
      if (!map) return;
      layer.clearLayers();
      var placed = view().filter(function (s) { return s.length > 2; });
      // The route between stops, in order (straight lines from stop to stop, so it follows the streets only roughly).
      L.polyline(placed.map(function (s) { return [s[2], s[3]]; }), { color: '#94a3b8', weight: 2, opacity: 0.5, interactive: false }).addTo(layer);
      markers = placed.map(function (s) {
        var m = L.circleMarker([s[2], s[3]], { weight: 1, color: 'rgba(255,255,255,0.7)', fillOpacity: 0.9 });
        m.bindTooltip(tip(s), { direction: 'top', className: 'mta-tt' });
        m.on('click', function () { m.openTooltip(); });
        m.addTo(layer);
        m.stop = s;
        return m;
      });
      map.invalidateSize();
      map.fitBounds(placed.map(function (s) { return [s[2], s[3]]; }), { paddingTopLeft: [28, 28], paddingBottomRight: [28, 52] }); // room for the credits line
      // Hour strip: everyone getting on along the route in each hour.
      var sums = [];
      for (var h = 0; h < 24; h++) sums.push(view().reduce(function (a, s) { return a + (s[1][day][h] || 0); }, 0));
      var top = Math.max.apply(null, sums) || 1;
      strip.innerHTML = sums.map(function (v, h) {
        return '<button type="button" data-h="' + h + '" title="' + hourName(h) + ': ' + num(v) + ' people getting on along the route">' +
          '<span style="height:' + Math.max(4, 100 * v / top) + '%"></span></button>';
      }).join('');
      strip.sums = sums;
    }
    function setHour(h) {
      hour = h;
      table.querySelectorAll('thead th[data-h]').forEach(function (th) { th.classList.toggle('on', +th.dataset.h === h); });
      if (!map) return;
      slider.value = h;
      clock.innerHTML = '<strong>' + hourName(h) + '</strong><span>' + num(strip.sums[h]) + ' get on along the route this hour</span>';
      markers.forEach(function (m) {
        var v = m.stop[1][day][h];
        m.setRadius(radius(v));
        m.setStyle(v === null ? { fillColor: '#475569', fillOpacity: 0.5, opacity: 0.4 } : { fillColor: colour(v), fillOpacity: 0.9, opacity: 1 });
        m.setTooltipContent(tip(m.stop));
      });
      // Busiest stops on top so they aren't hidden under quiet neighbours.
      markers.slice().sort(function (a, b) { return (a.stop[1][day][h] || 0) - (b.stop[1][day][h] || 0); }).forEach(function (m) { m.bringToFront(); });
      strip.querySelectorAll('button').forEach(function (b, i) { b.classList.toggle('on', i === h); });
    }

    // ---- the grid: every stop (rows) by every hour (columns) ----
    function drawGrid() {
      var stops = view();
      var head = '<thead><tr><th scope="col" class="mta-heat-stop">Stop</th>';
      for (var h = 0; h < 24; h++) head += '<th scope="col" data-h="' + h + '">' + (h % 3 === 0 ? (h % 12 || 12) + (h < 12 ? 'a' : 'p') : '') + '</th>';
      head += '<th scope="col" class="mta-heat-day">A day</th></tr></thead>';
      var body = stops.map(function (s, i) {
        var row = '<tr><th scope="row" class="mta-heat-stop"><button type="button" data-stop="' + i + '">' + esc(s[0]) + '</button></th>';
        s[1][day].forEach(function (v, h) {
          row += v === null ? '<td class="mta-heat-none" data-i="' + i + '" data-h="' + h + '"></td>'
                            : '<td data-i="' + i + '" data-h="' + h + '" style="background:' + colour(v) + '"></td>';
        });
        var t = total(s[1][day]);
        return row + '<td class="mta-heat-day">' + (t === null ? '–' : Math.round(t).toLocaleString('en-US')) + '</td></tr>';
      }).join('');
      table.innerHTML = head + '<tbody>' + body + '</tbody>';
      table.setAttribute('aria-label', 'People getting on at each stop of the ' + data.route + ' by hour, average ' + dayWord() + ', toward ' + data.dirs[dir].to);
      pick = null;
      say('Hover or tap a square to see how many people get on there in that hour. Tap a stop name for its whole day.');
    }
    function cell(td) {
      var s = view()[+td.dataset.i], h = +td.dataset.h, v = s[1][day][h];
      if (pick) pick.classList.remove('on');
      pick = td;
      td.classList.add('on');
      say('<strong>' + esc(s[0]) + '</strong> · ' + span(h) + ' · ' + (v === null ? 'no MTA estimate for this hour' : people(v)) + ', average ' + dayWord());
    }

    function draw() {
      max = 0;
      view().forEach(function (s) { s[1][day].forEach(function (v) { if (v > max) max = v; }); });
      drawMap();
      drawGrid();
      setHour(hour);
    }

    table.addEventListener('mouseover', function (e) { var td = e.target.closest('td[data-i]'); if (td) cell(td); });
    table.addEventListener('click', function (e) {
      var td = e.target.closest('td[data-i]'), b = e.target.closest('button[data-stop]');
      if (td) { cell(td); stopPlay(); setHour(+td.dataset.h); }
      else if (b) { var s = view()[+b.dataset.stop]; say('<strong>' + esc(s[0]) + '</strong> · ' + stopLine(s)); }
    });
    box.querySelector('.mta-heat-controls').addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.dir) dir = +b.dataset.dir;
      if (b.dataset.day) day = b.dataset.day;
      b.parentNode.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      draw();
    });
    draw();

    // Play the day once, the first time the map scrolls into view (unless the visitor prefers less motion).
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (map && !calm && 'IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (ents) {
        if (!ents[0].isIntersecting) return;
        io.disconnect();
        if (!touched) { setHour(5); startPlay(); } // don't override an hour the visitor already picked
      }, { threshold: 0.5 });
      io.observe(map.getContainer());
    }
  }

  // Citywide map: every bus stop, coloured and sized by people getting on in the chosen hour. Places load once
  // (all-places.json); each day kind's hours (all-wk/sa/su.json) load when first picked.
  function busAll(box, base, v) {
    var DAYS = [['wk', 'Weekdays', 'weekday'], ['sa', 'Saturdays', 'Saturday'], ['su', 'Sundays', 'Sunday']];
    var day = 'wk', hour = 8, max = 1, places, hours = {}, markers = [], sums = [], timer = null, touched = false;
    var ramp = [[34, 160, 70], [250, 204, 21], [220, 38, 38]]; // green (fewest) → yellow (middle) → red (most)
    function get(name) { return fetch(base + 'all-' + name + '.json?v=' + v).then(function (r) { if (!r.ok) throw r; return r.json(); }); }
    function share(x) { return Math.sqrt(Math.min(1, x / max)); }
    function colour(x) {
      var t = share(x) * (ramp.length - 1), i = Math.min(ramp.length - 2, Math.floor(t)), f = t - i;
      return 'rgb(' + ramp[i].map(function (c, k) { return Math.round(c + (ramp[i + 1][k] - c) * f); }).join(',') + ')';
    }
    function num(x) { return x < 1 ? 'fewer than 1' : 'about ' + Math.round(x).toLocaleString('en-US'); }
    function people(x) { return num(x) + (x < 1.5 ? ' person gets on' : ' people get on'); }
    function span(h) { return hourName(h) + '–' + hourName((h + 1) % 24); }
    function dayWord() { return DAYS.filter(function (d) { return d[0] === day; })[0][2]; }

    box.innerHTML = '<p class="mta-note">Loading the bus stops…</p>';
    Promise.all([get('places'), get('wk')]).then(function (r) {
      places = r[0]; hours.wk = r[1].hours;
      build();
    }).catch(function () { box.innerHTML = '<p class="mta-note">The bus stop map couldn’t load. Try reloading the page.</p>'; });

    function build() {
      box.innerHTML = '<div class="mta-heat-controls"><div class="mta-view-switch" role="group" aria-label="Day">' + DAYS.map(function (d) {
        return '<button type="button" data-day="' + d[0] + '" aria-pressed="' + (d[0] === day) + '">' + d[1] + '</button>';
      }).join('') + '</div></div>' +
        '<div class="mta-map-bar"><button type="button" class="mta-play" aria-label="Play">▶</button><div class="mta-clock"></div>' +
        '<input type="range" class="mta-slider" min="0" max="23" value="8" aria-label="Hour of day"></div><div class="mta-map mta-busall-map"></div>' +
        '<div class="mta-strip"></div><div class="mta-strip-labels"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span></div>' +
        '<div class="mta-heat-key"><span>Fewer</span><i></i><span>More people getting on</span></div>';
      var clock = box.querySelector('.mta-clock'), slider = box.querySelector('.mta-slider'), play = box.querySelector('.mta-play'), strip = box.querySelector('.mta-strip');
      var map = L.map(box.querySelector('.mta-busall-map'), { preferCanvas: true, scrollWheelZoom: false, zoomSnap: 0.25 });
      // Esri's dark gray canvas: free to use with attribution, no API key (same as the subway map).
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
      }).addTo(map);
      var renderer = L.canvas({ padding: 0.2 });
      map.fitBounds(places.stops.map(function (s) { return [s[0], s[1]]; }), { padding: [12, 12] });
      function size() { return Math.max(5, Math.min(11, map.getContainer().clientWidth / 90)) * Math.pow(1.35, map.getZoom() - 10.5); }
      var big = size();
      map.on('zoomend', function () { big = size(); setHour(hour); });

      // Stops come quietest first, so the busy ones are drawn on top. One shared tooltip and popup for all of them, and
      // the circles are made a batch at a time so the page never freezes while 13,000 of them are set up.
      var group = L.featureGroup().addTo(map), building = true;
      group.bindTooltip(function (m) {
        var s = places.stops[m.i], x = hours[day][m.i][hour];
        return '<strong>' + esc(s[2]) + '</strong><br>' + span(hour) + ': ' + (x === null ? 'no MTA estimate' : people(x)) +
          '<br><span class="mta-tt-lines">' + esc(s[3].map(function (r) { return places.routes[r]; }).join(', ')) + '</span>';
      }, { direction: 'top', className: 'mta-tt' });
      group.on('click', function (e) {
        var m = e.layer, s = places.stops[m.i], a = hours[day][m.i], t = 0, best = -1;
        a.forEach(function (x, h) { if (x !== null) { t += x; if (best < 0 || x > a[best]) best = h; } });
        L.popup({ className: 'mta-busall-pop' }).setLatLng(m.getLatLng()).setContent(
          '<strong>' + esc(s[2]) + '</strong><br>' + span(hour) + ': ' + (a[hour] === null ? 'no MTA estimate' : people(a[hour])) + '<br>' +
          'Average ' + dayWord() + ': ' + people(t) + (best >= 0 && a[best] >= 1 ? '; busiest ' + span(best) : '') + '<br>' +
          s[3].map(function (r) {
            var label = places.routes[r];
            return '<a href="' + location.pathname.replace(/\/+$/, '') + '/' + label.toLowerCase() + '/">' + esc(label) + '</a>';
          }).join(' ')).openOn(map);
      });
      markers = [];
      (function batch(from) {
        var to = Math.min(places.stops.length, from + 1500);
        for (var i = from; i < to; i++) {
          var m = L.circleMarker([places.stops[i][0], places.stops[i][1]], { renderer: renderer, radius: 0, stroke: false, fillOpacity: 0 });
          m.i = i;
          markers.push(m);
          group.addLayer(m);
        }
        if (to < places.stops.length) setTimeout(function () { batch(to); }, 0);
        else { building = false; setHour(hour); }
      })(0);

      function totals() {
        max = 1; sums = [];
        for (var h = 0; h < 24; h++) sums.push(0);
        hours[day].forEach(function (a) { a.forEach(function (x, h) { if (x) { sums[h] += x; if (x > max) max = x; } }); });
        var top = Math.max.apply(null, sums) || 1;
        strip.innerHTML = sums.map(function (x, h) {
          return '<button type="button" data-h="' + h + '" title="' + hourName(h) + ': ' + num(x) + ' people getting on at bus stops">' +
            '<span style="height:' + Math.max(4, 100 * x / top) + '%"></span></button>';
        }).join('');
      }
      function setHour(h) {
        hour = h;
        slider.value = h;
        clock.innerHTML = '<strong>' + hourName(h) + '</strong><span>' + num(sums[h]) + ' get on at bus stops this hour</span>';
        if (building) return; // drawn once all the circles exist
        var a = hours[day];
        markers.forEach(function (m) {
          var x = a[m.i][h];
          m.options.fillColor = x ? colour(x) : '#000';
          m.options.fillOpacity = x ? 0.85 : 0; // the map draws even a size-0 circle 1 pixel wide
          m.setRadius(x ? Math.max(1.2, big * share(x)) : 0); // one redraw per frame for all of them
        });
        strip.querySelectorAll('button').forEach(function (b, i) { b.classList.toggle('on', i === h); });
      }
      function stopPlay() { touched = true; clearInterval(timer); timer = null; play.textContent = '▶'; play.setAttribute('aria-label', 'Play'); }
      function startPlay() {
        if (timer) return;
        play.textContent = '❚❚'; play.setAttribute('aria-label', 'Pause');
        timer = setInterval(function () { setHour((hour + 1) % 24); }, 800);
      }
      play.addEventListener('click', function () { touched = true; timer ? stopPlay() : startPlay(); });
      slider.addEventListener('input', function () { stopPlay(); setHour(+slider.value); });
      strip.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { stopPlay(); setHour(+b.dataset.h); } });
      box.querySelector('.mta-heat-controls').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-day]');
        if (!b) return;
        b.parentNode.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        var want = b.dataset.day;
        (hours[want] ? Promise.resolve() : get(want).then(function (r) { hours[want] = r.hours; })).then(function () {
          day = want; totals(); setHour(hour);
        });
      });
      totals();
      setHour(hour);

      // Play the day once, the first time the map scrolls into view (unless the visitor prefers less motion).
      var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!calm && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (ents) {
          if (!ents[0].isIntersecting) return;
          io.disconnect();
          if (!touched) { setHour(5); startPlay(); }
        }, { threshold: 0.5 });
        io.observe(map.getContainer());
      }
    }
  }
})();
