/* Station maps (Leaflet, loaded only when a map is near the screen):
   - "flows" on a station page: where riders starting there go, hour by hour (uploads/mta-flows/<id>.json). */
(function () {
  var root = document.querySelector('.mta-stations');
  if (!root || !window.L) return;
  function slot(k) { return root.querySelector('[data-slot="' + k + '"]'); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function hourName(h) { return (h % 12 || 12) + (h < 12 ? ' AM' : ' PM'); }
  function span(h) { return hourName(h) + '–' + hourName((h + 1) % 24); }
  var DAYS = [['wk', 'Weekdays', 'weekday'], ['sa', 'Saturdays', 'Saturday'], ['su', 'Sundays', 'Sunday']];
  var ramp = [[34, 160, 70], [250, 204, 21], [220, 38, 38]]; // green (fewest) → yellow (middle) → red (most)
  function colourAt(t) {
    var x = t * (ramp.length - 1), i = Math.min(ramp.length - 2, Math.floor(x)), f = x - i;
    return 'rgb(' + ramp[i].map(function (c, k) { return Math.round(c + (ramp[i + 1][k] - c) * f); }).join(',') + ')';
  }
  function num(x) { return x < 1 ? 'fewer than 1' : 'about ' + Math.round(x).toLocaleString('en-US'); }
  function baseMap(el) {
    var map = L.map(el, { preferCanvas: true, scrollWheelZoom: false, zoomSnap: 0.25 });
    // Esri's dark gray canvas: free to use with attribution, no API key (same as the other maps).
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
    }).addTo(map);
    return map;
  }
  function lazy(el, fn) {
    if (!('IntersectionObserver' in window)) return fn();
    var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); fn(); } }, { rootMargin: '500px' });
    io.observe(el);
  }
  // Play the day once when the map first scrolls into view (unless the visitor prefers less motion or already picked an hour).
  function autoplay(el, go) {
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (calm || !('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); go(); } }, { threshold: 0.5 });
    io.observe(el);
  }
  // Play button, clock, slider and the hour strip under the map; onHour(h) redraws.
  function timeline(box, onHour) {
    var clock = box.querySelector('.mta-clock'), slider = box.querySelector('.mta-slider'), play = box.querySelector('.mta-play'), strip = box.querySelector('.mta-strip');
    var t = { hour: 8, timer: null, touched: false };
    t.set = function (h) {
      t.hour = h; slider.value = h;
      strip.querySelectorAll('button').forEach(function (b, i) { b.classList.toggle('on', i === h); });
      clock.innerHTML = onHour(h);
    };
    t.stop = function () { t.touched = true; clearInterval(t.timer); t.timer = null; play.textContent = '▶'; play.setAttribute('aria-label', 'Play'); };
    t.start = function () {
      if (t.timer) return;
      play.textContent = '❚❚'; play.setAttribute('aria-label', 'Pause');
      t.timer = setInterval(function () { t.set((t.hour + 1) % 24); }, 800);
    };
    t.bars = function (sums, word) {
      var top = Math.max.apply(null, sums) || 1;
      strip.innerHTML = sums.map(function (x, h) {
        return '<button type="button" data-h="' + h + '" title="' + hourName(h) + ': ' + num(x) + ' ' + word + '"><span style="height:' + Math.max(4, 100 * x / top) + '%"></span></button>';
      }).join('');
    };
    play.addEventListener('click', function () { t.touched = true; t.timer ? t.stop() : t.start(); });
    slider.addEventListener('input', function () { t.stop(); t.set(+slider.value); });
    strip.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { t.stop(); t.set(+b.dataset.h); } });
    return t;
  }
  var BAR = '<div class="mta-map-bar"><button type="button" class="mta-play" aria-label="Play">▶</button><div class="mta-clock"></div>' +
    '<input type="range" class="mta-slider" min="0" max="23" value="8" aria-label="Hour of day"></div>';
  var STRIP = '<div class="mta-strip"></div><div class="mta-strip-labels"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span></div>';
  function dayButtons(day) {
    return '<div class="mta-view-switch" role="group" aria-label="Day">' + DAYS.map(function (d) {
      return '<button type="button" data-day="' + d[0] + '" aria-pressed="' + (d[0] === day) + '">' + d[1] + '</button>';
    }).join('') + '</div>';
  }

  // ---- Trip flows: a line from this station to each destination, thicker and redder for more riders that hour ----
  var fl = slot('flows');
  if (fl && fl.dataset.src) lazy(fl, function () {
    fl.innerHTML = '<p class="mta-note">Loading the trips…</p>';
    fetch(fl.dataset.src).then(function (r) { if (!r.ok) throw r; return r.json(); }).then(function (d) { flows(fl, d, fl.dataset.base); })
      .catch(function () { fl.innerHTML = '<p class="mta-note">The trip map couldn’t load. Try reloading the page.</p>'; });
  });
  function flows(box, d, base) {
    var day = 'wk', max = 1, from = [d.from[1], d.from[2]];
    function trips(x) { return num(x) + (x < 1.5 ? ' trip' : ' trips'); }
    function dayWord() { return DAYS.filter(function (x) { return x[0] === day; })[0][2]; }
    box.innerHTML = '<div class="mta-heat-controls">' + dayButtons(day) + '</div>' + BAR + '<div class="mta-map mta-flows-map"></div>' + STRIP +
      '<div class="mta-heat-key"><span>Fewer</span><i></i><span>More riders going there</span></div>' +
      '<p class="mta-note">These ' + d.to.length + ' stations take ' + d.share + '% of weekday trips from ' + esc(d.from[0]) + '.</p>';
    var map = baseMap(box.querySelector('.mta-flows-map')), renderer = L.canvas({ padding: 0.3, tolerance: 6 }); // tolerance: easier to hit a small dot with a finger
    var pts = d.to.map(function (t) { return [t[2], t[3]]; }).concat([from]);
    map.fitBounds(pts, { padding: [24, 24], paddingBottomRight: [24, 40] });
    var lines = [], dots = [], sel = -1; // sel: the destination picked by a tap (-1 = none)
    d.to.forEach(function (t, i) {
      var line = L.polyline([from, [t[2], t[3]]], { renderer: renderer, weight: 1, opacity: 0, interactive: false }).addTo(map);
      var dot = L.circleMarker([t[2], t[3]], { renderer: renderer, radius: 4, weight: 1, color: 'rgba(255,255,255,0.6)', fillOpacity: 0.9 });
      dot.bindTooltip(function () {
        var a = t[4][day], all =a.reduce(function (s, v) { return s + v; }, 0);
        return '<strong>' + esc(t[0]) + '</strong><br>' + span(tl.hour) + ': ' + trips(a[tl.hour]) + ' from ' + esc(d.from[0]) +
          '<br><span class="mta-tt-lines">' + trips(all) + ' on an average ' + dayWord() + '</span>';
      }, { direction: 'top', className: 'mta-tt' });
      // A tap picks the station: its line stands out and a box gives its numbers and a link to its page.
      dot.on('click', function () {
        var a = t[4][day], all = a.reduce(function (s, v) { return s + v; }, 0), best = 0;
        a.forEach(function (x, h) { if (x > a[best]) best = h; });
        L.popup({ className: 'mta-busall-pop', offset: [0, -4], autoPanPaddingTopLeft: [56, 16], autoPanPaddingBottomRight: [16, 16], maxWidth: Math.min(300, map.getSize().x - 120) }).setLatLng([t[2], t[3]]).setContent('<strong>' + esc(t[0]) + '</strong><br>' +
          span(tl.hour) + ': ' + trips(a[tl.hour]) + ' from ' + esc(d.from[0]) + '<br>Average ' + dayWord() + ': ' + trips(all) +
          (a[best] >= 1 ? '; busiest ' + span(best) : '') + '<br><a href="' + esc(base + t[1] + '/') + '" class="mta-pop-go">Open the station page &rarr;</a>').openOn(map);
        dot.closeTooltip(); sel = i; tl.stop(); tl.set(tl.hour); // after openOn: closing the last box clears the old pick
      });
      dot.addTo(map);
      lines.push(line); dots.push(dot);
    });
    map.on('popupclose', function () { if (sel >= 0) { sel = -1; tl.set(tl.hour); } });
    // The station itself: a white ring on top of everything. Same canvas as the dots: a second canvas would sit on top and catch every tap.
    var home = L.circleMarker(from, { renderer: renderer, radius: 8, weight: 3, color: '#fff', fillColor: '#0f172a', fillOpacity: 1, interactive: false }).addTo(map);
    var tl = timeline(box, function (h) {
      var order = [];
      d.to.forEach(function (t, i) {
        var x = t[4][day][h], s = Math.sqrt(Math.min(1, x / max)), c = colourAt(s);
        lines[i].setStyle({ color: c, weight: x ? 1 + 9 * s : 0, opacity: x ? 0.35 + 0.5 * s : 0 });
        dots[i].setStyle({ fillColor: x ? c : '#475569', fillOpacity: x ? 0.95 : 0.5 });
        dots[i].setRadius(x ? 3 + 6 * s : 2.5);
        order.push([x, i]);
      });
      // Busiest last, so they draw on top of the quiet ones.
      order.sort(function (a, b) { return a[0] - b[0]; }).forEach(function (o) { lines[o[1]].bringToFront(); });
      order.forEach(function (o) { dots[o[1]].bringToFront(); });
      home.bringToFront();
      dots.forEach(function (m, i) { m.setStyle(i === sel ? { color: '#fff', weight: 3 } : { color: 'rgba(255,255,255,0.6)', weight: 1 }); });
      if (sel >= 0) {
        var x = d.to[sel][4][day][h], s = Math.sqrt(Math.min(1, x / max));
        lines[sel].setStyle({ color: x ? colourAt(s) : '#94a3b8', weight: Math.max(4, 1 + 9 * s), opacity: 1 }).bringToFront();
        dots[sel].setRadius(Math.max(7, 3 + 6 * s)).bringToFront();
      }
      return '<strong>' + hourName(h) + '</strong><span>' + num(d.total[day][h]) + ' subway trips start here this hour</span>';
    });
    function draw() {
      max = 1;
      d.to.forEach(function (t) { t[4][day].forEach(function (x) { if (x > max) max = x; }); });
      tl.bars(d.total[day], 'trips start here');
      tl.set(tl.hour);
    }
    box.querySelector('.mta-heat-controls').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-day]');
      if (!b) return;
      b.parentNode.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      day = b.dataset.day; draw();
    });
    draw();
    autoplay(map.getContainer(), function () { if (!tl.touched) { tl.set(5); tl.start(); } });
  }

  // ---- Stations page: every station sized by riders a day, coloured by growth or by elevators working ----
  var sm = slot('stmap'), smData = document.getElementById('mta-stmap-data');
  if (sm && smData) lazy(sm, function () { stationMap(sm, JSON.parse(smData.textContent)); });
  function stationMap(box, d) {
    // [label, where a station sits on the colour scale (0 green … 1 red, null = no value), key ends, words for the tooltip]
    var VIEWS = {
      yoy: ['Growing or shrinking', function (s) { return s[6] === null ? null : (15 - Math.max(-15, Math.min(15, s[6]))) / 30; },
            ['Shrinking (−15%)', 'Growing (+15%)'], function (s) { return s[6] === null ? 'no comparison with last year' : (s[6] > 0 ? '+' : '') + s[6] + '% riders vs last year'; }],
      elev: ['Elevators working', function (s) { return s[7] === null ? null : (99 - Math.max(85, Math.min(99, s[7]))) / 14; },
             ['Least (85%)', 'Most (99%+)'], function (s) { return s[7] === null ? 'no NYC Transit elevators' : 'elevators working ' + s[7] + '% of the time (' + s[8] + (s[8] === 1 ? ' elevator)' : ' elevators)'); }]
    };
    var view = 'yoy', top = d.s.length ? d.s[0][5] : 1;
    box.innerHTML = '<div class="mta-heat-controls"><div class="mta-view-switch" role="group" aria-label="View">' + Object.keys(VIEWS).map(function (k) {
      return '<button type="button" data-view="' + k + '" aria-pressed="' + (k === view) + '">' + VIEWS[k][0] + '</button>';
    }).join('') + '</div></div><div class="mta-map mta-stmap-map"></div>' +
      '<div class="mta-heat-key mta-speed-key"><span class="a"></span><i></i><span class="b"></span><b class="mta-heat-none"></b><span>No data</span></div>';
    var map = baseMap(box.querySelector('.mta-stmap-map')), renderer = L.canvas({ padding: 0.2, tolerance: 4 });
    map.fitBounds(d.s.map(function (s) { return [s[3], s[4]]; }), { padding: [12, 12] });
    var group = L.featureGroup().addTo(map);
    var dots = d.s.map(function (s, i) {
      var m = L.circleMarker([s[3], s[4]], { renderer: renderer, weight: 1, color: 'rgba(15,23,42,0.8)', fillOpacity: 0.9 });
      m.st = s; group.addLayer(m);
      return m;
    });
    group.bindTooltip(function (layer) {
      var s = layer.st;
      return '<strong>' + esc(s[0]) + '</strong>' + (s[2] ? ' <span class="mta-tt-lines">' + esc(s[2]) + '</span>' : '') + '<br>' +
        s[5].toLocaleString('en-US') + ' riders a day<br>' + esc(VIEWS[view][3](s));
    }, { direction: 'top', className: 'mta-tt' });
    // A tap picks the station: a white ring marks it and a box gives both numbers and a link to its page.
    var sel = null;
    group.on('click', function (e) {
      var s = e.layer.st;
      L.popup({ className: 'mta-busall-pop', offset: [0, -4], autoPanPaddingTopLeft: [56, 16], autoPanPaddingBottomRight: [16, 16], maxWidth: Math.min(300, map.getSize().x - 120) }).setLatLng(e.layer.getLatLng()).setContent('<strong>' + esc(s[0]) + '</strong><br>' +
        (s[2] ? 'Lines: ' + esc(s[2]) + '<br>' : '') + s[5].toLocaleString('en-US') + ' riders a day<br>' +
        esc(VIEWS.yoy[3](s)) + '<br>' + esc(VIEWS.elev[3](s)) + '<br><a href="' + esc(d.base + s[1] + '/') + '" class="mta-pop-go">Open the station page &rarr;</a>').openOn(map);
      group.closeTooltip(); sel = e.layer; draw(); // after openOn: closing the last box clears the old pick
    });
    map.on('popupclose', function () { if (sel) { sel = null; draw(); } });
    function size() { return Math.max(1, Math.pow(1.3, map.getZoom() - 11)); }
    function draw() {
      var v = VIEWS[view], k = size();
      box.querySelector('.mta-heat-key .a').textContent = v[2][0];
      box.querySelector('.mta-heat-key .b').textContent = v[2][1];
      dots.forEach(function (m) {
        var t = v[1](m.st), r = (2.5 + 9 * Math.sqrt(m.st[5] / top)) * k;
        m.setStyle(t === null ? { fillColor: '#64748b', fillOpacity: 0.5, opacity: 0.4 } : { fillColor: colourAt(t), fillOpacity: 0.9, opacity: 1 });
        m.setRadius(t === null ? Math.min(r, 3 * k) : r);
        if (m === sel) m.setStyle({ color: '#fff', weight: 3, opacity: 1 }).setRadius(Math.max(r, 6 * k)).bringToFront();
        else m.setStyle({ color: 'rgba(15,23,42,0.8)', weight: 1 });
      });
    }
    map.on('zoomend', draw);
    box.querySelector('.mta-heat-controls').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-view]');
      if (!b) return;
      b.parentNode.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      view = b.dataset.view; draw();
    });
    draw();
  }
})();
