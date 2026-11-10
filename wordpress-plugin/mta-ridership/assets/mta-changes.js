/* Service changes page: the MTA's current alerts and this week's planned work, from the site's two small copies of the
   MTA alerts file (/wp-json/mta-ridership/v1/alerts and /planned). Pick a line to see only its alerts. Refreshes every
   2 minutes while the tab is visible. All text is the MTA's own wording, set as text (never as HTML). */
(function () {
  var cfg = window.MTA_CHANGES;
  var nowBox = document.querySelector('[data-changes-now]');
  var planBox = document.querySelector('[data-changes-planned]');
  var pick = document.querySelector('[data-changes-pick]');
  if (!cfg || !nowBox || !planBox) return;
  var css = document.createElement('style');
  css.textContent = '.mta-chg{list-style:none;padding:0;margin:0}.mta-chg li{border:1px solid var(--mta-border,#2a3550);border-radius:10px;padding:.7em .9em;margin:0 0 .6em}' +
    '.mta-chg .k{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mta-muted,#a3adbd);margin-left:.4em}' +
    '.mta-chg .k.hot{color:#ffb4a8}.mta-chg p{margin:.4em 0 0}.mta-chg details{margin-top:.4em;font-size:.92rem;color:var(--mta-muted,#a3adbd)}' +
    '.mta-chg .w{display:block;font-size:.88rem;color:var(--mta-muted,#a3adbd);margin-top:.3em}.mta-chg-on{color:#9be7a1;font-weight:700}' +
    '.mta-chg-pick{margin:0 .3em .3em 0;padding:.35em .8em;font:inherit;font-size:.9rem;color:inherit;background:transparent;border:1px solid var(--mta-border,#2a3550);border-radius:999px;cursor:pointer}' +
    '.mta-chg-pick[aria-pressed="true"]{border-color:var(--mta-accent,#8ab4f8);color:var(--mta-accent,#8ab4f8)}';
  document.head.appendChild(css);

  var line = '';        // the picked line ('' = all)
  var data = { alerts: null, planned: null };
  var order = Object.keys(cfg.bullets || {});

  function bullet(r) {
    var b = (cfg.bullets || {})[r];
    var s = document.createElement('span');
    s.className = 'mta-bullet';
    s.textContent = b ? b[2] : r;
    s.setAttribute('aria-label', (b ? b[2] : r) + ' train');
    if (b) { s.style.background = b[0]; s.style.color = b[1]; } else { s.style.background = '#59606b'; s.style.color = '#fff'; }
    return s;
  }
  // The MTA writes lines as [A] in its text; show them as bullets.
  function textWithBullets(el, t) {
    var parts = String(t).split(/\[([A-Z0-9]{1,3})\]/);
    var alias = { S: 'GS', SIR: 'SI', '6X': '6', '7X': '7', FX: 'F' };
    parts.forEach(function (p, i) {
      if (i % 2) el.appendChild(bullet(alias[p] || p)); else if (p) el.appendChild(document.createTextNode(p));
    });
  }
  function fmtTime(sec) {
    try {
      return new Date(sec * 1000).toLocaleString('en-US', { timeZone: 'America/New_York', weekday: 'short', hour: 'numeric', minute: '2-digit' });
    } catch (e) { return ''; }
  }
  function matches(a) { return !line || a.routes.indexOf(line) >= 0; }

  function item(a, planned) {
    var li = document.createElement('li');
    var head = document.createElement('div');
    a.routes.slice().sort(function (x, y) { return order.indexOf(x) - order.indexOf(y); }).forEach(function (r) { head.appendChild(bullet(r)); });
    var k = document.createElement('span');
    k.className = 'k' + (/delay|suspend/i.test(a.type) && !planned ? ' hot' : '');
    k.textContent = a.type || 'Alert';
    head.appendChild(k);
    if (planned && a.now) {
      var on = document.createElement('span');
      on.className = 'k mta-chg-on';
      on.textContent = 'on now';
      head.appendChild(on);
    }
    li.appendChild(head);
    var p = document.createElement('p');
    textWithBullets(p, a.header);
    li.appendChild(p);
    var w = document.createElement('span');
    w.className = 'w';
    w.textContent = planned ? (a.when || ('From ' + fmtTime(a.start))) : (a.until ? 'Until about ' + fmtTime(a.until) : '');
    if (w.textContent) li.appendChild(w);
    if (a.description) {
      var d = document.createElement('details');
      var s = document.createElement('summary');
      s.textContent = 'More details';
      d.appendChild(s);
      var dp = document.createElement('p');
      textWithBullets(dp, a.description);
      d.appendChild(dp);
      li.appendChild(d);
    }
    return li;
  }

  function list(box, rows, planned, emptyText, fail) {
    box.textContent = '';
    if (rows === null) {
      var e = document.createElement('p');
      e.className = 'mta-note';
      e.textContent = fail;
      box.appendChild(e);
      return;
    }
    rows = rows.filter(matches);
    if (!rows.length) {
      var n = document.createElement('p');
      n.textContent = emptyText;
      box.appendChild(n);
      return;
    }
    var ul = document.createElement('ul');
    ul.className = 'mta-chg';
    var SHOW = 12; // long lists start short, with a button for the rest
    rows.forEach(function (a, i) {
      var li = item(a, planned);
      if (i >= SHOW) li.hidden = true;
      ul.appendChild(li);
    });
    box.appendChild(ul);
    if (rows.length > SHOW) {
      var more = document.createElement('button');
      more.type = 'button';
      more.className = 'mta-chg-pick';
      more.textContent = 'Show all ' + rows.length;
      more.addEventListener('click', function () {
        ul.querySelectorAll('li[hidden]').forEach(function (li) { li.hidden = false; });
        more.remove();
      });
      box.appendChild(more);
    }
  }

  function pills() {
    if (!pick) return;
    var seen = {};
    (data.alerts || []).concat(data.planned || []).forEach(function (a) { a.routes.forEach(function (r) { seen[r] = 1; }); });
    pick.textContent = '';
    var all = document.createElement('button');
    all.type = 'button';
    all.className = 'mta-chg-pick';
    all.textContent = 'All lines';
    all.setAttribute('aria-pressed', line ? 'false' : 'true');
    all.addEventListener('click', function () { line = ''; draw(); });
    pick.appendChild(all);
    order.filter(function (r) { return seen[r]; }).forEach(function (r) {
      var b = bullet(r);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = b.className;
      btn.style.cssText = b.style.cssText;
      btn.textContent = b.textContent;
      btn.setAttribute('aria-label', 'Only the ' + b.textContent + ' train');
      btn.setAttribute('aria-pressed', line === r ? 'true' : 'false');
      btn.addEventListener('click', function () { line = line === r ? '' : r; draw(); });
      pick.appendChild(btn);
    });
  }

  function draw() {
    pills();
    list(nowBox, data.alerts, false, line ? 'No current alerts for this line. Good service as far as the MTA has posted.' : 'No current alerts. Good service on every line as far as the MTA has posted.',
      'The MTA\'s alerts could not be loaded right now. See mta.info/alerts.');
    list(planBox, data.planned, true, line ? 'No planned work for this line in the next 8 days.' : 'No planned work in the next 8 days.',
      'Planned work could not be loaded right now. See mta.info/alerts.');
  }

  function get(url) {
    return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }
  function load() {
    Promise.all([
      get(cfg.alerts).then(function (j) { return j.ok ? j.alerts : null; }).catch(function () { return null; }),
      get(cfg.planned).then(function (j) { return j.ok ? j.planned : null; }).catch(function () { return null; })
    ]).then(function (r) {
      // Planned work that is on now is listed under planned work (marked "on now"), not twice.
      data.alerts = r[0] && r[0].filter(function (a) { return !/^planned/i.test(a.type || ''); });
      data.planned = r[1];
      draw();
    });
  }
  load();
  setInterval(function () { if (!document.hidden) load(); }, 120000);
})();
