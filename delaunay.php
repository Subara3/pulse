<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('delaunay');

$pageName = 'delaunay';
$pageTitle = 'DELAUNAY - PULSE';
$pageDesc = 'ドロネー三角形分割。点を配置すると空外接円条件を満たす三角形メッシュが形成される。ボロノイ図との双対関係を可視化。';
$pageCanonical = 'https://pulse.subara3.com/delaunay.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  POINTS: <span class="v" id="hud-pts">0</span><br>
  TRIANGLES: <span class="v" id="hud-tri">0</span><br>
  EDGES: <span class="v" id="hud-edg">0</span>
</div>

<div id="info">
  <span class="v">delaunay triangulation</span><br>
  &forall; &triangle;: empty circumcircle
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn" id="btn-circ">CIRCUMCIRCLES</button>
    <button class="control-btn" id="btn-vor">VORONOI</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-drift">DRIFT</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-clear">CLEAR</button>
    <button class="control-btn" id="btn-rand">RANDOMIZE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  var pts = [];
  var tris = [];
  var showCirc = false;
  var showVor = false;
  var drifting = false;
  var driftD = [];

  function addDriftData() {
    driftD.push({
      fx: 0.12 + Math.random() * 0.4,
      fy: 0.12 + Math.random() * 0.4,
      px: Math.random() * 6.2832,
      py: Math.random() * 6.2832,
      ax: 0.3 + Math.random() * 0.6,
      ay: 0.3 + Math.random() * 0.6
    });
  }

  function ccircle(ax, ay, bx, by, cx, cy) {
    var D = 2 * (ax * (by - cy) + bx * (cy - ay) + cx * (ay - by));
    if (Math.abs(D) < 1e-10) return null;
    var a2 = ax * ax + ay * ay;
    var b2 = bx * bx + by * by;
    var c2 = cx * cx + cy * cy;
    var ux = (a2 * (by - cy) + b2 * (cy - ay) + c2 * (ay - by)) / D;
    var uy = (a2 * (cx - bx) + b2 * (ax - cx) + c2 * (bx - ax)) / D;
    var dx = ax - ux, dy = ay - uy;
    return { x: ux, y: uy, r: Math.sqrt(dx * dx + dy * dy) };
  }

  function triangulate() {
    var n = pts.length;
    if (n < 3) { tris = []; return; }

    var mnx = pts[0].x, mxx = pts[0].x, mny = pts[0].y, mxy = pts[0].y;
    for (var i = 1; i < n; i++) {
      if (pts[i].x < mnx) mnx = pts[i].x;
      if (pts[i].x > mxx) mxx = pts[i].x;
      if (pts[i].y < mny) mny = pts[i].y;
      if (pts[i].y > mxy) mxy = pts[i].y;
    }
    var ddx = (mxx - mnx) || 1, ddy = (mxy - mny) || 1;
    var dm = Math.max(ddx, ddy) * 10;
    var mx = (mnx + mxx) / 2, my = (mny + mxy) / 2;

    var all = [];
    for (var i = 0; i < n; i++) all.push(pts[i]);
    all.push({ x: mx - dm, y: my - dm });
    all.push({ x: mx + dm, y: my - dm });
    all.push({ x: mx, y: my + dm });

    var T = [{ a: n, b: n + 1, c: n + 2 }];

    for (var i = 0; i < n; i++) {
      var px = pts[i].x, py = pts[i].y;
      var bad = [];

      for (var j = 0; j < T.length; j++) {
        var t = T[j];
        var c = ccircle(all[t.a].x, all[t.a].y, all[t.b].x, all[t.b].y, all[t.c].x, all[t.c].y);
        if (c) {
          var ex = px - c.x, ey = py - c.y;
          if (ex * ex + ey * ey < c.r * c.r + 0.01) bad.push(j);
        }
      }

      var poly = [];
      for (var j = 0; j < bad.length; j++) {
        var bt = T[bad[j]];
        var ed = [[bt.a, bt.b], [bt.b, bt.c], [bt.c, bt.a]];
        for (var k = 0; k < 3; k++) {
          var sh = false;
          for (var l = 0; l < bad.length; l++) {
            if (l === j) continue;
            var bt2 = T[bad[l]];
            var e2 = [[bt2.a, bt2.b], [bt2.b, bt2.c], [bt2.c, bt2.a]];
            for (var m = 0; m < 3; m++) {
              if ((ed[k][0] === e2[m][0] && ed[k][1] === e2[m][1]) ||
                  (ed[k][0] === e2[m][1] && ed[k][1] === e2[m][0])) {
                sh = true; break;
              }
            }
            if (sh) break;
          }
          if (!sh) poly.push(ed[k]);
        }
      }

      bad.sort(function(a, b) { return b - a; });
      for (var j = 0; j < bad.length; j++) T.splice(bad[j], 1);
      for (var j = 0; j < poly.length; j++) T.push({ a: poly[j][0], b: poly[j][1], c: i });
    }

    tris = [];
    for (var i = 0; i < T.length; i++) {
      var t = T[i];
      if (t.a < n && t.b < n && t.c < n) tris.push(t);
    }
  }

  function countEdges() {
    var es = {}, ec = 0;
    for (var i = 0; i < tris.length; i++) {
      var t = tris[i];
      var edges = [[t.a, t.b], [t.b, t.c], [t.c, t.a]];
      for (var j = 0; j < 3; j++) {
        var a = Math.min(edges[j][0], edges[j][1]);
        var b = Math.max(edges[j][0], edges[j][1]);
        var k = a + ',' + b;
        if (!es[k]) { es[k] = 1; ec++; }
      }
    }
    return ec;
  }

  function updateHUD() {
    document.getElementById('hud-pts').textContent = pts.length;
    document.getElementById('hud-tri').textContent = tris.length;
    document.getElementById('hud-edg').textContent = countEdges();
  }

  function randomize(count) {
    pts = [];
    driftD = [];
    var mg = 80;
    for (var i = 0; i < count; i++) {
      pts.push({
        x: mg + Math.random() * (W - 2 * mg),
        y: mg + Math.random() * (H - 2 * mg)
      });
      addDriftData();
    }
    triangulate();
    updateHUD();
  }

  randomize(30);

  var dragIdx = -1, isDrag = false, touchId = null;

  function nearest(x, y, maxDist) {
    var best = -1, bd = maxDist * maxDist;
    for (var i = 0; i < pts.length; i++) {
      var dx = pts[i].x - x, dy = pts[i].y - y, d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function tooClose(x, y) {
    for (var i = 0; i < pts.length; i++) {
      var dx = pts[i].x - x, dy = pts[i].y - y;
      if (dx * dx + dy * dy < 100) return true;
    }
    return false;
  }

  C.addEventListener('mousedown', function(e) {
    var x = e.clientX, y = e.clientY;
    if (e.button === 2) {
      var idx = nearest(x, y, 30);
      if (idx >= 0) {
        pts.splice(idx, 1);
        driftD.splice(idx, 1);
        triangulate();
        updateHUD();
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    var idx = nearest(x, y, 20);
    if (idx >= 0) {
      dragIdx = idx;
      isDrag = true;
    } else if (!tooClose(x, y)) {
      pts.push({ x: x, y: y });
      addDriftData();
      triangulate();
      updateHUD();
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (isDrag && dragIdx >= 0) {
      pts[dragIdx].x = e.clientX;
      pts[dragIdx].y = e.clientY;
      triangulate();
      updateHUD();
    } else {
      C.style.cursor = nearest(e.clientX, e.clientY, 20) >= 0 ? 'grab' : 'crosshair';
    }
  });

  window.addEventListener('mouseup', function() {
    isDrag = false;
    dragIdx = -1;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], x = t.clientX, y = t.clientY;
    var idx = nearest(x, y, 25);
    if (idx >= 0) {
      dragIdx = idx;
      isDrag = true;
      touchId = t.identifier;
    } else if (!tooClose(x, y)) {
      pts.push({ x: x, y: y });
      addDriftData();
      triangulate();
      updateHUD();
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDrag || dragIdx < 0) return;
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        pts[dragIdx].x = e.touches[i].clientX;
        pts[dragIdx].y = e.touches[i].clientY;
        triangulate();
        updateHUD();
        break;
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      isDrag = false;
      dragIdx = -1;
      touchId = null;
    }
  });

  document.getElementById('btn-circ').addEventListener('click', function() {
    showCirc = !showCirc;
    this.classList.toggle('active', showCirc);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-vor').addEventListener('click', function() {
    showVor = !showVor;
    this.classList.toggle('active', showVor);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-drift').addEventListener('click', function() {
    drifting = !drifting;
    this.classList.toggle('active', drifting);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    pts = [];
    driftD = [];
    triangulate();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btn-rand').addEventListener('click', function() {
    randomize(30);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var time = 0;

  function draw() {
    requestAnimationFrame(draw);
    time += 0.016;

    if (drifting && !isDrag) {
      var mg = 60;
      for (var i = 0; i < pts.length; i++) {
        var d = driftD[i];
        pts[i].x += Math.sin(time * d.fx + d.px) * d.ax;
        pts[i].y += Math.cos(time * d.fy + d.py) * d.ay;
        if (pts[i].x < mg) pts[i].x = mg;
        if (pts[i].x > W - mg) pts[i].x = W - mg;
        if (pts[i].y < mg) pts[i].y = mg;
        if (pts[i].y > H - mg) pts[i].y = H - mg;
      }
      triangulate();
      updateHUD();
    }

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Triangle fills (area-based subtle brightness)
    for (var i = 0; i < tris.length; i++) {
      var t = tris[i];
      var pa = pts[t.a], pb = pts[t.b], pc = pts[t.c];
      var area = Math.abs((pb.x - pa.x) * (pc.y - pa.y) - (pc.x - pa.x) * (pb.y - pa.y)) / 2;
      var bright = 0.01 + 1 / (1 + area / 3000) * 0.04;
      ctx.fillStyle = 'rgba(0,245,212,' + bright + ')';
      ctx.beginPath();
      ctx.moveTo(pa.x, pa.y);
      ctx.lineTo(pb.x, pb.y);
      ctx.lineTo(pc.x, pc.y);
      ctx.closePath();
      ctx.fill();
    }

    // Circumcircles
    if (showCirc) {
      ctx.strokeStyle = 'rgba(123,44,191,0.18)';
      ctx.lineWidth = 0.7;
      for (var i = 0; i < tris.length; i++) {
        var t = tris[i];
        var c = ccircle(pts[t.a].x, pts[t.a].y, pts[t.b].x, pts[t.b].y, pts[t.c].x, pts[t.c].y);
        if (c) {
          ctx.beginPath();
          ctx.arc(c.x, c.y, c.r, 0, 6.2832);
          ctx.stroke();
          ctx.fillStyle = 'rgba(123,44,191,0.35)';
          ctx.beginPath();
          ctx.arc(c.x, c.y, 1.5, 0, 6.2832);
          ctx.fill();
        }
      }
    }

    // Voronoi dual
    if (showVor) {
      var em = {};
      for (var i = 0; i < tris.length; i++) {
        var t = tris[i];
        var edges = [[t.a, t.b], [t.b, t.c], [t.c, t.a]];
        for (var j = 0; j < 3; j++) {
          var a = Math.min(edges[j][0], edges[j][1]);
          var b = Math.max(edges[j][0], edges[j][1]);
          var k = a + ',' + b;
          if (!em[k]) em[k] = [];
          em[k].push(i);
        }
      }
      ctx.strokeStyle = 'rgba(123,44,191,0.5)';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      for (var k in em) {
        if (em[k].length === 2) {
          var t1 = tris[em[k][0]], t2 = tris[em[k][1]];
          var c1 = ccircle(pts[t1.a].x, pts[t1.a].y, pts[t1.b].x, pts[t1.b].y, pts[t1.c].x, pts[t1.c].y);
          var c2 = ccircle(pts[t2.a].x, pts[t2.a].y, pts[t2.b].x, pts[t2.b].y, pts[t2.c].x, pts[t2.c].y);
          if (c1 && c2) {
            ctx.moveTo(c1.x, c1.y);
            ctx.lineTo(c2.x, c2.y);
          }
        }
      }
      ctx.stroke();
    }

    // Triangle edge glow
    ctx.strokeStyle = 'rgba(0,245,212,0.08)';
    ctx.lineWidth = 3;
    ctx.beginPath();
    for (var i = 0; i < tris.length; i++) {
      var t = tris[i];
      ctx.moveTo(pts[t.a].x, pts[t.a].y);
      ctx.lineTo(pts[t.b].x, pts[t.b].y);
      ctx.lineTo(pts[t.c].x, pts[t.c].y);
      ctx.closePath();
    }
    ctx.stroke();

    // Triangle edges
    ctx.strokeStyle = 'rgba(0,245,212,0.45)';
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    for (var i = 0; i < tris.length; i++) {
      var t = tris[i];
      ctx.moveTo(pts[t.a].x, pts[t.a].y);
      ctx.lineTo(pts[t.b].x, pts[t.b].y);
      ctx.lineTo(pts[t.c].x, pts[t.c].y);
      ctx.closePath();
    }
    ctx.stroke();

    // Point glow
    for (var i = 0; i < pts.length; i++) {
      ctx.fillStyle = 'rgba(0,245,212,0.12)';
      ctx.beginPath();
      ctx.arc(pts[i].x, pts[i].y, 8, 0, 6.2832);
      ctx.fill();
    }

    // Points
    for (var i = 0; i < pts.length; i++) {
      ctx.fillStyle = '#00f5d4';
      ctx.beginPath();
      ctx.arc(pts[i].x, pts[i].y, 3, 0, 6.2832);
      ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,0.3)';
      ctx.lineWidth = 0.5;
      ctx.stroke();
    }

    // Empty state
    if (pts.length < 3) {
      ctx.fillStyle = 'rgba(255,255,255,0.15)';
      ctx.font = '12px "Space Grotesk",sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(pts.length === 0 ? 'CLICK TO ADD POINTS' : 'ADD MORE POINTS', W / 2, H / 2);
    }
  }

  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
