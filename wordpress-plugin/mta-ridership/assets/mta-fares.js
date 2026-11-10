/* LIRR and Metro-North fare calculator. Prices come from the page (window.MTA_FARES, the official fare charts): LIRR
   by [from zone][to zone], Metro-North by the station's zone (trips to or from Grand Central / Harlem-125 St). Each
   list of prices is [peak, off-peak, senior, day pass weekday, day pass weekend, weekly, monthly, onboard peak, onboard
   off-peak]. The last trip picked is remembered in this browser only. */
(function () {
  var cfg = window.MTA_FARES;
  var box = document.querySelector('[data-mta-fares]');
  if (!cfg || !box) return;
  var from = box.querySelector('[data-fare-from]');
  var to = box.querySelector('[data-fare-to]');
  var out = box.querySelector('[data-fare-out]');
  var rrBtns = box.querySelectorAll('[data-rr]');
  var keys = Object.keys(cfg.keys);
  var rr = 'lirr';
  var KEY = 'mta-fare-trip';

  function save() { try { localStorage.setItem(KEY, JSON.stringify({ rr: rr, from: from.value, to: to.value })); } catch (e) {} }
  function saved() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }

  function opt(sel, label, value) {
    var o = document.createElement('option');
    o.textContent = label;
    o.value = value;
    sel.appendChild(o);
  }
  function fill() {
    from.textContent = '';
    to.textContent = '';
    var st = cfg.stations[rr] || [];
    opt(from, 'Pick a station', '');
    st.forEach(function (s, i) { if (rr === 'lirr' || s[1] !== 1) opt(from, s[0] + ' (Zone ' + s[1] + ')', i); });
    if (rr === 'lirr') {
      opt(to, 'Pick a station', '');
      st.forEach(function (s, i) { opt(to, s[0] + ' (Zone ' + s[1] + ')', i); });
      to.disabled = false;
    } else {
      opt(to, 'Grand Central / Harlem-125 St', 'gct');
      to.disabled = true;
    }
  }
  function money(v) { return '$' + Number(v).toFixed(2); }
  function show() {
    out.textContent = '';
    var st = cfg.stations[rr] || [];
    var a = st[from.value], b = rr === 'lirr' ? st[to.value] : null;
    var prices = null, note = '';
    if (a && rr === 'mnr') {
      prices = cfg.mnr[a[1]];
      note = a[1] >= 15 && a[1] < 30 ? 'Connecticut fares since July 1, 2026.' : (a[1] > 30 ? 'Branch fares since July 1, 2026.' : 'New York fares since January 4, 2026.');
    } else if (a && b) {
      prices = (cfg.lirr[a[1]] || {})[b[1]];
      note = a[1] >= 4 && b[1] >= 4 ? 'Between Zones 4 and 14 peak and off-peak cost the same. Fares since January 4, 2026.' : 'Fares since January 4, 2026.';
      if (a === b) note = 'Same station. ' + note;
    }
    if (!prices) {
      var p = document.createElement('p');
      p.className = 'mta-note';
      p.textContent = 'Pick two stations to see the fares.';
      out.appendChild(p);
      return;
    }
    var h = document.createElement('p');
    var strong = document.createElement('strong');
    strong.textContent = a[0] + ' ⇄ ' + (b ? b[0] : 'Grand Central / Harlem-125 St') + ': ' + money(prices[0]) + ' peak, ' + money(prices[1]) + ' off-peak';
    h.appendChild(strong);
    out.appendChild(h);
    var t = document.createElement('table');
    t.className = 'mta-table';
    var tb = document.createElement('tbody');
    keys.forEach(function (k, i) {
      var tr = document.createElement('tr');
      var th = document.createElement('td');
      th.textContent = cfg.keys[k];
      var td = document.createElement('td');
      td.textContent = money(prices[i]);
      tr.appendChild(th);
      tr.appendChild(td);
      tb.appendChild(tr);
    });
    t.appendChild(tb);
    var wrap = document.createElement('div');
    wrap.className = 'mta-table-wrap';
    wrap.appendChild(t);
    out.appendChild(wrap);
    var n = document.createElement('p');
    n.className = 'mta-note';
    n.textContent = note + ' Station pages: ';
    [a, b].forEach(function (s, i) {
      if (!s || !s[2]) return;
      if (i && a !== b) n.appendChild(document.createTextNode(' · '));
      if (i && a === b) return;
      var l = document.createElement('a');
      l.href = s[2];
      l.textContent = s[0];
      n.appendChild(l);
    });
    out.appendChild(n);
  }
  function setRR(k) {
    rr = k;
    rrBtns.forEach(function (b) {
      var on = b.getAttribute('data-rr') === k;
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    fill();
  }
  rrBtns.forEach(function (b) { b.addEventListener('click', function () { setRR(b.getAttribute('data-rr')); show(); save(); }); });
  from.addEventListener('change', function () { show(); save(); });
  to.addEventListener('change', function () { show(); save(); });
  box.querySelector('[data-fare-swap]').addEventListener('click', function () {
    if (rr !== 'lirr') return;
    var x = from.value;
    from.value = to.value;
    to.value = x;
    show();
    save();
  });

  var s = saved();
  setRR(s && cfg.stations[s.rr] ? s.rr : 'lirr');
  if (s && s.rr === rr) {
    from.value = s.from;
    if (rr === 'lirr') to.value = s.to;
  }
  show();
})();
