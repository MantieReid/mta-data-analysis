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
    find.innerHTML = '<div class="mta-find"><input type="search" class="mta-find-input" placeholder="e.g. M15, Bx12, Fordham, Flatbush…" ' +
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
})();
