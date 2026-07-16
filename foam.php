<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('foam');

$pageName = 'foam';
$pageTitle = 'FOAM - PULSE';
$pageDesc = '2D泡沫シミュレーション。表面張力がセル境界を曲げ、フォン・ノイマンの法則で泡が消え、トポロジーが組み変わる。';
$pageCanonical = 'https://pulse.subara3.com/foam.php';
$extraStyle = '
body{background:#0a0a0f;margin:0;overflow:hidden}
canvas{position:fixed;top:0;left:0;width:100%;height:100%}
.hud{position:fixed;top:3.2rem;left:1rem;z-index:999;
  font:.7rem "JetBrains Mono","Fira Code",monospace;color:rgba(255,255,255,.56);
  display:flex;flex-direction:column;gap:.18rem;pointer-events:none}
.hud b{color:#00f5d4;min-width:2.5em;display:inline-block;text-align:right}
@media(max-width:768px){.hud{top:2.8rem;font-size:.6rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>CELLS <b id="hCells">0</b></div>
  <div>T1 <b id="hT1">0</b></div>
  <div>T2 <b id="hT2">0</b></div>
  <div>STEP <b id="hStep">0</b></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'pop'],
  ['type' => 'drag', 'label' => 'push'],
  ['type' => 'right', 'label' => 'inflate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">TENSION</span>
    <input type="range" id="sTension" min="0.01" max="0.15" step="0.005" value="0.06">
    <span class="slider-value" id="vTension">0.06</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">PRESSURE</span>
    <input type="range" id="sPressure" min="0" max="0.08" step="0.002" value="0.02">
    <span class="slider-value" id="vPressure">0.02</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sSpeed" min="1" max="8" step="1" value="3">
    <span class="slider-value" id="vSpeed">3</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnReset">RESET</button>
    <button class="control-btn" id="btnEdges">EDGES</button>
    <button class="control-btn" id="btnPressure">P-MAP</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'),
      X = C.getContext('2d'),
      W, H, sites, edges, cells, step = 0, t1Count = 0, t2Count = 0,
      tension = 0.06, pressure = 0.02, speed = 3,
      showEdges = true, showPressure = false,
      dragging = false, dragX = 0, dragY = 0,
      animId, running = true;

  var ACCENT = [0, 245, 212],
      SUB    = [123, 44, 191],
      BG     = [10, 10, 15];

  function rgba(c, a) {
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
  }

  function hsl2rgb(h, s, l) {
    h = ((h % 360) + 360) % 360;
    s /= 100; l /= 100;
    var c = (1 - Math.abs(2*l - 1)) * s;
    var x = c * (1 - Math.abs((h/60)%2 - 1));
    var m = l - c/2;
    var r, g, b;
    if (h < 60) { r=c; g=x; b=0; }
    else if (h < 120) { r=x; g=c; b=0; }
    else if (h < 180) { r=0; g=c; b=x; }
    else if (h < 240) { r=0; g=x; b=c; }
    else if (h < 300) { r=x; g=0; b=c; }
    else { r=c; g=0; b=x; }
    return [Math.round((r+m)*255), Math.round((g+m)*255), Math.round((b+m)*255)];
  }

  function mix(a, b, t) {
    return [
      a[0] + (b[0]-a[0])*t,
      a[1] + (b[1]-a[1])*t,
      a[2] + (b[2]-a[2])*t
    ];
  }

  function dist(a, b) {
    var dx = a.x - b.x, dy = a.y - b.y;
    return Math.sqrt(dx*dx + dy*dy);
  }

  function cellColor(idx, n) {
    var hue = 175 + (idx * 137.508) % 100;
    if (hue > 275) hue = 175 + (hue - 275);
    return hsl2rgb(hue, 55, 22);
  }

  function generateSites(count) {
    var pts = [];
    var margin = 30;
    for (var i = 0; i < count; i++) {
      pts.push({
        x: margin + Math.random() * (W - 2*margin),
        y: margin + Math.random() * (H - 2*margin),
        vx: 0, vy: 0,
        area0: 0, alive: true
      });
    }
    return pts;
  }

  function computeVoronoi(pts) {
    var N = pts.length;
    var cellPolygons = new Array(N);
    for (var i = 0; i < N; i++) cellPolygons[i] = null;

    var edgeList = [];
    var bnd = [[-1, -1], [W+1, -1], [W+1, H+1], [-1, H+1]];

    for (var i = 0; i < N; i++) {
      if (!pts[i].alive) continue;
      var poly = [bnd[0].slice(), bnd[1].slice(), bnd[2].slice(), bnd[3].slice()];

      for (var j = 0; j < N; j++) {
        if (i === j || !pts[j].alive) continue;
        poly = clipPolygonByBisector(poly, pts[i], pts[j]);
        if (!poly || poly.length < 3) { poly = null; break; }
      }
      cellPolygons[i] = poly;
    }

    var edgeMap = {};
    for (var i = 0; i < N; i++) {
      if (!cellPolygons[i]) continue;
      var p = cellPolygons[i];
      for (var k = 0; k < p.length; k++) {
        var k2 = (k+1) % p.length;
        var key = edgeKey(p[k], p[k2]);
        if (!edgeMap[key]) {
          edgeMap[key] = { x1: p[k][0], y1: p[k][1], x2: p[k2][0], y2: p[k2][1], cells: [i] };
        } else {
          edgeMap[key].cells.push(i);
        }
      }
    }

    edgeList = [];
    for (var k in edgeMap) {
      edgeList.push(edgeMap[k]);
    }

    return { cells: cellPolygons, edges: edgeList };
  }

  function edgeKey(a, b) {
    var ax = Math.round(a[0]*10), ay = Math.round(a[1]*10);
    var bx = Math.round(b[0]*10), by = Math.round(b[1]*10);
    if (ax < bx || (ax === bx && ay < by)) {
      return ax + ',' + ay + '|' + bx + ',' + by;
    }
    return bx + ',' + by + '|' + ax + ',' + ay;
  }

  function clipPolygonByBisector(poly, si, sj) {
    var mx = (si.x + sj.x) * 0.5;
    var my = (si.y + sj.y) * 0.5;
    var dx = sj.x - si.x;
    var dy = sj.y - si.y;

    var out = [];
    var n = poly.length;
    for (var k = 0; k < n; k++) {
      var cur = poly[k];
      var nxt = poly[(k+1) % n];
      var dc = (cur[0] - mx) * dx + (cur[1] - my) * dy;
      var dn = (nxt[0] - mx) * dx + (nxt[1] - my) * dy;

      if (dc <= 0) out.push(cur);
      if ((dc < 0 && dn > 0) || (dc > 0 && dn < 0)) {
        var t = dc / (dc - dn);
        out.push([cur[0] + t*(nxt[0]-cur[0]), cur[1] + t*(nxt[1]-cur[1])]);
      }
    }
    return out.length >= 3 ? out : null;
  }

  function polygonArea(poly) {
    if (!poly || poly.length < 3) return 0;
    var a = 0;
    for (var i = 0, n = poly.length; i < n; i++) {
      var j = (i+1) % n;
      a += poly[i][0] * poly[j][1];
      a -= poly[j][0] * poly[i][1];
    }
    return Math.abs(a) * 0.5;
  }

  function polygonCentroid(poly) {
    if (!poly || poly.length < 3) return null;
    var cx = 0, cy = 0, a = 0;
    for (var i = 0, n = poly.length; i < n; i++) {
      var j = (i+1) % n;
      var f = poly[i][0]*poly[j][1] - poly[j][0]*poly[i][1];
      cx += (poly[i][0] + poly[j][0]) * f;
      cy += (poly[i][1] + poly[j][1]) * f;
      a += f;
    }
    a *= 0.5;
    if (Math.abs(a) < 1e-8) return null;
    cx /= (6 * a);
    cy /= (6 * a);
    return { x: cx, y: cy };
  }

  function lloydRelax(pts, voro) {
    var damping = 0.3;
    for (var i = 0; i < pts.length; i++) {
      if (!pts[i].alive || !voro.cells[i]) continue;
      var c = polygonCentroid(voro.cells[i]);
      if (!c) continue;
      var dx = (c.x - pts[i].x) * damping * tension * 10;
      var dy = (c.y - pts[i].y) * damping * tension * 10;
      pts[i].vx += dx;
      pts[i].vy += dy;
    }
  }

  function applyPressure(pts, voro) {
    for (var i = 0; i < pts.length; i++) {
      if (!pts[i].alive || !voro.cells[i]) continue;
      var area = polygonArea(voro.cells[i]);
      if (pts[i].area0 === 0) pts[i].area0 = area;
      var diff = (pts[i].area0 - area) / Math.max(pts[i].area0, 1);
      var c = polygonCentroid(voro.cells[i]);
      if (!c) continue;
      var fx = (pts[i].x - c.x) * diff * pressure * 2;
      var fy = (pts[i].y - c.y) * diff * pressure * 2;
      pts[i].vx += fx;
      pts[i].vy += fy;
    }
  }

  function integrate(pts) {
    var friction = 0.88;
    var margin = 15;
    for (var i = 0; i < pts.length; i++) {
      if (!pts[i].alive) continue;
      pts[i].vx *= friction;
      pts[i].vy *= friction;
      pts[i].x += pts[i].vx;
      pts[i].y += pts[i].vy;
      if (pts[i].x < margin) { pts[i].x = margin; pts[i].vx *= -0.5; }
      if (pts[i].x > W - margin) { pts[i].x = W - margin; pts[i].vx *= -0.5; }
      if (pts[i].y < margin) { pts[i].y = margin; pts[i].vy *= -0.5; }
      if (pts[i].y > H - margin) { pts[i].y = H - margin; pts[i].vy *= -0.5; }
    }
  }

  function checkT2(pts, voro) {
    var minArea = W * H * 0.0003;
    for (var i = pts.length - 1; i >= 0; i--) {
      if (!pts[i].alive || !voro.cells[i]) continue;
      var area = polygonArea(voro.cells[i]);
      if (area < minArea && pts.filter(function(p){return p.alive;}).length > 8) {
        pts[i].alive = false;
        t2Count++;
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
    }
  }

  function pushForce(pts, mx, my, radius, strength) {
    for (var i = 0; i < pts.length; i++) {
      if (!pts[i].alive) continue;
      var dx = pts[i].x - mx;
      var dy = pts[i].y - my;
      var d = Math.sqrt(dx*dx + dy*dy);
      if (d < radius && d > 1) {
        var f = (1 - d/radius) * strength;
        pts[i].vx += (dx/d) * f;
        pts[i].vy += (dy/d) * f;
      }
    }
  }

  function popCell(pts, voro, mx, my) {
    var best = -1, bestD = Infinity;
    for (var i = 0; i < pts.length; i++) {
      if (!pts[i].alive) continue;
      var d = Math.sqrt((pts[i].x-mx)*(pts[i].x-mx) + (pts[i].y-my)*(pts[i].y-my));
      if (d < bestD) { bestD = d; best = i; }
    }
    if (best >= 0 && pts.filter(function(p){return p.alive;}).length > 8) {
      pts[best].alive = false;
      t2Count++;
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  }

  function inflateAt(pts, mx, my) {
    var newPt = {
      x: mx + (Math.random()-0.5)*20,
      y: my + (Math.random()-0.5)*20,
      vx: 0, vy: 0,
      area0: 0, alive: true
    };
    pts.push(newPt);
    t1Count++;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function render(voro) {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var aliveCount = 0;
    for (var i = 0; i < sites.length; i++) {
      if (!sites[i].alive || !voro.cells[i]) continue;
      aliveCount++;
      var poly = voro.cells[i];
      var col;
      if (showPressure) {
        var area = polygonArea(poly);
        var target = sites[i].area0 || area;
        var ratio = area / Math.max(target, 1);
        var t = Math.max(0, Math.min(1, (ratio - 0.5) * 2));
        col = mix(SUB, ACCENT, t);
      } else {
        col = cellColor(i, sites.length);
      }

      X.beginPath();
      X.moveTo(poly[0][0], poly[0][1]);
      for (var k = 1; k < poly.length; k++) {
        X.lineTo(poly[k][0], poly[k][1]);
      }
      X.closePath();
      X.fillStyle = rgba(col, 0.35);
      X.fill();
    }

    if (showEdges) {
      X.strokeStyle = rgba(ACCENT, 0.45);
      X.lineWidth = 1.2;
      for (var i = 0; i < sites.length; i++) {
        if (!sites[i].alive || !voro.cells[i]) continue;
        var poly = voro.cells[i];
        X.beginPath();
        X.moveTo(poly[0][0], poly[0][1]);
        for (var k = 1; k < poly.length; k++) {
          X.lineTo(poly[k][0], poly[k][1]);
        }
        X.closePath();
        X.stroke();
      }
    }

    for (var i = 0; i < sites.length; i++) {
      if (!sites[i].alive) continue;
      X.beginPath();
      X.arc(sites[i].x, sites[i].y, 2, 0, Math.PI*2);
      X.fillStyle = rgba(ACCENT, 0.6);
      X.fill();
    }

    return aliveCount;
  }

  function updateHUD(cellCount) {
    document.getElementById('hCells').textContent = cellCount;
    document.getElementById('hT1').textContent = t1Count;
    document.getElementById('hT2').textContent = t2Count;
    document.getElementById('hStep').textContent = step;
  }

  function simulate() {
    var voro = computeVoronoi(sites);
    lloydRelax(sites, voro);
    applyPressure(sites, voro);
    integrate(sites);
    if (step % 10 === 0) checkT2(sites, voro);
    step++;
    return voro;
  }

  function draw() {
    var voro;
    for (var s = 0; s < speed; s++) {
      voro = simulate();
    }
    if (dragging) {
      pushForce(sites, dragX, dragY, 120, 2.5);
    }
    var cellCount = render(voro);
    if (step % 6 === 0) updateHUD(cellCount);
    animId = requestAnimationFrame(draw);
  }

  function init() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    var area = W * H;
    var count = Math.max(30, Math.min(120, Math.round(area / 8000)));
    sites = generateSites(count);
    step = 0; t1Count = 0; t2Count = 0;

    var voro = computeVoronoi(sites);
    for (var i = 0; i < sites.length; i++) {
      if (voro.cells[i]) {
        sites[i].area0 = polygonArea(voro.cells[i]);
      }
    }

    for (var r = 0; r < 20; r++) {
      voro = computeVoronoi(sites);
      lloydRelax(sites, voro);
      integrate(sites);
    }
    voro = computeVoronoi(sites);
    for (var i = 0; i < sites.length; i++) {
      if (voro.cells[i]) {
        sites[i].area0 = polygonArea(voro.cells[i]);
      }
      sites[i].vx = 0;
      sites[i].vy = 0;
    }
  }

  function getPos(e) {
    var r = C.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: (t.clientX - r.left) * (W/r.width), y: (t.clientY - r.top) * (H/r.height) };
  }

  C.addEventListener('mousedown', function(e) {
    var p = getPos(e);
    if (e.button === 2) {
      inflateAt(sites, p.x, p.y);
    } else {
      popCell(sites, null, p.x, p.y);
      dragging = true;
      dragX = p.x; dragY = p.y;
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var p = getPos(e);
    dragX = p.x; dragY = p.y;
  });

  C.addEventListener('mouseup', function() { dragging = false; });
  C.addEventListener('mouseleave', function() { dragging = false; });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchTimer = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var p = getPos(e);
    dragging = true;
    dragX = p.x; dragY = p.y;
    touchTimer = setTimeout(function() {
      inflateAt(sites, p.x, p.y);
      touchTimer = null;
    }, 400);
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (touchTimer) { clearTimeout(touchTimer); touchTimer = null; }
    var p = getPos(e);
    dragX = p.x; dragY = p.y;
    if (typeof SFX !== 'undefined') SFX.play('move', 120);
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (touchTimer) {
      clearTimeout(touchTimer);
      touchTimer = null;
      popCell(sites, null, dragX, dragY);
    }
    dragging = false;
  });

  // Controls
  var sl = document.getElementById('sTension');
  sl.addEventListener('input', function() {
    tension = parseFloat(this.value);
    document.getElementById('vTension').textContent = tension.toFixed(3);
    if (typeof SFX !== 'undefined') SFX.play('toggle', 80);
  });

  var sp = document.getElementById('sPressure');
  sp.addEventListener('input', function() {
    pressure = parseFloat(this.value);
    document.getElementById('vPressure').textContent = pressure.toFixed(3);
    if (typeof SFX !== 'undefined') SFX.play('toggle', 80);
  });

  var ss = document.getElementById('sSpeed');
  ss.addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('vSpeed').textContent = speed;
    if (typeof SFX !== 'undefined') SFX.play('toggle', 80);
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btnEdges').addEventListener('click', function() {
    showEdges = !showEdges;
    this.classList.toggle('active', showEdges);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btnEdges').classList.add('active');

  document.getElementById('btnPressure').addEventListener('click', function() {
    showPressure = !showPressure;
    this.classList.toggle('active', showPressure);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  window.addEventListener('resize', function() {
    init();
  });

  init();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
