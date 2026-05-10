<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('recaman');

$pageName = 'recaman';
$pageTitle = 'RECAMÁN - PULSE';
$pageDesc = 'レカマン数列。引けなければ足す——その単純なルールが描く半円弧の軌跡。';
$pageCanonical = 'https://pulse.subara3.com/recaman.php';

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
  STEP: <span id="hudStep" class="v">0</span><br>
  VALUE: <span id="hudVal" class="v">0</span><br>
  ARCS: <span id="hudArcs" class="v">0</span>
</div>

<?php
$pageInfo = 'recam&aacute;n sequence<br> a(n) = <span class="v">a(n-1) &minus; n</span> if positive &amp; new<br> a(n) = <span class="v">a(n-1) + n</span> otherwise';
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'step'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="60" value="8">
      <span class="slider-value" id="valSpeed">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">ARC WIDTH</label>
    <div class="slider-row">
      <input type="range" id="sldWidth" min="1" max="8" value="2">
      <span class="slider-value" id="valWidth">2</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">MODE</label>
    <div class="preset-group" id="modeGroup">
      <button class="preset-btn active" data-m="0">LINEAR</button>
      <button class="preset-btn" data-m="1">SPIRAL</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnAuto">AUTO: ON</button>
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

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  resize();
  window.addEventListener('resize', resize);

  var seq = [0];
  var visited = new Set([0]);
  var arcs = [];
  var step = 0;
  var autoPlay = true;
  var speed = 8;
  var arcWidth = 2;
  var mode = 0;

  var cam = { x: 0, y: 0, zoom: 1 };
  var targetCam = { x: 0, y: 0, zoom: 1 };
  var dragging = false;
  var dragStart = { x: 0, y: 0 };
  var camStart = { x: 0, y: 0 };
  var userPanned = false;

  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];

  function lerpColor(a, b, t) {
    return [
      a[0] + (b[0] - a[0]) * t,
      a[1] + (b[1] - a[1]) * t,
      a[2] + (b[2] - a[2]) * t
    ];
  }

  function rgba(c, a) {
    return 'rgba(' + Math.round(c[0]) + ',' + Math.round(c[1]) + ',' + Math.round(c[2]) + ',' + a + ')';
  }

  function nextStep() {
    step++;
    var prev = seq[seq.length - 1];
    var sub = prev - step;
    var next;
    if (sub > 0 && !visited.has(sub)) {
      next = sub;
    } else {
      next = prev + step;
    }
    seq.push(next);
    visited.add(next);

    var from = prev;
    var to = next;
    var dir = (step % 2 === 0) ? 1 : -1;
    arcs.push({
      from: Math.min(from, to),
      to: Math.max(from, to),
      dir: dir,
      step: step,
      birth: performance.now()
    });

    if (typeof SFX !== 'undefined') {
      if (next < prev) {
        SFX.play('tick', 30);
      } else {
        SFX.play('ripple', 30);
      }
    }

    updateHud();
  }

  function updateHud() {
    document.getElementById('hudStep').textContent = step;
    document.getElementById('hudVal').textContent = seq[seq.length - 1];
    document.getElementById('hudArcs').textContent = arcs.length;
  }

  function seqToLinearXY(val) {
    return { x: val * 4, y: 0 };
  }

  function seqToSpiralXY(val) {
    var a = 0.15;
    var theta = Math.sqrt(val) * 2.5;
    var r = a * theta * 30;
    return { x: Math.cos(theta) * r, y: Math.sin(theta) * r };
  }

  function seqToXY(val) {
    return mode === 1 ? seqToSpiralXY(val) : seqToLinearXY(val);
  }

  function autoFit() {
    if (userPanned || arcs.length === 0) return;
    if (mode === 0) {
      var maxVal = 0;
      for (var i = 0; i < arcs.length; i++) {
        if (arcs[i].to > maxVal) maxVal = arcs[i].to;
      }
      var span = maxVal * 4;
      var maxArcH = 0;
      for (var i = 0; i < arcs.length; i++) {
        var r = (arcs[i].to - arcs[i].from) * 4 / 2;
        if (r > maxArcH) maxArcH = r;
      }
      var viewW = span + 100;
      var viewH = maxArcH * 2 + 100;
      var zx = W / viewW;
      var zy = H / viewH;
      targetCam.zoom = Math.min(zx, zy, 2);
      targetCam.x = span / 2;
      targetCam.y = 0;
    } else {
      var maxR = 0;
      for (var i = 0; i < arcs.length; i++) {
        var p1 = seqToSpiralXY(arcs[i].from);
        var p2 = seqToSpiralXY(arcs[i].to);
        var d1 = Math.sqrt(p1.x * p1.x + p1.y * p1.y);
        var d2 = Math.sqrt(p2.x * p2.x + p2.y * p2.y);
        if (d1 > maxR) maxR = d1;
        if (d2 > maxR) maxR = d2;
      }
      var viewSize = maxR * 2 + 200;
      targetCam.zoom = Math.min(W, H) / viewSize;
      targetCam.x = 0;
      targetCam.y = 0;
    }
  }

  function worldToScreen(wx, wy) {
    return {
      x: (wx - cam.x) * cam.zoom + W / 2,
      y: -(wy - cam.y) * cam.zoom + H / 2
    };
  }

  function drawArc(arc, now) {
    var age = (now - arc.birth) / 1000;
    var fadeIn = Math.min(age * 3, 1);
    var t = (arc.step % 200) / 200;
    var col = lerpColor(TEAL, PURPLE, t);

    if (mode === 0) {
      var cx = (arc.from + arc.to) / 2 * 4;
      var r = (arc.to - arc.from) * 4 / 2;
      var sc = worldToScreen(cx, 0);
      var sr = r * cam.zoom;

      if (sc.x + sr < -50 || sc.x - sr > W + 50) return;
      if (sc.y + sr < -50 || sc.y - sr > H + 50) return;

      ctx.beginPath();
      if (arc.dir > 0) {
        ctx.arc(sc.x, sc.y, sr, Math.PI, 0, false);
      } else {
        ctx.arc(sc.x, sc.y, sr, 0, Math.PI, false);
      }
      ctx.strokeStyle = rgba(col, 0.6 * fadeIn);
      ctx.lineWidth = arcWidth;
      ctx.stroke();
    } else {
      var p1 = seqToSpiralXY(arc.from);
      var p2 = seqToSpiralXY(arc.to);
      var s1 = worldToScreen(p1.x, p1.y);
      var s2 = worldToScreen(p2.x, p2.y);

      var mx = (s1.x + s2.x) / 2;
      var my = (s1.y + s2.y) / 2;
      var dx = s2.x - s1.x;
      var dy = s2.y - s1.y;
      var dist = Math.sqrt(dx * dx + dy * dy);
      var bulge = dist * 0.3 * arc.dir;
      var nx = -dy / (dist || 1);
      var ny = dx / (dist || 1);
      var cpx = mx + nx * bulge;
      var cpy = my + ny * bulge;

      ctx.beginPath();
      ctx.moveTo(s1.x, s1.y);
      ctx.quadraticCurveTo(cpx, cpy, s2.x, s2.y);
      ctx.strokeStyle = rgba(col, 0.6 * fadeIn);
      ctx.lineWidth = arcWidth;
      ctx.stroke();
    }
  }

  function drawNumberLine() {
    if (mode !== 0) return;
    var s0 = worldToScreen(0, 0);

    var left = worldToScreen(-50, 0);
    var right = worldToScreen(seq[seq.length - 1] * 4 + 200, 0);

    ctx.beginPath();
    ctx.moveTo(Math.max(0, left.x), s0.y);
    ctx.lineTo(Math.min(W, right.x), s0.y);
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 1;
    ctx.stroke();

    var spacing = 10;
    if (cam.zoom < 0.3) spacing = 100;
    else if (cam.zoom < 0.8) spacing = 50;
    else if (cam.zoom < 1.5) spacing = 20;

    var startVal = Math.max(0, Math.floor((-W / 2 / cam.zoom + cam.x) / 4 / spacing) * spacing);
    var endVal = Math.ceil((W / 2 / cam.zoom + cam.x) / 4 / spacing) * spacing;

    ctx.font = '9px "JetBrains Mono", monospace';
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.textAlign = 'center';

    for (var v = startVal; v <= endVal; v += spacing) {
      var sx = worldToScreen(v * 4, 0);
      if (sx.x < -20 || sx.x > W + 20) continue;
      ctx.beginPath();
      ctx.moveTo(sx.x, s0.y - 3);
      ctx.lineTo(sx.x, s0.y + 3);
      ctx.strokeStyle = 'rgba(255,255,255,0.1)';
      ctx.lineWidth = 1;
      ctx.stroke();
      ctx.fillText(v, sx.x, s0.y + 14);
    }
  }

  function drawCurrentDot() {
    var val = seq[seq.length - 1];
    var p = seqToXY(val);
    var s = worldToScreen(p.x, p.y);
    var pulse = 0.5 + 0.5 * Math.sin(performance.now() / 200);
    var r = 4 + pulse * 2;

    ctx.beginPath();
    ctx.arc(s.x, s.y, r, 0, Math.PI * 2);
    ctx.fillStyle = rgba(TEAL, 0.8);
    ctx.fill();

    ctx.beginPath();
    ctx.arc(s.x, s.y, r + 4, 0, Math.PI * 2);
    ctx.strokeStyle = rgba(TEAL, 0.2 + pulse * 0.15);
    ctx.lineWidth = 1;
    ctx.stroke();
  }

  var lastAutoStep = 0;

  function draw(now) {
    if (autoPlay && now - lastAutoStep > 1000 / speed) {
      nextStep();
      autoFit();
      lastAutoStep = now;
    }

    cam.x += (targetCam.x - cam.x) * 0.08;
    cam.y += (targetCam.y - cam.y) * 0.08;
    cam.zoom += (targetCam.zoom - cam.zoom) * 0.08;

    ctx.clearRect(0, 0, W, H);

    drawNumberLine();

    for (var i = 0; i < arcs.length; i++) {
      drawArc(arcs[i], now);
    }

    drawCurrentDot();

    requestAnimationFrame(draw);
  }

  for (var i = 0; i < 20; i++) {
    nextStep();
  }
  autoFit();
  cam.x = targetCam.x;
  cam.y = targetCam.y;
  cam.zoom = targetCam.zoom;

  requestAnimationFrame(draw);

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    dragging = true;
    dragStart.x = e.clientX;
    dragStart.y = e.clientY;
    camStart.x = targetCam.x;
    camStart.y = targetCam.y;
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var dx = e.clientX - dragStart.x;
    var dy = e.clientY - dragStart.y;
    targetCam.x = camStart.x - dx / cam.zoom;
    targetCam.y = camStart.y + dy / cam.zoom;
    if (Math.abs(dx) > 5 || Math.abs(dy) > 5) userPanned = true;
  });

  window.addEventListener('mouseup', function() { dragging = false; });

  C.addEventListener('click', function(e) {
    if (userPanned) return;
    if (!autoPlay) {
      nextStep();
      autoFit();
    }
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var factor = e.deltaY > 0 ? 0.9 : 1.1;
    targetCam.zoom *= factor;
    targetCam.zoom = Math.max(0.01, Math.min(10, targetCam.zoom));
    userPanned = true;
  }, { passive: false });

  var touchDist0 = 0;
  var touchMid0 = { x: 0, y: 0 };
  var pinching = false;

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 2) {
      pinching = true;
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      touchDist0 = Math.sqrt(dx * dx + dy * dy);
      touchMid0.x = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      touchMid0.y = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      camStart.x = targetCam.x;
      camStart.y = targetCam.y;
    } else if (e.touches.length === 1) {
      dragging = true;
      dragStart.x = e.touches[0].clientX;
      dragStart.y = e.touches[0].clientY;
      camStart.x = targetCam.x;
      camStart.y = targetCam.y;
      userPanned = false;
    }
  }, { passive: true });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (pinching && e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var dist = Math.sqrt(dx * dx + dy * dy);
      var scale = dist / touchDist0;
      targetCam.zoom = Math.max(0.01, Math.min(10, cam.zoom * scale));
      touchDist0 = dist;
      userPanned = true;
    } else if (dragging && e.touches.length === 1) {
      var ddx = e.touches[0].clientX - dragStart.x;
      var ddy = e.touches[0].clientY - dragStart.y;
      targetCam.x = camStart.x - ddx / cam.zoom;
      targetCam.y = camStart.y + ddy / cam.zoom;
      if (Math.abs(ddx) > 5 || Math.abs(ddy) > 5) userPanned = true;
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length < 2) pinching = false;
    if (e.touches.length === 0) {
      if (!userPanned && !autoPlay) {
        nextStep();
        autoFit();
      }
      dragging = false;
    }
  }, { passive: true });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var sldSpeed = document.getElementById('sldSpeed');
  var valSpeed = document.getElementById('valSpeed');
  sldSpeed.addEventListener('input', function() {
    speed = parseInt(this.value);
    valSpeed.textContent = speed;
  });

  var sldWidth = document.getElementById('sldWidth');
  var valWidth = document.getElementById('valWidth');
  sldWidth.addEventListener('input', function() {
    arcWidth = parseInt(this.value);
    valWidth.textContent = arcWidth;
  });

  document.querySelectorAll('#modeGroup .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#modeGroup .preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      mode = parseInt(this.dataset.m);
      userPanned = false;
      autoFit();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  var btnAuto = document.getElementById('btnAuto');
  btnAuto.addEventListener('click', function() {
    autoPlay = !autoPlay;
    this.textContent = 'AUTO: ' + (autoPlay ? 'ON' : 'OFF');
    this.classList.toggle('active', autoPlay);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    seq = [0];
    visited = new Set([0]);
    arcs = [];
    step = 0;
    userPanned = false;
    targetCam = { x: 0, y: 0, zoom: 1 };
    updateHud();
    for (var i = 0; i < 20; i++) nextStep();
    autoFit();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
