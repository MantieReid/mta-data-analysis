/* Click a column heading to sort a table.mta-sortable. A heading with data-sort="num" sorts by each cell's
   data-v (or its text as a number); data-sort="text" sorts alphabetically; data-first="asc" starts a number column smallest first. Click again to reverse. */
(function () {
  function key(row, i, num) {
    var c = row.cells[i];
    if (!c) return num ? -Infinity : '';
    var v = c.getAttribute('data-v');
    if (v === null) v = c.textContent.trim();
    if (!num) return v.toLowerCase();
    var n = parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
    return isNaN(n) ? -Infinity : n;
  }
  document.querySelectorAll('table.mta-sortable').forEach(function (t) {
    var heads = t.tHead ? t.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(heads, function (th, i) {
      var type = th.getAttribute('data-sort');
      if (!type) return;
      th.tabIndex = 0;
      th.setAttribute('role', 'button');
      function sort() {
        var now = th.getAttribute('aria-sort');
        var asc = now ? now === 'descending' : (type === 'text' || th.getAttribute('data-first') === 'asc'); // numbers start biggest first, names A to Z
        Array.prototype.forEach.call(heads, function (h) { h.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var body = t.tBodies[0], rows = Array.prototype.slice.call(body.rows), num = type === 'num';
        rows.sort(function (a, b) {
          var x = key(a, i, num), y = key(b, i, num);
          return (x < y ? -1 : x > y ? 1 : 0) * (asc ? 1 : -1);
        });
        rows.forEach(function (r) { body.appendChild(r); });
      }
      th.addEventListener('click', sort);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
    });
  });
})();
