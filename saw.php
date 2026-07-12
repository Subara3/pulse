<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('saw');

$pageName = 'saw';
$pageTitle = 'SAW - PULSE';
$pageDesc = '自己回避歩行。自分の軌跡を二度と踏めないランダムウォークが、やがて自らを閉じ込める。高分子物理の基礎モデル。';
$pageCanonical = 'https://pulse.subara3.com/saw.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  WALKS <b id="h-walks">0</b><br>
  LONGEST <b id="h-long">0</b><br>
  STEPS <b id="h-steps">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'wall'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="20" value="5"><span class="slider-value" id="sv-speed">5</span></div></div>
  <div><div class="slider-row"><span class="slider-label">GRID</span><input type="range" id="sl-grid" min="4" max="14" value="6" step="2"><span class="slider-value" id="sv-grid">6</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn active" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-clear">CLEAR</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H, cols, rows, occ;
var walks = [], wallList = [];
var gs = 6, spd = 5, autoOn = true;
var tot = 0, best = 0, wid = 0, fr = 0, fill = 0;

var hW = document.getElementById('h-walks');
var hL = document.getElementById('h-long');
var hS = document.getElementById('h-steps');
var D4 = [[0,-1],[1,0],[0,1],[-1,0]];

function resize() {
  W = C.width = innerWidth; H = C.height = innerHeight;
  rebuild();
}

function rebuild() {
  cols = Math.floor(W / gs); rows = Math.floor(H / gs);
  occ = new Uint8Array(cols * rows);
  walks = []; wallList = [];
  tot = 0; best = 0; wid = 0; fr = 0; fill = 0;
}

function inB(x, y) { return x >= 0 && x < cols && y >= 0 && y < rows; }

function freedom(x, y) {
  var n = 0;
  for (var i = 0; i < 4; i++) {
    var nx = x + D4[i][0], ny = y + D4[i][1];
    if (inB(nx, ny) && !occ[ny * cols + nx]) n++;
  }
  return n;
}

function h2rgb(h, s, l) {
  s /= 100; l /= 100;
  var c = (1 - Math.abs(2 * l - 1)) * s;
  var x = c * (1 - Math.abs((h / 60) % 2 - 1));
  var m = l - c / 2, r, g, b;
  if (h < 60) { r = c; g = x; b = 0; }
  else if (h < 120) { r = x; g = c; b = 0; }
  else if (h < 180) { r = 0; g = c; b = x; }
  else if (h < 240) { r = 0; g = x; b = c; }
  else if (h < 300) { r = x; g = 0; b = c; }
  else { r = c; g = 0; b = x; }
  return [(r + m) * 255 | 0, (g + m) * 255 | 0, (b + m) * 255 | 0];
}

function wcol(ci, f) {
  var h = 175 + (ci * 22) % 80 + f * 80;
  return h2rgb(Math.min(h, 290), 85, 50);
}

function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

function spawn(px, py) {
  var gx = Math.floor(px / gs), gy = Math.floor(py / gs);
  if (!inB(gx, gy) || occ[gy * cols + gx]) {
    var ok = false;
    for (var r = 1; r < 30 && !ok; r++) {
      for (var dx = -r; dx <= r && !ok; dx++) {
        for (var dy = -r; dy <= r && !ok; dy++) {
          if (Math.abs(dx) < r && Math.abs(dy) < r) continue;
          var nx = gx + dx, ny = gy + dy;
          if (inB(nx, ny) && !occ[ny * cols + nx]) { gx = nx; gy = ny; ok = true; }
        }
      }
    }
    if (!ok) return;
  }
  occ[gy * cols + gx] = 1;
  walks.push({
    p: [{x: gx, y: gy}], alive: true, ci: wid++ % 5,
    pulse: Math.random() * 6.28, f: freedom(gx, gy), died: -1
  });
  if (typeof SFX !== 'undefined') SFX.play('spawn');
}

function step(w) {
  if (!w.alive) return;
  var h = w.p[w.p.length - 1], av = [];
  for (var i = 0; i < 4; i++) {
    var nx = h.x + D4[i][0], ny = h.y + D4[i][1];
    if (inB(nx, ny) && !occ[ny * cols + nx]) av.push(i);
  }
  if (!av.length) {
    w.alive = false; w.died = fr;
    if (typeof SFX !== 'undefined') SFX.play('pop');
    return;
  }
  var d = av[Math.random() * av.length | 0];
  var nx = h.x + D4[d][0], ny = h.y + D4[d][1];
  w.p.push({x: nx, y: ny});
  occ[ny * cols + nx] = 1;
  tot++;
  if (w.p.length > best) best = w.p.length;
  w.f = freedom(nx, ny);
}

function addWall(px, py) {
  var gx = Math.floor(px / gs), gy = Math.floor(py / gs);
  if (inB(gx, gy) && !occ[gy * cols + gx]) {
    occ[gy * cols + gx] = 1;
    wallList.push({x: gx, y: gy});
    if (typeof SFX !== 'undefined') SFX.play('tick', 50);
  }
}

function draw() {
  X.fillStyle = '#0a0a0f';
  X.fillRect(0, 0, W, H);
  var hs = gs / 2, lw = Math.max(1, gs * 0.32);
  X.lineCap = 'round'; X.lineWidth = lw;

  if (wallList.length) {
    X.fillStyle = 'rgba(0,245,212,0.06)';
    for (var i = 0; i < wallList.length; i++)
      X.fillRect(wallList[i].x * gs + 1, wallList[i].y * gs + 1, gs - 2, gs - 2);
  }

  for (var wi = 0; wi < walks.length; wi++) {
    var w = walks[wi], len = w.p.length;
    if (len < 1) continue;
    var ba = w.alive ? 0.9 : Math.max(0.05, 0.3 - (fr - w.died) * 0.0004);
    if (ba < 0.05) continue;

    if (len === 1) {
      var pt = w.p[0], c = wcol(w.ci, 0);
      X.beginPath(); X.arc(pt.x * gs + hs, pt.y * gs + hs, lw * 1.5, 0, 6.28);
      X.fillStyle = rgba(c, ba); X.fill(); continue;
    }

    var nc = Math.min(12, Math.max(3, len / 50 | 0));
    for (var ch = 0; ch < nc; ch++) {
      var i0 = ch * (len - 1) / nc | 0;
      var i1 = (ch + 1) * (len - 1) / nc | 0;
      if (i1 <= i0) continue;
      var f = (ch + 0.5) / nc;
      var c = wcol(w.ci, f);
      var a = ba * (0.12 + 0.88 * f);
      X.strokeStyle = rgba(c, a);
      X.beginPath(); X.moveTo(w.p[i0].x * gs + hs, w.p[i0].y * gs + hs);
      for (var j = i0 + 1; j <= i1; j++) X.lineTo(w.p[j].x * gs + hs, w.p[j].y * gs + hs);
      X.stroke();
    }

    if (w.alive && len > 20) {
      var s = w.p[0], e = w.p[len - 1];
      X.save();
      X.strokeStyle = 'rgba(255,255,255,0.06)'; X.lineWidth = 1;
      X.setLineDash([3, 5]);
      X.beginPath();
      X.moveTo(s.x * gs + hs, s.y * gs + hs);
      X.lineTo(e.x * gs + hs, e.y * gs + hs);
      X.stroke(); X.restore(); X.lineWidth = lw;
    }

    if (w.alive) {
      var sp = w.p[0], sc = wcol(w.ci, 0);
      X.fillStyle = rgba(sc, 0.4);
      X.fillRect(sp.x * gs + hs - 2, sp.y * gs + hs - 2, 4, 4);

      var hd = w.p[len - 1], hx = hd.x * gs + hs, hy = hd.y * gs + hs;
      w.pulse += 0.08;
      var gl = 4 + 2 * Math.sin(w.pulse);
      var hc = w.f <= 1 ? h2rgb(280, 90, 55) : wcol(w.ci, 1);
      var grd = X.createRadialGradient(hx, hy, 0, hx, hy, gl * 2.5);
      grd.addColorStop(0, rgba(hc, 0.5)); grd.addColorStop(1, rgba(hc, 0));
      X.fillStyle = grd; X.beginPath(); X.arc(hx, hy, gl * 2.5, 0, 6.28); X.fill();
      X.beginPath(); X.arc(hx, hy, lw * 1.2, 0, 6.28);
      X.fillStyle = rgba(hc, 1); X.fill();
    }

    if (w.died >= 0 && fr - w.died < 25) {
      var dh = w.p[len - 1], dpx = dh.x * gs + hs, dpy = dh.y * gs + hs;
      var fa = 1 - (fr - w.died) / 25;
      X.beginPath(); X.arc(dpx, dpy, 8 + (fr - w.died) * 0.8, 0, 6.28);
      X.strokeStyle = 'rgba(123,44,191,' + (fa * 0.5) + ')';
      X.lineWidth = 1.5; X.stroke(); X.lineWidth = lw;
    }
  }
}

function loop() {
  fr++;
  var alive = 0;
  for (var i = 0; i < walks.length; i++) {
    if (walks[i].alive) {
      for (var s = 0; s < spd; s++) { step(walks[i]); if (!walks[i].alive) break; }
      if (walks[i].alive) alive++;
    }
  }
  if (fr % 120 === 0) {
    var n = 0; for (var i = 0; i < occ.length; i++) if (occ[i]) n++;
    fill = n / occ.length;
  }
  if (autoOn && alive === 0 && fr > 5 && fill < 0.92)
    spawn(Math.random() * W, Math.random() * H);
  if (walks.length > 80)
    walks = walks.filter(function(w) { return w.alive || (fr - w.died) < 2000; });
  hW.textContent = alive + '/' + walks.length;
  hL.textContent = best; hS.textContent = tot;
  draw(); requestAnimationFrame(loop);
}

var mSt = null, mDr = false;
C.addEventListener('mousedown', function(e) {
  if (e.button === 0) { mSt = {x: e.clientX, y: e.clientY}; mDr = false; }
});
C.addEventListener('mousemove', function(e) {
  if (!mSt) return;
  if ((e.clientX - mSt.x) * (e.clientX - mSt.x) + (e.clientY - mSt.y) * (e.clientY - mSt.y) > 9) mDr = true;
  if (mDr) addWall(e.clientX, e.clientY);
});
C.addEventListener('mouseup', function(e) {
  if (e.button === 0 && mSt) { if (!mDr) spawn(mSt.x, mSt.y); mSt = null; mDr = false; }
});
C.addEventListener('contextmenu', function(e) {
  e.preventDefault(); rebuild();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

var tSt = null, tDr = false;
C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 1) { tSt = {x: e.touches[0].clientX, y: e.touches[0].clientY}; tDr = false; }
}, {passive: false});
C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!tSt || e.touches.length !== 1) return;
  if ((e.touches[0].clientX - tSt.x) * (e.touches[0].clientX - tSt.x) +
      (e.touches[0].clientY - tSt.y) * (e.touches[0].clientY - tSt.y) > 9) tDr = true;
  if (tDr) addWall(e.touches[0].clientX, e.touches[0].clientY);
}, {passive: false});
C.addEventListener('touchend', function(e) {
  if (tSt && !tDr) spawn(tSt.x, tSt.y);
  tSt = null; tDr = false;
});

document.getElementById('sl-speed').addEventListener('input', function() {
  spd = +this.value; document.getElementById('sv-speed').textContent = spd;
});
document.getElementById('sl-grid').addEventListener('input', function() {
  gs = +this.value; document.getElementById('sv-grid').textContent = gs;
  rebuild(); spawn(W / 2, H / 2);
});
document.getElementById('btn-auto').addEventListener('click', function() {
  autoOn = !autoOn; this.classList.toggle('active', autoOn);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});
document.getElementById('btn-clear').addEventListener('click', function() {
  rebuild();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

window.addEventListener('resize', resize);
resize(); spawn(W / 2, H / 2); loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
