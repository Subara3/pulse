<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('optics');

$pageName = 'optics';
$pageTitle = 'OPTICS - PULSE';
$pageDesc = '2D幾何光学。点光源の光線が鏡面で反射し、幾何学的パターンを描き出す。';
$pageCanonical = 'https://pulse.subara3.com/optics.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", "Segoe UI", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.64rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .v { color: #00f5d4; font-weight: 700; }
@media (max-width: 768px) {
  .hud { top: 0.6rem; right: 0.6rem; font-size: 0.54rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  MODE: <span id="hudMode" class="v">SOURCE</span><br>
  SOURCES: <span id="hudSrc" class="v">0</span> &nbsp;
  MIRRORS: <span id="hudMir" class="v">0</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place'],
  ['type' => 'drag', 'label' => 'draw / move'],
  ['type' => 'right', 'label' => 'delete'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btnSource">SOURCE</button>
    <button class="control-btn" id="btnMirror">MIRROR</button>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">RAYS</label>
      <span class="slider-value" id="valRays">360</span>
    </div>
    <input type="range" id="rngRays" min="36" max="720" step="36" value="360">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">BOUNCES</label>
      <span class="slider-value" id="valBounce">10</span>
    </div>
    <input type="range" id="rngBounce" min="1" max="20" step="1" value="10">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnPreset">PRESET</button>
    <button class="control-btn" id="btnClear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', {alpha: false});

  var hudMode = document.getElementById('hudMode');
  var hudSrc = document.getElementById('hudSrc');
  var hudMir = document.getElementById('hudMir');
  var btnSource = document.getElementById('btnSource');
  var btnMirror = document.getElementById('btnMirror');
  var rngRays = document.getElementById('rngRays');
  var valRays = document.getElementById('valRays');
  var rngBounce = document.getElementById('rngBounce');
  var valBounce = document.getElementById('valBounce');
  var btnPreset = document.getElementById('btnPreset');
  var btnClear = document.getElementById('btnClear');

  var W = 0, H = 0;
  var mode = 'source';
  var rayCount = 360;
  var maxBounce = 10;
  var sources = [];
  var mirrors = [];
  var nextId = 1;
  var placing = null;
  var dragging = null;
  var mouseX = 0, mouseY = 0;
  var presetIdx = 0;
  var time = 0;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
  }

  function dot(ax, ay, bx, by) { return ax * bx + ay * by; }
  function mag(x, y) { return Math.sqrt(x * x + y * y); }
  function norm(x, y) {
    var l = mag(x, y);
    return l < 1e-10 ? [0, 0] : [x / l, y / l];
  }

  function rayHit(ox, oy, dx, dy, ax, ay, bx, by) {
    var ex = bx - ax, ey = by - ay;
    var den = dx * ey - dy * ex;
    if (Math.abs(den) < 1e-10) return null;
    var t = ((ax - ox) * ey - (ay - oy) * ex) / den;
    var s = ((ax - ox) * dy - (ay - oy) * dx) / den;
    if (t < 0.001 || s < 0 || s > 1) return null;
    var n = norm(-ey, ex);
    if (dot(n[0], n[1], dx, dy) > 0) { n[0] = -n[0]; n[1] = -n[1]; }
    return {t: t, x: ox + t * dx, y: oy + t * dy, nx: n[0], ny: n[1]};
  }

  function refl(dx, dy, nx, ny) {
    var d = 2 * dot(dx, dy, nx, ny);
    return [dx - d * nx, dy - d * ny];
  }

  function trace() {
    var segs = [];
    var far = Math.max(W, H) * 2;
    for (var si = 0; si < sources.length; si++) {
      var src = sources[si];
      for (var ri = 0; ri < rayCount; ri++) {
        var a = (ri / rayCount) * Math.PI * 2;
        var dx = Math.cos(a), dy = Math.sin(a);
        var ox = src.x, oy = src.y;
        for (var b = 0; b <= maxBounce; b++) {
          var best = null, bt = Infinity;
          for (var mi = 0; mi < mirrors.length; mi++) {
            var m = mirrors[mi];
            var h = rayHit(ox, oy, dx, dy, m.x1, m.y1, m.x2, m.y2);
            if (h && h.t < bt) { bt = h.t; best = h; }
          }
          if (best) {
            segs.push({x1: ox, y1: oy, x2: best.x, y2: best.y, b: b});
            var r = refl(dx, dy, best.nx, best.ny);
            ox = best.x; oy = best.y; dx = r[0]; dy = r[1];
          } else {
            segs.push({x1: ox, y1: oy, x2: ox + dx * far, y2: oy + dy * far, b: b});
            break;
          }
        }
      }
    }
    return segs;
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var segs = trace();

    ctx.globalCompositeOperation = 'lighter';
    ctx.lineWidth = 1;
    for (var b = 0; b <= maxBounce; b++) {
      var alpha = 0.15 * Math.pow(0.6, b);
      if (alpha < 0.003) break;
      ctx.beginPath();
      for (var i = 0; i < segs.length; i++) {
        if (segs[i].b === b) {
          ctx.moveTo(segs[i].x1, segs[i].y1);
          ctx.lineTo(segs[i].x2, segs[i].y2);
        }
      }
      ctx.strokeStyle = 'rgba(0,245,212,' + alpha.toFixed(4) + ')';
      ctx.stroke();
    }
    ctx.globalCompositeOperation = 'source-over';

    for (var mi = 0; mi < mirrors.length; mi++) {
      var m = mirrors[mi];
      ctx.beginPath();
      ctx.moveTo(m.x1, m.y1);
      ctx.lineTo(m.x2, m.y2);
      ctx.strokeStyle = 'rgba(123,44,191,0.25)';
      ctx.lineWidth = 6;
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(m.x1, m.y1);
      ctx.lineTo(m.x2, m.y2);
      ctx.strokeStyle = '#7b2cbf';
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.fillStyle = '#7b2cbf';
      ctx.beginPath(); ctx.arc(m.x1, m.y1, 3, 0, Math.PI * 2); ctx.fill();
      ctx.beginPath(); ctx.arc(m.x2, m.y2, 3, 0, Math.PI * 2); ctx.fill();
    }

    var pulse = 0.5 + 0.5 * Math.sin(time * 2.5);
    for (var si = 0; si < sources.length; si++) {
      var s = sources[si];
      var gr = 14 + pulse * 6;
      ctx.beginPath(); ctx.arc(s.x, s.y, gr, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,' + (0.06 + pulse * 0.04).toFixed(3) + ')';
      ctx.fill();
      ctx.beginPath(); ctx.arc(s.x, s.y, 6, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,0.25)';
      ctx.fill();
      ctx.beginPath(); ctx.arc(s.x, s.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = '#00f5d4';
      ctx.fill();
    }

    if (placing) {
      ctx.beginPath();
      ctx.moveTo(placing.x, placing.y);
      ctx.lineTo(mouseX, mouseY);
      ctx.strokeStyle = 'rgba(123,44,191,0.5)';
      ctx.lineWidth = 2;
      ctx.setLineDash([6, 4]);
      ctx.stroke();
      ctx.setLineDash([]);
    }

    hudSrc.textContent = sources.length;
    hudMir.textContent = mirrors.length;
  }

  var lastT = 0;
  function loop(ts) {
    if (!lastT) lastT = ts;
    time += (ts - lastT) * 0.001;
    lastT = ts;
    draw();
    requestAnimationFrame(loop);
  }

  function distSeg(px, py, ax, ay, bx, by) {
    var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy;
    if (l2 < 1e-10) return mag(px - ax, py - ay);
    var t = Math.max(0, Math.min(1, dot(px - ax, py - ay, dx, dy) / l2));
    return mag(px - (ax + t * dx), py - (ay + t * dy));
  }

  function findNear(x, y, th) {
    th = th || 25;
    var best = null, bd = th;
    for (var i = 0; i < sources.length; i++) {
      var d = mag(x - sources[i].x, y - sources[i].y);
      if (d < bd) { bd = d; best = {type: 'source', i: i}; }
    }
    for (var i = 0; i < mirrors.length; i++) {
      var m = mirrors[i];
      var d = distSeg(x, y, m.x1, m.y1, m.x2, m.y2);
      if (d < bd) { bd = d; best = {type: 'mirror', i: i}; }
    }
    return best;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var x = e.clientX, y = e.clientY;
    var near = findNear(x, y);
    if (near) {
      if (near.type === 'source') {
        var s = sources[near.i];
        dragging = {type: 'source', i: near.i, ox: x - s.x, oy: y - s.y};
      } else {
        var m = mirrors[near.i];
        dragging = {type: 'mirror', i: near.i, ox: x - (m.x1 + m.x2) / 2, oy: y - (m.y1 + m.y2) / 2};
      }
      if (typeof SFX !== 'undefined') SFX.play('click');
    } else if (mode === 'source') {
      if (sources.length < 5) {
        sources.push({x: x, y: y, id: nextId++});
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    } else if (mode === 'mirror') {
      placing = {x: x, y: y};
    }
  });

  C.addEventListener('mousemove', function(e) {
    mouseX = e.clientX; mouseY = e.clientY;
    if (!dragging) return;
    if (dragging.type === 'source') {
      sources[dragging.i].x = mouseX - dragging.ox;
      sources[dragging.i].y = mouseY - dragging.oy;
    } else {
      var m = mirrors[dragging.i];
      var omx = (m.x1 + m.x2) / 2, omy = (m.y1 + m.y2) / 2;
      var nx = mouseX - dragging.ox, ny = mouseY - dragging.oy;
      var dx = nx - omx, dy = ny - omy;
      m.x1 += dx; m.y1 += dy; m.x2 += dx; m.y2 += dy;
    }
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  });

  C.addEventListener('mouseup', function() {
    if (placing) {
      if (mag(mouseX - placing.x, mouseY - placing.y) > 15) {
        mirrors.push({x1: placing.x, y1: placing.y, x2: mouseX, y2: mouseY, id: nextId++});
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
      placing = null;
    }
    dragging = null;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var near = findNear(e.clientX, e.clientY, 30);
    if (near) {
      if (near.type === 'source') sources.splice(near.i, 1);
      else mirrors.splice(near.i, 1);
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  });

  var touchNear = null, longPress = null;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], x = t.clientX, y = t.clientY;
    mouseX = x; mouseY = y;
    var near = findNear(x, y);
    if (near) {
      touchNear = near;
      if (near.type === 'source') {
        var s = sources[near.i];
        dragging = {type: 'source', i: near.i, ox: x - s.x, oy: y - s.y};
      } else {
        var m = mirrors[near.i];
        dragging = {type: 'mirror', i: near.i, ox: x - (m.x1 + m.x2) / 2, oy: y - (m.y1 + m.y2) / 2};
      }
      longPress = setTimeout(function() {
        if (touchNear) {
          if (touchNear.type === 'source') sources.splice(touchNear.i, 1);
          else mirrors.splice(touchNear.i, 1);
          dragging = null;
          if (typeof SFX !== 'undefined') SFX.play('pop');
        }
        touchNear = null;
      }, 500);
      if (typeof SFX !== 'undefined') SFX.play('click');
    } else if (mode === 'source') {
      if (sources.length < 5) {
        sources.push({x: x, y: y, id: nextId++});
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    } else if (mode === 'mirror') {
      placing = {x: x, y: y};
    }
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (longPress) { clearTimeout(longPress); longPress = null; }
    var t = e.touches[0];
    mouseX = t.clientX; mouseY = t.clientY;
    if (!dragging) return;
    if (dragging.type === 'source') {
      sources[dragging.i].x = mouseX - dragging.ox;
      sources[dragging.i].y = mouseY - dragging.oy;
    } else {
      var m = mirrors[dragging.i];
      var omx = (m.x1 + m.x2) / 2, omy = (m.y1 + m.y2) / 2;
      var nx = mouseX - dragging.ox, ny = mouseY - dragging.oy;
      m.x1 += nx - omx; m.y1 += ny - omy;
      m.x2 += nx - omx; m.y2 += ny - omy;
    }
  }, {passive: false});

  C.addEventListener('touchend', function() {
    if (longPress) { clearTimeout(longPress); longPress = null; }
    if (placing) {
      if (mag(mouseX - placing.x, mouseY - placing.y) > 15) {
        mirrors.push({x1: placing.x, y1: placing.y, x2: mouseX, y2: mouseY, id: nextId++});
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
      placing = null;
    }
    dragging = null;
    touchNear = null;
  });

  function setMode(m) {
    mode = m;
    btnSource.classList.toggle('active', m === 'source');
    btnMirror.classList.toggle('active', m === 'mirror');
    hudMode.textContent = m.toUpperCase();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }
  btnSource.addEventListener('click', function() { setMode('source'); });
  btnMirror.addEventListener('click', function() { setMode('mirror'); });

  rngRays.addEventListener('input', function() {
    rayCount = +this.value;
    valRays.textContent = rayCount;
  });
  rngBounce.addEventListener('input', function() {
    maxBounce = +this.value;
    valBounce.textContent = maxBounce;
  });

  btnClear.addEventListener('click', function() {
    sources = []; mirrors = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var presets = [
    function() {
      sources = [{x: W * 0.5, y: H * 0.35, id: nextId++}];
      mirrors = [
        {x1: W * 0.25, y1: H * 0.1, x2: W * 0.25, y2: H * 0.9, id: nextId++},
        {x1: W * 0.75, y1: H * 0.1, x2: W * 0.75, y2: H * 0.9, id: nextId++}
      ];
    },
    function() {
      var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.35;
      sources = [{x: cx + r * 0.12, y: cy - r * 0.15, id: nextId++}];
      mirrors = [];
      for (var i = 0; i < 3; i++) {
        var a1 = (i / 3) * Math.PI * 2 - Math.PI / 2;
        var a2 = ((i + 1) / 3) * Math.PI * 2 - Math.PI / 2;
        mirrors.push({x1: cx + r * Math.cos(a1), y1: cy + r * Math.sin(a1), x2: cx + r * Math.cos(a2), y2: cy + r * Math.sin(a2), id: nextId++});
      }
    },
    function() {
      var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.35;
      sources = [{x: cx + r * 0.1, y: cy - r * 0.08, id: nextId++}];
      mirrors = [];
      for (var i = 0; i < 5; i++) {
        var a1 = (i / 5) * Math.PI * 2 - Math.PI / 2;
        var a2 = ((i + 1) / 5) * Math.PI * 2 - Math.PI / 2;
        mirrors.push({x1: cx + r * Math.cos(a1), y1: cy + r * Math.sin(a1), x2: cx + r * Math.cos(a2), y2: cy + r * Math.sin(a2), id: nextId++});
      }
    },
    function() {
      var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.35;
      sources = [{x: cx + r * 0.08, y: cy - r * 0.12, id: nextId++}];
      mirrors = [];
      for (var i = 0; i < 8; i++) {
        var a1 = (i / 8) * Math.PI * 2;
        var a2 = ((i + 1) / 8) * Math.PI * 2;
        mirrors.push({x1: cx + r * Math.cos(a1), y1: cy + r * Math.sin(a1), x2: cx + r * Math.cos(a2), y2: cy + r * Math.sin(a2), id: nextId++});
      }
    }
  ];

  btnPreset.addEventListener('click', function() {
    presetIdx = (presetIdx + 1) % presets.length;
    presets[presetIdx]();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  window.addEventListener('resize', resize);
  resize();
  presets[0]();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
