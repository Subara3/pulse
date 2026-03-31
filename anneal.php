<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('anneal');

$pageName = 'anneal';
$pageTitle = 'ANNEAL - PULSE';
$pageDesc = 'Simulated annealing solves the Travelling Salesman Problem. Watch a hot chaotic path cool into the shortest route.';
$pageCanonical = 'https://pulse.subara3.com/anneal.php';
$extraStyle = '
#hud{position:fixed;top:1rem;left:1rem;font-size:.7rem;color:rgba(0,245,212,.65);font-family:monospace;pointer-events:none;line-height:2;z-index:10}
#hud span.v{color:#fff}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.28rem .62rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
.ctrl-row{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.ctrl-label{color:rgba(0,245,212,.55);font-family:monospace;font-size:.65rem;letter-spacing:.05em;min-width:3.8rem;text-align:right}
.ctrl-val{color:rgba(255,255,255,.7);font-family:monospace;font-size:.62rem;min-width:2.2rem;text-align:right}
input[type=range]{width:88px;accent-color:#00f5d4;cursor:pointer}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>TEMP &nbsp;&nbsp;<span class="v" id="hud-temp">100.0%</span></div>
  <div>DIST &nbsp;&nbsp;<span class="v" id="hud-dist">—</span></div>
  <div>CITIES &nbsp;<span class="v" id="hud-cities">60</span></div>
  <div>STATUS &nbsp;<span class="v" id="hud-status">ANNEALING</span></div>
</div>
<div id="controls">
  <div class="ctrl-row">
    <span class="ctrl-label">CITIES</span>
    <input type="range" id="sl-cities" min="10" max="120" value="60" step="5">
    <span class="ctrl-val" id="val-cities">60</span>
  </div>
  <div class="ctrl-row">
    <span class="ctrl-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="5" value="3" step="1">
    <span class="ctrl-val" id="val-speed">3</span>
  </div>
  <button class="btn" id="btn-reset">RESET</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add city'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  let W, H;
  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize);
  resize();

  // ── Constants ────────────────────────────────────────────────────────────
  const PAD      = 70;
  const T_INIT   = 1.0;
  const T_MIN    = 0.00008;
  const ALPHA    = 0.99992;
  const BASE_ITER = 250;

  // ── State ────────────────────────────────────────────────────────────────
  let cities     = [];
  let tour       = [];
  let bestTour   = [];
  let bestDist   = Infinity;
  let currentDist = Infinity;
  let T          = T_INIT;
  let frozen     = false;
  let improvements = 0;
  let numCities  = 60;
  let speed      = 3;

  // ── Helpers ──────────────────────────────────────────────────────────────
  function d(a, b) {
    const dx = a.x - b.x, dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function calcDist(t) {
    let s = 0, n = t.length;
    for (let i = 0; i < n; i++) s += d(cities[t[i]], cities[t[(i + 1) % n]]);
    return s;
  }

  function randomCity() {
    return { x: PAD + Math.random() * (W - 2 * PAD),
             y: PAD + Math.random() * (H - 2 * PAD) };
  }

  // Greedy nearest-neighbor starting tour
  function greedyTour() {
    const n = cities.length;
    const vis = new Uint8Array(n);
    const t   = [0]; vis[0] = 1;
    for (let i = 1; i < n; i++) {
      const last = t[t.length - 1];
      let best = -1, bd = Infinity;
      for (let j = 0; j < n; j++) {
        if (!vis[j]) { const dd = d(cities[last], cities[j]); if (dd < bd) { bd = dd; best = j; } }
      }
      t.push(best); vis[best] = 1;
    }
    return t;
  }

  function initTour() {
    if (cities.length < 3) return;
    tour        = greedyTour();
    currentDist = calcDist(tour);
    bestTour    = [...tour];
    bestDist    = currentDist;
    T           = T_INIT;
    frozen      = false;
    improvements = 0;
  }

  function init(n) {
    numCities = n;
    cities    = Array.from({ length: n }, randomCity);
    initTour();
  }

  // ── Simulated Annealing (2-opt) ──────────────────────────────────────────
  function step() {
    const n = cities.length;
    if (n < 4 || frozen) return;

    // Random pair i < k with segment length ≥ 2 and < n-1
    let i = (Math.random() * n) | 0;
    let k = (Math.random() * (n - 2)) | 0;
    if (k >= i) k += 2; else { const tmp = i; i = k; k = tmp; }
    if (i < 0 || k >= n || k - i < 2 || k - i >= n - 1) return;

    const a = cities[tour[i]],     b = cities[tour[i + 1]];
    const c = cities[tour[k]],     dd = cities[tour[(k + 1) % n]];
    const delta = d(a, c) + d(b, dd) - d(a, b) - d(c, dd);

    if (delta < 0 || Math.random() < Math.exp(-delta / T)) {
      // Reverse tour[i+1 .. k]
      let l = i + 1, r = k;
      while (l < r) { const tmp = tour[l]; tour[l] = tour[r]; tour[r] = tmp; l++; r--; }
      currentDist += delta;

      if (currentDist < bestDist - 0.01) {
        bestDist  = currentDist;
        bestTour  = [...tour];
        improvements++;
        SFX.play('success', 250);
      }
    }

    T *= ALPHA;
    if (T < T_MIN) { T = T_MIN; frozen = true; }
  }

  // ── Draw ─────────────────────────────────────────────────────────────────
  let lastHud = 0;

  function updateHUD() {
    const now = Date.now();
    if (now - lastHud < 80) return;
    lastHud = now;
    const tp = Math.max(0, Math.min(1, Math.log(T / T_MIN) / Math.log(T_INIT / T_MIN)));
    document.getElementById('hud-temp').textContent   = (tp * 100).toFixed(1) + '%';
    document.getElementById('hud-dist').textContent   = bestDist < Infinity ? bestDist.toFixed(0) : '—';
    document.getElementById('hud-cities').textContent = cities.length;
    document.getElementById('hud-status').textContent = frozen ? 'FROZEN' : 'ANNEALING';
  }

  function drawTour(t, alpha, width, glow) {
    if (t.length < 2) return;
    ctx.strokeStyle = `rgba(0,245,212,${alpha})`;
    ctx.lineWidth   = width;
    ctx.shadowColor = glow ? '#00f5d4' : 'transparent';
    ctx.shadowBlur  = glow ? 6 : 0;
    ctx.beginPath();
    ctx.moveTo(cities[t[0]].x, cities[t[0]].y);
    for (let i = 1; i < t.length; i++) ctx.lineTo(cities[t[i]].x, cities[t[i]].y);
    ctx.closePath();
    ctx.stroke();
    ctx.shadowBlur = 0;
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (cities.length < 2) { updateHUD(); return; }

    // Temperature-tinted background overlay
    const tp = Math.max(0, Math.min(1, Math.log(T / T_MIN) / Math.log(T_INIT / T_MIN)));
    if (!frozen && tp > 0.01) {
      ctx.fillStyle = `rgba(123,44,191,${tp * 0.07})`;
      ctx.fillRect(0, 0, W, H);
    }

    // Current (exploratory) tour — visible only while warm
    if (!frozen && tp > 0.05) {
      drawTour(tour, 0.06 + tp * 0.12, 1, false);
    }

    // Best tour
    drawTour(bestTour, frozen ? 0.9 : 0.6, frozen ? 1.5 : 1.2, frozen);

    // Cities
    for (let i = 0; i < cities.length; i++) {
      const { x, y } = cities[i];
      ctx.fillStyle   = 'rgba(255,255,255,0.85)';
      ctx.shadowColor = '#00f5d4';
      ctx.shadowBlur  = 3;
      ctx.beginPath();
      ctx.arc(x, y, 3, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.shadowBlur = 0;

    updateHUD();
  }

  // ── Main loop ─────────────────────────────────────────────────────────────
  let completedOnce = false;
  function loop() {
    if (!frozen) {
      const iters = BASE_ITER * Math.pow(2, speed - 1);
      for (let i = 0; i < iters; i++) {
        step();
        if (frozen) {
          if (!completedOnce) { completedOnce = true; SFX.play('complete', 100); }
          break;
        }
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  // ── Events ───────────────────────────────────────────────────────────────
  function addCity(x, y) {
    cities.push({ x, y });
    initTour();
    completedOnce = false;
    SFX.play('spawn');
  }

  function removeCity(x, y) {
    if (cities.length <= 4) return;
    let best = -1, bd = Infinity;
    for (let i = 0; i < cities.length; i++) {
      const dd = Math.hypot(cities[i].x - x, cities[i].y - y);
      if (dd < bd) { bd = dd; best = i; }
    }
    if (best >= 0 && bd < 40) {
      cities.splice(best, 1);
      if (cities.length >= 3) initTour();
      completedOnce = false;
      SFX.play('pop');
    }
  }

  canvas.addEventListener('click', e => {
    const r = canvas.getBoundingClientRect();
    addCity(e.clientX - r.left, e.clientY - r.top);
  });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    const r = canvas.getBoundingClientRect();
    removeCity(e.clientX - r.left, e.clientY - r.top);
  });
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    const t = e.changedTouches[0], r = canvas.getBoundingClientRect();
    addCity(t.clientX - r.left, t.clientY - r.top);
  }, { passive: false });

  // Sliders
  const slCities = document.getElementById('sl-cities');
  const slSpeed  = document.getElementById('sl-speed');

  slCities.addEventListener('input', () => {
    document.getElementById('val-cities').textContent = slCities.value;
    SFX.play('tick');
  });
  slSpeed.addEventListener('input', () => {
    speed = parseInt(slSpeed.value);
    document.getElementById('val-speed').textContent = slSpeed.value;
    SFX.play('tick');
  });
  document.getElementById('btn-reset').addEventListener('click', () => {
    init(parseInt(slCities.value));
    completedOnce = false;
    SFX.play('click');
  });

  // ── Start ────────────────────────────────────────────────────────────────
  init(60);
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
