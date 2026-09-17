<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('delay');

$pageName = 'delay';
$pageTitle = 'DELAY - PULSE';
$pageDesc = '遅延微分方程式（マッキー・グラス方程式）。現在の変化率が過去の状態に依存するとき、遅延時間τが秩序とカオスの境界を決める。';
$pageCanonical = 'https://pulse.subara3.com/delay.php';
$extraStyle = '
#hud {
  position: fixed; top: 16px; left: 16px; z-index: 100;
  font-family: var(--font-mono, "JetBrains Mono", monospace);
  font-size: 0.65rem; color: rgba(255,255,255,0.56);
  pointer-events: none; line-height: 1.7;
}
#hud span { color: #00f5d4; }
#hud .pur { color: #b58cff; }
@media (max-width: 768px) {
  #hud { top: 60px; font-size: 0.6rem; }
}
#modes {
  position: fixed; top: 68px; left: 50%; transform: translateX(-50%);
  display: flex; gap: 6px; z-index: 100;
}
#modes button {
  background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
  color: rgba(255,255,255,0.7); font-size: 11px; padding: 4px 10px;
  border-radius: 4px; cursor: pointer;
  font-family: var(--font-display, "Space Grotesk", sans-serif);
  letter-spacing: 0.08em; transition: all 0.2s;
}
#modes button:hover {
  background: rgba(0,245,212,0.1); border-color: rgba(0,245,212,0.3); color: #00f5d4;
}
#modes button.active {
  background: rgba(0,245,212,0.15); border-color: rgba(0,245,212,0.5); color: #00f5d4;
}
.tau-bar {
  position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);
  z-index: 100; display: flex; align-items: center; gap: 10px;
  background: rgba(10,10,15,0.75); padding: 8px 16px; border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.08);
  backdrop-filter: blur(6px);
}
.tau-bar label {
  color: rgba(255,255,255,0.5); font-size: 12px;
  font-family: var(--font-mono, "JetBrains Mono", monospace);
}
.tau-bar input[type=range] { width: 200px; accent-color: #00f5d4; }
.tau-bar .val {
  color: #00f5d4; font-size: 14px;
  font-family: var(--font-mono, "JetBrains Mono", monospace); min-width: 38px;
}
@media (max-width: 600px) {
  #modes { flex-wrap: wrap; max-width: 90vw; justify-content: center; top: 60px; }
  .tau-bar { width: calc(100% - 32px); bottom: 70px; }
  .tau-bar input[type=range] { flex: 1; width: auto; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  τ = <span id="hud-tau">23.0</span><br>
  <span class="pur" id="hud-beh">CHAOTIC</span>
</div>
<div id="modes">
  <button data-tau="4">STABLE</button>
  <button data-tau="10">PERIODIC</button>
  <button data-tau="15">PERIOD-2</button>
  <button data-tau="23" class="active">CHAOS</button>
  <button id="btn-auto">AUTO</button>
</div>
<div class="tau-bar">
  <label>τ</label>
  <input type="range" id="sl-tau" min="2" max="40" step="0.1" value="23">
  <span class="val" id="sv-tau">23.0</span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <label class="ctrl-label">β</label>
      <input type="range" id="sl-beta" min="0.05" max="0.5" step="0.01" value="0.2">
      <span class="slider-value" id="sv-beta">0.20</span>
    </div>
    <div class="slider-row">
      <label class="ctrl-label">γ</label>
      <input type="range" id="sl-gamma" min="0.02" max="0.3" step="0.01" value="0.1">
      <span class="slider-value" id="sv-gamma">0.10</span>
    </div>
    <div class="slider-row">
      <label class="ctrl-label">n</label>
      <input type="range" id="sl-n" min="2" max="20" step="1" value="10">
      <span class="slider-value" id="sv-n">10</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="ctrl-label">SPEED</label>
      <input type="range" id="sl-speed" min="1" max="5" step="0.5" value="1">
      <span class="slider-value" id="sv-speed">1.0</span>
    </div>
    <div class="slider-row">
      <label class="ctrl-label">TRAIL</label>
      <input type="range" id="sl-trail" min="500" max="6000" step="100" value="3000">
      <span class="slider-value" id="sv-trail">3000</span>
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
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  var tau = 23, beta = 0.2, gamma = 0.1, nn = 10;
  var DT = 0.02, SPF = 25;
  var speed = 1, trailMax = 3000;
  var autoSweep = false, autoDir = 1;

  var MAX_TAU = 50;
  var bufSize = Math.ceil(MAX_TAU / DT) + 500;
  var buf = new Float64Array(bufSize);
  var wPos = 0;

  var TALLOC = 6000;
  var trX = new Float64Array(TALLOC);
  var trY = new Float64Array(TALLOC);
  var trHead = 0, trCount = 0;

  var TS_LEN = 600;
  var ts = new Float64Array(TS_LEN);
  var tsH = 0, tsC = 0;

  var maxima = [];
  var prevX = 0, ppX = 0;
  var behLabel = 'CHAOTIC';

  var vxMin = 0.2, vxMax = 1.8, vyMin = 0.2, vyMax = 1.8;
  var txMin = 0.2, txMax = 1.8, tyMin = 0.2, tyMax = 1.8;

  var phH, tsAreaY, tsAreaH;
  var fc = 0;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    tsAreaH = Math.min(100, H * 0.16);
    phH = H - tsAreaH;
    tsAreaY = phH;
  }

  function fpVal() {
    if (beta / gamma <= 1) return 0.5;
    return Math.pow(beta / gamma - 1, 1 / nn);
  }

  function initSystem() {
    var x0 = fpVal();
    for (var i = 0; i < bufSize; i++) {
      buf[i] = x0 + 0.1 * Math.sin(2 * Math.PI * i * DT / 8);
    }
    wPos = bufSize - 1;
    trHead = 0; trCount = 0;
    tsH = 0; tsC = 0;
    maxima = []; prevX = x0; ppX = x0;
    for (var i = 0; i < 40000; i++) step();
    var p = phPt();
    vxMin = txMin = p[0] - 0.5;
    vxMax = txMax = p[0] + 0.5;
    vyMin = tyMin = p[1] - 0.5;
    vyMax = tyMax = p[1] + 0.5;
    for (var i = 0; i < 8000; i++) {
      step();
      if (i % 3 === 0) {
        var q = phPt();
        trX[trHead] = q[0]; trY[trHead] = q[1];
        trHead = (trHead + 1) % TALLOC;
        if (trCount < TALLOC) trCount++;
        ts[tsH] = buf[wPos];
        tsH = (tsH + 1) % TS_LEN;
        if (tsC < TS_LEN) tsC++;
      }
    }
    fitView(true);
  }

  function step() {
    var dSteps = Math.round(tau / DT);
    if (dSteps < 1) dSteps = 1;
    if (dSteps >= bufSize) dSteps = bufSize - 1;
    var rP = ((wPos - dSteps) % bufSize + bufSize) % bufSize;
    var xT = buf[rP], x = buf[wPos];
    var denom = 1 + Math.pow(Math.abs(xT), nn);
    var dx = beta * xT / denom - gamma * x;
    var xN = x + DT * dx;
    if (xN < 0) xN = 0.0001;
    if (xN > 20) xN = 20;
    wPos = (wPos + 1) % bufSize;
    buf[wPos] = xN;
    return xN;
  }

  function phPt() {
    var dSteps = Math.round(tau / DT);
    if (dSteps < 1) dSteps = 1;
    if (dSteps >= bufSize) dSteps = bufSize - 1;
    var rP = ((wPos - dSteps) % bufSize + bufSize) % bufSize;
    return [buf[rP], buf[wPos]];
  }

  function fitView(snap) {
    var disp = Math.min(trCount, trailMax);
    if (disp < 50) return;
    var st = (trHead - disp + TALLOC) % TALLOC;
    var xn = 1e9, xx = -1e9, yn = 1e9, yx = -1e9;
    for (var i = 0; i < disp; i++) {
      var idx = (st + i) % TALLOC;
      var x = trX[idx], y = trY[idx];
      if (x < xn) xn = x; if (x > xx) xx = x;
      if (y < yn) yn = y; if (y > yx) yx = y;
    }
    var pad = 0.15, dx = (xx - xn) * pad, dy = (yx - yn) * pad;
    if (dx < 0.1) dx = 0.3; if (dy < 0.1) dy = 0.3;
    var cx = (xn + xx) / 2, cy = (yn + yx) / 2;
    var aspect = W / phH;
    var rng = Math.max((xx - xn + 2 * dx), (yx - yn + 2 * dy) * aspect) / aspect;
    var rngY = rng * aspect > (yx - yn + 2 * dy) ? rng : (yx - yn + 2 * dy);
    rng = Math.max(xx - xn + 2 * dx, rngY);
    txMin = cx - rng / 2; txMax = cx + rng / 2;
    tyMin = cy - rng / 2; tyMax = cy + rng / 2;
    if (snap) { vxMin = txMin; vxMax = txMax; vyMin = tyMin; vyMax = tyMax; }
  }

  function lerpView() {
    var r = 0.04;
    vxMin += (txMin - vxMin) * r;
    vxMax += (txMax - vxMax) * r;
    vyMin += (tyMin - vyMin) * r;
    vyMax += (tyMax - vyMax) * r;
  }

  function detect() {
    var x = buf[wPos];
    if (prevX > x && prevX > ppX && prevX > 0.01) {
      maxima.push(prevX);
      if (maxima.length > 80) maxima.shift();
    }
    ppX = prevX; prevX = x;
    if (maxima.length < 15) { behLabel = '...'; return; }
    var rec = maxima.slice(-40);
    var mean = 0;
    for (var i = 0; i < rec.length; i++) mean += rec[i];
    mean /= rec.length;
    var v = 0;
    for (var i = 0; i < rec.length; i++) { var d = rec[i] - mean; v += d * d; }
    v /= rec.length;
    var s = rec.slice().sort(function(a, b) { return a - b; });
    var cl = 1;
    for (var i = 1; i < s.length; i++) if (s[i] - s[i - 1] > 0.02) cl++;
    if (v < 0.00005) behLabel = tau < 5 ? 'FIXED POINT' : 'LIMIT CYCLE';
    else if (cl === 2 && v < 0.01) behLabel = 'PERIOD-2';
    else if (cl <= 4 && v < 0.015) behLabel = 'PERIOD-' + cl;
    else behLabel = 'CHAOTIC';
  }

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function mx(v) { return (v - vxMin) / (vxMax - vxMin) * W; }
  function my(v) { return phH - (v - vyMin) / (vyMax - vyMin) * phH; }

  function drawGrid() {
    var range = vxMax - vxMin;
    var gs = 0.1;
    if (range > 2) gs = 0.5;
    if (range > 5) gs = 1;
    if (range < 0.5) gs = 0.05;
    ctx.strokeStyle = 'rgba(255,255,255,0.035)';
    ctx.lineWidth = 0.5;
    var sx = Math.ceil(vxMin / gs) * gs;
    for (var gx = sx; gx < vxMax; gx += gs) {
      var px = mx(gx);
      ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, phH); ctx.stroke();
    }
    var sy = Math.ceil(vyMin / gs) * gs;
    for (var gy = sy; gy < vyMax; gy += gs) {
      var py = my(gy);
      ctx.beginPath(); ctx.moveTo(0, py); ctx.lineTo(W, py); ctx.stroke();
    }
    ctx.fillStyle = 'rgba(255,255,255,0.15)';
    ctx.font = '10px "Space Grotesk",sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('x(t − τ)', W / 2, phH - 6);
    ctx.save();
    ctx.translate(14, phH / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('x(t)', 0, 0);
    ctx.restore();
  }

  function drawTrail() {
    var disp = Math.min(trCount, trailMax);
    if (disp < 2) return;
    var st = (trHead - disp + TALLOC) % TALLOC;
    var groups = 24;
    var gs = Math.ceil(disp / groups);
    ctx.lineCap = 'round';
    for (var g = 0; g < groups; g++) {
      var i0 = g * gs, i1 = Math.min(i0 + gs, disp);
      if (i1 - i0 < 2) continue;
      var mid = (i0 + i1) / 2 / disp;
      var alpha = mid * mid * 0.85;
      if (alpha < 0.005) continue;
      var hue = brandHue(1 - mid);
      ctx.strokeStyle = 'hsla(' + hue + ',85%,58%,' + alpha + ')';
      ctx.lineWidth = 0.4 + mid * 1.6;
      ctx.beginPath();
      var first = true;
      for (var i = i0; i < i1; i++) {
        var idx = (st + i) % TALLOC;
        var sx = mx(trX[idx]), sy = my(trY[idx]);
        if (sx < -100 || sx > W + 100 || sy < -100 || sy > phH + 100) { first = true; continue; }
        if (first) { ctx.moveTo(sx, sy); first = false; }
        else ctx.lineTo(sx, sy);
      }
      ctx.stroke();
    }
    var last = (trHead - 1 + TALLOC) % TALLOC;
    var lx = mx(trX[last]), ly = my(trY[last]);
    var grad = ctx.createRadialGradient(lx, ly, 0, lx, ly, 18);
    grad.addColorStop(0, 'rgba(0,245,212,0.5)');
    grad.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(lx, ly, 18, 0, 6.283); ctx.fill();
    ctx.fillStyle = '#00f5d4';
    ctx.beginPath(); ctx.arc(lx, ly, 2.5, 0, 6.283); ctx.fill();
  }

  function drawTS() {
    if (tsC < 2) return;
    ctx.save();
    ctx.beginPath();
    ctx.rect(0, tsAreaY, W, tsAreaH);
    ctx.clip();
    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
    ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(0, tsAreaY); ctx.lineTo(W, tsAreaY); ctx.stroke();
    var ymin = 1e9, ymax = -1e9;
    for (var i = 0; i < tsC; i++) {
      var idx = ((tsH - tsC + i + TS_LEN) % TS_LEN);
      var v = ts[idx];
      if (v < ymin) ymin = v; if (v > ymax) ymax = v;
    }
    var pad = (ymax - ymin) * 0.15;
    if (pad < 0.05) pad = 0.2;
    ymin -= pad; ymax += pad;
    ctx.strokeStyle = 'rgba(0,245,212,0.5)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var i = 0; i < tsC; i++) {
      var idx = ((tsH - tsC + i + TS_LEN) % TS_LEN);
      var v = ts[idx];
      var sx = (i / (TS_LEN - 1)) * W;
      var sy = tsAreaY + tsAreaH - ((v - ymin) / (ymax - ymin)) * tsAreaH;
      if (i === 0) ctx.moveTo(sx, sy); else ctx.lineTo(sx, sy);
    }
    ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.font = '9px "Space Grotesk",sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('x(t)', 6, tsAreaY + 12);
    ctx.restore();
  }

  function update() {
    var steps = Math.round(SPF * speed);
    for (var i = 0; i < steps; i++) {
      step();
      fc++;
      if (fc % 4 === 0) {
        var q = phPt();
        trX[trHead] = q[0]; trY[trHead] = q[1];
        trHead = (trHead + 1) % TALLOC;
        if (trCount < TALLOC) trCount++;
        ts[tsH] = buf[wPos];
        tsH = (tsH + 1) % TS_LEN;
        if (tsC < TS_LEN) tsC++;
      }
    }
    detect();
    fitView(false);
    lerpView();
    if (autoSweep) {
      tau += 0.012 * autoDir * speed;
      if (tau >= 35) { tau = 35; autoDir = -1; }
      if (tau <= 3) { tau = 3; autoDir = 1; }
      updTauUI();
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawGrid();
    drawTrail();
    drawTS();
    document.getElementById('hud-tau').textContent = tau.toFixed(1);
    document.getElementById('hud-beh').textContent = behLabel;
  }

  function loop() { update(); draw(); requestAnimationFrame(loop); }

  function updTauUI() {
    document.getElementById('sl-tau').value = tau;
    document.getElementById('sv-tau').textContent = tau.toFixed(1);
    var btns = document.querySelectorAll('#modes button[data-tau]');
    for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
    document.getElementById('btn-auto').classList.remove('active');
    if (autoSweep) {
      document.getElementById('btn-auto').classList.add('active');
    } else {
      for (var i = 0; i < btns.length; i++) {
        if (Math.abs(parseFloat(btns[i].dataset.tau) - tau) < 0.5) btns[i].classList.add('active');
      }
    }
  }

  document.getElementById('sl-tau').addEventListener('input', function() {
    tau = parseFloat(this.value);
    document.getElementById('sv-tau').textContent = tau.toFixed(1);
    autoSweep = false; maxima = [];
    updTauUI();
    SFX.play('move', 100);
  });

  var mBtns = document.querySelectorAll('#modes button[data-tau]');
  for (var i = 0; i < mBtns.length; i++) {
    mBtns[i].addEventListener('click', function() {
      tau = parseFloat(this.dataset.tau);
      autoSweep = false; maxima = [];
      updTauUI(); SFX.play('toggle');
    });
  }

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoSweep = !autoSweep;
    if (autoSweep) autoDir = 1;
    updTauUI(); SFX.play('toggle');
  });

  document.getElementById('sl-beta').addEventListener('input', function() {
    beta = parseFloat(this.value);
    document.getElementById('sv-beta').textContent = beta.toFixed(2);
    maxima = [];
  });
  document.getElementById('sl-gamma').addEventListener('input', function() {
    gamma = parseFloat(this.value);
    document.getElementById('sv-gamma').textContent = gamma.toFixed(2);
    maxima = [];
  });
  document.getElementById('sl-n').addEventListener('input', function() {
    nn = parseInt(this.value);
    document.getElementById('sv-n').textContent = nn;
    maxima = [];
  });
  document.getElementById('sl-speed').addEventListener('input', function() {
    speed = parseFloat(this.value);
    document.getElementById('sv-speed').textContent = speed.toFixed(1);
  });
  document.getElementById('sl-trail').addEventListener('input', function() {
    trailMax = parseInt(this.value);
    document.getElementById('sv-trail').textContent = trailMax;
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    initSystem(); SFX.play('click');
  });

  C.addEventListener('click', function(e) {
    if (e.target !== C) return;
    var mag = 0.3 + Math.random() * 0.3;
    buf[wPos] = Math.max(0.01, buf[wPos] + (Math.random() - 0.5) * mag);
    maxima = [];
    SFX.play('spawn');
  });
  C.addEventListener('touchstart', function(e) {
    if (e.target !== C) return;
    e.preventDefault();
    var mag = 0.3 + Math.random() * 0.3;
    buf[wPos] = Math.max(0.01, buf[wPos] + (Math.random() - 0.5) * mag);
    maxima = [];
    SFX.play('spawn');
  }, {passive: false});

  resize();
  window.addEventListener('resize', resize);
  initSystem();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
