<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lightning');

$pageName = 'lightning';
$pageTitle = 'LIGHTNING - PULSE';
$pageDesc = 'Dielectric breakdown model. Branching discharges race across the canvas and leave Lichtenberg-like traces.';
$pageCanonical = 'https://pulse.subara3.com/lightning.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  BOLTS: <span id="hudB" class="v">0</span><br>
  STRIKES: <span id="hudS" class="v">0</span>
</div>

<div id="info">
  dielectric breakdown model<br>
  <span class="v">Lichtenberg figure</span><br>
  fractal discharge
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'strike'],
  ['type' => 'drag', 'label' => 'arc'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">BRANCH</label>
    <div class="slider-row">
      <input type="range" id="sBranch" min="10" max="80" value="45">
      <span class="slider-value" id="vBranch">0.45</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">DEPTH</label>
    <div class="slider-row">
      <input type="range" id="sDepth" min="4" max="10" value="7">
      <span class="slider-value" id="vDepth">7</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">INTERVAL</label>
    <div class="slider-row">
      <input type="range" id="sInterval" min="5" max="50" value="25">
      <span class="slider-value" id="vInterval">2.5s</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnAuto">AUTO</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H;
  var offC, offX;

  function resize() {
    var old = offC;
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    offC = document.createElement('canvas');
    offC.width = W; offC.height = H;
    offX = offC.getContext('2d');
    if (old) offX.drawImage(old, 0, 0);
  }
  resize();
  addEventListener('resize', resize);

  var bolts = [];
  var branchProb = 0.45;
  var maxDepth = 7;
  var autoMode = true;
  var autoInterval = 900;
  var lastAuto = 0;
  var totalStrikes = 0;
  var flashIntensity = 0;
  var MAX_BOLTS = 20;

  function genBolt(x0, y0, x1, y1) {
    var segs = [];
    var ox = x0, oy = y0;

    (function sub(ax, ay, bx, by, d) {
      var dx = bx - ax, dy = by - ay;
      var len = Math.sqrt(dx * dx + dy * dy);
      if (d >= maxDepth || len < 3) {
        segs.push({x0: ax, y0: ay, x1: bx, y1: by, d: d, dist: 0});
        return;
      }
      var nx = -dy / len, ny = dx / len;
      var spread = len * 0.25;
      var off = (Math.random() - 0.5) * 2 * spread;
      var mx = (ax + bx) / 2 + nx * off;
      var my = (ay + by) / 2 + ny * off;

      sub(ax, ay, mx, my, d + 1);
      sub(mx, my, bx, by, d + 1);

      if (d < maxDepth - 1 && Math.random() < branchProb * Math.pow(0.65, d)) {
        var angle = Math.atan2(by - ay, bx - ax) + (Math.random() > 0.5 ? 1 : -1) * (0.3 + Math.random() * 0.7);
        var bl = len * (0.15 + Math.random() * 0.35);
        sub(mx, my, mx + Math.cos(angle) * bl, my + Math.sin(angle) * bl, d + 2);
      }
    })(x0, y0, x1, y1, 0);

    var maxDist = 0;
    for (var i = 0; i < segs.length; i++) {
      var dx = segs[i].x0 - ox, dy = segs[i].y0 - oy;
      segs[i].dist = Math.sqrt(dx * dx + dy * dy);
      if (segs[i].dist > maxDist) maxDist = segs[i].dist;
    }

    var gf = Math.max(12, Math.ceil(maxDist / 25));
    return {
      segs: segs,
      maxDist: maxDist || 1,
      f: 0,
      growFrames: gf,
      flashEnd: gf + 6,
      fadeEnd: gf + 46,
      glowOuter: Math.min(18, Math.max(4, maxDist * 0.06)),
      glowMid: Math.min(10, Math.max(2, maxDist * 0.035))
    };
  }

  function doStrike(x, y) {
    if (bolts.length >= MAX_BOLTS) return;
    var tx, ty;
    if (y < H * 0.35) {
      ty = H * (0.65 + Math.random() * 0.3);
      tx = x + (Math.random() - 0.5) * W * 0.5;
    } else if (y > H * 0.65) {
      ty = H * (0.02 + Math.random() * 0.25);
      tx = x + (Math.random() - 0.5) * W * 0.5;
    } else {
      ty = Math.random() > 0.5 ? H * (0.8 + Math.random() * 0.15) : H * (0.02 + Math.random() * 0.15);
      tx = x + (Math.random() - 0.5) * W * 0.4;
    }
    tx = Math.max(10, Math.min(W - 10, tx));
    ty = Math.max(10, Math.min(H - 10, ty));

    bolts.push(genBolt(x, y, tx, ty));
    totalStrikes++;
    flashIntensity = Math.min(flashIntensity + 0.18, 0.32);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function doArc(x, y) {
    if (bolts.length >= MAX_BOLTS) return;
    var cx = x + (Math.random() - 0.5) * 80;
    var cy = y + (Math.random() - 0.5) * 80;
    var b = genBolt(x, y, cx, cy);
    b.growFrames = 3;
    b.flashEnd = 5;
    b.fadeEnd = 18;
    bolts.push(b);
    if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
  }

  function drawBolt(b) {
    var segs = b.segs, n = segs.length;
    var alpha, radius;

    if (b.f < b.growFrames) {
      radius = b.maxDist * (b.f / b.growFrames);
      alpha = 1;
    } else if (b.f < b.flashEnd) {
      radius = b.maxDist * 2;
      alpha = 0.7 + Math.random() * 0.3;
    } else if (b.f < b.fadeEnd) {
      radius = b.maxDist * 2;
      var t = (b.f - b.flashEnd) / (b.fadeEnd - b.flashEnd);
      alpha = (1 - t) * (1 - t);
    } else {
      return false;
    }

    X.save();
    X.globalCompositeOperation = 'lighter';
    X.lineCap = 'round';

    X.beginPath();
    for (var i = 0; i < n; i++) {
      if (segs[i].dist > radius) continue;
      X.moveTo(segs[i].x0, segs[i].y0);
      X.lineTo(segs[i].x1, segs[i].y1);
    }
    X.strokeStyle = 'rgba(123,44,191,' + (alpha * 0.06) + ')';
    X.lineWidth = b.glowOuter;
    X.stroke();

    X.beginPath();
    for (var i = 0; i < n; i++) {
      if (segs[i].dist > radius) continue;
      X.moveTo(segs[i].x0, segs[i].y0);
      X.lineTo(segs[i].x1, segs[i].y1);
    }
    X.strokeStyle = 'rgba(0,245,212,' + (alpha * 0.15) + ')';
    X.lineWidth = b.glowMid;
    X.stroke();

    for (var dd = 0; dd <= maxDepth + 2; dd++) {
      var df = Math.max(0.15, 1 - dd / (maxDepth + 2));
      X.beginPath();
      var has = false;
      for (var i = 0; i < n; i++) {
        if (segs[i].d !== dd || segs[i].dist > radius) continue;
        X.moveTo(segs[i].x0, segs[i].y0);
        X.lineTo(segs[i].x1, segs[i].y1);
        has = true;
      }
      if (has) {
        X.strokeStyle = 'rgba(200,255,248,' + (alpha * 0.85 * df) + ')';
        X.lineWidth = Math.max(0.5, 2.5 - dd * 0.2);
        X.stroke();
      }
    }

    if (b.f < b.growFrames) {
      var lo = radius - 15, hi = radius;
      X.beginPath();
      for (var i = 0; i < n; i++) {
        if (segs[i].dist < lo || segs[i].dist > hi) continue;
        X.moveTo(segs[i].x1, segs[i].y1);
        X.arc(segs[i].x1, segs[i].y1, 3 + Math.random() * 3, 0, Math.PI * 2);
      }
      X.fillStyle = 'rgba(0,245,212,' + (alpha * 0.5) + ')';
      X.fill();
    }

    X.restore();
    b.f++;
    return true;
  }

  function bakeResidual(b) {
    offX.save();
    offX.globalCompositeOperation = 'lighter';
    offX.lineCap = 'round';
    for (var dd = 0; dd <= maxDepth + 2; dd++) {
      var df = Math.max(0.06, 1 - dd / (maxDepth + 2));
      offX.beginPath();
      var has = false;
      for (var i = 0; i < b.segs.length; i++) {
        if (b.segs[i].d !== dd) continue;
        offX.moveTo(b.segs[i].x0, b.segs[i].y0);
        offX.lineTo(b.segs[i].x1, b.segs[i].y1);
        has = true;
      }
      if (has) {
        offX.strokeStyle = 'rgba(0,245,212,' + (0.06 * df) + ')';
        offX.lineWidth = Math.max(0.3, 1 - dd * 0.08);
        offX.stroke();
      }
    }
    offX.restore();
  }

  function render() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    X.drawImage(offC, 0, 0);

    for (var i = bolts.length - 1; i >= 0; i--) {
      if (!drawBolt(bolts[i])) {
        bakeResidual(bolts[i]);
        bolts.splice(i, 1);
      }
    }

    if (flashIntensity > 0.002) {
      X.fillStyle = 'rgba(0,245,212,' + (flashIntensity * 0.08) + ')';
      X.fillRect(0, 0, W, H);
      flashIntensity *= 0.88;
    }

    document.getElementById('hudB').textContent = bolts.length;
    document.getElementById('hudS').textContent = totalStrikes;
  }

  function loop() {
    var now = Date.now();
    if (autoMode && now - lastAuto > autoInterval) {
      doStrike(W * (0.15 + Math.random() * 0.7), 5);
      lastAuto = now;
    }
    render();
    requestAnimationFrame(loop);
  }

  var dragging = false;
  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) {
      doStrike(e.clientX, e.clientY);
      dragging = true;
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (dragging && Math.random() < 0.12) doArc(e.clientX, e.clientY);
  });
  C.addEventListener('mouseup', function() { dragging = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    doStrike(e.touches[0].clientX, e.touches[0].clientY);
    dragging = true;
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragging && e.touches.length > 0 && Math.random() < 0.15) {
      doArc(e.touches[0].clientX, e.touches[0].clientY);
    }
  }, {passive: false});
  C.addEventListener('touchend', function() { dragging = false; });

  document.getElementById('sBranch').addEventListener('input', function() {
    branchProb = this.value / 100;
    document.getElementById('vBranch').textContent = branchProb.toFixed(2);
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  document.getElementById('sDepth').addEventListener('input', function() {
    maxDepth = parseInt(this.value);
    document.getElementById('vDepth').textContent = maxDepth;
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  document.getElementById('sInterval').addEventListener('input', function() {
    autoInterval = this.value * 100;
    document.getElementById('vInterval').textContent = (autoInterval / 1000).toFixed(1) + 's';
  });
  document.getElementById('btnAuto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    this.textContent = autoMode ? 'AUTO' : 'MANUAL';
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btnClear').addEventListener('click', function() {
    offX.clearRect(0, 0, W, H);
    bolts = [];
    totalStrikes = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  lastAuto = Date.now() - autoInterval;
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>