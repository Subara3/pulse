<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('levydrift');

$pageName = 'levydrift';
$pageTitle = 'LEVY DRIFT - PULSE';
$pageDesc = 'Heavy-tailed random walks carve luminous transport paths and hotspots.';
$pageCanonical = 'https://pulse.subara3.com/levydrift.php';
$extraStyle = <<<'CSS'
<style>
  html, body {
    margin: 0;
    width: 100%;
    height: 100%;
    background: #0a0a0f;
    overflow: hidden;
  }
  #canvas {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    touch-action: none;
  }
  .hud {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 20;
    display: grid;
    gap: 8px;
    min-width: 190px;
    font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace;
    color: #dff;
    background: rgba(10, 10, 15, 0.58);
    border: 1px solid rgba(0, 245, 212, 0.35);
    border-radius: 10px;
    padding: 10px;
    backdrop-filter: blur(4px);
  }
  .hud .row { display: flex; justify-content: space-between; gap: 10px; }
  .controls {
    position: fixed;
    right: 12px;
    bottom: 12px;
    z-index: 20;
    display: grid;
    gap: 8px;
    width: min(260px, calc(100vw - 24px));
    font: 11px/1.2 ui-monospace, SFMono-Regular, Menlo, monospace;
    color: #dff;
    background: rgba(10, 10, 15, 0.62);
    border: 1px solid rgba(123, 44, 191, 0.45);
    border-radius: 10px;
    padding: 10px;
  }
  .controls label { display: grid; gap: 3px; }
  .controls .line { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .controls input[type='range'] { width: 100%; accent-color: #00f5d4; }
  .controls button {
    border: 1px solid rgba(0, 245, 212, 0.45);
    background: rgba(0, 245, 212, 0.12);
    color: #dff;
    border-radius: 8px;
    padding: 7px 8px;
    cursor: pointer;
  }
  .controls button:hover { background: rgba(0, 245, 212, 0.2); }
  .info {
    position: fixed;
    left: 12px;
    bottom: 12px;
    z-index: 20;
    max-width: min(420px, calc(100vw - 24px));
    font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace;
    color: rgba(222, 245, 255, 0.9);
    background: rgba(10, 10, 15, 0.5);
    border: 1px solid rgba(123, 44, 191, 0.38);
    border-radius: 10px;
    padding: 10px;
  }
  @media (max-width: 740px) {
    .hud { min-width: 155px; font-size: 11px; }
    .controls { width: min(225px, calc(100vw - 24px)); }
    .info { font-size: 11px; max-width: 56vw; }
  }
</style>
CSS;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud" id="hud">
  <div class="row"><span>WALKERS</span><strong id="walkerCount">0</strong></div>
  <div class="row"><span>MEAN STEP</span><strong id="meanStep">0.0</strong></div>
  <div class="row"><span>HOTSPOT</span><strong id="hotspot">0</strong></div>
</div>
<div class="controls">
  <label>
    <div class="line"><span>ALPHA (tail)</span><span id="alphaVal">1.45</span></div>
    <input id="alpha" type="range" min="1.1" max="2" step="0.01" value="1.45">
  </label>
  <label>
    <div class="line"><span>TURN JITTER</span><span id="jitterVal">0.25</span></div>
    <input id="jitter" type="range" min="0" max="1" step="0.01" value="0.25">
  </label>
  <label>
    <div class="line"><span>WALKERS</span><span id="densityVal">120</span></div>
    <input id="density" type="range" min="20" max="280" step="10" value="120">
  </label>
  <button id="burstBtn">BURST</button>
</div>
<div class="info">Heavy-tailed steps produce occasional long jumps, then local trapping. Tap to inject walkers. Drag to attract. Right-click to clear trails.</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inject'],
  ['type' => 'drag', 'label' => 'attract'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(() => {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d', { alpha: false });

  const alphaEl = document.getElementById('alpha');
  const jitterEl = document.getElementById('jitter');
  const densityEl = document.getElementById('density');
  const alphaVal = document.getElementById('alphaVal');
  const jitterVal = document.getElementById('jitterVal');
  const densityVal = document.getElementById('densityVal');

  const walkerCountEl = document.getElementById('walkerCount');
  const meanStepEl = document.getElementById('meanStep');
  const hotspotEl = document.getElementById('hotspot');

  let w = 0, h = 0, dpr = 1;
  let walkers = [];
  let heat;
  let pointer = { x: 0, y: 0, down: false };
  let alpha = parseFloat(alphaEl.value);
  let jitter = parseFloat(jitterEl.value);

  function resize() {
    dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
    w = Math.floor(window.innerWidth);
    h = Math.floor(window.innerHeight);
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    heat = new Float32Array(w * h);
    seed(parseInt(densityEl.value, 10), w * 0.5, h * 0.5, Math.min(w, h) * 0.3);
    paintBackground(true);
  }

  function rand(min, max) { return min + Math.random() * (max - min); }

  function sampleLevy(a) {
    // Pareto-like step with finite cap for stable mobile performance
    const u = Math.max(1e-6, 1 - Math.random());
    const step = Math.pow(u, -1 / a);
    return Math.min(step * 2.2, 42);
  }

  function createWalker(x, y, spread = 20) {
    return {
      x: x + rand(-spread, spread),
      y: y + rand(-spread, spread),
      a: rand(0, Math.PI * 2),
      v: rand(0.85, 1.2),
      hue: rand(176, 286)
    };
  }

  function seed(count, cx, cy, spread) {
    walkers = [];
    for (let i = 0; i < count; i++) walkers.push(createWalker(cx, cy, spread));
    walkerCountEl.textContent = walkers.length;
  }

  function addBurst(cx, cy, count = 24) {
    for (let i = 0; i < count; i++) walkers.push(createWalker(cx, cy, 8));
    const target = parseInt(densityEl.value, 10) + 130;
    if (walkers.length > target) walkers.splice(0, walkers.length - target);
    walkerCountEl.textContent = walkers.length;
    if (window.SFX) SFX.play('spawn', 40);
  }

  function clearHeat() {
    heat.fill(0);
    paintBackground(true);
    if (window.SFX) SFX.play('ripple');
  }

  function paintBackground(full = false) {
    if (full) {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, w, h);
      return;
    }
    ctx.fillStyle = 'rgba(10,10,15,0.06)';
    ctx.fillRect(0, 0, w, h);
  }

  function tick() {
    paintBackground(false);

    const baseCount = parseInt(densityEl.value, 10);
    while (walkers.length < baseCount) walkers.push(createWalker(rand(0, w), rand(0, h), 6));
    if (walkers.length > baseCount + 130) walkers.length = baseCount + 130;

    let stepAcc = 0;
    let hot = 0;

    for (let i = 0; i < walkers.length; i++) {
      const p = walkers[i];
      const step = sampleLevy(alpha) * p.v;
      stepAcc += step;

      p.a += rand(-jitter, jitter);
      if (pointer.down) {
        const dx = pointer.x - p.x;
        const dy = pointer.y - p.y;
        const ang = Math.atan2(dy, dx);
        p.a += Math.sin(ang - p.a) * 0.18;
      }

      p.x += Math.cos(p.a) * step;
      p.y += Math.sin(p.a) * step;

      if (p.x < 0) p.x += w;
      if (p.x >= w) p.x -= w;
      if (p.y < 0) p.y += h;
      if (p.y >= h) p.y -= h;

      const xi = p.x | 0;
      const yi = p.y | 0;
      const idx = yi * w + xi;
      heat[idx] = Math.min(heat[idx] + 1.8, 420);
      const local = heat[idx];
      if (local > hot) hot = local;

      const sat = 66;
      const light = Math.min(80, 34 + local * 0.18);
      ctx.fillStyle = `hsla(${p.hue}, ${sat}%, ${light}%, ${Math.min(0.9, 0.2 + local * 0.004)})`;
      ctx.fillRect(p.x, p.y, 1.6, 1.6);

      if (local > 20) {
        ctx.fillStyle = 'rgba(255,255,255,0.09)';
        ctx.fillRect(p.x, p.y, 1, 1);
      }
    }

    walkerCountEl.textContent = walkers.length;
    meanStepEl.textContent = (stepAcc / Math.max(1, walkers.length)).toFixed(1);
    hotspotEl.textContent = hot.toFixed(0);

    requestAnimationFrame(tick);
  }

  function pointerPos(e) {
    const rect = canvas.getBoundingClientRect();
    if (e.touches && e.touches[0]) {
      return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
    }
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
  }

  canvas.addEventListener('pointerdown', (e) => {
    const p = pointerPos(e);
    pointer.x = p.x; pointer.y = p.y; pointer.down = true;
    addBurst(pointer.x, pointer.y, 28);
  });
  canvas.addEventListener('pointermove', (e) => {
    const p = pointerPos(e);
    pointer.x = p.x; pointer.y = p.y;
    if (pointer.down && window.SFX) SFX.play('move', 70);
  });
  window.addEventListener('pointerup', () => { pointer.down = false; });

  canvas.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    clearHeat();
  });

  canvas.addEventListener('touchstart', (e) => {
    const p = pointerPos(e);
    pointer.x = p.x; pointer.y = p.y; pointer.down = true;
    addBurst(pointer.x, pointer.y, 24);
  }, { passive: true });
  canvas.addEventListener('touchmove', (e) => {
    const p = pointerPos(e);
    pointer.x = p.x; pointer.y = p.y;
    if (window.SFX) SFX.play('move', 80);
  }, { passive: true });
  canvas.addEventListener('touchend', () => { pointer.down = false; }, { passive: true });

  alphaEl.addEventListener('input', () => {
    alpha = parseFloat(alphaEl.value);
    alphaVal.textContent = alpha.toFixed(2);
    if (window.SFX) SFX.play('toggle');
  });
  jitterEl.addEventListener('input', () => {
    jitter = parseFloat(jitterEl.value);
    jitterVal.textContent = jitter.toFixed(2);
    if (window.SFX) SFX.play('tick', 60);
  });
  densityEl.addEventListener('input', () => {
    densityVal.textContent = densityEl.value;
    if (window.SFX) SFX.play('tick', 50);
  });
  document.getElementById('burstBtn').addEventListener('click', () => {
    addBurst(rand(w * 0.2, w * 0.8), rand(h * 0.2, h * 0.8), 36);
    if (window.SFX) SFX.play('grow');
  });

  window.addEventListener('resize', resize);
  resize();
  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
