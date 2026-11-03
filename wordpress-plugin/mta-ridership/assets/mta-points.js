/* A map of places (the E-ZPass retailers page): dots from data-points = [[lat, lon, name, address], ...],
   drawn with Leaflet when the map is near the screen, and a search box that filters the list below it. */
(function () {
  var root = document.querySelector('.mta-ezpass');
  if (!root) return;
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var el = root.querySelector('[data-points]');
  if (el && window.L) {
    var draw = function () {
      var pts = JSON.parse(el.getAttribute('data-points') || '[]');
      var map = L.map(el, { preferCanvas: true, scrollWheelZoom: false });
      // Esri's dark gray canvas: free to use with attribution, no API key (same as the other maps).
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
      }).addTo(map);
      var group = L.featureGroup();
      pts.forEach(function (p) {
        L.circleMarker([p[0], p[1]], { radius: 6, weight: 1, color: '#fff', fillColor: '#8ab4f8', fillOpacity: 0.9 })
          .bindPopup('<strong>' + esc(p[2]) + '</strong><br>' + esc(p[3])).addTo(group);
      });
      group.addTo(map);
      // Start on New York City, where most of the visitors are; zoom out to see the whole state.
      map.setView([40.73, -73.95], 10);
    };
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); draw(); } }, { rootMargin: '500px' });
      io.observe(el);
    } else {
      draw();
    }
  }

  var box = root.querySelector('.mta-points-filter');
  if (box) {
    var rows = root.querySelectorAll('.mta-table tbody tr');
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      rows.forEach(function (r) { r.style.display = !q || r.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
    });
  }
})();
