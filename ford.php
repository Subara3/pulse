<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ford');

$pageName = 'ford';
$pageTitle = 'FORD - PULSE';
$pageDesc = 'フォード円。すべての既約分数 p/q が x 軸上で接する円となり、隣接円はファレイ数列の隣人と一致する。';
$pageCanonical = 'https://pulse.subara3.com/ford.php';

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
  DENOM ≤ <span class="v" id="hud-denom">40</span><br>
  CIRCLES: <span class="v" id="hud-count">0</span><br>
  ZOOM: <span class="v" id="hud-zoom">1.00×</span><br>
  SELECT: <span class="v" id="hud-sel">—</span>
</div>

<div id="info">
  <span class="v">ford</span><br>
  r(p/q) = 1 / (2q²)
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
  ['type' => 'click', 'label' => 'select'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label>DENOM</label>
    <input type="range" id="sl-denom" min="6" max="80" step="1" value="40">
    <span class="slider-value" id="sv-denom">40</span>
  </div>
  <div class="slider-row">
    <label>STROKE</label>
    <input type="range" id="sl-stroke" min="0.4" max="2.5" step="0.1" value="1.1">
    <span class="slider-value" id="sv-stroke">1.1</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-fill">FILL</button>
    <button class="control-btn active" id="btn-axis">AXIS</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-fit">FIT</button>
    <button class="control-btn" id="btn-reset">RESET</button>
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

  function gcd(a, b) { while (b) { var t = b; b = a % b; a = t; } return a; }

  // Generate all Farey fractions p/q (in [0,1], lowest terms) with q <= N, sorted by value.
  var circles = [];
  var maxDenom = 40;

  function rebuild() {
    circles.length = 0;
    circles.push({ p: 0, q: 1, x: 0, r: 0.5 });
    for (var q = 1; q <= maxDenom; q++) {
      for (var p = 1; p <= q; p++) {
        if (gcd(p, q) !== 1) continue;
        var x = p / q;
        var r = 1 / (2 * q * q);
        circles.push({ p: p, q: q, x: x, r: r });
      }
    }
    circles.sort(function(a, b) {
      if (a.x !== b.x) return a.x - b.x;
      return a.q - b.q;
    });
    // dedup (1/1 will appear from q=1 only)
    var seen = Object.create(null);
    circles = circles.filter(function(c) {
      var k = c.p + '/' + c.q;
      if (seen[k]) return false;
      seen[k] = 1;
      return true;
    });
    hudCount.textContent = circles.length;
  }

  // World→screen: x in [0,1] maps across canvas width with margin; y up from axis.
  var camX = 0.5, camY = 0;       // world point at screen center / axis
  var camScale = 1;                // 1 = fit [0,1] into width with margin

  function baseScale() { return Math.min(W, H * 1.3) * 0.85; }
  function s2w(sx, sy) {
    var bs = baseScale() * camScale;
    var axisY = H - Math.min(64, H * 0.08);
    var wx = camX + (sx - W / 2) / bs;
    var wy = (axisY - sy) / bs + camY;
    return [wx, wy];
  }
  function w2s(wx, wy) {
    var bs = baseScale() * camScale;
    var axisY = H - Math.min(64, H * 0.08);
    var sx = W / 2 + (wx - camX) * bs;
    var sy = axisY - (wy - camY) * bs;
    return [sx, sy];
  }

  var slDenom = document.getElementById('sl-denom');
  var slStroke = document.getElementById('sl-stroke');
  var svDenom = document.getElementById('sv-denom');
  var svStroke = document.getElementById('sv-stroke');
  var hudDenom = document.getElementById('hud-denom');
  var hudCount = document.getElementById('hud-count');
  var hudZoom = document.getElementById('hud-zoom');
  var hudSel = document.getElementById('hud-sel');

  var stroke = 1.1;
  var doFill = false;
  var showAxis = true;
  var selectedIdx = -1;
  var growT = 0;          // 0→1: growth animation timeline
  var growSpeed = 1.6;

  function updateHUD() {
    hudDenom.textContent = maxDenom;
    hudZoom.textContent = camScale.toFixed(2) + '×';
    svDenom.textContent = maxDenom;
    svStroke.textContent = stroke.toFixed(1);
    if (selectedIdx >= 0 && circles[selectedIdx]) {
      var c = circles[selectedIdx];
      hudSel.textContent = c.p + '/' + c.q;
    } else {
      hudSel.textContent = '—';
    }
  }

  slDenom.addEventListener('input', function() {
    var prev = maxDenom;
    maxDenom = parseInt(this.value);
    if (maxDenom !== prev) {
      rebuild();
      selectedIdx = -1;
      if (maxDenom > prev) growT = Math.min(growT, 0.6);
      if (typeof SFX !== 'undefined') SFX.play('tick', 80);
    }
    updateHUD();
  });
  slStroke.addEventListener('input', function() {
    stroke = parseFloat(this.value);
    updateHUD();
  });
  document.getElementById('btn-fill').addEventListener('click', function() {
    doFill = !doFill;
    this.classList.toggle('active', doFill);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-axis').addEventListener('click', function() {
    showAxis = !showAxis;
    this.classList.toggle('active', showAxis);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-fit').addEventListener('click', function() {
    camX = 0.5; camY = 0; camScale = 1;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    camX = 0.5; camY = 0; camScale = 1;
    maxDenom = 40; stroke = 1.1; doFill = false; showAxis = true;
    selectedIdx = -1;
    growT = 0;
    slDenom.value = 40;
    slStroke.value = 1.1;
    document.getElementById('btn-fill').classList.remove('active');
    document.getElementById('btn-axis').classList.add('active');
    rebuild();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Mouse interactions
  var dragging = false;
  var dragX = 0, dragY = 0;
  var dragMoved = false;
  var dragStartCamX = 0, dragStartCamY = 0;

  function hitTest(sx, sy) {
    var bs = baseScale() * camScale;
    var best = -1, bestD = Infinity;
    var w = s2w(sx, sy);
    for (var i = 0; i < circles.length; i++) {
      var c = circles[i];
      var dx = c.x - w[0];
      var dy = c.r - w[1];
      var d = Math.sqrt(dx * dx + dy * dy);
      var slack = Math.max(2 / bs, c.r * 0.05);
      if (d <= c.r + slack && d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    dragging = true;
    dragX = e.clientX; dragY = e.clientY;
    dragMoved = false;
    dragStartCamX = camX; dragStartCamY = camY;
  });
  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var dx = e.clientX - dragX;
    var dy = e.clientY - dragY;
    if (Math.abs(dx) + Math.abs(dy) > 4) dragMoved = true;
    var bs = baseScale() * camScale;
    camX = dragStartCamX - dx / bs;
    camY = dragStartCamY + dy / bs;
  });
  window.addEventListener('mouseup', function(e) {
    if (dragging && !dragMoved) {
      var idx = hitTest(e.clientX, e.clientY);
      if (idx >= 0) {
        selectedIdx = idx;
        updateHUD();
        if (typeof SFX !== 'undefined') SFX.play('click');
      } else {
        selectedIdx = -1;
        updateHUD();
      }
    }
    dragging = false;
  });
  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var w = s2w(e.clientX, e.clientY);
    var factor = e.deltaY > 0 ? 0.88 : 1.13;
    camScale *= factor;
    if (camScale < 0.5) camScale = 0.5;
    if (camScale > 60) camScale = 60;
    var w2 = s2w(e.clientX, e.clientY);
    camX += w[0] - w2[0];
    camY += w[1] - w2[1];
    updateHUD();
  }, { passive: false });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // Touch
  var tId = null, tx = 0, ty = 0, tMoved = false;
  var pinch = null;

  function dist(a, b) { return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      var t = e.touches[0];
      tId = t.identifier;
      tx = t.clientX; ty = t.clientY;
      tMoved = false;
      dragStartCamX = camX; dragStartCamY = camY;
    } else if (e.touches.length === 2) {
      pinch = { d: dist(e.touches[0], e.touches[1]), s: camScale,
                cx: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                cy: (e.touches[0].clientY + e.touches[1].clientY) / 2,
                wx: 0, wy: 0 };
      var w = s2w(pinch.cx, pinch.cy);
      pinch.wx = w[0]; pinch.wy = w[1];
      tId = null;
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 1 && tId !== null && e.touches[0].identifier === tId) {
      var t = e.touches[0];
      var dx = t.clientX - tx, dy = t.clientY - ty;
      if (Math.abs(dx) + Math.abs(dy) > 6) tMoved = true;
      var bs = baseScale() * camScale;
      camX = dragStartCamX - dx / bs;
      camY = dragStartCamY + dy / bs;
    } else if (e.touches.length === 2 && pinch) {
      var d = dist(e.touches[0], e.touches[1]);
      camScale = Math.max(0.5, Math.min(60, pinch.s * (d / pinch.d)));
      var w2 = s2w(pinch.cx, pinch.cy);
      camX += pinch.wx - w2[0];
      camY += pinch.wy - w2[1];
      updateHUD();
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (tId !== null && !tMoved) {
        var idx = hitTest(tx, ty);
        if (idx >= 0) {
          selectedIdx = idx;
          updateHUD();
          if (typeof SFX !== 'undefined') SFX.play('click');
        }
      }
      tId = null; pinch = null;
    }
  });

  function color(q, alpha, hi) {
    var t = Math.min(1, (q - 1) / Math.max(1, maxDenom - 1));
    var h = 175 + t * 100;
    var s = 70 - t * 10;
    var l = 60 - t * 18;
    if (hi) { h = 288; s = 95; l = 74; }
    return 'hsla(' + h + ',' + s + '%,' + l + '%,' + alpha + ')';
  }

  function drawAxis() {
    var p1 = w2s(-0.05, 0);
    var p2 = w2s(1.05, 0);
    ctx.strokeStyle = 'rgba(255,255,255,0.16)';
    ctx.lineWidth = 0.7;
    ctx.beginPath();
    ctx.moveTo(0, p1[1]);
    ctx.lineTo(W, p2[1]);
    ctx.stroke();
    // ticks at 0, 1/4, 1/3, 1/2, 2/3, 3/4, 1
    var ticks = [[0,1],[1,4],[1,3],[1,2],[2,3],[3,4],[1,1]];
    ctx.fillStyle = 'rgba(255,255,255,0.32)';
    ctx.font = '10px "JetBrains Mono","Space Grotesk",monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (var i = 0; i < ticks.length; i++) {
      var p = ticks[i][0], q = ticks[i][1];
      var pos = w2s(p / q, 0);
      ctx.strokeStyle = 'rgba(255,255,255,0.22)';
      ctx.lineWidth = 0.6;
      ctx.beginPath();
      ctx.moveTo(pos[0], pos[1]);
      ctx.lineTo(pos[0], pos[1] + 5);
      ctx.stroke();
      ctx.fillText(p + '/' + q, pos[0], pos[1] + 7);
    }
  }

  function draw() {
    requestAnimationFrame(draw);

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (growT < 1) growT = Math.min(1, growT + 0.016 * growSpeed);

    if (showAxis) drawAxis();

    var bs = baseScale() * camScale;
    var visibleCount = Math.floor(circles.length * growT);
    if (visibleCount < 2) visibleCount = 2;

    var nbrs = null;
    if (selectedIdx >= 0 && selectedIdx < circles.length) {
      nbrs = {
        prev: selectedIdx > 0 ? selectedIdx - 1 : -1,
        next: selectedIdx < circles.length - 1 ? selectedIdx + 1 : -1,
      };
    }

    // Cull + draw
    for (var i = 0; i < visibleCount; i++) {
      var c = circles[i];
      var sp = w2s(c.x, c.r);
      var rp = c.r * bs;
      if (rp < 0.45) continue;
      if (sp[0] + rp < -10 || sp[0] - rp > W + 10) continue;
      if (sp[1] + rp < -10 || sp[1] - rp > H + 10) continue;

      var isSel = (i === selectedIdx);
      var isNbr = nbrs && (i === nbrs.prev || i === nbrs.next);
      var alpha = Math.min(0.85, 0.18 + 0.6 / Math.max(1, Math.sqrt(c.q)));
      if (isNbr) alpha = 0.95;

      if (doFill) {
        ctx.fillStyle = color(c.q, alpha * 0.18, isSel);
        ctx.beginPath();
        ctx.arc(sp[0], sp[1], rp, 0, 6.2832);
        ctx.fill();
      }
      ctx.strokeStyle = color(c.q, alpha, isSel);
      ctx.lineWidth = stroke * (isSel ? 2.6 : (isNbr ? 1.8 : 1));
      ctx.beginPath();
      ctx.arc(sp[0], sp[1], rp, 0, 6.2832);
      ctx.stroke();

      // Label for big circles or selected
      if ((rp > 28 || isSel) && c.q > 0) {
        ctx.fillStyle = isSel ? '#00f5d4' : 'rgba(255,255,255,' + (Math.min(0.7, rp / 80)) + ')';
        ctx.font = (isSel ? '700 ' : '') + Math.min(13, Math.max(9, rp / 4)) + 'px "JetBrains Mono","Space Grotesk",monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(c.p + '/' + c.q, sp[0], sp[1]);
      }
    }

    if (selectedIdx >= 0 && circles[selectedIdx]) {
      var cs2 = circles[selectedIdx];
      var sp2 = w2s(cs2.x, cs2.r);
      var pulse = 1 + 0.2 * Math.sin(performance.now() / 220);
      ctx.strokeStyle = 'rgba(0,245,212,' + (0.55 + 0.25 * Math.sin(performance.now() / 220)) + ')';
      ctx.lineWidth = stroke * 2.8;
      ctx.beginPath();
      ctx.arc(sp2[0], sp2[1], cs2.r * bs * pulse, 0, 6.2832);
      ctx.stroke();
    }
  }

  rebuild();
  updateHUD();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
