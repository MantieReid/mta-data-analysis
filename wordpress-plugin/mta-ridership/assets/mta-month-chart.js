/* Month column charts (mta_more_month_chart): tap, hover or use the arrow keys on a chart to see a bar's month and
   number in a small label above it. Each bar carries data-l (month) and data-v (number as shown). */
(function () {
  var charts = document.querySelectorAll('.mta-mchart');
  if (!charts.length) return;
  var css = document.createElement('style');
  css.textContent = '.mta-mchart{position:relative;touch-action:pan-y}.mta-mchart svg{display:block;cursor:pointer}' +
    '.mta-mchart svg:focus{outline:2px solid #8ab4f8;outline-offset:4px;border-radius:4px}' +
    '.mta-mchart.is-on rect{opacity:.45}.mta-mchart.is-on rect.is-pick{opacity:1;fill:#c6dafc}' +
    '.mta-mtip{position:absolute;top:0;transform:translate(-50%,-110%);background:#0b1220;border:1px solid #8ab4f8;color:#e6edf6;' +
    'border-radius:8px;padding:4px 10px;font-size:14px;line-height:1.35;white-space:nowrap;pointer-events:none;display:none;z-index:2}' +
    '.mta-mtip b{color:#8ab4f8}.mta-mhint{font-size:13px;color:#a3adbd;margin:6px 0 0}';
  document.head.appendChild(css);

  charts.forEach(function (box) {
    var svg = box.querySelector('svg');
    var bars = Array.prototype.slice.call(svg.querySelectorAll('rect[data-l]'));
    if (!bars.length) return;
    // The label replaces the browser's own hover text.
    bars.forEach(function (b) { var t = b.querySelector('title'); if (t) t.remove(); });
    var tip = document.createElement('div');
    tip.className = 'mta-mtip';
    tip.setAttribute('role', 'status');
    box.appendChild(tip);
    var hint = document.createElement('p');
    hint.className = 'mta-mhint';
    hint.textContent = 'Tap or hover a bar to see its number.';
    box.appendChild(hint);
    svg.setAttribute('tabindex', '0');
    var cur = -1;

    function show(i) {
      i = Math.max(0, Math.min(bars.length - 1, i));
      if (cur >= 0) bars[cur].classList.remove('is-pick');
      cur = i;
      var b = bars[i];
      b.classList.add('is-pick');
      box.classList.add('is-on');
      tip.innerHTML = '';
      tip.appendChild(document.createTextNode(b.getAttribute('data-l') + ': '));
      var v = document.createElement('b');
      v.textContent = b.getAttribute('data-v');
      tip.appendChild(v);
      tip.style.display = 'block';
      // Centre the label over the bar, kept inside the chart.
      var r = b.getBoundingClientRect(), br = box.getBoundingClientRect(), half = tip.offsetWidth / 2;
      var x = Math.max(half, Math.min(br.width - half, r.left + r.width / 2 - br.left));
      tip.style.left = x + 'px';
      tip.style.top = Math.max(tip.offsetHeight + 4, r.top - br.top) + 'px';
    }
    function hide() {
      if (cur >= 0) bars[cur].classList.remove('is-pick');
      cur = -1;
      box.classList.remove('is-on');
      tip.style.display = 'none';
    }
    // Bars share the width equally, so the pointer's position across the chart picks the bar.
    function at(e) {
      var r = svg.getBoundingClientRect();
      return Math.floor((e.clientX - r.left) / r.width * bars.length);
    }
    svg.addEventListener('pointermove', function (e) { if (e.pointerType === 'mouse') show(at(e)); });
    svg.addEventListener('pointerdown', function (e) { show(at(e)); });
    svg.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') hide(); });
    svg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        show(cur < 0 ? bars.length - 1 : cur + (e.key === 'ArrowRight' ? 1 : -1));
      } else if (e.key === 'Escape') {
        hide();
      }
    });
    svg.addEventListener('blur', hide);
    // A tap anywhere else on the page closes the label.
    document.addEventListener('pointerdown', function (e) { if (cur >= 0 && !svg.contains(e.target)) hide(); });
  });
})();
