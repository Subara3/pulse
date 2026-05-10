<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('shadow');

$pageName = 'shadow';
$pageTitle = 'SHADOW - PULSE';
$pageDesc = '2Dレイキャスティング。光源から光線を投射し、壁が作る影と可視領域をリアルタイムに描画する。';
$pageCanonical = 'https://pulse.subara3.com/shadow.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  LIGHTS: <span id="hudLights" class="v">1</span><br>
  WALLS: <span id="hudWalls" class="v">0</span><br>
  RAYS: <span id="hudRays" class="v">0</span>
</div>

<?php
$pageInfo = [
  '2D raycasting',
  'visibility polygon algorithm',
  'light and shadow boundaries',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add light'],
  ['type' => 'drag', 'label' => 'draw wall'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">INTENSITY</label>
    <div class="slider-row">
      <input type="range" id="sldIntensity" min="10" max="100" value="60">
      <span class="slider-value" id="valIntensity">60</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">RADIUS</label>
    <div class="slider-row">
      <input type="range" id="sldRadius" min="80" max="600" value="300">
      <span class="slider-value" id="valRadius">300</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SCENE</label>
    <div class="preset-group" id="sceneGroup">
      <button class="preset-btn active" data-s="pillars">PILLARS</button>
      <button class="preset-btn" data-s="room">ROOM</button>
      <button class="preset-btn" data-s="scatter">SCATTER</button>
      <button class="preset-btn" data-s="clear">CLEAR</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnShowRays">RAYS: OFF</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  var walls = [];
  var lights = [];
  var intensity = 0.6;
  var lightRadius = 300;
  var showRays = false;
  var totalRays = 0;
  var time = 0;
  var curPreset = 'pillars';

  var pointerDown = false;
  var drawStart = null;
  var draggingLight = -1;
  var didDrag = false;
  var startPos = null;
  var isRight = false;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function bounds() {
    return [
      {x1: 0, y1: 0, x2: W, y2: 0},
      {x1: W, y1: 0, x2: W, y2: H},
      {x1: W, y1: H, x2: 0, y2: H},
      {x1: 0, y1: H, x2: 0, y2: 0}
    ];
  }

  function allSegs() { return bounds().concat(walls); }

  function uniquePts(segs) {
    var m = {}, r = [];
    for (var i = 0; i < segs.length; i++) {
      var s = segs[i];
      var k1 = (s.x1 * 1000 | 0) + ',' + (s.y1 * 1000 | 0);
      var k2 = (s.x2 * 1000 | 0) + ',' + (s.y2 * 1000 | 0);
      if (!m[k1]) { m[k1] = 1; r.push({x: s.x1, y: s.y1}); }
      if (!m[k2]) { m[k2] = 1; r.push({x: s.x2, y: s.y2}); }
    }
    return r;
  }

  function raySeg(ox, oy, dx, dy, x1, y1, x2, y2) {
    var sx = x2 - x1, sy = y2 - y1;
    var d = dx * sy - dy * sx;
    if (d > -1e-10 && d < 1e-10) return -1;
    var t = ((x1 - ox) * sy - (y1 - oy) * sx) / d;
    var u = ((x1 - ox) * dy - (y1 - oy) * dx) / d;
    if (t < 1e-6 || u < 0 || u > 1) return -1;
    return t;
  }

  function castVis(lx, ly, segs, pts) {
    var angles = [];
    var eps = 0.00001;
    for (var i = 0; i < pts.length; i++) {
      var a = Math.atan2(pts[i].y - ly, pts[i].x - lx);
      angles.push(a - eps, a, a + eps);
    }
    angles.sort(function(a, b) { return a - b; });

    var poly = [];
    for (var i = 0; i < angles.length; i++) {
      var a = angles[i];
      var dx = Math.cos(a), dy = Math.sin(a);
      var minT = 1e9;
      for (var j = 0; j < segs.length; j++) {
        var s = segs[j];
        var t = raySeg(lx, ly, dx, dy, s.x1, s.y1, s.x2, s.y2);
        if (t > 0 && t < minT) minT = t;
      }
      if (minT < 1e9) poly.push({x: lx + dx * minT, y: ly + dy * minT});
    }
    return poly;
  }

  function drawPoly(poly) {
    if (poly.length < 3) return;
    ctx.beginPath();
    ctx.moveTo(poly[0].x, poly[0].y);
    for (var i = 1; i < poly.length; i++) ctx.lineTo(poly[i].x, poly[i].y);
    ctx.closePath();
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var segs = allSegs();
    var pts = uniquePts(segs);
    totalRays = 0;
    var pulse = 1 + Math.sin(time * 2) * 0.06;
    var eff = intensity * pulse;

    ctx.globalCompositeOperation = 'lighter';
    for (var i = 0; i < lights.length; i++) {
      var l = lights[i];
      var poly = castVis(l.x, l.y, segs, pts);
      totalRays += poly.length;
      if (poly.length < 3) continue;

      ctx.save();
      drawPoly(poly);
      ctx.clip();
      var g = ctx.createRadialGradient(l.x, l.y, 0, l.x, l.y, lightRadius);
      g.addColorStop(0, 'rgba(0,245,212,' + (eff * 0.5).toFixed(3) + ')');
      g.addColorStop(0.15, 'rgba(0,245,212,' + (eff * 0.35).toFixed(3) + ')');
      g.addColorStop(0.4, 'rgba(0,245,212,' + (eff * 0.15).toFixed(3) + ')');
      g.addColorStop(0.7, 'rgba(0,245,212,' + (eff * 0.05).toFixed(3) + ')');
      g.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, W, H);
      ctx.restore();

      if (i < lights.length) {
        drawPoly(poly);
        ctx.strokeStyle = 'rgba(0,245,212,' + (eff * 0.12).toFixed(3) + ')';
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    }
    ctx.globalCompositeOperation = 'source-over';

    if (showRays) {
      ctx.globalAlpha = 0.06;
      ctx.strokeStyle = '#00f5d4';
      ctx.lineWidth = 0.5;
      for (var i = 0; i < lights.length; i++) {
        var l = lights[i];
        var poly = castVis(l.x, l.y, segs, pts);
        for (var j = 0; j < poly.length; j++) {
          ctx.beginPath();
          ctx.moveTo(l.x, l.y);
          ctx.lineTo(poly[j].x, poly[j].y);
          ctx.stroke();
        }
      }
      ctx.globalAlpha = 1;
    }

    ctx.lineCap = 'round';
    for (var i = 0; i < walls.length; i++) {
      var w = walls[i];
      ctx.strokeStyle = 'rgba(123,44,191,0.25)';
      ctx.lineWidth = 7;
      ctx.beginPath();
      ctx.moveTo(w.x1, w.y1); ctx.lineTo(w.x2, w.y2);
      ctx.stroke();
      ctx.strokeStyle = 'rgba(123,44,191,0.85)';
      ctx.lineWidth = 2.5;
      ctx.beginPath();
      ctx.moveTo(w.x1, w.y1); ctx.lineTo(w.x2, w.y2);
      ctx.stroke();
    }

    for (var i = 0; i < lights.length; i++) {
      var l = lights[i];
      var p = 1 + Math.sin(time * 3 + i * 1.7) * 0.25;
      ctx.beginPath();
      ctx.arc(l.x, l.y, 14 * p, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,0.1)';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(l.x, l.y, 5, 0, Math.PI * 2);
      ctx.fillStyle = '#00f5d4';
      ctx.fill();
    }

    if (drawStart && didDrag && draggingLight < 0) {
      ctx.setLineDash([5, 5]);
      ctx.strokeStyle = 'rgba(123,44,191,0.5)';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(drawStart.x, drawStart.y);
      ctx.lineTo(drawStart.ex, drawStart.ey);
      ctx.stroke();
      ctx.setLineDash([]);
    }

    document.getElementById('hudLights').textContent = lights.length;
    document.getElementById('hudWalls').textContent = walls.length;
    document.getElementById('hudRays').textContent = totalRays;
  }

  function frame(t) {
    time = t / 1000;
    render();
    requestAnimationFrame(frame);
  }

  function addRect(cx, cy, hw, hh) {
    walls.push(
      {x1: cx-hw, y1: cy-hh, x2: cx+hw, y2: cy-hh},
      {x1: cx+hw, y1: cy-hh, x2: cx+hw, y2: cy+hh},
      {x1: cx+hw, y1: cy+hh, x2: cx-hw, y2: cy+hh},
      {x1: cx-hw, y1: cy+hh, x2: cx-hw, y2: cy-hh}
    );
  }

  function presetPillars() {
    walls.length = 0;
    var cols = 4, rows = 3;
    var pw = Math.min(28, W * 0.025), ph = pw;
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        addRect(W * (c + 1) / (cols + 1), H * (r + 1) / (rows + 1), pw, ph);
  }

  function presetRoom() {
    walls.length = 0;
    var mx = W * 0.12, my = H * 0.12;
    var rw = W - 2 * mx, rh = H - 2 * my;
    var gap = Math.min(50, rw * 0.06);
    walls.push(
      {x1: mx, y1: my, x2: mx + rw, y2: my},
      {x1: mx + rw, y1: my, x2: mx + rw, y2: my + rh},
      {x1: mx + rw, y1: my + rh, x2: mx, y2: my + rh},
      {x1: mx, y1: my + rh, x2: mx, y2: my}
    );
    var cx = mx + rw / 2, cy = my + rh / 2;
    walls.push(
      {x1: mx, y1: cy, x2: cx - gap, y2: cy},
      {x1: cx + gap, y1: cy, x2: mx + rw, y2: cy},
      {x1: cx, y1: my, x2: cx, y2: cy - gap},
      {x1: cx, y1: cy + gap, x2: cx, y2: my + rh}
    );
    addRect(mx + rw * 0.25, my + rh * 0.25, 18, 18);
    addRect(mx + rw * 0.75, my + rh * 0.75, 18, 18);
  }

  function presetScatter() {
    walls.length = 0;
    for (var i = 0; i < 22; i++) {
      var cx = W * (0.08 + Math.random() * 0.84);
      var cy = H * (0.08 + Math.random() * 0.84);
      var a = Math.random() * Math.PI;
      var len = 25 + Math.random() * 90;
      walls.push({
        x1: cx - Math.cos(a) * len / 2, y1: cy - Math.sin(a) * len / 2,
        x2: cx + Math.cos(a) * len / 2, y2: cy + Math.sin(a) * len / 2
      });
    }
  }

  function loadPreset(name) {
    curPreset = name;
    if (name === 'pillars') presetPillars();
    else if (name === 'room') presetRoom();
    else if (name === 'scatter') presetScatter();
    else walls.length = 0;
  }

  function dist(x1, y1, x2, y2) {
    var dx = x1 - x2, dy = y1 - y2;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function nearLight(x, y, r) {
    for (var i = 0; i < lights.length; i++)
      if (dist(x, y, lights[i].x, lights[i].y) < r) return i;
    return -1;
  }

  function nearWall(x, y, r) {
    var best = -1, bestD = r;
    for (var i = 0; i < walls.length; i++) {
      var w = walls[i];
      var dx = w.x2 - w.x1, dy = w.y2 - w.y1;
      var len2 = dx * dx + dy * dy;
      var t = len2 > 0 ? Math.max(0, Math.min(1, ((x - w.x1) * dx + (y - w.y1) * dy) / len2)) : 0;
      var d = dist(x, y, w.x1 + t * dx, w.y1 + t * dy);
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  C.addEventListener('pointerdown', function(e) {
    e.preventDefault();
    pointerDown = true;
    didDrag = false;
    isRight = (e.button === 2);
    startPos = {x: e.clientX, y: e.clientY};

    if (isRight) {
      var li = nearLight(e.clientX, e.clientY, 22);
      if (li >= 0) {
        lights.splice(li, 1);
        if (typeof SFX !== 'undefined') SFX.play('pop');
      } else {
        var wi = nearWall(e.clientX, e.clientY, 18);
        if (wi >= 0) {
          walls.splice(wi, 1);
          if (typeof SFX !== 'undefined') SFX.play('pop');
        }
      }
      return;
    }

    draggingLight = nearLight(e.clientX, e.clientY, 28);
    if (draggingLight < 0) {
      drawStart = {x: e.clientX, y: e.clientY, ex: e.clientX, ey: e.clientY};
    }
  });

  C.addEventListener('pointermove', function(e) {
    if (!pointerDown) return;
    e.preventDefault();
    if (startPos && dist(e.clientX, e.clientY, startPos.x, startPos.y) > 8) didDrag = true;

    if (draggingLight >= 0) {
      lights[draggingLight].x = e.clientX;
      lights[draggingLight].y = e.clientY;
      if (typeof SFX !== 'undefined') SFX.play('move', 100);
    } else if (drawStart) {
      drawStart.ex = e.clientX;
      drawStart.ey = e.clientY;
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (!pointerDown) return;
    pointerDown = false;

    if (!isRight) {
      if (didDrag && draggingLight < 0 && drawStart) {
        if (dist(drawStart.x, drawStart.y, e.clientX, e.clientY) > 15) {
          walls.push({x1: drawStart.x, y1: drawStart.y, x2: e.clientX, y2: e.clientY});
          if (typeof SFX !== 'undefined') SFX.play('tick');
        }
      } else if (!didDrag && draggingLight < 0) {
        if (lights.length < 8) {
          lights.push({x: e.clientX, y: e.clientY});
          if (typeof SFX !== 'undefined') SFX.play('spawn');
        }
      }
    }

    drawStart = null;
    draggingLight = -1;
    isRight = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  document.getElementById('sldIntensity').addEventListener('input', function() {
    intensity = parseInt(this.value) / 100;
    document.getElementById('valIntensity').textContent = this.value;
  });

  document.getElementById('sldRadius').addEventListener('input', function() {
    lightRadius = parseInt(this.value);
    document.getElementById('valRadius').textContent = this.value;
  });

  document.querySelectorAll('#sceneGroup .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#sceneGroup .preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      loadPreset(this.dataset.s);
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btnShowRays').addEventListener('click', function() {
    showRays = !showRays;
    this.textContent = 'RAYS: ' + (showRays ? 'ON' : 'OFF');
    this.classList.toggle('active', showRays);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    lights.length = 0;
    loadPreset(curPreset);
    lights.push({x: W * 0.35, y: H * 0.4});
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  window.addEventListener('resize', function() {
    resize();
    loadPreset(curPreset);
  });
  presetPillars();
  lights.push({x: W * 0.35, y: H * 0.4});
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
