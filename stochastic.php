<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('stochastic');

$pageName = 'stochastic';
$pageTitle = 'STOCHASTIC RESONANCE - PULSE';
$pageDesc = '確率共鳴。弱い信号がノイズの力で増幅される逆説的現象。適度なノイズが閾値を超える助けとなり、信号が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/stochastic.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>NOISE <span id="hud-noise">0.00</span></div>
  <div>SIGNAL <span id="hud-signal">0.00</span></div>
  <div>SNR <span id="hud-snr">--</span></div>
  <div>DETECTIONS <span id="hud-det">0</span></div>
  <div>MODE <span id="hud-mode">BISTABLE</span></div>
</div>
<div id="info-label"><span>STOCHASTIC RESONANCE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'pulse'],
  ['type' => 'drag', 'label' => 'noise'],
  ['type' => 'wheel', 'label' => 'frequency'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">NOISE</label>
    <input type="range" id="sl-noise" min="0" max="100" value="0">
    <span class="slider-value" id="v-noise">0.00</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SIGNAL</label>
    <input type="range" id="sl-signal" min="0" max="100" value="30">
    <span class="slider-value" id="v-signal">0.30</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">FREQ</label>
    <input type="range" id="sl-freq" min="1" max="30" value="5">
    <span class="slider-value" id="v-freq">5</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">THRESHOLD</label>
    <input type="range" id="sl-thresh" min="10" max="90" value="50">
    <span class="slider-value" id="v-thresh">0.50</span>
  </div>
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-bistable">BISTABLE</button>
    <button class="ctrl-btn" id="btn-threshold">THRESHOLD</button>
    <button class="ctrl-btn" id="btn-particles">PARTICLES</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-reset">RESET</button>
    <button class="ctrl-btn" id="btn-auto">AUTO SWEEP</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  const C = document.getElementById('canvas');
  const ctx = C.getContext('2d');
  let W, H;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
  }
  resize();
  window.addEventListener('resize', resize);

  const ACCENT = [0, 245, 212];
  const SUB = [123, 44, 191];
  const BG = '#0a0a0f';

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) { return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t]; }

  let noiseLevel = 0;
  let signalAmp = 0.3;
  let freq = 5;
  let threshold = 0.5;
  let mode = 'bistable';
  let autoSweep = false;
  let sweepDir = 1;
  let time = 0;
  let detections = 0;

  const HISTORY_LEN = 600;
  let signalHistory = new Float32Array(HISTORY_LEN);
  let noiseHistory = new Float32Array(HISTORY_LEN);
  let outputHistory = new Float32Array(HISTORY_LEN);
  let snrHistory = new Float32Array(200);
  let snrIdx = 0;

  let bistableState = 0;

  const particles = [];
  const MAX_PARTICLES = 120;
  const TRAIL_LEN = 40;

  function initParticles() {
    particles.length = 0;
    for (let i = 0; i < MAX_PARTICLES; i++) {
      particles.push({
        x: Math.random() * W,
        y: H * 0.5,
        vx: 0,
        vy: 0,
        trail: [],
        crossed: false
      });
    }
  }
  initParticles();

  function gaussRand() {
    let u = 0, v = 0;
    while (u === 0) u = Math.random();
    while (v === 0) v = Math.random();
    return Math.sqrt(-2.0 * Math.log(u)) * Math.cos(2.0 * Math.PI * v);
  }

  function doubleWell(x) {
    return -x * x + x * x * x * x * 0.25;
  }

  function doubleWellForce(x) {
    return 2 * x - x * x * x;
  }

  const dt = 0.016;

  function stepBistable() {
    let signal = signalAmp * Math.sin(2 * Math.PI * freq * time * 0.01);
    let noise = noiseLevel * gaussRand();
    let force = doubleWellForce(bistableState) + signal + noise;
    bistableState += force * dt * 2;
    bistableState = Math.max(-3, Math.min(3, bistableState));
    return { signal, noise: noise, output: bistableState > 0 ? 1 : -1 };
  }

  function stepThreshold() {
    let signal = signalAmp * Math.sin(2 * Math.PI * freq * time * 0.01);
    let noise = noiseLevel * gaussRand();
    let combined = signal + noise;
    let output = combined > threshold ? 1 : (combined < -threshold ? -1 : 0);
    return { signal, noise, output };
  }

  function updateParticles() {
    let signal = signalAmp * Math.sin(2 * Math.PI * freq * time * 0.01);
    let crossCount = 0;
    for (let p of particles) {
      let noise = noiseLevel * gaussRand();
      let wellY = H * 0.5;
      let forceY = -0.02 * (p.y - wellY) + signal * 30 + noise * 20;
      let threshY = H * 0.5 - threshold * H * 0.3;
      let wasBelowThresh = p.y > threshY;

      p.vy += forceY * dt;
      p.vy *= 0.95;
      p.y += p.vy;

      p.vx += (Math.random() - 0.5) * 0.5;
      p.vx *= 0.98;
      p.x += p.vx;

      if (p.x < 0) p.x = W;
      if (p.x > W) p.x = 0;
      p.y = Math.max(20, Math.min(H - 20, p.y));

      p.trail.push({ x: p.x, y: p.y });
      if (p.trail.length > TRAIL_LEN) p.trail.shift();

      let nowBelowThresh = p.y > threshY;
      if (wasBelowThresh && !nowBelowThresh) {
        crossCount++;
        p.crossed = true;
        setTimeout(() => { p.crossed = false; }, 200);
      }
    }
    return { signal, crossCount };
  }

  function computeSNR() {
    let signalPower = 0, noisePower = 0;
    let len = Math.min(200, HISTORY_LEN);
    for (let i = HISTORY_LEN - len; i < HISTORY_LEN; i++) {
      signalPower += signalHistory[i] * signalHistory[i];
      let diff = outputHistory[i] - signalHistory[i];
      noisePower += diff * diff;
    }
    if (noisePower < 0.001) return 30;
    return 10 * Math.log10(signalPower / noisePower);
  }

  function drawWaveform(arr, yCenter, height, color, label) {
    let plotW = W * 0.45;
    let plotX = W * 0.03;
    ctx.strokeStyle = rgba(ACCENT, 0.15);
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    ctx.moveTo(plotX, yCenter);
    ctx.lineTo(plotX + plotW, yCenter);
    ctx.stroke();

    ctx.strokeStyle = color;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    let step = plotW / HISTORY_LEN;
    for (let i = 0; i < HISTORY_LEN; i++) {
      let x = plotX + i * step;
      let y = yCenter - arr[i] * height;
      if (i === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    }
    ctx.stroke();

    ctx.fillStyle = rgba(ACCENT, 0.4);
    ctx.font = '10px Space Grotesk, monospace';
    ctx.fillText(label, plotX, yCenter - height - 5);
  }

  function drawSNRCurve() {
    let plotW = W * 0.38;
    let plotH = H * 0.28;
    let plotX = W * 0.55;
    let plotY = H * 0.58;

    ctx.strokeStyle = rgba(ACCENT, 0.12);
    ctx.lineWidth = 0.5;
    ctx.strokeRect(plotX, plotY, plotW, plotH);

    ctx.beginPath();
    ctx.moveTo(plotX, plotY + plotH * 0.5);
    ctx.lineTo(plotX + plotW, plotY + plotH * 0.5);
    ctx.stroke();

    ctx.fillStyle = rgba(ACCENT, 0.4);
    ctx.font = '10px Space Grotesk, monospace';
    ctx.fillText('SNR vs NOISE', plotX, plotY - 8);
    ctx.fillText('NOISE →', plotX + plotW - 50, plotY + plotH + 14);
    ctx.save();
    ctx.translate(plotX - 8, plotY + plotH * 0.5);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('SNR', 0, 0);
    ctx.restore();

    let points = [];
    let steps = 50;
    for (let i = 0; i <= steps; i++) {
      let n = i / steps * 2;
      let snrVal;
      if (n < 0.01) {
        snrVal = -5;
      } else {
        let sumSig = 0, sumNoise = 0;
        for (let j = 0; j < 100; j++) {
          let t = j * 0.1;
          let sig = signalAmp * Math.sin(2 * Math.PI * freq * t * 0.01);
          let ns = n * gaussRand();
          let out;
          if (mode === 'bistable') {
            out = (sig + ns) > 0 ? 1 : -1;
          } else {
            out = (sig + ns) > threshold ? 1 : ((sig + ns) < -threshold ? -1 : 0);
          }
          sumSig += sig * sig;
          let diff = out - sig;
          sumNoise += diff * diff;
        }
        snrVal = sumNoise < 0.01 ? 20 : 10 * Math.log10(sumSig / sumNoise);
      }
      points.push({ x: plotX + (i / steps) * plotW, y: plotY + plotH * 0.5 - snrVal * plotH * 0.015 });
    }

    ctx.strokeStyle = rgba(SUB, 0.6);
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (let i = 0; i < points.length; i++) {
      let p = points[i];
      let py = Math.max(plotY, Math.min(plotY + plotH, p.y));
      if (i === 0) ctx.moveTo(p.x, py);
      else ctx.lineTo(p.x, py);
    }
    ctx.stroke();

    let curX = plotX + (noiseLevel / 2) * plotW;
    ctx.strokeStyle = rgba(ACCENT, 0.8);
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 4]);
    ctx.beginPath();
    ctx.moveTo(curX, plotY);
    ctx.lineTo(curX, plotY + plotH);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = rgba(ACCENT, 0.9);
    ctx.beginPath();
    ctx.arc(curX, plotY + plotH * 0.5 - computeSNR() * plotH * 0.015, 4, 0, Math.PI * 2);
    ctx.fill();
  }

  function drawBistablePotential() {
    let plotW = W * 0.38;
    let plotH = H * 0.22;
    let plotX = W * 0.55;
    let plotY = H * 0.08;

    ctx.fillStyle = rgba(ACCENT, 0.4);
    ctx.font = '10px Space Grotesk, monospace';
    ctx.fillText('DOUBLE-WELL POTENTIAL', plotX, plotY - 8);

    ctx.strokeStyle = rgba(SUB, 0.4);
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (let i = 0; i <= 100; i++) {
      let xn = (i / 100) * 4 - 2;
      let val = -xn * xn + xn * xn * xn * xn * 0.25;
      let signal = signalAmp * Math.sin(2 * Math.PI * freq * time * 0.01);
      val -= signal * xn * 0.5;
      let x = plotX + (i / 100) * plotW;
      let y = plotY + plotH * 0.5 - val * plotH * 0.2;
      y = Math.max(plotY, Math.min(plotY + plotH, y));
      if (i === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    }
    ctx.stroke();

    let ballXNorm = (bistableState + 2) / 4;
    let ballX = plotX + ballXNorm * plotW;
    let xn = bistableState;
    let signal = signalAmp * Math.sin(2 * Math.PI * freq * time * 0.01);
    let val = -xn * xn + xn * xn * xn * xn * 0.25 - signal * xn * 0.5;
    let ballY = plotY + plotH * 0.5 - val * plotH * 0.2;
    ballY = Math.max(plotY + 5, Math.min(plotY + plotH - 5, ballY));

    ctx.shadowColor = rgba(ACCENT, 0.6);
    ctx.shadowBlur = 12;
    ctx.fillStyle = rgba(ACCENT, 0.9);
    ctx.beginPath();
    ctx.arc(ballX, ballY, 5, 0, Math.PI * 2);
    ctx.fill();
    ctx.shadowBlur = 0;
  }

  function drawParticleMode() {
    let threshY = H * 0.5 - threshold * H * 0.3;

    ctx.strokeStyle = rgba(SUB, 0.3);
    ctx.lineWidth = 1;
    ctx.setLineDash([6, 6]);
    ctx.beginPath();
    ctx.moveTo(0, threshY);
    ctx.lineTo(W, threshY);
    ctx.stroke();

    ctx.strokeStyle = rgba(SUB, 0.3);
    ctx.beginPath();
    ctx.moveTo(0, H - threshY + H * 0.0);
    ctx.lineTo(W, H - threshY + H * 0.0);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = rgba(ACCENT, 0.15);
    ctx.font = '9px Space Grotesk, monospace';
    ctx.fillText('THRESHOLD', 10, threshY - 6);

    for (let p of particles) {
      if (p.trail.length > 1) {
        ctx.beginPath();
        for (let i = 0; i < p.trail.length; i++) {
          let alpha = (i / p.trail.length) * 0.3;
          if (i === 0) {
            ctx.moveTo(p.trail[i].x, p.trail[i].y);
          } else {
            ctx.lineTo(p.trail[i].x, p.trail[i].y);
          }
        }
        ctx.strokeStyle = p.crossed ? rgba(ACCENT, 0.6) : rgba(SUB, 0.25);
        ctx.lineWidth = 0.8;
        ctx.stroke();
      }

      let col = p.crossed ? ACCENT : mix(SUB, ACCENT, 0.3);
      let r = p.crossed ? 4 : 2.5;
      if (p.crossed) {
        ctx.shadowColor = rgba(ACCENT, 0.8);
        ctx.shadowBlur = 10;
      }
      ctx.fillStyle = rgba(col, p.crossed ? 1 : 0.6);
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fill();
      ctx.shadowBlur = 0;
    }
  }

  let lastDetections = 0;

  function update() {
    time++;

    if (autoSweep) {
      noiseLevel += 0.003 * sweepDir;
      if (noiseLevel >= 2) { sweepDir = -1; }
      if (noiseLevel <= 0) { sweepDir = 1; noiseLevel = 0; }
      document.getElementById('sl-noise').value = (noiseLevel / 2) * 100;
      document.getElementById('v-noise').textContent = noiseLevel.toFixed(2);
    }

    let result;
    if (mode === 'particles') {
      result = updateParticles();
      detections += result.crossCount;
      signalHistory.copyWithin(0, 1);
      signalHistory[HISTORY_LEN - 1] = result.signal;
    } else {
      if (mode === 'bistable') {
        result = stepBistable();
      } else {
        result = stepThreshold();
      }

      signalHistory.copyWithin(0, 1);
      signalHistory[HISTORY_LEN - 1] = result.signal;
      noiseHistory.copyWithin(0, 1);
      noiseHistory[HISTORY_LEN - 1] = result.signal + result.noise;
      outputHistory.copyWithin(0, 1);
      outputHistory[HISTORY_LEN - 1] = result.output;

      if (result.output !== 0) {
        let prev = outputHistory[HISTORY_LEN - 2];
        if (prev !== result.output) detections++;
      }
    }

    if (detections !== lastDetections && window.SFX) {
      SFX.play('tick', 80);
      lastDetections = detections;
    }
  }

  function draw() {
    ctx.fillStyle = BG;
    ctx.fillRect(0, 0, W, H);

    if (mode === 'particles') {
      drawParticleMode();

      let wfH = H * 0.08;
      drawWaveform(signalHistory, H * 0.92, wfH, rgba(ACCENT, 0.5), 'INPUT SIGNAL');
    } else {
      let wfH = H * 0.06;
      let y1 = H * 0.12;
      let y2 = H * 0.28;
      let y3 = H * 0.44;

      drawWaveform(signalHistory, y1, wfH, rgba(ACCENT, 0.5), 'WEAK SIGNAL');
      drawWaveform(noiseHistory, y2, wfH, rgba(SUB, 0.6), 'SIGNAL + NOISE');
      drawWaveform(outputHistory, y3, wfH * 1.5, rgba(ACCENT, 0.8), 'OUTPUT');

      if (mode === 'bistable') {
        drawBistablePotential();
      }

      drawSNRCurve();
    }

    document.getElementById('hud-noise').textContent = noiseLevel.toFixed(2);
    document.getElementById('hud-signal').textContent = signalAmp.toFixed(2);
    document.getElementById('hud-snr').textContent = mode === 'particles' ? '--' : computeSNR().toFixed(1);
    document.getElementById('hud-det').textContent = detections;
    document.getElementById('hud-mode').textContent = mode.toUpperCase();
  }

  function loop() {
    update();
    draw();
    requestAnimationFrame(loop);
  }

  const slNoise = document.getElementById('sl-noise');
  const slSignal = document.getElementById('sl-signal');
  const slFreq = document.getElementById('sl-freq');
  const slThresh = document.getElementById('sl-thresh');

  slNoise.addEventListener('input', function() {
    noiseLevel = this.value / 100 * 2;
    document.getElementById('v-noise').textContent = noiseLevel.toFixed(2);
  });
  slSignal.addEventListener('input', function() {
    signalAmp = this.value / 100;
    document.getElementById('v-signal').textContent = signalAmp.toFixed(2);
  });
  slFreq.addEventListener('input', function() {
    freq = parseInt(this.value);
    document.getElementById('v-freq').textContent = freq;
  });
  slThresh.addEventListener('input', function() {
    threshold = this.value / 100;
    document.getElementById('v-thresh').textContent = threshold.toFixed(2);
  });

  function setMode(m) {
    mode = m;
    document.getElementById('btn-bistable').classList.toggle('active', m === 'bistable');
    document.getElementById('btn-threshold').classList.toggle('active', m === 'threshold');
    document.getElementById('btn-particles').classList.toggle('active', m === 'particles');
    if (m === 'particles') initParticles();
    detections = 0;
    bistableState = 0;
    signalHistory.fill(0);
    noiseHistory.fill(0);
    outputHistory.fill(0);
    if (window.SFX) SFX.play('toggle');
  }

  document.getElementById('btn-bistable').addEventListener('click', function() { setMode('bistable'); });
  document.getElementById('btn-threshold').addEventListener('click', function() { setMode('threshold'); });
  document.getElementById('btn-particles').addEventListener('click', function() { setMode('particles'); });

  document.getElementById('btn-reset').addEventListener('click', function() {
    detections = 0;
    bistableState = 0;
    signalHistory.fill(0);
    noiseHistory.fill(0);
    outputHistory.fill(0);
    time = 0;
    if (mode === 'particles') initParticles();
    if (window.SFX) SFX.play('click');
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoSweep = !autoSweep;
    this.classList.toggle('active', autoSweep);
    if (autoSweep) {
      noiseLevel = 0;
      sweepDir = 1;
    }
    if (window.SFX) SFX.play('toggle');
  });

  let dragging = false;
  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) {
      dragging = true;
      if (window.SFX) SFX.play('spawn');
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (dragging) {
      noiseLevel = (e.clientX / W) * 2;
      noiseLevel = Math.max(0, Math.min(2, noiseLevel));
      slNoise.value = (noiseLevel / 2) * 100;
      document.getElementById('v-noise').textContent = noiseLevel.toFixed(2);
    }
  });
  C.addEventListener('mouseup', function() { dragging = false; });

  C.addEventListener('click', function(e) {
    if (mode === 'bistable') {
      bistableState += (Math.random() - 0.5) * 2;
    }
    if (window.SFX) SFX.play('ripple');
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    freq += e.deltaY > 0 ? -1 : 1;
    freq = Math.max(1, Math.min(30, freq));
    slFreq.value = freq;
    document.getElementById('v-freq').textContent = freq;
  }, { passive: false });

  C.addEventListener('touchstart', function(e) {
    dragging = true;
    let t = e.touches[0];
    noiseLevel = (t.clientX / W) * 2;
    noiseLevel = Math.max(0, Math.min(2, noiseLevel));
    slNoise.value = (noiseLevel / 2) * 100;
    document.getElementById('v-noise').textContent = noiseLevel.toFixed(2);
    if (window.SFX) SFX.play('spawn');
  }, { passive: true });
  C.addEventListener('touchmove', function(e) {
    if (dragging) {
      let t = e.touches[0];
      noiseLevel = (t.clientX / W) * 2;
      noiseLevel = Math.max(0, Math.min(2, noiseLevel));
      slNoise.value = (noiseLevel / 2) * 100;
      document.getElementById('v-noise').textContent = noiseLevel.toFixed(2);
    }
  }, { passive: true });
  C.addEventListener('touchend', function() { dragging = false; }, { passive: true });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
