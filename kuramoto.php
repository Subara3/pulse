<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kuramoto');

$pageName = 'kuramoto';
$pageTitle = 'KURAMOTO - PULSE';
$pageDesc = '蔵本モデル。N個の振動子が弱い力で引き合い、臨界点を超えると一斉に同期する。';
$pageCanonical = 'https://pulse.subara3.com/kuramoto.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; }
.hud { position: fixed; top: 1rem; right: 1rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: right; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .v { color: #00f5d4; }
.hud .sync { color: #00f5d4; font-size: 1rem; font-weight: bold; transition: color 0.5s; }
.hud .sync.low { color: rgba(123,44,191,0.8); }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 120px; accent-color: #00f5d4; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .ctrl input[type=range] { width: 90px; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .hud { font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>N: <span class="v" id="nDisplay">80</span></div>
  <div>K: <span class="v" id="kDisplay">1.00</span></div>
  <div>r: <span class="sync" id="rDisplay">0.00</span></div>
</div>
<div class="ctrl">
  <label>K (coupling) <input type="range" id="kSlider" min="0" max="6" step="0.05" value="1.0"></label>
  <label>N (oscillators) <input type="range" id="nSlider" min="10" max="200" step="5" value="80"></label>
  <button id="btnReset">RESET</button>
  <button id="btnAuto">AUTO: OFF</button>
</div>
<div class="title">KURAMOTO</div>
<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'K coupling'],
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  let W, H, cx, cy, R;
  let N = 80;
  let K = 1.0;
  let autoMode = false;
  let autoDir = 1;
  let autoT = 0;

  // oscillator state
  let theta = [];   // phase
  let omega = [];   // natural frequency (Gaussian)
  let prevSync = 0;
  let syncFlash = 0;

  function gaussian() {
    let u = 0, v = 0;
    while (u === 0) u = Math.random();
    while (v === 0) v = Math.random();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
  }

  function initOscillators() {
    theta = [];
    omega = [];
    for (let i = 0; i < N; i++) {
      theta.push(Math.random() * Math.PI * 2);
      omega.push(gaussian() * 1.0); // std dev 1
    }
    prevSync = 0;
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    R = Math.min(W, H) * 0.36;
  }

  // Order parameter r = |1/N * sum exp(i*theta_j)|
  function orderParameter() {
    let re = 0, im = 0;
    for (let i = 0; i < N; i++) {
      re += Math.cos(theta[i]);
      im += Math.sin(theta[i]);
    }
    return Math.sqrt(re * re + im * im) / N;
  }

  function meanPhase() {
    let re = 0, im = 0;
    for (let i = 0; i < N; i++) {
      re += Math.cos(theta[i]);
      im += Math.sin(theta[i]);
    }
    return Math.atan2(im, re);
  }

  // RK4 step for all oscillators
  function step(dt) {
    const K_N = K / N;

    function deriv(ths) {
      const d = new Float64Array(N);
      for (let i = 0; i < N; i++) {
        let coupling = 0;
        for (let j = 0; j < N; j++) {
          coupling += Math.sin(ths[j] - ths[i]);
        }
        d[i] = omega[i] + K_N * coupling;
      }
      return d;
    }

    const k1 = deriv(theta);
    const th2 = theta.map((t, i) => t + 0.5 * dt * k1[i]);
    const k2 = deriv(th2);
    const th3 = theta.map((t, i) => t + 0.5 * dt * k2[i]);
    const k3 = deriv(th3);
    const th4 = theta.map((t, i) => t + dt * k3[i]);
    const k4 = deriv(th4);

    for (let i = 0; i < N; i++) {
      theta[i] += (dt / 6) * (k1[i] + 2 * k2[i] + 2 * k3[i] + k4[i]);
    }
  }

  function hslToRgb(h, s, l) {
    h /= 360; s /= 100; l /= 100;
    let r, g, b;
    if (s === 0) { r = g = b = l; }
    else {
      const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      const p = 2 * l - q;
      const hue2rgb = (p, q, t) => {
        if (t < 0) t += 1; if (t > 1) t -= 1;
        if (t < 1/6) return p + (q - p) * 6 * t;
        if (t < 1/2) return q;
        if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
        return p;
      };
      r = hue2rgb(p, q, h + 1/3);
      g = hue2rgb(p, q, h);
      b = hue2rgb(p, q, h - 1/3);
    }
    return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
  }

  // Color oscillator by phase relative to mean
  function oscillatorColor(i, r) {
    const psi = meanPhase();
    const diff = Math.abs(((theta[i] - psi + Math.PI) % (2 * Math.PI)) - Math.PI);
    // diff in [0, PI]: 0 = in sync (teal), PI = opposite (purple)
    const t = diff / Math.PI; // 0..1
    // hue: 175 (teal) → 270 (purple)
    const hue = 175 + t * 95;
    const sat = 80 - t * 20;
    const lit = 55 + r * 10;
    const [rr, gg, bb] = hslToRgb(hue, sat, lit);
    const alpha = 0.7 + r * 0.3;
    return `rgba(${rr},${gg},${bb},${alpha})`;
  }

  let trailCanvas, trailCtx;

  function initTrail() {
    trailCanvas = document.createElement('canvas');
    trailCanvas.width = W;
    trailCanvas.height = H;
    trailCtx = trailCanvas.getContext('2d');
  }

  function draw(r) {
    // Fade trail
    ctx.fillStyle = 'rgba(10,10,15,0.25)';
    ctx.fillRect(0, 0, W, H);

    // Draw orbit ring
    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.strokeStyle = `rgba(0,245,212,${0.04 + r * 0.06})`;
    ctx.lineWidth = 1;
    ctx.stroke();

    // Order parameter vector (mean field)
    const psi = meanPhase();
    const arrowLen = R * r;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + Math.cos(psi) * arrowLen, cy + Math.sin(psi) * arrowLen);
    const grad = ctx.createLinearGradient(cx, cy, cx + Math.cos(psi) * arrowLen, cy + Math.sin(psi) * arrowLen);
    grad.addColorStop(0, `rgba(0,245,212,0)`);
    grad.addColorStop(1, `rgba(0,245,212,${0.3 + r * 0.5})`);
    ctx.strokeStyle = grad;
    ctx.lineWidth = 2 + r * 3;
    ctx.stroke();

    // Center glow when synchronized
    if (r > 0.7) {
      const grd = ctx.createRadialGradient(cx, cy, 0, cx, cy, R * r * 0.8);
      grd.addColorStop(0, `rgba(0,245,212,${(r - 0.7) * 0.15})`);
      grd.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = grd;
      ctx.beginPath();
      ctx.arc(cx, cy, R, 0, Math.PI * 2);
      ctx.fill();
    }

    // Oscillators
    const dotR = Math.max(2.5, Math.min(5, 300 / N));
    for (let i = 0; i < N; i++) {
      const px = cx + Math.cos(theta[i]) * R;
      const py = cy + Math.sin(theta[i]) * R;

      ctx.beginPath();
      ctx.arc(px, py, dotR, 0, Math.PI * 2);
      ctx.fillStyle = oscillatorColor(i, r);
      ctx.fill();

      // Glow for synchronized oscillators
      if (r > 0.5) {
        const psi = meanPhase();
        const diff = Math.abs(((theta[i] - psi + Math.PI) % (2 * Math.PI)) - Math.PI);
        if (diff < 0.3) {
          ctx.beginPath();
          ctx.arc(px, py, dotR * 2.5, 0, Math.PI * 2);
          ctx.fillStyle = `rgba(0,245,212,${(0.3 - diff) * 0.3 * r})`;
          ctx.fill();
        }
      }
    }

    // Sync flash effect
    if (syncFlash > 0) {
      ctx.fillStyle = `rgba(0,245,212,${syncFlash * 0.05})`;
      ctx.fillRect(0, 0, W, H);
      syncFlash -= 0.03;
    }
  }

  let lastTime = 0;
  const DT = 0.016; // simulation timestep
  const STEPS_PER_FRAME = 3;

  function loop(ts) {
    requestAnimationFrame(loop);

    const dt_real = Math.min((ts - lastTime) / 1000, 0.05);
    lastTime = ts;

    // Auto-sweep K
    if (autoMode) {
      autoT += dt_real * 0.15;
      K = 3 + Math.sin(autoT) * 2.8;
      document.getElementById('kSlider').value = K;
      document.getElementById('kDisplay').textContent = K.toFixed(2);
    }

    // Physics steps
    for (let s = 0; s < STEPS_PER_FRAME; s++) {
      step(DT / STEPS_PER_FRAME);
    }

    const r = orderParameter();

    // Sync flash trigger
    if (r > 0.8 && prevSync < 0.8) {
      syncFlash = 1.0;
      SFX.play('ripple');
    }
    prevSync = r;

    draw(r);

    // Update HUD
    const rEl = document.getElementById('rDisplay');
    rEl.textContent = r.toFixed(3);
    rEl.className = 'sync' + (r > 0.6 ? '' : ' low');
  }

  function perturb() {
    for (let i = 0; i < N; i++) {
      theta[i] += (Math.random() - 0.5) * 1.5;
    }
    SFX.play('spawn');
  }

  // Init
  resize();
  initOscillators();
  requestAnimationFrame(loop);

  window.addEventListener('resize', () => {
    resize();
    initOscillators();
  });

  // Controls
  const kSlider = document.getElementById('kSlider');
  const nSlider = document.getElementById('nSlider');

  kSlider.addEventListener('input', () => {
    K = parseFloat(kSlider.value);
    document.getElementById('kDisplay').textContent = K.toFixed(2);
  });

  nSlider.addEventListener('input', () => {
    N = parseInt(nSlider.value);
    document.getElementById('nDisplay').textContent = N;
    initOscillators();
    SFX.play('click');
  });

  document.getElementById('btnReset').addEventListener('click', () => {
    initOscillators();
    SFX.play('click');
  });

  document.getElementById('btnAuto').addEventListener('click', () => {
    autoMode = !autoMode;
    document.getElementById('btnAuto').textContent = 'AUTO: ' + (autoMode ? 'ON' : 'OFF');
    SFX.play('toggle');
  });

  // Canvas click = perturb
  canvas.addEventListener('click', (e) => {
    perturb();
  });

  // Right click = reset
  canvas.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    initOscillators();
    SFX.play('click');
  });

  // Touch
  canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    perturb();
  }, { passive: false });

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
