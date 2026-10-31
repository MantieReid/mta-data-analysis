/* Live subway map from window.MTA_MAP (see includes/subwaymap.php), drawn entirely with MapLibre GL on OpenFreeMap:
   - lines and stops from lines.json; in the diagram views each line is drawn station to station, and lines that run
     along the same stretch sit side by side (MapLibre's line-offset), changing lanes only at stations;
   - the next trains at a stop from the MTA's GTFS-Realtime feeds (read directly; they allow it and need no key);
   - the active service alerts from this site's /alerts endpoint. */
(function () {
  var M = window.MTA_MAP;
  var box = document.querySelector('[data-mta-map]');
  if (!M || !box) return;
  M.routes = M.routes.map(String); // "7", not 7: alert routes are text
  var canvas = box.querySelector('.mta-map-canvas'), status = box.querySelector('.mta-map-status'), alertBox = box.querySelector('.mta-map-alerts');
  var D, map, stopRoutes = {}, GROUP = {}, LANE = {}, ids = [], focus = M.routes.length ? M.routes.slice() : [];
  var STYLES = 'https://tiles.openfreemap.org/styles/';
  var picker = document.querySelector('[data-mta-picker]');

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function base(r) { return /^(\d|F)X$/.test(r) ? r[0] : r; }
  function bullet(r) {
    var x = D && D.routes[r];
    return x ? '<span class="mta-bullet" style="background:#' + x.c + ';color:#' + x.t + '">' + esc(x.s) + '</span>' : esc(r);
  }
  /* A line colour for the dark view: blend dark colours towards white (deep blue A/C/E, navy SIR); bright ones stay. */
  function onDark(hex) {
    var n = parseInt(hex, 16), r = n >> 16, g = n >> 8 & 255, b = n & 255;
    var lum = (.299 * r + .587 * g + .114 * b) / 255, t = lum < .35 ? .45 : lum < .55 ? .2 : 0;
    var mix = function (v) { return Math.round(v + (255 - v) * t); };
    return '#' + ((1 << 24) + (mix(r) << 16) + (mix(g) << 8) + mix(b)).toString(16).slice(1);
  }
  function time(d) { return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }

  /* Google encoded polyline -> [[lon, lat], ...] (GeoJSON order) */
  function decode(s) {
    var pts = [], i = 0, lat = 0, lon = 0;
    while (i < s.length) {
      for (var k = 0; k < 2; k++) {
        var r = 0, sh = 0, b;
        do { b = s.charCodeAt(i++) - 63; r |= (b & 0x1f) << sh; sh += 5; } while (b >= 0x20);
        var v = r & 1 ? ~(r >> 1) : r >> 1;
        if (k === 0) lat += v; else lon += v;
      }
      pts.push([lon / 1e5, lat / 1e5]);
    }
    return pts;
  }

  /* ---- GTFS-Realtime: just the fields we need, read straight from the protobuf bytes ----
     FeedMessage: entity=2. FeedEntity: trip_update=3. TripUpdate: trip=1 (route_id=5), stop_time_update=2.
     StopTimeUpdate: arrival=2, departure=3 (time=2), stop_id=4. */
  function fields(b, from, to, fn) {
    var i = from;
    while (i < to) {
      var key = 0, s = 1, c;
      do { c = b[i++]; key += (c & 0x7f) * s; s *= 128; } while (c >= 0x80);
      var f = Math.floor(key / 8), wt = key % 8;
      if (wt === 0) {
        var v = 0; s = 1;
        do { c = b[i++]; v += (c & 0x7f) * s; s *= 128; } while (c >= 0x80);
        fn(f, 0, v);
      } else if (wt === 2) {
        var n = 0; s = 1;
        do { c = b[i++]; n += (c & 0x7f) * s; s *= 128; } while (c >= 0x80);
        fn(f, 2, i, i + n);
        i += n;
      } else if (wt === 1) { i += 8; } else if (wt === 5) { i += 4; } else { return; }
    }
  }
  function str(b, a, z) { var s = ''; for (var i = a; i < z; i++) s += String.fromCharCode(b[i]); return s; }
  /* -> [{route, stops: [[stopId, unixTime], ...]}] */
  function parseFeed(b) {
    var trips = [];
    fields(b, 0, b.length, function (f, wt, a, z) {
      if (f !== 2 || wt !== 2) return;
      fields(b, a, z, function (f2, wt2, a2, z2) {
        if (f2 !== 3 || wt2 !== 2) return;
        var t = { route: '', id: '', stops: [] };
        fields(b, a2, z2, function (f3, wt3, a3, z3) {
          if (wt3 !== 2) return;
          if (f3 === 1) fields(b, a3, z3, function (f4, wt4, a4, z4) {
            if (f4 === 5 && wt4 === 2) t.route = base(str(b, a4, z4));
            if (f4 === 1 && wt4 === 2) t.id = str(b, a4, z4);
          });
          if (f3 === 2) {
            var sid = '', arr = 0, dep = 0;
            fields(b, a3, z3, function (f4, wt4, a4, z4) {
              if (f4 === 4 && wt4 === 2) sid = str(b, a4, z4);
              if ((f4 === 2 || f4 === 3) && wt4 === 2) {
                fields(b, a4, z4, function (f5, wt5, v) { if (f5 === 2 && wt5 === 0) { if (f4 === 2) arr = v; else dep = v; } });
              }
            });
            if (sid) t.stops.push([sid, arr || dep]);
          }
        });
        if (t.route && t.stops.length) trips.push(t);
      });
    });
    return trips;
  }
  var feeds = {};
  function getFeed(name) {
    var c = feeds[name];
    if (c && Date.now() - c.at < 25000) return Promise.resolve(c.trips);
    return fetch(M.feed + name, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error(r.status);
      return r.arrayBuffer();
    }).then(function (buf) {
      var trips = parseFeed(new Uint8Array(buf));
      feeds[name] = { at: Date.now(), trips: trips };
      return trips;
    });
  }

  /* ---- Next trains at a stop ---- */
  var open = null, timer = null, popup = null;
  function groupRoutes(stop) {
    var rs = [];
    (GROUP[stop] || [stop]).forEach(function (id) { (stopRoutes[id] || []).forEach(function (r) { if (rs.indexOf(r) < 0) rs.push(r); }); });
    return rs;
  }
  function arrivals(stop) {
    var here = GROUP[stop] || [stop], rs = groupRoutes(stop), names = {};
    rs.forEach(function (r) { names[D.routes[r].f] = true; });
    return Promise.all(Object.keys(names).map(getFeed)).then(function (lists) {
      var now = Date.now() / 1000, groups = {};
      lists.forEach(function (trips) {
        trips.forEach(function (t) {
          if (rs.indexOf(t.route) < 0) return;
          for (var i = 0; i < t.stops.length; i++) {
            var sid = t.stops[i][0], when = t.stops[i][1];
            if (here.indexOf(sid.slice(0, -1)) < 0 || when < now - 30) continue;
            var last = t.stops[t.stops.length - 1][0].slice(0, -1);
            if (here.indexOf(last) >= 0) continue; // the train ends here
            var to = (D.stops[last] || [last])[0], key = t.route + '|' + to;
            (groups[key] = groups[key] || { route: t.route, to: to, times: [] }).times.push(when);
            break;
          }
        });
      });
      return Object.keys(groups).map(function (k) {
        var g = groups[k];
        g.times.sort(function (a, b) { return a - b; });
        g.mins = g.times.slice(0, 3).map(function (w) { return Math.max(0, Math.round((w - now) / 60)); });
        return g;
      }).sort(function (a, b) { return a.times[0] - b.times[0]; });
    });
  }
  function popupHtml(stop, groups, err) {
    var s = D.stops[stop], rs = groupRoutes(stop);
    var link = (GROUP[stop] || [stop]).map(function (id) { return M.links[id]; }).filter(Boolean)[0];
    var h = '<div class="mta-pop"><strong>' + esc(s[0]) + '</strong><div class="mta-pop-lines">' + rs.map(bullet).join('') + '</div>';
    if (err) h += '<p class="mta-pop-note">Live times are not available right now.</p>';
    else if (!groups) h += '<p class="mta-pop-note">Loading the next trains…</p>';
    else if (!groups.length) h += '<p class="mta-pop-note">No trains expected in the MTA\'s live data right now.</p>';
    else {
      h += '<table class="mta-pop-times">';
      groups.slice(0, 8).forEach(function (g) {
        h += '<tr><td>' + bullet(g.route) + '</td><td>to ' + esc(g.to) + '</td><td class="mta-pop-min">' +
          g.mins.map(function (m) { return m === 0 ? 'now' : m + ' min'; }).join(', ') + '</td></tr>';
      });
      h += '</table><p class="mta-pop-note">MTA live estimates · ' + time(new Date()) + '</p>';
    }
    if (link) h += '<a class="mta-pop-link" href="' + esc(link) + '">Station page: riders, busiest hours &rarr;</a>';
    return h + '</div>';
  }
  function showStop(stop) {
    var st = D.stops[stop];
    if (!st) return;
    open = stop;
    if (popup) popup.remove();
    popup = new maplibregl.Popup({ maxWidth: '320px', offset: 10 }).setLngLat([st[2], st[1]]).setHTML(popupHtml(stop)).addTo(map);
    popup.on('close', function () { if (open === stop) { open = null; clearInterval(timer); } });
    function load() {
      if (open !== stop || document.hidden) return;
      arrivals(stop).then(function (g) { if (open === stop) popup.setHTML(popupHtml(stop, g)); },
                          function () { if (open === stop) popup.setHTML(popupHtml(stop, null, true)); });
    }
    load();
    clearInterval(timer);
    timer = setInterval(load, 30000);
  }

  /* ---- Live trains ----
     The MTA's subway feed has no GPS, so each train is placed from its own arrival estimates: between the stop before its
     next stop and that next stop, by how much of the trip between them is left. It moves smoothly between updates. */
  var trains = [], trainTimer = null, PREV = null;
  // For every stop on a line: the stop before it, going south (the order of our stop lists) and going north.
  function prevIndex() {
    if (PREV) return PREV;
    PREV = {};
    ids.forEach(function (r) {
      var m = PREV[r] = { S: {}, N: {} };
      D.routes[r].b.forEach(function (b) {
        var st = b[3];
        for (var i = 0; i < st.length; i++) {
          if (i > 0 && !m.S[st[i][0]]) m.S[st[i][0]] = st[i - 1][0];
          if (i + 1 < st.length && !m.N[st[i][0]]) m.N[st[i][0]] = st[i + 1][0];
        }
      });
    });
    return PREV;
  }
  function loadTrains() {
    if (document.hidden) return;
    var names = {};
    ids.forEach(function (r) { names[D.routes[r].f] = true; });
    Promise.all(Object.keys(names).map(function (n) { return getFeed(n).catch(function () { return []; }); })).then(function (lists) {
      var pv = prevIndex(), now = Date.now() / 1000, out = [];
      lists.forEach(function (list) {
        list.forEach(function (t) {
          if (ids.indexOf(t.route) < 0 || !t.stops.length) return;
          // The first stop still ahead is the next stop.
          var i = 0;
          while (i < t.stops.length && t.stops[i][1] && t.stops[i][1] < now - 20) i++;
          if (i >= t.stops.length) return;
          var next = t.stops[i][0], dir = next.slice(-1), nextId = next.slice(0, -1);
          if (!D.stops[nextId] || (dir !== 'N' && dir !== 'S')) return;
          var prevId = pv[t.route][dir][nextId];
          var t1 = t.stops[i][1], t2 = i + 1 < t.stops.length ? t.stops[i + 1][1] : 0;
          // Time between two stops here: the gap to the stop after next is a good guess; keep it between 1 and 5 minutes.
          var seg = t2 > t1 ? Math.min(300, Math.max(60, t2 - t1)) : 120;
          if (!prevId) { if (t1 - now > 60) return; prevId = nextId; } // waiting at the first stop of its run
          if (t1 - now > seg + 90) return; // not on its way to this stop yet (a later departure)
          var last = t.stops[t.stops.length - 1][0].slice(0, -1);
          out.push({ id: t.id, route: t.route, from: prevId, to: nextId, t1: t1, seg: seg, last: last });
        });
      });
      trains = out;
      drawTrains();
    });
  }
  function lanePx(z) { // same lane spacing as the lines (laneAt), per lane
    var k = M.routes.length ? 1.3 : 1, pts = [[9, 2.6], [12, 4], [14, 6], [16, 9]];
    if (z <= 9) return 2.6 * k * .95;
    for (var i = 1; i < pts.length; i++) {
      if (z <= pts[i][0]) return (pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * (z - pts[i - 1][0]) / (pts[i][0] - pts[i - 1][0])) * k * .95;
    }
    return 9 * k * .95;
  }
  function trainPos(tr) {
    var a = D.stops[tr.from], b = D.stops[tr.to], f = 1 - (tr.t1 - Date.now() / 1000) / tr.seg;
    f = Math.max(0, Math.min(1, f));
    var lon = a[2] + (b[2] - a[2]) * f, lat = a[1] + (b[1] - a[1]) * f;
    var L = LANE[tr.route + '|' + tr.from + '|' + tr.to];
    if (!L || !L.lane || !map || !isDiagram(view)) return [lon, lat];
    // Right-hand side of the stretch as it is drawn (from -> to), like MapLibre's line-offset.
    var p = D.stops[L.from], q = D.stops[L.to], cos = Math.cos(lat * Math.PI / 180);
    var dx = (q[2] - p[2]) * cos, dy = q[1] - p[1], len = Math.hypot(dx, dy) || 1;
    var mpp = 40075016.686 * cos / (512 * Math.pow(2, map.getZoom())); // metres per pixel
    var m = L.lane * lanePx(map.getZoom()) * mpp;
    return [lon + (dy / len) * m / (111320 * cos), lat - (dx / len) * m / 111320];
  }
  function drawTrains() {
    var src = map && map.getSource('mta-trains');
    if (!src) return;
    src.setData({ type: 'FeatureCollection', features: trains.map(function (tr, i) {
      var x = D.routes[tr.route];
      return { type: 'Feature', id: i, properties: { i: i, r: tr.route, s: x.s.length > 2 ? 'S' : x.s, c: '#' + x.c, t: '#' + x.t },
               geometry: { type: 'Point', coordinates: trainPos(tr) } };
    }) });
  }
  function startTrains() {
    loadTrains();
    clearInterval(trainTimer);
    trainTimer = setInterval(loadTrains, 30000);
    setInterval(function () { if (!document.hidden) drawTrains(); }, 2000); // glide between updates
    map.on('zoomend', drawTrains); // lanes are measured in pixels, so trains move with the zoom
  }
  function showTrain(i) {
    var tr = trains[i];
    if (!tr) return;
    var mins = Math.max(0, Math.round((tr.t1 - Date.now() / 1000) / 60));
    if (popup) popup.remove();
    open = null;
    popup = new maplibregl.Popup({ maxWidth: '300px', offset: 12 }).setLngLat(trainPos(tr)).setHTML(
      '<div class="mta-pop"><div class="mta-pop-lines">' + bullet(tr.route) + ' <strong>to ' + esc((D.stops[tr.last] || [tr.last])[0]) + '</strong></div>' +
      '<p>Next stop: <strong>' + esc(D.stops[tr.to][0]) + '</strong> ' + (mins === 0 ? 'now' : 'in ' + mins + ' min') + '</p>' +
      '<p class="mta-pop-note">Position estimated from the MTA\'s live arrival times.</p></div>').addTo(map);
  }

  /* ---- Alerts ---- */
  var alerts = [];
  function renderAlerts() {
    var shown = alerts.filter(function (a) { return !focus.length || a.routes.some(function (r) { return focus.indexOf(r) >= 0; }); });
    if (!shown.length) {
      alertBox.innerHTML = '<p class="mta-map-ok">No active MTA alerts' + (focus.length ? ' for this line' : '') + ' right now.</p>';
      return;
    }
    var item = function (a) {
      // "[7][7X]" is one line to a rider: one badge per line in each run of badges.
      var head = esc(a.header).replace(/(\[[A-Z0-9]+\])+/g, function (run) {
        var seen = {};
        return run.match(/[A-Z0-9]+/g).map(function (r) {
          r = base(r);
          if (seen[r]) return '';
          seen[r] = true;
          return D.routes[r] ? bullet(r) : '[' + r + ']';
        }).join('');
      });
      return '<li class="mta-alert' + (/delay/i.test(a.type) ? ' delay' : /^planned/i.test(a.type) ? ' planned' : '') + '"><span class="mta-alert-type">' +
        esc(a.type) + '</span> ' + head + '</li>';
    };
    var first = shown.slice(0, 3).map(item).join(''), rest = shown.slice(3).map(item).join('');
    alertBox.innerHTML = '<p class="mta-map-alerts-head"><strong>' + shown.length + ' active MTA alert' + (shown.length > 1 ? 's' : '') + '</strong> · Source: MTA</p>' +
      '<ul class="mta-alerts">' + first + '</ul>' +
      (rest ? '<details class="mta-how"><summary>Show ' + (shown.length - 3) + ' more</summary><ul class="mta-alerts">' + rest + '</ul></details>' : '');
  }
  function loadAlerts() {
    fetch(M.alerts).then(function (r) { return r.json(); }).then(function (a) {
      alerts = a.alerts || [];
      renderAlerts();
    }, function () { alertBox.innerHTML = ''; });
  }

  /* ---- The line data as map layers ---- */

  /* Station-to-station lines with lanes. Each stretch between two stations knows which lines run along it (an express
     that skips stops counts on the local's stretches too), and each line gets its own lane there, in one fixed order. */
  function buildLines() {
    var order = ids.slice().sort(function (a, b) {
      var x = D.routes[a], y = D.routes[b];
      return x.c < y.c ? -1 : x.c > y.c ? 1 : x.s < y.s ? -1 : 1;
    });
    var rank = {};
    order.forEach(function (r, i) { rank[r] = i; });
    // Every line's stretches (deduplicated), as [route, a, b].
    var segs = [], seen = {};
    ids.forEach(function (r) {
      D.routes[r].b.forEach(function (b) {
        for (var i = 0; i + 1 < b[3].length; i++) {
          var a = b[3][i][0], c = b[3][i + 1][0], k = r + '|' + (a < c ? a + '|' + c : c + '|' + a);
          if (D.stops[a] && D.stops[c] && !seen[k]) { seen[k] = true; segs.push([r, a, c]); }
        }
      });
    });
    // Sample points along each stretch into a ~60 m grid, to find which lines share it.
    var CY = .00055, CX = .0007, grid = {}, cell = function (lat, lon) { return Math.floor(lat / CY) + ':' + Math.floor(lon / CX); };
    var samples = function (s) {
      var A = D.stops[s[1]], B = D.stops[s[2]], n = Math.max(3, Math.min(30, Math.round(Math.hypot((B[1] - A[1]) * 111000, (B[2] - A[2]) * 84000) / 80))), out = [];
      for (var k = 1; k < n; k++) out.push([A[1] + (B[1] - A[1]) * k / n, A[2] + (B[2] - A[2]) * k / n]);
      return out;
    };
    segs.forEach(function (s) {
      s.pts = samples(s);
      s.pts.forEach(function (p) { var c = cell(p[0], p[1]); (grid[c] = grid[c] || {})[s[0]] = true; });
    });
    var feats = [];
    segs.forEach(function (s) {
      var count = {};
      s.pts.forEach(function (p) {
        var y = Math.floor(p[0] / CY), x = Math.floor(p[1] / CX), here = {};
        for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) { var g = grid[(y + dy) + ':' + (x + dx)]; if (g) for (var k in g) here[k] = true; }
        for (var k2 in here) count[k2] = (count[k2] || 0) + 1;
      });
      var set = Object.keys(count).filter(function (k) { return k === s[0] || count[k] * 2 >= s.pts.length; }).sort(function (a, b) { return rank[a] - rank[b]; });
      var A = D.stops[s[1]], B = D.stops[s[2]];
      // Draw every stretch along one fixed direction (towards the south-east), so a lane is on the same side for all lines.
      var fwd = (B[1] - A[1]) * -1 + (B[2] - A[2]) * .7 >= 0;
      var coords = fwd ? [[A[2], A[1]], [B[2], B[1]]] : [[B[2], B[1]], [A[2], A[1]]];
      var ln = set.indexOf(s[0]) - (set.length - 1) / 2;
      LANE[s[0] + '|' + s[1] + '|' + s[2]] = LANE[s[0] + '|' + s[2] + '|' + s[1]] = { lane: ln, from: fwd ? s[1] : s[2], to: fwd ? s[2] : s[1] };
      feats.push({ type: 'Feature', properties: { r: s[0], c: '#' + D.routes[s[0]].c, cd: onDark(D.routes[s[0]].c), lane: set.indexOf(s[0]) - (set.length - 1) / 2 },
                   geometry: { type: 'LineString', coordinates: coords } });
    });
    return { type: 'FeatureCollection', features: feats };
  }
  /* Real track shapes, for the street map. */
  function buildTracks() {
    var feats = [];
    ids.forEach(function (r) {
      D.routes[r].sh.forEach(function (sh) {
        feats.push({ type: 'Feature', properties: { r: r, c: '#' + D.routes[r].c, cd: onDark(D.routes[r].c) }, geometry: { type: 'LineString', coordinates: decode(sh) } });
      });
    });
    return { type: 'FeatureCollection', features: feats };
  }
  function buildStops() {
    var feats = [], seen = {}, list = [];
    ids.forEach(function (r) {
      D.routes[r].b.forEach(function (b) {
        b[3].forEach(function (s0) {
          var id = s0[0], st = D.stops[id];
          if (!st || seen[id]) return;
          seen[id] = true;
          list.push([id, r]);
        });
      });
    });
    // Same name within ~150 m: one station (e.g. 59 St-Columbus Circle's two platform groups).
    var near = function (a, b) { return Math.hypot((a[1] - b[1]) * 111000, (a[2] - b[2]) * 84000) < 150; };
    list.forEach(function (x) {
      if (GROUP[x[0]]) return;
      var g = list.filter(function (y) { return !GROUP[y[0]] && D.stops[y[0]][0] === D.stops[x[0]][0] && near(D.stops[x[0]], D.stops[y[0]]); }).map(function (y) { return y[0]; });
      g.forEach(function (id) { GROUP[id] = g; });
    });
    var links = [];
    list.forEach(function (x) {
      var id = x[0], st = D.stops[id], g = GROUP[id];
      feats.push({ type: 'Feature', properties: { id: id, label: g[0] === id ? st[0] : '', t: g.length > 1 || groupRoutes(id).length > 1 ? 1 : 0, c: '#' + D.routes[x[1]].c, cd: onDark(D.routes[x[1]].c) },
                   geometry: { type: 'Point', coordinates: [st[2], st[1]] } });
      if (g[0] === id) for (var i = 1; i < g.length; i++) {
        var o = D.stops[g[i]];
        links.push({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [[st[2], st[1]], [o[2], o[1]]] } });
      }
    });
    DATA.links = { type: 'FeatureCollection', features: links };
    return { type: 'FeatureCollection', features: feats };
  }
  var BOROUGHS = { type: 'FeatureCollection', features: [['The Bronx', 40.862, -73.845], ['Manhattan', 40.792, -73.968], ['Queens', 40.712, -73.795],
    ['Brooklyn', 40.628, -73.952], ['Staten Island', 40.585, -74.145]].map(function (b) {
      return { type: 'Feature', properties: { name: b[0] }, geometry: { type: 'Point', coordinates: [b[2], b[1]] } };
    }) };

  /* ---- Views ----
     "diagram": our plain style (white land, blue water, parks); "streets": the same plus streets and their names;
     "street": OpenFreeMap's street map in the site's chosen look (M.look). */
  var view = 'diagram', look = Object.assign({ style: 'positron' }, M.look || {}), DATA = {};
  try { view = localStorage.getItem('mta-map-view3') || view; } catch (e) {}
  if (['diagram', 'streets', 'street'].indexOf(view) < 0) view = 'diagram';
  var GLYPHS = 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf';
  var DIAGRAM = {
    version: 8, glyphs: GLYPHS,
    sources: { omt: { type: 'vector', url: 'https://tiles.openfreemap.org/planet' } },
    layers: [
      { id: 'background', type: 'background', paint: { 'background-color': '#ffffff' } },
      { id: 'park', type: 'fill', source: 'omt', 'source-layer': 'park', paint: { 'fill-color': '#e8f3e1' } },
      { id: 'water', type: 'fill', source: 'omt', 'source-layer': 'water', paint: { 'fill-color': '#cfe4f6' } }
    ]
  };
  var STREETS = JSON.parse(JSON.stringify(DIAGRAM));
  STREETS.layers.push(
    { id: 'streets', type: 'line', source: 'omt', 'source-layer': 'transportation', minzoom: 11,
      filter: ['in', ['get', 'class'], ['literal', ['motorway', 'trunk', 'primary', 'secondary', 'tertiary', 'minor', 'service']]],
      paint: { 'line-color': '#e3e6ea', 'line-width': ['interpolate', ['linear'], ['zoom'], 11, .5, 16, 5] } },
    { id: 'street-names', type: 'symbol', source: 'omt', 'source-layer': 'transportation_name', minzoom: 13.5,
      layout: { 'symbol-placement': 'line', 'text-field': ['coalesce', ['get', 'name_en'], ['get', 'name']], 'text-font': ['Noto Sans Regular'],
                'text-size': ['interpolate', ['linear'], ['zoom'], 13.5, 10, 17, 13] },
      paint: { 'text-color': '#6b7280', 'text-halo-color': '#ffffff', 'text-halo-width': 1.5 } });
  // "dark": OpenFreeMap's dark style (dim streets and place names) under bright lines with dark outlines.
  function isDiagram(v) { return v === 'dark' || v === 'diagram' || v === 'streets'; }
  function baseStyle(v) { return v === 'dark' ? STYLES + 'dark' : v === 'diagram' ? DIAGRAM : v === 'streets' ? STREETS : STYLES + look.style; }

  // Line width and lane spacing grow with zoom; a single line (line pages) is a little thicker.
  function widthAt(k) { return ['interpolate', ['linear'], ['zoom'], 9, 2.6 * k, 12, 4 * k, 14, 6 * k, 16, 9 * k]; }
  function laneAt(k) {
    return ['interpolate', ['linear'], ['zoom'], 9, ['*', ['get', 'lane'], 2.6 * k * .95], 12, ['*', ['get', 'lane'], 4 * k * .95],
            14, ['*', ['get', 'lane'], 6 * k * .95], 16, ['*', ['get', 'lane'], 9 * k * .95]];
  }
  function lineOpacity() {
    return focus.length && !M.routes.length ? ['case', ['==', ['get', 'r'], focus[0]], 1, .12] : 1;
  }

  /* Our layers on top of whichever base style is showing (they are re-added after each style switch). */
  function addOverlay() {
    if (map.getSource('mta-lines')) return;
    var k = M.routes.length ? 1.3 : 1, diag = isDiagram(view), dark = view === 'dark';
    var lineColor = ['get', dark ? 'cd' : 'c'];
    map.addSource('mta-lines', { type: 'geojson', data: DATA.lines });
    map.addSource('mta-tracks', { type: 'geojson', data: DATA.tracks });
    map.addSource('mta-stops', { type: 'geojson', data: DATA.stops });
    map.addSource('mta-boroughs', { type: 'geojson', data: BOROUGHS });
    map.addSource('mta-links', { type: 'geojson', data: DATA.links });
    map.addLayer({ id: 'mta-boroughs', type: 'symbol', source: 'mta-boroughs', maxzoom: 13, layout: { visibility: diag && !M.routes.length ? 'visible' : 'none',
      'text-field': ['upcase', ['get', 'name']], 'text-font': ['Noto Sans Bold'], 'text-size': 15, 'text-letter-spacing': .08 },
      paint: { 'text-color': dark ? 'rgba(209,197,174,.3)' : 'rgba(17,24,39,.25)' } });
    // An outline under every line (dark navy on the dark map, white on the light ones) keeps side-by-side lines apart.
    map.addLayer({ id: 'mta-casing', type: 'line', source: 'mta-lines', layout: { visibility: dark ? 'visible' : 'none', 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': dark ? '#0f1e2e' : '#ffffff', 'line-width': ['interpolate', ['linear'], ['zoom'], 9, 2.6 * k + 2, 12, 4 * k + 3, 14, 6 * k + 3, 16, 9 * k + 4],
               'line-offset': laneAt(k), 'line-opacity': lineOpacity() } });
    map.addLayer({ id: 'mta-lines', type: 'line', source: 'mta-lines', layout: { visibility: diag ? 'visible' : 'none', 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': lineColor, 'line-width': widthAt(k), 'line-offset': laneAt(k), 'line-opacity': lineOpacity() } });
    map.addLayer({ id: 'mta-tracks', type: 'line', source: 'mta-tracks', layout: { visibility: diag ? 'none' : 'visible', 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': lineColor, 'line-width': widthAt(k * .8), 'line-opacity': lineOpacity() } });
    // Transfer links between the dots of one station.
    map.addLayer({ id: 'mta-links', type: 'line', source: 'mta-links', layout: { 'line-cap': 'round' },
      paint: { 'line-color': dark ? '#e8dfcc' : '#111827', 'line-width': ['interpolate', ['linear'], ['zoom'], 9, 2, 14, 5, 16, 7] } });
    map.addLayer({ id: 'mta-stops', type: 'circle', source: 'mta-stops', paint: {
      'circle-radius': ['interpolate', ['linear'], ['zoom'], 9, ['case', ['==', ['get', 't'], 1], 1.6, .9], 12, ['case', ['==', ['get', 't'], 1], 4, 2.8],
                        14, ['case', ['==', ['get', 't'], 1], 6, 4.5], 16, ['case', ['==', ['get', 't'], 1], 8, 6]],
      'circle-color': dark ? '#1c1b1b' : '#ffffff',
      // Dark view: a ring in the line's colour (transfer stations in light beige); light views: dark rings.
      'circle-stroke-color': dark ? ['case', ['==', ['get', 't'], 1], '#e8dfcc', ['get', 'cd']] : diag ? '#111827' : ['case', ['==', ['get', 't'], 1], '#1f2937', ['get', 'c']],
      'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 9, .6, 14, ['case', ['==', ['get', 't'], 1], 2.5, 1.8]] } });
    map.addSource('mta-trains', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({ id: 'mta-trains', type: 'circle', source: 'mta-trains', paint: {
      'circle-radius': ['interpolate', ['linear'], ['zoom'], 9, 4, 12, 7.5, 15, 10],
      'circle-color': ['get', 'c'], 'circle-stroke-color': '#ffffff', 'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 9, 1, 13, 2] } });
    map.addLayer({ id: 'mta-train-letters', type: 'symbol', source: 'mta-trains', minzoom: 11, layout: {
      'text-field': ['get', 's'], 'text-font': ['Noto Sans Bold'], 'text-size': ['interpolate', ['linear'], ['zoom'], 11, 8, 15, 12],
      'text-allow-overlap': true, 'text-ignore-placement': true }, paint: { 'text-color': ['get', 't'] } });
    drawTrains();
    map.addLayer({ id: 'mta-stop-names', type: 'symbol', source: 'mta-stops', minzoom: M.routes.length ? 0 : 11.5, layout: {
      'text-field': ['get', 'label'], 'text-font': ['Noto Sans Bold'], 'text-size': ['interpolate', ['linear'], ['zoom'], 11, 10, 15, 13],
      'text-variable-anchor': ['left', 'right', 'top', 'bottom'], 'text-radial-offset': .9, 'text-justify': 'auto', 'text-max-width': 9 },
      paint: { 'text-color': dark ? '#d1c5ae' : '#111827', 'text-halo-color': dark ? '#1c1b1b' : '#ffffff', 'text-halo-width': dark ? 1.6 : 2 } });
  }

  function setView(v) {
    view = v;
    try { localStorage.setItem('mta-map-view3', v); } catch (e) {}
    canvas.classList.toggle('dark', v === 'dark');
    box.querySelectorAll('[data-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === v ? 'true' : 'false'); });
    map.setStyle(baseStyle(v));
    map.once('style.load', function () { if (v === 'street') recolor(look); addOverlay(); drawTrains(); });
  }

  /* Fade every line but the picked one (system map). */
  function applyFocus() {
    if (!map || !map.getLayer('mta-lines') || M.routes.length) return;
    map.setPaintProperty('mta-lines', 'line-opacity', lineOpacity());
    map.setPaintProperty('mta-casing', 'line-opacity', lineOpacity());
    map.setPaintProperty('mta-tracks', 'line-opacity', lineOpacity());
  }

  function draw() {
    ids = M.routes.length ? M.routes.filter(function (r) { return D.routes[r]; }) : Object.keys(D.routes);
    ids.forEach(function (r) {
      D.routes[r].b.forEach(function (b) { b[3].forEach(function (s0) { var l = stopRoutes[s0[0]] = stopRoutes[s0[0]] || []; if (l.indexOf(r) < 0) l.push(r); }); });
    });
    DATA = {};
    DATA.stops = buildStops();
    DATA.lines = buildLines();
    DATA.tracks = buildTracks();
    // Start on the main system (Staten Island is far off and has one line).
    var b = new maplibregl.LngLatBounds();
    DATA.stops.features.forEach(function (f) { if (M.routes.length || (stopRoutes[f.properties.id] || []).indexOf('SI') < 0) b.extend(f.geometry.coordinates); });
    canvas.classList.toggle('dark', view === 'dark');
    map = new maplibregl.Map({ container: canvas, style: baseStyle(view), bounds: b, fitBoundsOptions: { padding: 20 },
                               scrollZoom: false, dragRotate: false, pitchWithRotate: false, attributionControl: false });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-left');
    map.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: 'Subway data: MTA' }));
    map.touchZoomRotate.disableRotation();
    map.once('style.load', function () { if (view === 'street') recolor(look); addOverlay(); });
    map.on('click', function (e) {
      var tr = map.getLayer('mta-trains') && map.queryRenderedFeatures(e.point, { layers: ['mta-trains'] });
      if (tr && tr.length) return showTrain(tr[0].properties.i);
      var st = map.getLayer('mta-stops') && map.queryRenderedFeatures(e.point, { layers: ['mta-stops'] });
      if (st && st.length) showStop(st[0].properties.id);
    });
    ['mta-stops', 'mta-trains'].forEach(function (l) {
      map.on('mouseenter', l, function () { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', l, function () { map.getCanvas().style.cursor = ''; });
    });
    startTrains();
    // "Diagram / Diagram + streets / Street map" switch, above the map.
    var sw = document.createElement('div');
    sw.className = 'mta-view-switch';
    sw.innerHTML = '<button type="button" data-view="diagram">Diagram</button><button type="button" data-view="streets">Diagram + streets</button>' +
      '<button type="button" data-view="street">Street map</button>';
    canvas.parentNode.insertBefore(sw, canvas);
    sw.addEventListener('click', function (e) { var v = e.target.getAttribute('data-view'); if (v && v !== view) setView(v); });
    sw.querySelectorAll('[data-view]').forEach(function (bt) { bt.setAttribute('aria-pressed', bt.getAttribute('data-view') === view ? 'true' : 'false'); });
    if (M.tester) { window.MTA_MAP_GL = map; tester(); } // preview only
    status.textContent = 'Live trains move along the lines. Click a train or a stop for times. Positions are estimated from the MTA\'s live arrival times.';
  }

  /* ---- Street map colours ----
     Which of a style's layers each colour paints. Land is the background plus land-use areas (Positron's grey). */
  var KINDS = {
    land: function (l) { return l.type === 'background' || (l.type === 'fill' && /^landuse/.test(l.id) && !/park|cemetery|pitch|track/.test(l.id)); },
    water: function (l) { return /water/.test(l.id) && (l.type === 'fill' || l.type === 'line') && !/label|name/.test(l.id); },
    // Roads include bridges and tunnels (separate layers in Liberty and Bright); edges are their outlines.
    roads: function (l) { return l.type === 'line' && /road|highway|^tunnel-|^bridge-/.test(l.id) && !/casing|path|rail|label|name/.test(l.id); },
    edges: function (l) { return l.type === 'line' && /road|highway|^tunnel-|^bridge-/.test(l.id) && /casing/.test(l.id) && !/path|rail/.test(l.id); },
    parks: function (l) { return l.type === 'fill' && /park|grass|wood|landcover/.test(l.id); },
    buildings: function (l) { return /building/.test(l.id) && /fill/.test(l.type); }
  };
  var PROP = { background: 'background-color', fill: 'fill-color', line: 'line-color', 'fill-extrusion': 'fill-extrusion-color' };
  function recolor(lk) {
    (map.getStyle().layers || []).forEach(function (l) {
      if (/^mta-/.test(l.id)) return;
      Object.keys(KINDS).forEach(function (k) {
        if (lk[k] && KINDS[k](l) && PROP[l.type]) map.setPaintProperty(l.id, PROP[l.type], lk[k]);
      });
    });
  }

  /* ---- Look tester: only with ?mta_preview=1. Try styles and colours, then copy the settings. ---- */
  function tester() {
    var PRESETS = {
      'Positron (now)': { style: 'positron' },
      'Bright white': { style: 'positron', land: '#ffffff', water: '#cde4f7', roads: '#eef0f3', parks: '#e2f2dc', buildings: '#f1f1ef' },
      'Soft cream': { style: 'positron', land: '#fbf8f1', water: '#cfe3f3', roads: '#ffffff', parks: '#e5f0da', buildings: '#efebe3' },
      'Liberty': { style: 'liberty' },
      'Bright': { style: 'bright' }
    };
    var cur = Object.assign({}, look);
    try { cur = Object.assign(cur, JSON.parse(localStorage.getItem('mta-map-look') || '{}')); } catch (e) {}
    var panel = document.createElement('div');
    panel.className = 'mta-tester';
    var colours = ['land', 'water', 'roads', 'edges', 'parks', 'buildings'];
    panel.innerHTML = '<p class="mta-tester-head"><strong>Map look tester</strong> · only you see this (preview mode). It changes the Street map view.</p>' +
      '<div class="mta-tester-presets">' + Object.keys(PRESETS).map(function (p) { return '<button type="button" class="mta-find-pick" data-preset="' + esc(p) + '">' + esc(p) + '</button>'; }).join('') + '</div>' +
      '<div class="mta-tester-row"><label>Style <select data-k="style"><option value="positron">Positron</option><option value="liberty">Liberty</option><option value="bright">Bright</option></select></label>' +
      colours.map(function (k) { var name = k === 'edges' ? 'Road edges' : k[0].toUpperCase() + k.slice(1); return '<label>' + name + ' <input type="color" data-k="' + k + '"></label>'; }).join('') + '</div>' +
      '<p class="mta-tester-out">Settings to send me: <code></code> <button type="button" class="mta-find-pick" data-copy>Copy</button></p>';
    box.appendChild(panel);
    var out = panel.querySelector('code');
    function show() {
      panel.querySelector('[data-k="style"]').value = cur.style;
      colours.forEach(function (k) { var i = panel.querySelector('[data-k="' + k + '"]'); i.value = cur[k] || '#ffffff'; i.style.opacity = cur[k] ? 1 : .45; });
      out.textContent = JSON.stringify(cur);
      try { localStorage.setItem('mta-map-look', JSON.stringify(cur)); } catch (e) {}
    }
    function apply() { look = Object.assign({}, cur); setView('street'); show(); } // reload the style so earlier colours are cleared
    panel.addEventListener('input', function (e) {
      var k = e.target.getAttribute('data-k');
      if (!k) return;
      cur[k] = e.target.value;
      apply();
    });
    panel.addEventListener('click', function (e) {
      var p = e.target.getAttribute('data-preset');
      if (p) { cur = Object.assign({}, PRESETS[p]); apply(); }
      if (e.target.hasAttribute('data-copy')) {
        (navigator.clipboard ? navigator.clipboard.writeText(out.textContent) : Promise.reject()).then(function () { e.target.textContent = 'Copied'; }, function () {
          var rng = document.createRange(); rng.selectNodeContents(out); getSelection().removeAllRanges(); getSelection().addRange(rng);
        });
      }
    });
    show();
  }

  /* System map: highlight one line. */
  if (picker) picker.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-route]');
    if (!btn || !map) return;
    var r = btn.getAttribute('data-route');
    focus = r ? [r] : [];
    picker.querySelectorAll('[data-route]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); b.classList.toggle('on', b === btn); });
    applyFocus();
    if (r) {
      var b = new maplibregl.LngLatBounds();
      DATA.stops.features.forEach(function (f) { if ((stopRoutes[f.properties.id] || []).indexOf(r) >= 0) b.extend(f.geometry.coordinates); });
      if (!b.isEmpty()) map.fitBounds(b, { padding: 30 });
    }
    renderAlerts();
  });

  /* MapLibre is ~800 KB: load it only when a map is about to be shown. */
  function load(src, css) {
    return new Promise(function (ok) {
      var el = document.createElement(css ? 'link' : 'script');
      if (css) { el.rel = 'stylesheet'; el.href = src; } else { el.src = src; }
      el.onload = el.onerror = function () { ok(); };
      document.head.appendChild(el);
    });
  }
  function start() {
    var lib = 'https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/';
    load(lib + 'maplibre-gl.css', true);
    load(lib + 'maplibre-gl.js').then(function () {
      if (!window.maplibregl) throw new Error('no maplibre');
      return fetch(M.data);
    }).then(function (r) { return r.json(); }).then(function (d) {
      D = d;
      draw();
      loadAlerts();
      setInterval(function () { if (!document.hidden) loadAlerts(); }, 120000);
    }).catch(function () { status.textContent = 'The map could not be loaded. Please try again later.'; });
  }
  // Build the map only when it is about to scroll into view.
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); start(); } }, { rootMargin: '400px 0px' });
    io.observe(box);
  } else start();
})();
