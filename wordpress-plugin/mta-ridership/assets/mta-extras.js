/* Extras on the MTA pages (see includes/features.php):
   - "Search or ask": pages, stations, lines, bus routes and railroad stations, plus answers from the site's own FAQs,
     matched word by word in this browser (no AI, nothing sent anywhere; the index is loaded the first time the box is used);
   - "Share" on the big numbers at the top of a page (the phone's share sheet, or copy to the clipboard);
   - "My stations": save a subway station on its page; saved stations show as links under the search box on every page.
     They live only in this browser (localStorage). */
(function () {
  var cfg = window.MTA_EXTRAS;
  var root = document.querySelector('.mta-ridership');
  if (!cfg || !root) return;

  var css = document.createElement('style');
  css.textContent =
    '.mta-ask{position:relative;margin:0 0 1.2em}.mta-ask input{width:100%;box-sizing:border-box;padding:.7em 1em;font:inherit;color:inherit;' +
    'background:var(--mta-card,#111a2c);border:1px solid var(--mta-border,#2a3550);border-radius:12px}.mta-ask input:focus{outline:2px solid var(--mta-accent,#8ab4f8);outline-offset:1px}' +
    '.mta-ask-res{position:absolute;left:0;right:0;top:100%;z-index:20;margin-top:4px;background:#0b1220;border:1px solid var(--mta-border,#2a3550);border-radius:12px;' +
    'max-height:70vh;overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.45)}.mta-ask-res[hidden]{display:none}' +
    '.mta-ask-res a{display:block;padding:.55em 1em;color:inherit;text-decoration:none;border-bottom:1px solid #1b2438}.mta-ask-res a:hover,.mta-ask-res a.on{background:#16213a}' +
    '.mta-ask-res small{display:block;color:var(--mta-muted,#a3adbd);font-size:.82rem}.mta-ask-res .ans{white-space:normal}.mta-ask-res .ans b{color:var(--mta-accent,#8ab4f8)}' +
    '.mta-ask-res .k{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mta-muted,#a3adbd);margin-right:.5em}' +
    '.mta-ask-none{padding:.7em 1em;color:var(--mta-muted,#a3adbd)}' +
    '.mta-mine{margin:.5em 0 0;font-size:.9rem;color:var(--mta-muted,#a3adbd)}.mta-mine a{margin-right:.2em}.mta-mine button{background:none;border:0;color:inherit;cursor:pointer;padding:0 .5em 0 .1em;font:inherit}' +
    '.mta-share{display:inline-block;margin-top:.35em;padding:.15em .6em;font:inherit;font-size:.78rem;color:var(--mta-muted,#a3adbd);background:transparent;border:1px solid var(--mta-border,#2a3550);border-radius:999px;cursor:pointer}' +
    '.mta-share:hover{color:var(--mta-accent,#8ab4f8);border-color:var(--mta-accent,#8ab4f8)}' +
    '.mta-save{margin:.2em 0 .8em;padding:.4em .9em;font:inherit;font-size:.9rem;color:inherit;background:transparent;border:1px solid var(--mta-border,#2a3550);border-radius:999px;cursor:pointer}' +
    '.mta-save[aria-pressed="true"]{border-color:#f6c945;color:#f6c945}';
  document.head.appendChild(css);

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function cleanUrl() {
    var u = new URL(location.href);
    u.searchParams.delete('mta_preview');
    return u.toString();
  }

  /* ---------- My stations ---------- */
  var KEY = 'mta-my-stations';
  function mine() { try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; } }
  function setMine(a) { try { localStorage.setItem(KEY, JSON.stringify(a.slice(0, 8))); } catch (e) {} drawMine(); }

  var mineBox = el('p', 'mta-mine');
  function drawMine() {
    var a = mine();
    mineBox.textContent = '';
    mineBox.hidden = !a.length;
    if (!a.length) return;
    mineBox.appendChild(document.createTextNode('My stations: '));
    a.forEach(function (s) {
      var l = el('a', '', s.n);
      l.href = s.u;
      mineBox.appendChild(l);
      var x = el('button', '', '×');
      x.type = 'button';
      x.setAttribute('aria-label', 'Remove ' + s.n);
      x.addEventListener('click', function () { setMine(mine().filter(function (y) { return y.u !== s.u; })); syncSave(); });
      mineBox.appendChild(x);
    });
  }

  // On a station page (/stations/<slug>/): a button to save it.
  var saveBtn = null;
  var here = location.pathname.match(/\/stations\/([^\/]+)\/?$/);
  var h1 = root.querySelector('h1');
  function syncSave() {
    if (!saveBtn) return;
    var on = mine().some(function (s) { return s.u === location.origin + location.pathname; });
    saveBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    saveBtn.textContent = on ? '★ In My stations' : '☆ Save to My stations';
  }
  if (here && h1) {
    saveBtn = el('button', 'mta-save');
    saveBtn.type = 'button';
    saveBtn.addEventListener('click', function () {
      var u = location.origin + location.pathname;
      var a = mine();
      if (a.some(function (s) { return s.u === u; })) a = a.filter(function (s) { return s.u !== u; });
      else a.unshift({ n: h1.textContent.replace(/\s*(subway station|station).*$/i, '').trim().slice(0, 60), u: u });
      setMine(a);
      syncSave();
    });
    h1.insertAdjacentElement('afterend', saveBtn);
    syncSave();
  }

  /* ---------- Search or ask ---------- */
  var box = el('div', 'mta-ask');
  var input = el('input');
  input.type = 'search';
  input.placeholder = 'Search stations, lines, buses, pages, or ask a question…';
  input.setAttribute('aria-label', 'Search the MTA data pages or ask a question');
  input.setAttribute('autocomplete', 'off');
  var res = el('div', 'mta-ask-res');
  res.hidden = true;
  res.setAttribute('role', 'listbox');
  box.appendChild(input);
  box.appendChild(res);
  box.appendChild(mineBox);
  var hero = root.querySelector('.mta-hero');
  if (hero) hero.insertAdjacentElement('afterend', box); else root.insertBefore(box, root.firstChild);
  drawMine();

  var idx = null, loading = null;
  var STOP = ' the a an is are was of to in on at how what which when where who why does do did i my me for much many with and or it this that there be can than most more ';
  var SYN = { street: 'st', avenue: 'av', ave: 'av', square: 'sq', boulevard: 'blvd', road: 'rd', parkway: 'pkwy', center: 'ctr', metronorth: 'metro', trains: 'train', stations: 'station', buses: 'bus', fares: 'fare', tolls: 'toll' };
  function words(t) {
    return String(t).toLowerCase().replace(/metro-north/g, 'metronorth').replace(/[^a-z0-9]+/g, ' ').trim().split(' ')
      .map(function (w) { return SYN[w] || w; }).filter(function (w) { return w && STOP.indexOf(' ' + w + ' ') < 0; });
  }
  function prep(j) {
    j.items.forEach(function (it) { it.t = words(it[0]); it.h = ' ' + words(it[0] + ' ' + it[2] + ' ' + it[3]).join(' ') + ' '; });
    j.faq.forEach(function (f) { f.t = words(f[0]); f.h = ' ' + words(f[0] + ' ' + f[1]).join(' ') + ' '; });
    return j;
  }
  function load() {
    if (!loading) {
      loading = fetch(cfg.index, { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (j) { idx = prep(j); })
        .catch(function () { loading = null; });
    }
    return loading;
  }
  function score(q, it) {
    var s = 0;
    for (var i = 0; i < q.length; i++) {
      var w = q[i], hit = 0;
      for (var k = 0; k < it.t.length; k++) {
        if (it.t[k] === w) { hit = 4; break; }
        if (it.t[k].indexOf(w) === 0) hit = Math.max(hit, 3);
      }
      if (!hit && it.h.indexOf(' ' + w) >= 0) hit = 1;
      if (!hit) return 0; // every word has to be somewhere
      s += hit;
    }
    return s + (it.t.length ? 4 / it.t.length : 0); // shorter titles first when equal
  }
  function scoreFaq(q, f) {
    var s = 0, n = 0;
    q.forEach(function (w) {
      if (f.t.indexOf(w) >= 0) { s += 3; n++; } else if (f.h.indexOf(' ' + w) >= 0) { s += 1; n++; }
    });
    return n >= Math.max(1, Math.ceil(q.length * 0.6)) ? s : 0;
  }
  var cur = -1;
  function search() {
    var q = words(input.value);
    res.textContent = '';
    cur = -1;
    if (!q.length || !idx) { res.hidden = true; return; }
    var items = idx.items.map(function (it) { return [score(q, it), it]; }).filter(function (x) { return x[0] > 0; })
      .sort(function (a, b) { return b[0] - a[0]; }).slice(0, 7);
    var question = /\?$|^(how|what|which|when|where|who|why|is|are|does|do|can)\b/i.test(input.value.trim()) || q.length >= 3;
    var faqs = idx.faq.map(function (f) { return [scoreFaq(q, f), f]; }).filter(function (x) { return x[0] > 0; })
      .sort(function (a, b) { return b[0] - a[0]; }).slice(0, question ? 3 : 1);
    if (!question && faqs.length && items.length && faqs[0][0] < 6) faqs = [];
    var list = question ? faqs.concat(items) : items.concat(faqs);
    list.forEach(function (x) {
      var it = x[1], a = el('a');
      a.setAttribute('role', 'option');
      if (it.length === 3) { // an FAQ answer: [question, answer, url]; other results have 4 parts
        a.href = it[2];
        a.className = 'ans';
        a.appendChild(el('span', 'k', 'Answer'));
        a.appendChild(el('b', '', it[0]));
        a.appendChild(el('small', '', it[1].length > 300 ? it[1].slice(0, 297) + '…' : it[1]));
      } else {
        a.href = it[1];
        a.appendChild(el('span', 'k', it[2]));
        a.appendChild(document.createTextNode(it[0]));
        if (it[3] && it[2] !== 'Subway line') a.appendChild(el('small', '', it[3].split(' · ').slice(0, 2).join(' · ')));
      }
      res.appendChild(a);
    });
    if (!list.length) res.appendChild(el('div', 'mta-ask-none', 'Nothing found. Try a station name, a line like "Q train", or a question like "how much is the toll".'));
    res.hidden = false;
  }
  var t = null;
  input.addEventListener('focus', load);
  input.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () { (idx ? Promise.resolve() : load()).then(search); }, 120);
  });
  input.addEventListener('keydown', function (e) {
    var links = res.querySelectorAll('a');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!links.length) return;
      e.preventDefault();
      if (cur >= 0) links[cur].classList.remove('on');
      cur = (cur + (e.key === 'ArrowDown' ? 1 : links.length - 1)) % links.length;
      links[cur].classList.add('on');
      links[cur].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && links.length) {
      e.preventDefault();
      location.href = links[Math.max(0, cur)].href;
    } else if (e.key === 'Escape') {
      res.hidden = true;
    }
  });
  document.addEventListener('pointerdown', function (e) { if (!box.contains(e.target)) res.hidden = true; });

  /* ---------- Share buttons on the top numbers ---------- */
  root.querySelectorAll('.mta-hero .mta-tile').forEach(function (tile) {
    var big = tile.querySelector('strong'), small = tile.querySelector('span');
    if (!big || !small || !big.textContent.trim()) return;
    var b = el('button', 'mta-share', 'Share');
    b.type = 'button';
    b.setAttribute('aria-label', 'Share this number: ' + big.textContent + ' ' + small.textContent);
    b.addEventListener('click', function () {
      var title = (h1 ? h1.textContent : document.title).trim();
      var text = big.textContent.trim() + ' ' + small.textContent.trim() + ' (' + title + ')';
      var url = cleanUrl();
      if (navigator.share) {
        navigator.share({ title: title, text: text, url: url }).catch(function () {});
        return;
      }
      var done = function () { b.textContent = 'Copied!'; setTimeout(function () { b.textContent = 'Share'; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(text + ' ' + url).then(done, function () {});
    });
    tile.appendChild(b);
  });
})();
