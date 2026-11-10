/* Subway station quiz ("Which station is busier?") and the two-station comparison. Stations come from the page
   (window.MTA_QUIZ.stations: [name, borough, riders a day, rank, url, lines, busiest hour, change vs last year %]).
   The best streak is kept in this browser only. */
(function () {
  var cfg = window.MTA_QUIZ;
  var quiz = document.querySelector('[data-mta-quiz]');
  var cmp = document.querySelector('[data-mta-compare]');
  if (!cfg || !quiz) return;
  var S = cfg.stations;
  var css = document.createElement('style');
  css.textContent = '.mta-q-pair{display:grid;grid-template-columns:1fr 1fr;gap:.8em;margin:.6em 0}.mta-q-pick{padding:1em;font:inherit;color:inherit;text-align:left;' +
    'background:var(--mta-card,#111a2c);border:2px solid var(--mta-border,#2a3550);border-radius:12px;cursor:pointer;min-height:6.5em}' +
    '.mta-q-pick:hover:not([disabled]){border-color:var(--mta-accent,#8ab4f8)}.mta-q-pick b{display:block;font-size:1.1rem}.mta-q-pick small{color:var(--mta-muted,#a3adbd)}' +
    '.mta-q-pick .n{display:block;margin-top:.4em;font-size:1.3rem;font-weight:700}.mta-q-pick.win{border-color:#4caf50}.mta-q-pick.lose{border-color:#e57368}' +
    '.mta-q-bar{display:flex;gap:1em;flex-wrap:wrap;align-items:center;color:var(--mta-muted,#a3adbd)}.mta-q-bar strong{color:inherit}' +
    '.mta-q-next{padding:.5em 1.1em;font:inherit;color:inherit;background:transparent;border:1px solid var(--mta-accent,#8ab4f8);border-radius:999px;cursor:pointer}' +
    '.mta-q-msg{font-weight:700;margin:.4em 0}.mta-cmp-pick{display:grid;grid-template-columns:1fr 1fr;gap:.8em}.mta-cmp-pick input{width:100%;box-sizing:border-box;padding:.55em .8em;font:inherit;color:inherit;' +
    'background:var(--mta-card,#111a2c);border:1px solid var(--mta-border,#2a3550);border-radius:10px}@media(max-width:560px){.mta-q-pair,.mta-cmp-pick{grid-template-columns:1fr}}';
  document.head.appendChild(css);

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function fmt(n) { return Number(n).toLocaleString('en-US'); }
  var KEY = 'mta-quiz-best';
  function best() { try { return +localStorage.getItem(KEY) || 0; } catch (e) { return 0; } }
  function setBest(n) { try { localStorage.setItem(KEY, String(n)); } catch (e) {} }

  /* ---------- quiz ---------- */
  var streak = 0, answered = 0, right = 0;
  // Pairs get harder as the streak grows: the two stations' riders get closer.
  function pair() {
    var a, b, tries = 0, ratio = Math.max(1.08, 3 - streak * 0.25);
    do {
      a = S[Math.floor(Math.random() * S.length)];
      b = S[Math.floor(Math.random() * S.length)];
      tries++;
    } while ((a === b || a[2] === b[2] || Math.max(a[2], b[2]) / Math.max(1, Math.min(a[2], b[2])) > ratio) && tries < 400);
    return [a, b];
  }
  function round() {
    var p = pair();
    quiz.textContent = '';
    var bar = el('div', 'mta-q-bar');
    bar.appendChild(el('span', '', 'Streak: ')).appendChild(el('strong', '', String(streak)));
    bar.appendChild(el('span', '', 'Best: ')).appendChild(el('strong', '', String(best())));
    if (answered) bar.appendChild(el('span', '', right + ' of ' + answered + ' right'));
    quiz.appendChild(bar);
    var grid = el('div', 'mta-q-pair');
    var btns = p.map(function (s) {
      var b = el('button', 'mta-q-pick');
      b.type = 'button';
      b.appendChild(el('b', '', s[0]));
      b.appendChild(el('small', '', s[1] + (s[5] ? ' · ' + s[5] : '')));
      grid.appendChild(b);
      return b;
    });
    quiz.appendChild(grid);
    var msg = el('p', 'mta-q-msg');
    msg.setAttribute('aria-live', 'polite');
    quiz.appendChild(msg);
    btns.forEach(function (b, i) {
      b.addEventListener('click', function () {
        var me = p[i], other = p[1 - i], ok = me[2] > other[2];
        answered++;
        if (ok) { right++; streak++; if (streak > best()) setBest(streak); } else { streak = 0; }
        btns.forEach(function (x, j) {
          x.disabled = true;
          x.classList.add(p[j][2] > p[1 - j][2] ? 'win' : 'lose');
          x.appendChild(el('span', 'n', fmt(p[j][2]) + ' riders a day'));
          x.appendChild(el('small', '', '#' + p[j][3] + ' of ' + cfg.count + ' stations'));
        });
        msg.textContent = (ok ? 'Right! ' : 'Not quite. ') + me[0] + ' has ' + fmt(me[2]) + ' riders a day; ' + other[0] + ' has ' + fmt(other[2]) + '.';
        var next = el('button', 'mta-q-next', ok ? 'Next pair →' : 'Try again →');
        next.type = 'button';
        next.addEventListener('click', round);
        quiz.appendChild(next);
        next.focus();
      });
    });
  }
  round();

  /* ---------- compare ---------- */
  if (!cmp) return;
  cmp.textContent = '';
  var dl = el('datalist');
  dl.id = 'mta-cmp-list';
  var names = {};
  S.forEach(function (s) {
    var label = s[0] + ' (' + s[1] + (s[5] ? ', ' + s[5] : '') + ')';
    names[label] = s;
    var o = el('option');
    o.value = label;
    dl.appendChild(o);
  });
  cmp.appendChild(dl);
  var pick = el('div', 'mta-cmp-pick');
  var inputs = [0, 1].map(function (i) {
    var lab = el('label', '', i ? 'Second station' : 'First station');
    var inp = el('input');
    inp.setAttribute('list', 'mta-cmp-list');
    inp.placeholder = 'Type a station name';
    lab.appendChild(inp);
    pick.appendChild(lab);
    return inp;
  });
  cmp.appendChild(pick);
  var out = el('div');
  out.setAttribute('aria-live', 'polite');
  cmp.appendChild(out);
  // Start with the two busiest stations.
  var top = S.slice().sort(function (a, b) { return a[3] - b[3]; });
  inputs[0].value = Object.keys(names).find(function (k) { return names[k] === top[0]; });
  inputs[1].value = Object.keys(names).find(function (k) { return names[k] === top[1]; });
  function draw() {
    var a = names[inputs[0].value], b = names[inputs[1].value];
    out.textContent = '';
    if (!a || !b) { out.appendChild(el('p', 'mta-note', 'Pick two stations from the list.')); return; }
    var rows = [['Riders a day', fmt(a[2]), fmt(b[2])], ['Rank (of ' + cfg.count + ')', '#' + a[3], '#' + b[3]], ['Borough', a[1], b[1]], ['Lines', a[5] || '–', b[5] || '–'],
                ['Busiest hour', a[6] || '–', b[6] || '–'], ['Change vs a year before', (a[7] > 0 ? '+' : '') + a[7] + '%', (b[7] > 0 ? '+' : '') + b[7] + '%']];
    var wrap = el('div', 'mta-table-wrap'), t = el('table', 'mta-table'), th = el('thead'), tr = el('tr');
    ['', a[0], b[0]].forEach(function (h, i) {
      var c = el('th');
      if (i) { var l = el('a', '', h); l.href = (i === 1 ? a : b)[4]; c.appendChild(l); } else c.textContent = h;
      tr.appendChild(c);
    });
    th.appendChild(tr);
    t.appendChild(th);
    var tb = el('tbody');
    rows.forEach(function (r) {
      var row = el('tr');
      r.forEach(function (c) { row.appendChild(el('td', '', c)); });
      tb.appendChild(row);
    });
    t.appendChild(tb);
    wrap.appendChild(t);
    out.appendChild(wrap);
    var hi = a[2] >= b[2] ? a : b, lo = hi === a ? b : a;
    if (lo[2] > 0) out.appendChild(el('p', '', hi[0] + ' has ' + (hi[2] / lo[2]).toFixed(1) + ' times as many riders as ' + lo[0] + '.'));
  }
  inputs.forEach(function (i) { i.addEventListener('change', draw); i.addEventListener('input', function () { if (names[i.value]) draw(); }); });
  draw();
})();
