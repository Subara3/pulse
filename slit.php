<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('slit');

$pageName = 'slit';
$pageTitle = 'SLIT - PULSE';
$pageDesc = '二重スリット実験。粒子が一つずつスリットを通過し、干渉縞が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/slit.php';

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
  PARTICLES: <span id="hudN" class="v">0</span><br>
  SLITS: <span id="hudS" class="v">2</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'burst'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SLITS</label>
      <span class="slider-value" id="valSlits">2</span>
    </div>
    <input type="range" id="rngSlits" min="1" max="6" step="1" value="2">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">WIDTH</label>
      <span class="slider-value" id="valWidth">15</span>
    </div>
    <input type="range" id="rngWidth" min="5" max="40" step="1" value="15">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">GAP</label>
      <span class="slider-value" id="valGap">55</span>
    </div>
    <input type="range" id="rngGap" min="20" max="120" step="1" value="55">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">RATE</label>
      <span class="slider-value" id="valRate">40</span>
    </div>
    <input type="range" id="rngRate" min="5" max="200" step="5" value="40">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnTheory">THEORY</button>
    <button class="control-btn" id="btnReset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', {alpha: false});

  var hudN = document.getElementById('hudN');
  var hudS = document.getElementById('hudS');
  var rngSlits = document.getElementById('rngSlits');
  var rngWidth = document.getElementById('rngWidth');
  var rngGap = document.getElementById('rngGap');
  var rngRate = document.getElementById('rngRate');
  var valSlits = document.getElementById('valSlits');
  var valWidth = document.getElementById('valWidth');
  var valGap = document.getElementById('valGap');
  var valRate = document.getElementById('valRate');
  var btnTheory = document.getElementById('btnTheory');
  var btnReset = document.getElementById('btnReset');

  var numSlits = 2;
  var slitW = 0.015;
  var slitD = 0.055;
  var rate = 40;
  var showTheory = false;

  var W = 0, H = 0, sourceY, barrierY, screenY;
  var particles = [];
  var total = 0;
  var BINS = 300;
  var bins, maxBin;
  var offCvs, offCtx;
  var prob, cdf;
  var lastT = 0, acc = 0;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    sourceY = H * 0.07;
    barrierY = H * 0.38;
    screenY = H * 0.78;
    offCvs = document.createElement('canvas');
    offCvs.width = W; offCvs.height = H;
    offCtx = offCvs.getContext('2d');
    reset();
  }

  function reset() {
    particles = [];
    total = 0;
    bins = new Float64Array(BINS);
    maxBin = 1;
    if (offCtx) offCtx.clearRect(0, 0, W, H);
    computeDist();
  }

  function computeDist() {
    var a = slitW * W;
    var d = slitD * W;
    var wl = 0.3 * W * a / (screenY - barrierY);
    var L = screenY - barrierY;
    var lambdaL = wl * L;
    prob = new Float64Array(BINS);
    cdf = new Float64Array(BINS);
    var sum = 0;
    for (var i = 0; i < BINS; i++) {
      var x = ((i + 0.5) / BINS - 0.5) * W;
      var beta = Math.PI * a * x / lambdaL;
      var sc = Math.abs(beta) < 1e-8 ? 1 : Math.sin(beta) / beta;
      var val = sc * sc;
      if (numSlits > 1) {
        var alpha = Math.PI * d * x / lambdaL;
        var sa = Math.sin(alpha);
        if (Math.abs(sa) < 1e-10) {
          val *= numSlits * numSlits;
        } else {
          var s = Math.sin(numSlits * alpha) / sa;
          val *= s * s;
        }
      }
      prob[i] = val;
      sum += val;
    }
    var cum = 0;
    for (var i = 0; i < BINS; i++) {
      prob[i] /= sum;
      cum += prob[i];
      cdf[i] = cum;
    }
  }

  function sampleX() {
    var r = Math.random();
    var lo = 0, hi = BINS - 1;
    while (lo < hi) {
      var m = (lo + hi) >> 1;
      if (cdf[m] < r) lo = m + 1; else hi = m;
    }
    return ((lo + Math.random()) / BINS) * W;
  }

  function getSlits() {
    var out = [];
    var cx = W / 2, d = slitD * W, a = slitW * W;
    for (var i = 0; i < numSlits; i++) {
      out.push({x: cx + (i - (numSlits - 1) / 2) * d, w: a});
    }
    return out;
  }

  function fire() {
    var tx = sampleX();
    var slits = getSlits();
    var best = slits[0], bd = Math.abs(slits[0].x - tx);
    for (var i = 1; i < slits.length; i++) {
      var dd = Math.abs(slits[i].x - tx);
      if (dd < bd) { bd = dd; best = slits[i]; }
    }
    var sx = best.x + (Math.random() - 0.5) * best.w * 0.7;
    particles.push({
      sx: W / 2 + (Math.random() - 0.5) * 16,
      ex: sx, tx: tx, p: 0,
      sp: 0.012 + Math.random() * 0.008
    });
    total++;
  }

  function ppos(pt) {
    var t = pt.p;
    if (t < 0.45) {
      var u = t / 0.45;
      return {x: pt.sx + (pt.ex - pt.sx) * u, y: sourceY + (barrierY - sourceY) * u};
    }
    var u = (t - 0.45) / 0.55;
    return {x: pt.ex + (pt.tx - pt.ex) * u, y: barrierY + (screenY - barrierY) * u};
  }

  function update(dt) {
    acc += dt;
    var iv = 1000 / rate;
    while (acc >= iv) { acc -= iv; fire(); }
    for (var i = particles.length - 1; i >= 0; i--) {
      var pt = particles[i];
      pt.p += pt.sp;
      if (pt.p >= 1) {
        offCtx.fillStyle = 'rgba(0,245,212,0.45)';
        offCtx.beginPath();
        offCtx.arc(pt.tx, screenY + Math.random() * 5, 1.2, 0, 6.2832);
        offCtx.fill();
        var b = Math.floor((pt.tx / W) * BINS);
        if (b >= 0 && b < BINS) { bins[b]++; if (bins[b] > maxBin) maxBin = bins[b]; }
        if (typeof SFX !== 'undefined') SFX.play('tick', 30);
        particles.splice(i, 1);
      }
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    ctx.drawImage(offCvs, 0, 0);

    // Source glow
    var g = ctx.createRadialGradient(W / 2, sourceY, 0, W / 2, sourceY, 28);
    g.addColorStop(0, 'rgba(0,245,212,0.35)');
    g.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(W / 2, sourceY, 28, 0, 6.2832);
    ctx.fill();

    // Barrier
    var slits = getSlits();
    var sorted = slits.slice().sort(function(a, b) { return a.x - b.x; });
    ctx.fillStyle = 'rgba(123,44,191,0.3)';
    var left = 0;
    for (var i = 0; i < sorted.length; i++) {
      var r = sorted[i].x - sorted[i].w / 2;
      if (r > left) ctx.fillRect(left, barrierY - 2, r - left, 4);
      left = sorted[i].x + sorted[i].w / 2;
    }
    if (left < W) ctx.fillRect(left, barrierY - 2, W - left, 4);

    // Slit openings glow
    for (var i = 0; i < slits.length; i++) {
      var s = slits[i];
      var gg = ctx.createLinearGradient(s.x - s.w / 2, 0, s.x + s.w / 2, 0);
      gg.addColorStop(0, 'rgba(0,245,212,0)');
      gg.addColorStop(0.3, 'rgba(0,245,212,0.12)');
      gg.addColorStop(0.5, 'rgba(0,245,212,0.22)');
      gg.addColorStop(0.7, 'rgba(0,245,212,0.12)');
      gg.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = gg;
      ctx.fillRect(s.x - s.w / 2, barrierY - 14, s.w, 28);
    }

    // Detection screen line
    ctx.fillStyle = 'rgba(123,44,191,0.08)';
    ctx.fillRect(0, screenY - 1, W, 1);

    // Histogram
    var histH = H - screenY - 16;
    var histY = screenY + 8;
    var bw = W / BINS;
    if (maxBin > 0) {
      ctx.fillStyle = 'rgba(0,245,212,0.06)';
      for (var i = 0; i < BINS; i++) {
        if (bins[i] > 0) {
          var h = (bins[i] / maxBin) * histH * 0.9;
          ctx.fillRect(i * bw, histY + histH - h, bw + 0.5, h);
        }
      }
    }

    // Theory curve
    if (showTheory && total > 20) {
      ctx.beginPath();
      ctx.strokeStyle = 'rgba(123,44,191,0.7)';
      ctx.lineWidth = 1.5;
      for (var i = 0; i < BINS; i++) {
        var x = (i + 0.5) * bw;
        var ec = total * prob[i];
        var h = Math.min((ec / maxBin) * histH * 0.9, histH * 0.9);
        var y = histY + histH - h;
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.stroke();
    }

    // Active particles
    ctx.fillStyle = '#00f5d4';
    for (var i = 0; i < particles.length; i++) {
      var pos = ppos(particles[i]);
      ctx.beginPath();
      ctx.arc(pos.x, pos.y, 1.5, 0, 6.2832);
      ctx.fill();
    }
    ctx.fillStyle = 'rgba(0,245,212,0.1)';
    for (var i = 0; i < particles.length; i++) {
      var pos = ppos(particles[i]);
      ctx.beginPath();
      ctx.arc(pos.x, pos.y, 5, 0, 6.2832);
      ctx.fill();
    }

    hudN.textContent = total;
    hudS.textContent = numSlits;
  }

  function loop(ts) {
    var dt = lastT ? ts - lastT : 16;
    lastT = ts;
    if (dt > 100) dt = 16;
    update(dt);
    draw();
    requestAnimationFrame(loop);
  }

  // Events
  function burst() {
    for (var i = 0; i < 25; i++) fire();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  C.addEventListener('click', burst);
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    burst();
  }, {passive: false});

  // Controls
  rngSlits.addEventListener('input', function() {
    numSlits = +this.value; valSlits.textContent = numSlits; reset();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  rngWidth.addEventListener('input', function() {
    slitW = +this.value / 1000; valWidth.textContent = this.value; reset();
  });
  rngGap.addEventListener('input', function() {
    slitD = +this.value / 1000; valGap.textContent = this.value; reset();
  });
  rngRate.addEventListener('input', function() {
    rate = +this.value; valRate.textContent = this.value;
  });
  btnTheory.addEventListener('click', function() {
    showTheory = !showTheory;
    this.classList.toggle('active', showTheory);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  btnReset.addEventListener('click', function() {
    reset();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
