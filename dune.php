<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dune');

$pageName = 'dune';
$pageTitle = 'DUNE - PULSE';
$pageDesc = '風成砂丘シミュレーション。風が砂粒を跳躍させ、堆積と雪崩を繰り返して三日月型バルハン砂丘が自律的に形成・移動する。';
$pageCanonical = 'https://pulse.subara3.com/dune.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4}
.hud .sub{color:rgba(123,44,191,.9)}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  WIND <b id="h-wind">0.6</b><br>
  SAND <b id="h-sand">0</b><br>
  <span class="sub">ANGLE</span> <b id="h-angle">0&deg;</b>
</div>

<div id="info">
  aeolian sand transport<br>
  <span class="v">saltation &middot; deposition &middot; avalanche</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add sand'],
  ['type' => 'drag', 'label' => 'wind dir'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">WIND</span>
      <input type="range" id="sldWind" min="1" max="10" value="6">
      <span class="slider-value" id="valWind">0.6</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SUPPLY</span>
      <input type="range" id="sldSupply" min="0" max="10" value="2">
      <span class="slider-value" id="valSupply">0.2</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnSeed">SEED</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H;

  var GW = 300, GH = 170, MH = 25;
  var grid = new Uint8Array(GW * GH);
  var shade = new Uint8Array(GW * GH);

  var off = document.createElement('canvas');
  off.width = GW; off.height = GH;
  var oCtx = off.getContext('2d');
  var img = oCtx.createImageData(GW, GH);

  var LR = new Uint8Array(MH * 2 + 1);
  var LG = new Uint8Array(MH * 2 + 1);
  var LB = new Uint8Array(MH * 2 + 1);
  LR[0] = 10; LG[0] = 10; LB[0] = 15;
  for (var h = 1; h <= MH; h++) {
    var t = h / MH;
    LR[h] = (5 + 5 * t) | 0;
    LG[h] = (25 + 220 * t) | 0;
    LB[h] = (20 + 192 * t) | 0;
    var j = h + MH;
    LR[j] = (30 + 93 * t) | 0;
    LG[j] = (10 + 34 * t) | 0;
    LB[j] = (30 + 161 * t) | 0;
  }

  var wAng = 0, wSpd = 0.6, wDx = 1, wDy = 0;
  var supply = 0.2;
  var frame = 0;
  var parts = [], MAXP = 150;
  var pDown = false, pRight = false;
  var pSX = 0, pSY = 0, pX = 0, pY = 0, dragD = 0;

  function resize() {
    W = innerWidth; H = innerHeight;
    C.width = W; C.height = H;
  }

  function updWind() {
    wDx = Math.cos(wAng);
    wDy = Math.sin(wAng);
  }

  function addMound(cx, cy, r, mh) {
    var r2 = r * r;
    for (var dy = -r; dy <= r; dy++) {
      for (var dx = -r; dx <= r; dx++) {
        var x = cx + dx, y = cy + dy;
        if (x < 0 || x >= GW || y < 0 || y >= GH) continue;
        var d2 = dx * dx + dy * dy;
        if (d2 > r2) continue;
        var a = (mh * (1 - Math.sqrt(d2) / r)) | 0;
        var i = y * GW + x;
        grid[i] = Math.min(MH, grid[i] + a);
      }
    }
  }

  function erase(cx, cy, r) {
    var r2 = r * r;
    for (var dy = -r; dy <= r; dy++) {
      for (var dx = -r; dx <= r; dx++) {
        var x = cx + dx, y = cy + dy;
        if (x < 0 || x >= GW || y < 0 || y >= GH) continue;
        if (dx * dx + dy * dy <= r2) grid[y * GW + x] = 0;
      }
    }
  }

  function seed() {
    for (var i = 0; i < 7; i++) {
      addMound(
        (GW * 0.1 + Math.random() * GW * 0.8) | 0,
        (GH * 0.15 + Math.random() * GH * 0.7) | 0,
        6 + (Math.random() * 10) | 0,
        8 + (Math.random() * 14) | 0
      );
    }
  }

  function updShade() {
    shade.fill(0);
    var ta = 0.15;
    for (var y = 0; y < GH; y++) {
      for (var x = 0; x < GW; x++) {
        var idx = y * GW + x, hh = grid[idx];
        for (var d = 1; d <= 12; d++) {
          var ux = Math.round(x - wDx * d);
          var uy = Math.round(y - wDy * d);
          if (ux < 0 || ux >= GW || uy < 0 || uy >= GH) break;
          if (grid[uy * GW + ux] - hh > d * ta) { shade[idx] = 1; break; }
        }
      }
    }
  }

  function step() {
    var x = (Math.random() * GW) | 0;
    var y = (Math.random() * GH) | 0;
    var i = y * GW + x;
    if (!grid[i] || shade[i]) return;
    if (Math.random() > wSpd * 0.45) return;

    grid[i]--;
    var hop = 4 + wSpd * 10;

    if (parts.length < MAXP && Math.random() < 0.1) {
      parts.push({
        x: x, y: y,
        vx: wDx * hop * 0.12 + (Math.random() - 0.5) * 0.3,
        vy: wDy * hop * 0.12 - 0.4,
        life: (18 + Math.random() * 25) | 0
      });
    }

    var gx = x, gy = y;
    for (var b = 0; b < 3; b++) {
      var hl = hop * (0.5 + Math.random() * 0.5);
      gx += wDx * hl; gy += wDy * hl;
      var nx = Math.round(gx), ny = Math.round(gy);
      if (nx < 0 || nx >= GW || ny < 0 || ny >= GH) return;
      var ni = ny * GW + nx;
      var pd = grid[ni] ? 0.6 : 0.4;
      if (shade[ni]) pd = 0.9;
      if (Math.random() < pd || b === 2) {
        if (grid[ni] < MH) grid[ni]++;
        return;
      }
    }
  }

  function avalanche() {
    for (var it = 0; it < 3; it++) {
      var ch = false;
      for (var y = 1; y < GH - 1; y++) {
        for (var x = 1; x < GW - 1; x++) {
          var i = y * GW + x, hv = grid[i];
          if (hv < 3) continue;
          var nb = [i - 1, i + 1, i - GW, i + GW];
          for (var k = 0; k < 4; k++) {
            if (hv - grid[nb[k]] > 2) {
              grid[i]--; grid[nb[k]]++; hv--; ch = true;
            }
          }
        }
      }
      if (!ch) break;
    }
  }

  function doSupply() {
    if (Math.random() > supply * 0.15) return;
    var ex, ey;
    if (Math.abs(wDx) >= Math.abs(wDy)) {
      ex = wDx > 0 ? 0 : GW - 1;
      ey = (Math.random() * GH) | 0;
    } else {
      ey = wDy > 0 ? 0 : GH - 1;
      ex = (Math.random() * GW) | 0;
    }
    var idx = ey * GW + ex;
    if (grid[idx] < MH) grid[idx]++;
  }

  function updParts() {
    for (var i = parts.length - 1; i >= 0; i--) {
      var p = parts[i];
      p.x += p.vx; p.y += p.vy;
      p.vy += 0.025;
      if (--p.life <= 0 || p.x < -2 || p.x > GW + 2 || p.y < -2 || p.y > GH + 2) {
        parts.splice(i, 1);
      }
    }
  }

  function render() {
    var d = img.data;
    for (var i = 0, n = GW * GH; i < n; i++) {
      var hv = grid[i], pi = i << 2;
      if (!hv) { d[pi] = 10; d[pi + 1] = 10; d[pi + 2] = 15; }
      else {
        var li = shade[i] ? hv + MH : hv;
        d[pi] = LR[li]; d[pi + 1] = LG[li]; d[pi + 2] = LB[li];
      }
      d[pi + 3] = 255;
    }
    oCtx.putImageData(img, 0, 0);

    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(off, 0, 0, W, H);

    var sx = W / GW, sy = H / GH;
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i], al = Math.min(1, p.life / 25) * 0.7;
      ctx.globalAlpha = al;
      ctx.fillStyle = '#00f5d4';
      ctx.fillRect(p.x * sx - 1, p.y * sy - 1, 3, 3);
    }
    ctx.globalAlpha = 1;

    var cx = W - 50, cy = 70, cr = 18;
    ctx.strokeStyle = 'rgba(0,245,212,0.2)';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx, cy, cr, 0, Math.PI * 2); ctx.stroke();
    ctx.strokeStyle = 'rgba(0,245,212,0.7)';
    ctx.lineWidth = 1.5;
    var ex = cx + wDx * cr, ey = cy + wDy * cr;
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(ex, ey); ctx.stroke();
    var a1 = wAng + 2.7, a2 = wAng - 2.7;
    ctx.beginPath();
    ctx.moveTo(ex, ey); ctx.lineTo(ex + Math.cos(a1) * 6, ey + Math.sin(a1) * 6);
    ctx.moveTo(ex, ey); ctx.lineTo(ex + Math.cos(a2) * 6, ey + Math.sin(a2) * 6);
    ctx.stroke();
    ctx.fillStyle = 'rgba(0,245,212,0.35)';
    ctx.font = '9px "JetBrains Mono",monospace';
    ctx.textAlign = 'center';
    ctx.fillText('WIND', cx, cy + cr + 12);
  }

  function updHUD() {
    var t = 0;
    for (var i = 0; i < GW * GH; i++) t += grid[i];
    document.getElementById('h-wind').textContent = wSpd.toFixed(1);
    document.getElementById('h-sand').textContent = t > 9999 ? (t / 1000).toFixed(1) + 'K' : '' + t;
    document.getElementById('h-angle').textContent = ((wAng * 180 / Math.PI % 360 + 360) % 360).toFixed(0) + '°';
  }

  function toG(px, py) { return { x: (px / W * GW) | 0, y: (py / H * GH) | 0 }; }

  function onDown(x, y, right) {
    pDown = true; pRight = right;
    pSX = x; pSY = y; pX = x; pY = y; dragD = 0;
    if (right) { var g = toG(x, y); erase(g.x, g.y, 8); if (window.SFX) SFX.play('pop'); }
  }

  function onMove(x, y) {
    if (!pDown) return;
    pX = x; pY = y;
    var dx = x - pSX, dy = y - pSY;
    dragD = Math.sqrt(dx * dx + dy * dy);
    if (!pRight && dragD > 15) { wAng = Math.atan2(dy, dx); updWind(); }
    if (pRight) { var g = toG(x, y); erase(g.x, g.y, 8); }
  }

  function onUp() {
    if (!pRight && dragD < 15) {
      var g = toG(pX, pY);
      addMound(g.x, g.y, 8, 12);
      if (window.SFX) SFX.play('spawn');
    } else if (!pRight && dragD >= 15) {
      if (window.SFX) SFX.play('toggle');
    }
    pDown = false; pRight = false; dragD = 0;
  }

  C.addEventListener('mousedown', function(e) { e.preventDefault(); onDown(e.clientX, e.clientY, e.button === 2); });
  C.addEventListener('mousemove', function(e) { onMove(e.clientX, e.clientY); });
  C.addEventListener('mouseup', function() { onUp(); });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });
  C.addEventListener('touchstart', function(e) { e.preventDefault(); var t = e.touches[0]; onDown(t.clientX, t.clientY, false); }, { passive: false });
  C.addEventListener('touchmove', function(e) { e.preventDefault(); var t = e.touches[0]; onMove(t.clientX, t.clientY); }, { passive: false });
  C.addEventListener('touchend', function() { onUp(); });

  document.getElementById('sldWind').addEventListener('input', function() {
    wSpd = this.value / 10;
    document.getElementById('valWind').textContent = wSpd.toFixed(1);
  });
  document.getElementById('sldSupply').addEventListener('input', function() {
    supply = this.value / 10;
    document.getElementById('valSupply').textContent = supply.toFixed(1);
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    grid.fill(0); shade.fill(0); parts.length = 0;
    if (window.SFX) SFX.play('click');
  });
  document.getElementById('btnSeed').addEventListener('click', function() {
    seed();
    if (window.SFX) SFX.play('spawn');
  });

  function loop() {
    var steps = (5000 + wSpd * 3000) | 0;
    for (var s = 0; s < steps; s++) {
      step();
      if (s % 8 === 0) doSupply();
    }
    if (frame % 10 === 0) updShade();
    if (frame % 40 === 0) avalanche();
    if (frame % 20 === 0) updHUD();
    updParts();
    render();
    frame++;
    requestAnimationFrame(loop);
  }

  resize();
  grid.fill(0);
  seed();
  updWind();
  updShade();
  updHUD();
  loop();
  addEventListener('resize', resize);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
