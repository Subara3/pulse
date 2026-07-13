<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tissue');

$pageName = 'tissue';
$pageTitle = 'TISSUE - PULSE';
$pageDesc = '細胞ポッツモデル。格子上の拡張セルが表面張力と微分接着で自己組織化し、異なる型の細胞が自発的に分離する。';
$pageCanonical = 'https://pulse.subara3.com/tissue.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .bp{color:#7b2cbf;font-weight:700}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  CELLS <b id="h-n">0</b><br>
  A <b id="h-a">0</b> B <b class="bp" id="h-b">0</b><br>
  MCS <b id="h-mcs">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="slider-row"><span class="slider-label">TEMP</span><input type="range" id="sl-temp" min="2" max="50" value="20"><span class="slider-value" id="sv-temp">20</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SORT</span><input type="range" id="sl-sort" min="0" max="20" value="12"><span class="slider-value" id="sv-sort">12</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SIZE</span><input type="range" id="sl-size" min="10" max="80" value="40"><span class="slider-value" id="sv-size">40</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-shuffle">SHUFFLE</button>
    <button class="control-btn" id="btn-clear">CLEAR</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H, gw, gh, gs;
var grid, cType, cArea, cHue, nxId;
var mcs = 0;
var D4 = [[0,-1],[1,0],[0,1],[-1,0]];
var oc, ox, imgD, pd;

var T = 20, sortS = 12, At = 40;
var Jcm = 16, Js = 2, lam = 0.5;

var hN = document.getElementById('h-n');
var hA = document.getElementById('h-a');
var hB = document.getElementById('h-b');
var hM = document.getElementById('h-mcs');

function h2r(h, s, l) {
  h = ((h % 360) + 360) % 360; s /= 100; l /= 100;
  var c = (1 - Math.abs(2 * l - 1)) * s;
  var x = c * (1 - Math.abs(h / 60 % 2 - 1));
  var m = l - c / 2, r, g, b;
  if (h < 60) { r=c; g=x; b=0; }
  else if (h < 120) { r=x; g=c; b=0; }
  else if (h < 180) { r=0; g=c; b=x; }
  else if (h < 240) { r=0; g=x; b=c; }
  else if (h < 300) { r=x; g=0; b=c; }
  else { r=c; g=0; b=x; }
  return [(r+m)*255|0, (g+m)*255|0, (b+m)*255|0];
}

function setup() {
  W = C.width = innerWidth; H = C.height = innerHeight;
  gs = Math.max(4, Math.min(7, Math.round(Math.min(W, H) / 130)));
  gw = Math.floor(W / gs); gh = Math.floor(H / gs);
  oc = document.createElement('canvas');
  oc.width = gw; oc.height = gh;
  ox = oc.getContext('2d');
  imgD = ox.createImageData(gw, gh);
  pd = imgD.data;
  init(50);
}

function init(n) {
  grid = new Uint16Array(gw * gh);
  cType = {}; cArea = {}; cHue = {};
  nxId = 1; mcs = 0;
  var placed = [];
  var r = Math.max(3, Math.ceil(Math.sqrt(At / Math.PI)));
  for (var i = 0; i < n; i++) {
    var ok = false, cx, cy;
    for (var t = 0; t < 120 && !ok; t++) {
      cx = gw * 0.15 + Math.random() * gw * 0.7 | 0;
      cy = gh * 0.15 + Math.random() * gh * 0.7 | 0;
      ok = true;
      for (var k = 0; k < placed.length; k++) {
        var dx = cx - placed[k][0], dy = cy - placed[k][1];
        if (dx * dx + dy * dy < (2 * r + 1) * (2 * r + 1)) { ok = false; break; }
      }
    }
    if (!ok) continue;
    placed.push([cx, cy]);
    var id = nxId++;
    cType[id] = i % 2;
    cArea[id] = 0;
    cHue[id] = (Math.random() - 0.5) * 16;
    for (var dy2 = -r; dy2 <= r; dy2++) {
      for (var dx2 = -r; dx2 <= r; dx2++) {
        if (dx2 * dx2 + dy2 * dy2 > r * r) continue;
        var gx = cx + dx2, gy = cy + dy2;
        if (gx < 0 || gx >= gw || gy < 0 || gy >= gh) continue;
        if (grid[gy * gw + gx]) continue;
        grid[gy * gw + gx] = id;
        cArea[id]++;
      }
    }
    if (!cArea[id]) { delete cType[id]; delete cArea[id]; delete cHue[id]; }
  }
}

function getJ(a, b) {
  if (a === b) return 0;
  if (a === 0 || b === 0) return Jcm;
  return cType[a] === cType[b] ? Js : Js + sortS;
}

function mc() {
  var x = Math.random() * gw | 0, y = Math.random() * gh | 0;
  var d = Math.random() * 4 | 0;
  var nx = x + D4[d][0], ny = y + D4[d][1];
  if (nx < 0 || nx >= gw || ny < 0 || ny >= gh) return;
  var oId = grid[y * gw + x], nId = grid[ny * gw + nx];
  if (oId === nId) return;
  if (oId > 0 && cArea[oId] <= 2) return;

  var dH = 0;
  for (var i = 0; i < 4; i++) {
    var ax = x + D4[i][0], ay = y + D4[i][1];
    if (ax < 0 || ax >= gw || ay < 0 || ay >= gh) continue;
    var nb = grid[ay * gw + ax];
    dH += getJ(nId, nb) - getJ(oId, nb);
  }
  if (oId > 0) { var a = cArea[oId]; dH += lam * ((a-1-At)*(a-1-At) - (a-At)*(a-At)); }
  if (nId > 0) { var a2 = cArea[nId]; dH += lam * ((a2+1-At)*(a2+1-At) - (a2-At)*(a2-At)); }

  if (dH <= 0 || Math.random() < Math.exp(-dH / T)) {
    grid[y * gw + x] = nId;
    if (oId > 0) {
      cArea[oId]--;
      if (cArea[oId] <= 0) { delete cType[oId]; delete cArea[oId]; delete cHue[oId]; }
    }
    if (nId > 0) cArea[nId]++;
  }
}

function render() {
  for (var y = 0; y < gh; y++) {
    for (var x = 0; x < gw; x++) {
      var i4 = (y * gw + x) * 4;
      var id = grid[y * gw + x];

      if (!id) {
        var glow = false, gTp = 0;
        for (var d = 0; d < 4; d++) {
          var ax = x + D4[d][0], ay = y + D4[d][1];
          if (ax >= 0 && ax < gw && ay >= 0 && ay < gh) {
            var ni = grid[ay * gw + ax];
            if (ni > 0 && ni in cType) { glow = true; gTp = cType[ni]; break; }
          }
        }
        if (glow) {
          var gh2 = gTp === 0 ? 178 : 275;
          var rgb = h2r(gh2, 50, 9);
          pd[i4] = rgb[0]; pd[i4+1] = rgb[1]; pd[i4+2] = rgb[2];
        } else {
          pd[i4] = 10; pd[i4+1] = 10; pd[i4+2] = 15;
        }
        pd[i4+3] = 255;
        continue;
      }

      if (!(id in cType)) {
        pd[i4] = 10; pd[i4+1] = 10; pd[i4+2] = 15; pd[i4+3] = 255;
        continue;
      }

      var bdr = false, xbdr = false;
      for (var d2 = 0; d2 < 4; d2++) {
        var bx = x + D4[d2][0], by = y + D4[d2][1];
        if (bx < 0 || bx >= gw || by < 0 || by >= gh) { bdr = true; continue; }
        var bid = grid[by * gw + bx];
        if (bid !== id) {
          bdr = true;
          if (bid > 0 && bid in cType && cType[bid] !== cType[id]) xbdr = true;
        }
      }

      var tp = cType[id], ho = cHue[id] || 0;
      var hue = tp === 0 ? 178 + ho : 275 + ho;
      var sat = bdr ? 88 : 60;
      var lit = xbdr ? 54 : (bdr ? 42 : 25);
      var rgb2 = h2r(hue, sat, lit);
      pd[i4] = rgb2[0]; pd[i4+1] = rgb2[1]; pd[i4+2] = rgb2[2]; pd[i4+3] = 255;
    }
  }
  ox.putImageData(imgD, 0, 0);
  X.imageSmoothingEnabled = false;
  X.drawImage(oc, 0, 0, gw, gh, 0, 0, W, H);
}

function cnt() {
  var a = 0, b = 0;
  for (var id in cType) { if (cType[id] === 0) a++; else b++; }
  return [a, b, a + b];
}

function seedCell(sx, sy, tp) {
  var gx = Math.floor(sx / gs), gy = Math.floor(sy / gs);
  var r = Math.max(2, Math.ceil(Math.sqrt(At / Math.PI)));
  if (gx < r + 1 || gx >= gw - r - 1 || gy < r + 1 || gy >= gh - r - 1) return;

  var id = nxId++;
  cType[id] = tp; cArea[id] = 0; cHue[id] = (Math.random() - 0.5) * 16;
  for (var dy = -r; dy <= r; dy++) {
    for (var dx = -r; dx <= r; dx++) {
      if (dx * dx + dy * dy > r * r) continue;
      var px2 = gx + dx, py = gy + dy;
      if (px2 < 0 || px2 >= gw || py < 0 || py >= gh) continue;
      if (grid[py * gw + px2]) continue;
      grid[py * gw + px2] = id; cArea[id]++;
    }
  }
  if (!cArea[id]) { delete cType[id]; delete cArea[id]; delete cHue[id]; }
  else if (typeof SFX !== 'undefined') SFX.play('spawn');
}

function eraseAt(ex, ey) {
  var gx = Math.floor(ex / gs), gy = Math.floor(ey / gs);
  var r = 5;
  for (var dy = -r; dy <= r; dy++) {
    for (var dx = -r; dx <= r; dx++) {
      if (dx * dx + dy * dy > r * r) continue;
      var px2 = gx + dx, py = gy + dy;
      if (px2 < 0 || px2 >= gw || py < 0 || py >= gh) continue;
      var id = grid[py * gw + px2];
      if (id > 0) {
        grid[py * gw + px2] = 0;
        if (id in cArea) {
          cArea[id]--;
          if (cArea[id] <= 0) { delete cType[id]; delete cArea[id]; delete cHue[id]; }
        }
      }
    }
  }
}

var seedTp = 0, drag = false, dragM = false, erasing = false;

C.addEventListener('mousedown', function(e) {
  e.preventDefault();
  if (e.button === 0) { drag = true; dragM = false; }
  else if (e.button === 2) {
    erasing = true; eraseAt(e.clientX, e.clientY);
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }
});
C.addEventListener('mousemove', function(e) {
  if (drag) { dragM = true; seedCell(e.clientX, e.clientY, seedTp); }
  else if (erasing) eraseAt(e.clientX, e.clientY);
});
C.addEventListener('mouseup', function(e) {
  if (drag && !dragM) { seedCell(e.clientX, e.clientY, seedTp); seedTp = 1 - seedTp; }
  drag = false;
});
window.addEventListener('mouseup', function() { drag = false; erasing = false; });
C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  seedCell(t.clientX, t.clientY, seedTp); seedTp = 1 - seedTp; drag = true;
}, {passive: false});
C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (drag) { var t = e.touches[0]; seedCell(t.clientX, t.clientY, seedTp); }
}, {passive: false});
C.addEventListener('touchend', function() { drag = false; });

var slT = document.getElementById('sl-temp'), svT = document.getElementById('sv-temp');
var slS = document.getElementById('sl-sort'), svS = document.getElementById('sv-sort');
var slZ = document.getElementById('sl-size'), svZ = document.getElementById('sv-size');

slT.addEventListener('input', function() { T = +this.value; svT.textContent = T; if (typeof SFX !== 'undefined') SFX.play('toggle', 100); });
slS.addEventListener('input', function() { sortS = +this.value; svS.textContent = sortS; if (typeof SFX !== 'undefined') SFX.play('toggle', 100); });
slZ.addEventListener('input', function() { At = +this.value; svZ.textContent = At; if (typeof SFX !== 'undefined') SFX.play('toggle', 100); });

document.getElementById('btn-shuffle').addEventListener('click', function() {
  init(50); if (typeof SFX !== 'undefined') SFX.play('click');
});
document.getElementById('btn-clear').addEventListener('click', function() {
  grid.fill(0); cType = {}; cArea = {}; cHue = {}; nxId = 1; mcs = 0;
  if (typeof SFX !== 'undefined') SFX.play('click');
});

function loop() {
  var steps = gw * gh * 3;
  for (var i = 0; i < steps; i++) mc();
  mcs += 3;
  render();
  if (mcs % 6 === 0) {
    var c = cnt();
    hN.textContent = c[2]; hA.textContent = c[0]; hB.textContent = c[1];
    hM.textContent = mcs;
  }
  requestAnimationFrame(loop);
}

setup();
window.addEventListener('resize', setup);
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
