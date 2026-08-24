<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('traffic');

$pageName = 'traffic';
$pageTitle = 'TRAFFIC - PULSE';
$pageDesc = 'Nagel-Schreckenberg交通流モデル。単純な4つのルールから渋滞が自然発生する。';
$pageCanonical = 'https://pulse.subara3.com/traffic.php';

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

<div class="hud">
  CARS: <span id="hudCars" class="v">0</span><br>
  FLOW: <span id="hudFlow" class="v">0.00</span><br>
  AVG v: <span id="hudAvg" class="v">0.0</span><br>
  JAMMED: <span id="hudJam" class="v">0</span>
</div>

<div id="info">
  Nagel-Schreckenberg &middot; traffic flow<br>
  &rho; = <span class="v" id="infoRho">0.30</span> &middot; p<sub>slow</sub> = <span class="v" id="infoPslow">0.30</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'block'],
  ['type' => 'drag', 'label' => 'brake zone'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">DENSITY</label>
    <div class="slider-row">
      <input type="range" id="sldDensity" min="5" max="70" value="30">
      <span class="slider-value" id="valDensity">0.30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SLOWDOWN</label>
    <div class="slider-row">
      <input type="range" id="sldSlow" min="0" max="100" value="30">
      <span class="slider-value" id="valSlow">0.30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">MAX SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldVmax" min="1" max="10" value="5">
      <span class="slider-value" id="valVmax">5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SIM SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSim" min="1" max="30" value="8">
      <span class="slider-value" id="valSim">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, cx, cy, R;

  var L = 300;
  var road = new Int8Array(L);
  var vmax = 5;
  var pSlow = 0.3;
  var density = 0.30;
  var simSpeed = 8;

  var STH = 200;
  var stCvs = document.createElement('canvas');
  var stCtx, stImg;
  var stLine = 0, stFilled = 0;
  var stX, stY, stDW, stDH;

  var flowBuf = new Float32Array(60);
  var flowIdx = 0;
  var brakeZone = -1;
  var dragging = false;

  function initST() {
    stCvs.width = L;
    stCvs.height = STH;
    stCtx = stCvs.getContext('2d');
    stImg = stCtx.createImageData(L, STH);
    for (var i = 0; i < stImg.data.length; i += 4) {
      stImg.data[i] = 14; stImg.data[i + 1] = 14;
      stImg.data[i + 2] = 20; stImg.data[i + 3] = 255;
    }
    stLine = 0;
    stFilled = 0;
  }

  function initRoad() {
    road.fill(-1);
    var n = Math.round(L * density);
    var pos = [];
    for (var i = 0; i < L; i++) pos.push(i);
    for (var i = pos.length - 1; i > 0; i--) {
      var j = (Math.random() * (i + 1)) | 0;
      var tmp = pos[i]; pos[i] = pos[j]; pos[j] = tmp;
    }
    for (var i = 0; i < n; i++) {
      road[pos[i]] = (Math.random() * (vmax + 1)) | 0;
    }
    flowBuf.fill(0);
    flowIdx = 0;
    initST();
  }

  function simStep() {
    var cars = [];
    for (var i = 0; i < L; i++) {
      if (road[i] >= 0) cars.push(i);
    }
    var nc = cars.length;
    if (nc === 0) return;

    var nv = new Int8Array(nc);
    for (var c = 0; c < nc; c++) {
      var p = cars[c];
      var v = road[p];
      var next = cars[(c + 1) % nc];
      var gap = c < nc - 1 ? next - p - 1 : next + L - p - 1;

      if (brakeZone >= 0) {
        var bd = (brakeZone - p + L) % L;
        if (bd > 0 && bd - 1 < gap) gap = bd - 1;
      }

      v = Math.min(v + 1, vmax);
      if (v > gap) v = gap;
      if (v > 0 && Math.random() < pSlow) v--;
      nv[c] = Math.max(0, v);
    }

    var nr = new Int8Array(L);
    nr.fill(-1);
    var sf = 0;
    for (var c = 0; c < nc; c++) {
      nr[(cars[c] + nv[c]) % L] = nv[c];
      sf += nv[c];
    }
    road = nr;
    flowBuf[flowIdx % 60] = sf / L;
    flowIdx++;
  }

  function recordST() {
    var y = stLine % STH;
    for (var x = 0; x < L; x++) {
      var idx = (y * L + x) * 4;
      if (road[x] >= 0) {
        var v = road[x];
        var t = v / vmax;
        stImg.data[idx]     = (123 * (1 - t)) | 0;
        stImg.data[idx + 1] = (44 + 201 * t) | 0;
        stImg.data[idx + 2] = (191 + 21 * t) | 0;
        stImg.data[idx + 3] = 255;
      } else {
        stImg.data[idx] = 14;
        stImg.data[idx + 1] = 14;
        stImg.data[idx + 2] = 20;
        stImg.data[idx + 3] = 255;
      }
    }
    stLine++;
    if (stFilled < STH) stFilled++;
  }

  function velRGB(v) {
    var t = v / vmax;
    return [(123 * (1 - t)) | 0, (44 + 201 * t) | 0, (191 + 21 * t) | 0];
  }

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    cx = W / 2;
    cy = H * 0.34;
    R = Math.min(W * 0.36, H * 0.24, 200);
    stDW = Math.min(W - 40, 600);
    stDH = Math.max(60, Math.min(H - cy - R - 60, H * 0.3, 180));
    stX = Math.round(cx - stDW / 2);
    stY = Math.round(cy + R + 28);
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 18;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 2;
    ctx.stroke();

    if (brakeZone >= 0) {
      var ba = (brakeZone / L) * Math.PI * 2 - Math.PI / 2;
      ctx.beginPath();
      ctx.arc(cx, cy, R, ba - 0.1, ba + 0.1);
      ctx.strokeStyle = 'rgba(123,44,191,0.6)';
      ctx.lineWidth = 22;
      ctx.stroke();
    }

    var carCnt = 0, speedSum = 0, jammed = 0;
    for (var i = 0; i < L; i++) {
      if (road[i] < 0) continue;
      var v = road[i];
      var angle = (i / L) * Math.PI * 2 - Math.PI / 2;
      var px = cx + Math.cos(angle) * R;
      var py = cy + Math.sin(angle) * R;
      var rgb = velRGB(v);
      var sz = 2 + (v / vmax) * 1.5;

      ctx.beginPath();
      ctx.arc(px, py, sz + 4, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',0.12)';
      ctx.fill();

      ctx.beginPath();
      ctx.arc(px, py, sz, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',0.85)';
      ctx.fill();

      carCnt++;
      speedSum += v;
      if (v === 0) jammed++;
    }

    if (stFilled > 0) {
      stCtx.putImageData(stImg, 0, 0);
      ctx.imageSmoothingEnabled = false;

      var cur = stLine % STH;
      if (stFilled >= STH) {
        var topRows = STH - cur;
        if (topRows > 0)
          ctx.drawImage(stCvs, 0, cur, L, topRows, stX, stY, stDW, stDH * topRows / STH);
        if (cur > 0)
          ctx.drawImage(stCvs, 0, 0, L, cur, stX, stY + stDH * topRows / STH, stDW, stDH * cur / STH);
      } else {
        var off = stDH * (1 - stFilled / STH);
        ctx.drawImage(stCvs, 0, 0, L, stFilled, stX, stY + off, stDW, stDH - off);
      }
      ctx.imageSmoothingEnabled = true;

      ctx.strokeStyle = 'rgba(0,245,212,0.08)';
      ctx.lineWidth = 1;
      ctx.strokeRect(stX - 0.5, stY - 0.5, stDW + 1, stDH + 1);

      ctx.fillStyle = 'rgba(255,255,255,0.12)';
      ctx.font = '0.5rem "JetBrains Mono", monospace';
      ctx.textAlign = 'left';
      ctx.fillText('position →', stX, stY - 5);
      ctx.textAlign = 'right';
      ctx.fillText('time ↓', stX + stDW, stY - 5);
      ctx.textAlign = 'center';
      ctx.fillText('SPACE-TIME', cx, stY + stDH + 14);
    }

    var avgFlow = 0;
    var cnt = Math.min(flowIdx, 60);
    for (var k = 0; k < cnt; k++) avgFlow += flowBuf[k];
    if (cnt > 0) avgFlow /= cnt;

    document.getElementById('hudCars').textContent = carCnt;
    document.getElementById('hudFlow').textContent = avgFlow.toFixed(2);
    document.getElementById('hudAvg').textContent = carCnt > 0 ? (speedSum / carCnt).toFixed(1) : '0.0';
    document.getElementById('hudJam').textContent = jammed;
    document.getElementById('infoRho').textContent = (carCnt / L).toFixed(2);
  }

  function loop() {
    for (var i = 0; i < simSpeed; i++) {
      simStep();
      recordST();
    }
    draw();
    requestAnimationFrame(loop);
  }

  function cellFromXY(px, py) {
    var a = Math.atan2(py - cy, px - cx);
    var norm = (a + Math.PI * 0.5) / (Math.PI * 2);
    norm = ((norm % 1) + 1) % 1;
    return (norm * L) | 0;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    brakeZone = cellFromXY(e.clientX, e.clientY);
    dragging = true;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });
  C.addEventListener('mousemove', function(e) {
    if (dragging) brakeZone = cellFromXY(e.clientX, e.clientY);
  });
  C.addEventListener('mouseup', function() {
    dragging = false;
    brakeZone = -1;
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    initRoad();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var tc = e.touches[0];
    brakeZone = cellFromXY(tc.clientX, tc.clientY);
    dragging = true;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragging && e.touches[0])
      brakeZone = cellFromXY(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    dragging = false;
    brakeZone = -1;
  }, { passive: false });

  document.getElementById('sldDensity').addEventListener('input', function() {
    density = parseInt(this.value) / 100;
    document.getElementById('valDensity').textContent = density.toFixed(2);
    initRoad();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('sldSlow').addEventListener('input', function() {
    pSlow = parseInt(this.value) / 100;
    document.getElementById('valSlow').textContent = pSlow.toFixed(2);
    document.getElementById('infoPslow').textContent = pSlow.toFixed(2);
  });
  document.getElementById('sldVmax').addEventListener('input', function() {
    vmax = parseInt(this.value);
    document.getElementById('valVmax').textContent = vmax;
  });
  document.getElementById('sldSim').addEventListener('input', function() {
    simSpeed = parseInt(this.value);
    document.getElementById('valSim').textContent = simSpeed;
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    initRoad();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  initRoad();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
