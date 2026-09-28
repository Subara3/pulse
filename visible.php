<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('visible');

$pageName = 'visible';
$pageTitle = 'VISIBLE - PULSE';
$pageDesc = '原点に立って格子の点を見渡す。手前の点に隠されず見える点は、座標が互いに素な点だけ。その密度はちょうど 6/π²。';
$pageCanonical = 'https://pulse.subara3.com/visible.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.hud .dim{color:rgba(255,255,255,.3)}
.hud .target{color:rgba(181,140,255,.7);font-size:11px}
.reveal-btn{background:rgba(0,245,212,.1);border:1px solid rgba(0,245,212,.25);color:#00f5d4;font:11px/1 ui-monospace,monospace;padding:4px 10px;border-radius:4px;cursor:pointer;letter-spacing:.1em;margin-top:4px;display:inline-block}
.reveal-btn:hover{background:rgba(0,245,212,.2)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <span class="sub">VISIBLE</span><br>
  <b id="h-vis">0</b> <span class="dim">/</span> <b id="h-all">0</b><br>
  <span id="h-density">DENSITY <span class="dim">?</span></span><br>
  <button class="reveal-btn" id="btn-reveal">GUESS</button>
</div>
<div class="mark">VISIBLE</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inspect'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="mode-btn active" id="btn-scan">SCAN</button>
      <button class="mode-btn" id="btn-rays">RAYS</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="ctrl-label">RANGE</label>
      <input type="range" id="sl-zoom" min="6" max="70" value="28">
      <span class="slider-value" id="sv-zoom">28</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){ 'use strict';

var c = document.getElementById('canvas'), ctx = c.getContext('2d');
var W, H, dpr;
var TAU = Math.PI * 2;

var zoom = 28, panX = 0, panY = 0;
var scanAngle = 0, mode = 'scan', revealed = false;
var selected = null, time = 0;
var cntR = 0, cntVis = 0, cntAll = 0;
var sparks = [];

var ptrDown = false, lastPX = 0, lastPY = 0, moved = false;
var pinching = false, pinchD0 = 0, zoom0 = 0;

var hVis = document.getElementById('h-vis');
var hAll = document.getElementById('h-all');
var hDensity = document.getElementById('h-density');
var btnReveal = document.getElementById('btn-reveal');
var btnScan = document.getElementById('btn-scan');
var btnRays = document.getElementById('btn-rays');
var slZoom = document.getElementById('sl-zoom');
var svZoom = document.getElementById('sv-zoom');
var btnReset = document.getElementById('btn-reset');

function gcd(a, b) {
  if (a < 0) a = -a;
  if (b < 0) b = -b;
  while (b) { var t = b; b = a % b; a = t; }
  return a;
}

function resize() {
  dpr = devicePixelRatio || 1;
  W = innerWidth; H = innerHeight;
  c.width = W * dpr; c.height = H * dpr;
  c.style.width = W + 'px'; c.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function g2s(gx, gy) {
  return [W * 0.5 + (gx - panX) * zoom, H * 0.5 - (gy - panY) * zoom];
}

function s2g(sx, sy) {
  return [Math.round((sx - W * 0.5) / zoom + panX),
          Math.round(-(sy - H * 0.5) / zoom + panY)];
}

function visRange() {
  var hw = W * 0.5 / zoom + 2, hh = H * 0.5 / zoom + 2;
  var lim = 130;
  return {
    x0: Math.max(Math.floor(panX - hw), -lim),
    x1: Math.min(Math.ceil(panX + hw), lim),
    y0: Math.max(Math.floor(panY - hh), -lim),
    y1: Math.min(Math.ceil(panY + hh), lim)
  };
}

function stepCount() {
  for (var i = 0; i < 4 && cntR < 600; i++) {
    cntR++;
    var r = cntR;
    for (var x = -r; x <= r; x++) {
      cntAll++; if (gcd(x, r) === 1) cntVis++;
      cntAll++; if (gcd(x, r) === 1) cntVis++;
    }
    for (var y = -(r - 1); y <= r - 1; y++) {
      cntAll++; if (gcd(r, y) === 1) cntVis++;
      cntAll++; if (gcd(r, y) === 1) cntVis++;
    }
  }
}

function zoomAt(sx, sy, factor) {
  var wx = panX + (sx - W * 0.5) / zoom;
  var wy = panY - (sy - H * 0.5) / zoom;
  zoom = Math.max(4, Math.min(80, zoom * factor));
  panX = wx - (sx - W * 0.5) / zoom;
  panY = wy + (sy - H * 0.5) / zoom;
  slZoom.value = Math.round(zoom);
  svZoom.textContent = Math.round(zoom);
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var rng = visRange();
  var os = g2s(0, 0), osx = os[0], osy = os[1];
  var pr = Math.max(1.2, Math.min(zoom * 0.13, 5.5));
  var nPts = (rng.x1 - rng.x0 + 1) * (rng.y1 - rng.y0 + 1);
  var dense = nPts > 12000;

  if (zoom > 10 && !dense) {
    ctx.strokeStyle = 'rgba(255,255,255,0.02)';
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    for (var gx = rng.x0; gx <= rng.x1; gx++) {
      var sx = g2s(gx, 0)[0];
      ctx.moveTo(sx, 0); ctx.lineTo(sx, H);
    }
    for (var gy = rng.y0; gy <= rng.y1; gy++) {
      var sy = g2s(0, gy)[1];
      ctx.moveTo(0, sy); ctx.lineTo(W, sy);
    }
    ctx.stroke();
  }

  ctx.strokeStyle = 'rgba(0,245,212,0.08)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(0, osy); ctx.lineTo(W, osy);
  ctx.moveTo(osx, 0); ctx.lineTo(osx, H);
  ctx.stroke();

  if (mode === 'scan') {
    var bl = Math.sqrt(W * W + H * H);
    ctx.strokeStyle = 'rgba(0,245,212,0.2)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(osx, osy);
    ctx.lineTo(osx + Math.cos(scanAngle) * bl, osy - Math.sin(scanAngle) * bl);
    ctx.stroke();
    ctx.strokeStyle = 'rgba(0,245,212,0.03)';
    ctx.lineWidth = 12;
    ctx.stroke();
  }

  if (mode === 'rays' && !dense) {
    ctx.lineWidth = 0.5;
    for (var gx = rng.x0; gx <= rng.x1; gx++) {
      for (var gy = rng.y0; gy <= rng.y1; gy++) {
        if (gx === 0 && gy === 0) continue;
        if (gcd(gx, gy) !== 1) continue;
        var sp = g2s(gx, gy);
        var d = Math.sqrt(gx * gx + gy * gy);
        var a = Math.max(0.008, 0.07 / (1 + d * 0.02));
        ctx.strokeStyle = 'rgba(0,245,212,' + a + ')';
        ctx.beginPath();
        ctx.moveTo(osx, osy);
        ctx.lineTo(sp[0], sp[1]);
        ctx.stroke();
      }
    }
  }

  var visBatch = [], blkBatch = [], glowBatch = [];

  for (var gx = rng.x0; gx <= rng.x1; gx++) {
    for (var gy = rng.y0; gy <= rng.y1; gy++) {
      if (gx === 0 && gy === 0) continue;
      var sp = g2s(gx, gy);
      if (sp[0] < -10 || sp[0] > W + 10 || sp[1] < -10 || sp[1] > H + 10) continue;

      var g = gcd(gx, gy);
      var vis = g === 1;

      var pulse = 0;
      if (mode === 'scan') {
        var ptA = Math.atan2(gy, gx);
        var diff = ((ptA - scanAngle) % TAU + TAU) % TAU;
        if (diff > Math.PI) diff = TAU - diff;
        if (diff < 0.12) pulse = 1 - diff / 0.12;
      }

      if (vis) {
        visBatch.push(sp[0], sp[1], pr + pulse * 2, 0.6 + pulse * 0.4);
        if (!dense && zoom > 14) {
          glowBatch.push(sp[0], sp[1], pr * 2.5 + pulse * 4, 0.05 + pulse * 0.1);
        }
      } else {
        blkBatch.push(sp[0], sp[1], pr * 0.5, 0.15 + pulse * 0.1);
      }
    }
  }

  for (var i = 0; i < glowBatch.length; i += 4) {
    ctx.fillStyle = 'rgba(0,245,212,' + glowBatch[i + 3] + ')';
    ctx.beginPath();
    ctx.arc(glowBatch[i], glowBatch[i + 1], glowBatch[i + 2], 0, TAU);
    ctx.fill();
  }

  for (var i = 0; i < blkBatch.length; i += 4) {
    ctx.fillStyle = 'rgba(123,44,191,' + blkBatch[i + 3] + ')';
    ctx.beginPath();
    ctx.arc(blkBatch[i], blkBatch[i + 1], blkBatch[i + 2], 0, TAU);
    ctx.fill();
  }

  for (var i = 0; i < visBatch.length; i += 4) {
    ctx.fillStyle = 'rgba(0,245,212,' + visBatch[i + 3] + ')';
    ctx.beginPath();
    ctx.arc(visBatch[i], visBatch[i + 1], visBatch[i + 2], 0, TAU);
    ctx.fill();
  }

  ctx.fillStyle = '#00f5d4';
  ctx.beginPath(); ctx.arc(osx, osy, pr * 1.8, 0, TAU); ctx.fill();
  ctx.fillStyle = 'rgba(0,245,212,0.1)';
  ctx.beginPath(); ctx.arc(osx, osy, pr * 4, 0, TAU); ctx.fill();

  if (selected) drawSelected(osx, osy, pr);

  for (var i = sparks.length - 1; i >= 0; i--) {
    var s = sparks[i];
    s.life -= 0.025;
    if (s.life <= 0) { sparks.splice(i, 1); continue; }
    ctx.fillStyle = 'rgba(0,245,212,' + (s.life * 0.35) + ')';
    ctx.beginPath(); ctx.arc(s.x, s.y, s.r * s.life, 0, TAU); ctx.fill();
  }
}

function drawSelected(osx, osy, pr) {
  var gx = selected[0], gy = selected[1];
  var sp = g2s(gx, gy);
  var g = gcd(gx, gy);

  ctx.strokeStyle = 'rgba(0,245,212,0.8)';
  ctx.lineWidth = 1.5;
  ctx.beginPath(); ctx.arc(sp[0], sp[1], pr * 3.5, 0, TAU); ctx.stroke();

  if (g > 1) {
    var bx = gx / g, by = gy / g;
    var bp = g2s(bx, by);
    ctx.strokeStyle = 'rgba(181,140,255,0.45)';
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 4]);
    ctx.beginPath(); ctx.moveTo(osx, osy); ctx.lineTo(sp[0], sp[1]); ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(181,140,255,0.85)';
    ctx.beginPath(); ctx.arc(bp[0], bp[1], pr * 2, 0, TAU); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = (zoom > 20 ? 12 : 10) + 'px ui-monospace,monospace';
    ctx.textAlign = 'center';
    ctx.fillText('(' + gx + ', ' + gy + ')  gcd=' + g, sp[0], sp[1] - pr * 5);
    ctx.fillStyle = 'rgba(181,140,255,0.7)';
    ctx.fillText('BLOCKED BY (' + bx + ', ' + by + ')', sp[0], sp[1] - pr * 5 + 14);
  } else {
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = (zoom > 20 ? 12 : 10) + 'px ui-monospace,monospace';
    ctx.textAlign = 'center';
    ctx.fillText('(' + gx + ', ' + gy + ')  VISIBLE', sp[0], sp[1] - pr * 5);
  }
}

function tick() {
  scanAngle += 0.004;
  if (scanAngle > TAU) scanAngle -= TAU;
  time++;
  stepCount();

  if (time % 5 === 0 && mode === 'scan' && sparks.length < 60) {
    var d = 3 + Math.random() * 20;
    var a = scanAngle + (Math.random() - 0.5) * 0.06;
    var gx = Math.round(d * Math.cos(a));
    var gy = Math.round(d * Math.sin(a));
    if ((gx !== 0 || gy !== 0) && gcd(gx, gy) === 1) {
      var sp = g2s(gx, gy);
      if (sp[0] > 0 && sp[0] < W && sp[1] > 0 && sp[1] < H) {
        sparks.push({ x: sp[0], y: sp[1], life: 1, r: 5 + Math.random() * 5 });
      }
    }
  }

  hVis.textContent = cntVis;
  hAll.textContent = cntAll;
  if (revealed && cntAll > 0) {
    var pct = (cntVis / cntAll * 100).toFixed(2);
    hDensity.innerHTML = 'DENSITY <b>' + pct + '%</b>';
    if (cntR >= 100) {
      hDensity.innerHTML += ' <span class="target">→ 6/π² ≈ 60.79%</span>';
    }
  }

  draw();
  requestAnimationFrame(tick);
}

c.addEventListener('mousedown', function(e) {
  if (e.button === 2) {
    e.preventDefault();
    selected = null;
    SFX.play('click');
    return;
  }
  ptrDown = true; moved = false;
  lastPX = e.clientX; lastPY = e.clientY;
});

window.addEventListener('mousemove', function(e) {
  if (!ptrDown) return;
  var dx = e.clientX - lastPX, dy = e.clientY - lastPY;
  if (Math.abs(dx) + Math.abs(dy) > 3) moved = true;
  panX -= dx / zoom;
  panY += dy / zoom;
  lastPX = e.clientX; lastPY = e.clientY;
});

window.addEventListener('mouseup', function(e) {
  if (!moved && ptrDown) {
    var g = s2g(e.clientX, e.clientY);
    if (g[0] === 0 && g[1] === 0) { selected = null; }
    else {
      selected = g;
      SFX.play(gcd(g[0], g[1]) === 1 ? 'spawn' : 'tick');
    }
  }
  ptrDown = false;
});

c.addEventListener('wheel', function(e) {
  e.preventDefault();
  zoomAt(e.clientX, e.clientY, e.deltaY > 0 ? 0.9 : 1.1);
}, { passive: false });

c.addEventListener('touchstart', function(e) {
  if (e.touches.length === 2) {
    pinching = true;
    var dx = e.touches[0].clientX - e.touches[1].clientX;
    var dy = e.touches[0].clientY - e.touches[1].clientY;
    pinchD0 = Math.sqrt(dx * dx + dy * dy);
    zoom0 = zoom;
    e.preventDefault();
  } else if (e.touches.length === 1) {
    ptrDown = true; moved = false;
    lastPX = e.touches[0].clientX; lastPY = e.touches[0].clientY;
  }
}, { passive: false });

c.addEventListener('touchmove', function(e) {
  if (pinching && e.touches.length === 2) {
    var dx = e.touches[0].clientX - e.touches[1].clientX;
    var dy = e.touches[0].clientY - e.touches[1].clientY;
    var d = Math.sqrt(dx * dx + dy * dy);
    zoom = Math.max(4, Math.min(80, zoom0 * d / pinchD0));
    slZoom.value = Math.round(zoom);
    svZoom.textContent = Math.round(zoom);
    e.preventDefault();
  } else if (ptrDown && e.touches.length === 1) {
    var mx = e.touches[0].clientX, my = e.touches[0].clientY;
    var dx = mx - lastPX, dy = my - lastPY;
    if (Math.abs(dx) + Math.abs(dy) > 3) moved = true;
    panX -= dx / zoom; panY += dy / zoom;
    lastPX = mx; lastPY = my;
    e.preventDefault();
  }
}, { passive: false });

c.addEventListener('touchend', function(e) {
  if (pinching && e.touches.length < 2) pinching = false;
  if (e.touches.length === 0) {
    if (!moved && ptrDown) {
      var g = s2g(lastPX, lastPY);
      if (g[0] !== 0 || g[1] !== 0) {
        selected = g;
        SFX.play(gcd(g[0], g[1]) === 1 ? 'spawn' : 'tick');
      } else { selected = null; }
    }
    ptrDown = false;
  }
});

c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

btnReveal.addEventListener('click', function() {
  revealed = true;
  btnReveal.style.display = 'none';
  SFX.play('toggle');
});

btnScan.addEventListener('click', function() {
  mode = 'scan';
  btnScan.classList.add('active'); btnRays.classList.remove('active');
  SFX.play('toggle');
});

btnRays.addEventListener('click', function() {
  mode = 'rays';
  btnRays.classList.add('active'); btnScan.classList.remove('active');
  SFX.play('toggle');
});

slZoom.addEventListener('input', function() {
  zoom = +this.value; svZoom.textContent = this.value;
});

btnReset.addEventListener('click', function() {
  panX = 0; panY = 0; zoom = 28; selected = null;
  slZoom.value = 28; svZoom.textContent = '28';
  SFX.play('click');
});

resize();
window.addEventListener('resize', resize);
tick();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
