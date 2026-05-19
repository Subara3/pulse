<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('orbit');

$pageName = 'orbit';
$pageTitle = 'ORBIT - PULSE';
$pageDesc = 'ケプラー軌道。中心天体の重力場で質点が円錐曲線を描く。射出速度で楕円・放物線・双曲線が分かれる。';
$pageCanonical = 'https://pulse.subara3.com/orbit.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
#arrow-layer{position:fixed;inset:0;pointer-events:none;z-index:90}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<canvas id="arrow-layer" width="1" height="1"></canvas>

<div class="hud" id="hud">
  BODIES <span class="v" id="hud-count">0</span><br>
  <span id="hud-sel" style="display:none">
    e = <span class="v" id="hud-ecc">0.00</span><br>
    <span class="v" id="hud-type">CIRCULAR</span>
  </span>
</div>

<div id="info">
  <span class="v">kepler orbits</span><br>
  v<sub>esc</sub> = &radic;(2GM/r)
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'launch'],
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">MASS</span>
      <input type="range" id="sl-mass" min="20" max="200" value="100">
      <span class="slider-value" id="v-mass">1.00</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">TRAIL</span>
      <input type="range" id="sl-trail" min="50" max="500" value="200">
      <span class="slider-value" id="v-trail">200</span>
    </div>
  </div>
  <div>
    <span class="slider-label">VIEW</span>
    <div class="btn-row" id="view-btns">
      <button class="control-btn active" data-v="trails">TRAILS</button>
      <button class="control-btn" data-v="predict">PREDICT</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var AC = document.getElementById('arrow-layer');
  var actx = AC.getContext('2d');
  var W, H, dpr;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    AC.width = W * dpr;
    AC.height = H * dpr;
    actx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  resize();
  window.addEventListener('resize', resize);

  var GM = 8000;
  var maxTrail = 200;
  var viewMode = 'trails';
  var bodies = [];
  var nextId = 0;
  var cx, cy;

  function orbitColor(e) {
    var t = Math.min(e, 2) / 2;
    var r = Math.round(0 + t * 123);
    var g = Math.round(245 - t * 201);
    var b = Math.round(212 - t * 21);
    return [r, g, b];
  }

  function classifyOrbit(e) {
    if (e < 0.01) return 'CIRCULAR';
    if (e < 1.0) return 'ELLIPTICAL';
    if (e < 1.02) return 'PARABOLIC';
    return 'HYPERBOLIC';
  }

  function calcOrbitalElements(x, y, vx, vy) {
    var rx = x - cx, ry = y - cy;
    var r = Math.sqrt(rx * rx + ry * ry);
    if (r < 1) r = 1;
    var v2 = vx * vx + vy * vy;
    var energy = 0.5 * v2 - GM / r;
    var Lz = rx * vy - ry * vx;
    var mu = GM;
    var ex = (v2 / mu - 1 / r) * rx - (rx * vx + ry * vy) / mu * vx;
    var ey = (v2 / mu - 1 / r) * ry - (rx * vx + ry * vy) / mu * vy;
    var e = Math.sqrt(ex * ex + ey * ey);
    var a = (e < 1) ? -mu / (2 * energy) : mu / (2 * Math.abs(energy));
    return { e: e, a: a, energy: energy, L: Lz, ex: ex, ey: ey };
  }

  function predictOrbit(x, y, vx, vy, steps) {
    var pts = [];
    var dt = 0.3;
    var px = x, py = y, pvx = vx, pvy = vy;
    for (var i = 0; i < steps; i++) {
      var rx = px - cx, ry = py - cy;
      var r = Math.sqrt(rx * rx + ry * ry);
      if (r < 10) break;
      var f = GM / (r * r * r);
      var ax = -f * rx, ay = -f * ry;
      pvx += ax * dt;
      pvy += ay * dt;
      px += pvx * dt;
      py += pvy * dt;
      pts.push({ x: px, y: py });
      var d = Math.sqrt((px - cx) * (px - cx) + (py - cy) * (py - cy));
      if (d > Math.max(W, H) * 2) break;
    }
    return pts;
  }

  function spawnBody(x, y, vx, vy) {
    var col = orbitColor(calcOrbitalElements(x, y, vx, vy).e);
    bodies.push({
      id: nextId++,
      x: x, y: y,
      vx: vx, vy: vy,
      trail: [{ x: x, y: y }],
      col: col,
      alive: true
    });
    if (bodies.length > 30) {
      bodies.shift();
    }
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function init() {
    cx = W / 2;
    cy = H / 2;
    bodies = [];
    var r1 = Math.min(W, H) * 0.22;
    var v1 = Math.sqrt(GM / r1);
    spawnBody(cx + r1, cy, 0, v1);

    var r2 = Math.min(W, H) * 0.14;
    var v2 = Math.sqrt(GM / r2) * 0.7;
    spawnBody(cx, cy - r2, v2, 0);

    var r3 = Math.min(W, H) * 0.32;
    var v3 = Math.sqrt(GM / r3) * 1.15;
    spawnBody(cx - r3, cy, 0, -v3);
  }
  init();

  function update(dt) {
    cx = W / 2;
    cy = H / 2;
    for (var i = bodies.length - 1; i >= 0; i--) {
      var b = bodies[i];
      if (!b.alive) continue;

      var rx = b.x - cx, ry = b.y - cy;
      var r = Math.sqrt(rx * rx + ry * ry);
      if (r < 8) {
        b.alive = false;
        if (typeof SFX !== 'undefined') SFX.play('collide', 50);
        continue;
      }

      var f = GM / (r * r * r);
      var ax = -f * rx, ay = -f * ry;

      b.vx += ax * dt;
      b.vy += ay * dt;
      b.x += b.vx * dt;
      b.y += b.vy * dt;

      b.trail.push({ x: b.x, y: b.y });
      if (b.trail.length > maxTrail) b.trail.shift();

      if (b.x < -W || b.x > W * 2 || b.y < -H || b.y > H * 2) {
        b.alive = false;
      }
    }
    bodies = bodies.filter(function(b) { return b.alive; });
  }

  function drawStar() {
    var gr = ctx.createRadialGradient(cx, cy, 0, cx, cy, 40);
    gr.addColorStop(0, 'rgba(0,245,212,0.5)');
    gr.addColorStop(0.3, 'rgba(0,245,212,0.15)');
    gr.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.beginPath();
    ctx.arc(cx, cy, 40, 0, Math.PI * 2);
    ctx.fillStyle = gr;
    ctx.fill();

    ctx.beginPath();
    ctx.arc(cx, cy, 6, 0, Math.PI * 2);
    ctx.fillStyle = '#00f5d4';
    ctx.fill();

    ctx.beginPath();
    ctx.arc(cx, cy, 3, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
  }

  function drawBodies() {
    for (var i = 0; i < bodies.length; i++) {
      var b = bodies[i];
      var c = b.col;

      if (b.trail.length > 2) {
        ctx.beginPath();
        ctx.moveTo(b.trail[0].x, b.trail[0].y);
        for (var j = 1; j < b.trail.length; j++) {
          ctx.lineTo(b.trail[j].x, b.trail[j].y);
        }
        ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.4)';
        ctx.lineWidth = 1.2;
        ctx.stroke();
      }

      if (viewMode === 'predict') {
        var pred = predictOrbit(b.x, b.y, b.vx, b.vy, 600);
        if (pred.length > 2) {
          ctx.beginPath();
          ctx.moveTo(pred[0].x, pred[0].y);
          for (var j = 1; j < pred.length; j++) {
            ctx.lineTo(pred[j].x, pred[j].y);
          }
          ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.12)';
          ctx.lineWidth = 1;
          ctx.setLineDash([4, 4]);
          ctx.stroke();
          ctx.setLineDash([]);
        }
      }

      var gr = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, 10);
      gr.addColorStop(0, 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.7)');
      gr.addColorStop(1, 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0)');
      ctx.beginPath();
      ctx.arc(b.x, b.y, 10, 0, Math.PI * 2);
      ctx.fillStyle = gr;
      ctx.fill();

      ctx.beginPath();
      ctx.arc(b.x, b.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',1)';
      ctx.fill();
    }
  }

  function drawEquipotential() {
    ctx.strokeStyle = 'rgba(0,245,212,0.025)';
    ctx.lineWidth = 0.5;
    var radii = [60, 100, 160, 240, 340];
    for (var i = 0; i < radii.length; i++) {
      ctx.beginPath();
      ctx.arc(cx, cy, radii[i], 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawEquipotential();
    drawBodies();
    drawStar();
  }

  function updateHUD() {
    document.getElementById('hud-count').textContent = bodies.length;
    if (bodies.length > 0) {
      var last = bodies[bodies.length - 1];
      var el = calcOrbitalElements(last.x, last.y, last.vx, last.vy);
      document.getElementById('hud-ecc').textContent = el.e.toFixed(2);
      document.getElementById('hud-type').textContent = classifyOrbit(el.e);
      var typeEl = document.getElementById('hud-type');
      if (el.e < 1) {
        typeEl.style.color = '#00f5d4';
      } else {
        typeEl.style.color = '#c77dff';
      }
      document.getElementById('hud-sel').style.display = '';
    } else {
      document.getElementById('hud-sel').style.display = 'none';
    }
  }

  var dragging = false;
  var dragSX, dragSY, dragEX, dragEY;

  function drawArrow() {
    actx.clearRect(0, 0, W, H);
    if (!dragging) return;
    var dx = dragEX - dragSX, dy = dragEY - dragSY;
    var len = Math.sqrt(dx * dx + dy * dy);
    if (len < 5) return;

    actx.beginPath();
    actx.moveTo(dragSX, dragSY);
    actx.lineTo(dragEX, dragEY);
    actx.strokeStyle = 'rgba(0,245,212,0.6)';
    actx.lineWidth = 2;
    actx.stroke();

    var angle = Math.atan2(dy, dx);
    var hl = 10;
    actx.beginPath();
    actx.moveTo(dragEX, dragEY);
    actx.lineTo(dragEX - hl * Math.cos(angle - 0.4), dragEY - hl * Math.sin(angle - 0.4));
    actx.moveTo(dragEX, dragEY);
    actx.lineTo(dragEX - hl * Math.cos(angle + 0.4), dragEY - hl * Math.sin(angle + 0.4));
    actx.stroke();

    var speed = len * 0.8;
    var rx = dragSX - cx, ry = dragSY - cy;
    var r = Math.sqrt(rx * rx + ry * ry);
    var vesc = Math.sqrt(2 * GM / Math.max(r, 1));
    var ratio = speed / vesc;
    actx.font = '0.6rem "JetBrains Mono", monospace';
    actx.fillStyle = 'rgba(255,255,255,0.5)';
    actx.fillText('v/v_esc=' + ratio.toFixed(2), dragSX + 10, dragSY - 10);

    if (viewMode === 'predict') {
      var vx = dx * 0.8, vy = dy * 0.8;
      var pred = predictOrbit(dragSX, dragSY, vx, vy, 800);
      if (pred.length > 2) {
        var el = calcOrbitalElements(dragSX, dragSY, vx, vy);
        var c = orbitColor(el.e);
        actx.beginPath();
        actx.moveTo(pred[0].x, pred[0].y);
        for (var j = 1; j < pred.length; j++) {
          actx.lineTo(pred[j].x, pred[j].y);
        }
        actx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.3)';
        actx.lineWidth = 1;
        actx.setLineDash([3, 3]);
        actx.stroke();
        actx.setLineDash([]);
      }
    }
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) {
      e.preventDefault();
      var minD = Infinity, minIdx = -1;
      for (var i = 0; i < bodies.length; i++) {
        var dx = bodies[i].x - e.clientX, dy = bodies[i].y - e.clientY;
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d < minD) { minD = d; minIdx = i; }
      }
      if (minIdx >= 0 && minD < 50) {
        bodies.splice(minIdx, 1);
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    if (e.button !== 0) return;
    dragging = true;
    dragSX = e.clientX;
    dragSY = e.clientY;
    dragEX = dragSX;
    dragEY = dragSY;
  });

  C.addEventListener('mousemove', function(e) {
    if (dragging) {
      dragEX = e.clientX;
      dragEY = e.clientY;
      drawArrow();
    }
  });

  window.addEventListener('mouseup', function(e) {
    if (!dragging) return;
    dragging = false;
    actx.clearRect(0, 0, W, H);
    var dx = dragEX - dragSX, dy = dragEY - dragSY;
    var len = Math.sqrt(dx * dx + dy * dy);
    if (len < 3) {
      var rx = dragSX - cx, ry = dragSY - cy;
      var r = Math.sqrt(rx * rx + ry * ry);
      if (r > 15) {
        var vc = Math.sqrt(GM / r);
        var ang = Math.atan2(ry, rx) + Math.PI / 2;
        spawnBody(dragSX, dragSY, vc * Math.cos(ang), vc * Math.sin(ang));
      }
    } else {
      spawnBody(dragSX, dragSY, dx * 0.8, dy * 0.8);
    }
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchId = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (touchId !== null) return;
    var t = e.changedTouches[0];
    touchId = t.identifier;
    dragging = true;
    dragSX = t.clientX;
    dragSY = t.clientY;
    dragEX = dragSX;
    dragEY = dragSY;
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.changedTouches.length; i++) {
      if (e.changedTouches[i].identifier === touchId) {
        dragEX = e.changedTouches[i].clientX;
        dragEY = e.changedTouches[i].clientY;
        drawArrow();
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.changedTouches.length; i++) {
      if (e.changedTouches[i].identifier === touchId) {
        touchId = null;
        dragging = false;
        actx.clearRect(0, 0, W, H);
        var dx = dragEX - dragSX, dy = dragEY - dragSY;
        var len = Math.sqrt(dx * dx + dy * dy);
        if (len < 10) {
          var rx = dragSX - cx, ry = dragSY - cy;
          var r = Math.sqrt(rx * rx + ry * ry);
          if (r > 15) {
            var vc = Math.sqrt(GM / r);
            var ang = Math.atan2(ry, rx) + Math.PI / 2;
            spawnBody(dragSX, dragSY, vc * Math.cos(ang), vc * Math.sin(ang));
          }
        } else {
          spawnBody(dragSX, dragSY, dx * 0.8, dy * 0.8);
        }
      }
    }
  }, { passive: false });

  document.getElementById('sl-mass').addEventListener('input', function() {
    GM = this.value * 80;
    document.getElementById('v-mass').textContent = (this.value / 100).toFixed(2);
  });
  document.getElementById('sl-trail').addEventListener('input', function() {
    maxTrail = +this.value;
    document.getElementById('v-trail').textContent = this.value;
  });

  var viewBtns = document.querySelectorAll('#view-btns .control-btn');
  for (var i = 0; i < viewBtns.length; i++) {
    viewBtns[i].addEventListener('click', function() {
      for (var j = 0; j < viewBtns.length; j++) viewBtns[j].classList.remove('active');
      this.classList.add('active');
      viewMode = this.dataset.v;
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  document.getElementById('btn-reset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var lastTS = 0;
  function loop(ts) {
    var dt = Math.min((ts - lastTS) / 1000, 0.05);
    lastTS = ts;

    var steps = 4;
    var sdt = dt / steps;
    for (var s = 0; s < steps; s++) {
      update(sdt);
    }

    draw();
    updateHUD();
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(function(ts) {
    lastTS = ts;
    loop(ts);
  });
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
