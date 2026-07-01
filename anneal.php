<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('anneal');

$pageName = 'anneal';
$pageTitle = 'ANNEAL - PULSE';
$pageDesc = 'Simulated annealing solves the Travelling Salesman Problem. Watch a hot chaotic path cool into the shortest route.';
$pageCanonical = 'https://pulse.subara3.com/anneal.php';
$extraStyle = '
body{overflow:hidden}
#canvas{position:fixed;top:0;left:0;width:100%;height:100%}
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
  <div>BEST &nbsp;&nbsp;<span class="v" id="hud-best">—</span></div>
  <div>CITIES &nbsp;<span class="v" id="hud-cities">60</span></div>
  <div>STATUS &nbsp;<span class="v" id="hud-status">ANNEALING</span></div>
</div>
<div id="controls" class="drawer-controls">
  <div class="ctrl-row">
    <span class="ctrl-label">CITIES</span>
    <input type="range" id="sl-cities" min="10" max="150" value="60" step="5">
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
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
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

  /* ── State ──────────────────────────────────────────────────────────── */
  const PAD = 60;
  let cities     = [];
  let tour       = [];      // current working tour
  let bestTour   = [];
  let bestDist   = Infinity;
  let curDist    = Infinity;
  let T          = 1;       // current temperature (will be auto-calibrated)
  let T_INIT     = 1;       // stored for percentage display
  let T_MIN      = 0.001;
  let ALPHA      = 0.999985;
  let frozen     = false;
  let speed      = 3;
  let completedOnce = false;

  /* ── Distance helpers ───────────────────────────────────────────────── */
  function dist(a, b) {
    const dx = a.x - b.x, dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function tourDist(t) {
    let s = 0;
    for (let i = 0, n = t.length; i < n; i++)
      s += dist(cities[t[i]], cities[t[(i + 1) % n]]);
    return s;
  }

  /* ── Random initial tour (Fisher-Yates shuffle) ─────────────────────── */
  function randomTour() {
    const t = Array.from({ length: cities.length }, (_, i) => i);
    for (let i = t.length - 1; i > 0; i--) {
      const j = (Math.random() * (i + 1)) | 0;
      const tmp = t[i]; t[i] = t[j]; t[j] = tmp;
    }
    return t;
  }

  function randomCity() {
    return {
      x: PAD + Math.random() * (W - 2 * PAD),
      y: PAD + Math.random() * (H - 2 * PAD)
    };
  }

  /* ── Auto-calibrate initial temperature ─────────────────────────────── */
  function calibrateTemp() {
    // Sample random 2-opt deltas to find a good starting T
    // so that ~80% of uphill moves are accepted initially
    const n = cities.length;
    if (n < 4) return 100;
    const deltas = [];
    for (let s = 0; s < 200; s++) {
      let i = (Math.random() * n) | 0;
      let k = (Math.random() * n) | 0;
      if (i === k) continue;
      if (i > k) { const tmp = i; i = k; k = tmp; }
      if (k - i < 2 || k - i >= n - 1) continue;
      const a = cities[tour[i]],     b = cities[tour[i + 1]];
      const c = cities[tour[k]],     dd = cities[tour[(k + 1) % n]];
      const delta = dist(a, c) + dist(b, dd) - dist(a, b) - dist(c, dd);
      if (delta > 0) deltas.push(delta);
    }
    if (deltas.length === 0) return 100;
    deltas.sort((a, b) => a - b);
    // Set T so that the median uphill delta has ~80% acceptance
    const median = deltas[Math.floor(deltas.length * 0.5)];
    return -median / Math.log(0.8);
  }

  /* ── Init ───────────────────────────────────────────────────────────── */
  function init(n) {
    cities  = Array.from({ length: n }, randomCity);
    tour    = randomTour();
    curDist = tourDist(tour);
    bestTour = [...tour];
    bestDist = curDist;

    // Calibrate temperature to match problem scale
    T_INIT = calibrateTemp();
    T      = T_INIT;
    T_MIN  = T_INIT * 0.000001;
    ALPHA  = 0.999985;
    frozen = false;
    completedOnce = false;
  }

  /* ── 2-opt SA step ──────────────────────────────────────────────────── */
  function step() {
    const n = cities.length;
    if (n < 4 || frozen) return;

    // Pick two random positions for 2-opt
    let i = (Math.random() * n) | 0;
    let k = (Math.random() * n) | 0;
    if (i === k) return;
    if (i > k) { const tmp = i; i = k; k = tmp; }
    if (k - i < 2 || k - i >= n - 1) return;

    // Delta evaluation (O(1))
    const a  = cities[tour[i]];
    const b  = cities[tour[i + 1]];
    const c  = cities[tour[k]];
    const dd = cities[tour[(k + 1) % n]];
    const delta = dist(a, c) + dist(b, dd) - dist(a, b) - dist(c, dd);

    // Metropolis criterion
    if (delta < 0 || Math.random() < Math.exp(-delta / T)) {
      // Reverse segment tour[i+1 .. k]
      let l = i + 1, r = k;
      while (l < r) { const tmp = tour[l]; tour[l] = tour[r]; tour[r] = tmp; l++; r--; }
      curDist += delta;

      if (curDist < bestDist - 0.01) {
        bestDist = curDist;
        bestTour = [...tour];
        if (typeof SFX !== 'undefined') SFX.play('success', 300);
      }
    }

    T *= ALPHA;
    if (T < T_MIN) { T = T_MIN; frozen = true; }
  }

  /* ── Deterministic 2-opt local search (post-freeze cleanup) ────────── */
  function localOpt() {
    const n = tour.length;
    if (n < 4) return;
    let improved = true;
    while (improved) {
      improved = false;
      for (let i = 0; i < n - 2; i++) {
        for (let k = i + 2; k < n; k++) {
          if (i === 0 && k === n - 1) continue;
          const a = cities[tour[i]], b = cities[tour[i + 1]];
          const c = cities[tour[k]], dd = cities[tour[(k + 1) % n]];
          const delta = dist(a, c) + dist(b, dd) - dist(a, b) - dist(c, dd);
          if (delta < -0.01) {
            let l = i + 1, r = k;
            while (l < r) { const tmp = tour[l]; tour[l] = tour[r]; tour[r] = tmp; l++; r--; }
            curDist += delta;
            improved = true;
          }
        }
      }
    }
    bestTour = [...tour];
    bestDist = curDist;
  }

  /* ── Drawing ────────────────────────────────────────────────────────── */
  // Temperature as 0..1 (1 = hottest)
  function tempPct() {
    if (T_INIT <= T_MIN) return 0;
    return Math.max(0, Math.min(1, Math.log(T / T_MIN) / Math.log(T_INIT / T_MIN)));
  }

  function drawPath(t, r, g, b, alpha, lineW, glow) {
    if (t.length < 2) return;
    ctx.strokeStyle = `rgba(${r},${g},${b},${alpha})`;
    ctx.lineWidth   = lineW;
    if (glow) { ctx.shadowColor = `rgb(${r},${g},${b})`; ctx.shadowBlur = 8; }
    ctx.beginPath();
    ctx.moveTo(cities[t[0]].x, cities[t[0]].y);
    for (let j = 1; j < t.length; j++) ctx.lineTo(cities[t[j]].x, cities[t[j]].y);
    ctx.closePath();
    ctx.stroke();
    ctx.shadowBlur = 0;
  }

  let lastHud = 0;

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    if (cities.length < 2) return;

    const tp = tempPct();

    // Purple heat overlay
    if (!frozen && tp > 0.01) {
      ctx.fillStyle = `rgba(123,44,191,${tp * 0.08})`;
      ctx.fillRect(0, 0, W, H);
    }

    // Current tour (hot = purple, cool = teal)
    const cr = Math.round(123 * tp + 0 * (1 - tp));
    const cg = Math.round(44 * tp + 245 * (1 - tp));
    const cb = Math.round(191 * tp + 212 * (1 - tp));

    if (!frozen) {
      // Show current exploratory tour
      drawPath(tour, cr, cg, cb, 0.15 + tp * 0.25, 0.8, false);
    }

    // Best tour (always teal, brighter when frozen)
    drawPath(bestTour, 0, 245, 212, frozen ? 0.9 : 0.5, frozen ? 1.6 : 1.1, frozen);

    // Cities
    ctx.shadowColor = '#00f5d4';
    ctx.shadowBlur  = 3;
    for (let i = 0; i < cities.length; i++) {
      const { x, y } = cities[i];
      ctx.fillStyle = 'rgba(255,255,255,0.85)';
      ctx.beginPath();
      ctx.arc(x, y, 3, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.shadowBlur = 0;

    // HUD throttle
    const now = Date.now();
    if (now - lastHud > 80) {
      lastHud = now;
      document.getElementById('hud-temp').textContent   = (tp * 100).toFixed(1) + '%';
      document.getElementById('hud-dist').textContent   = curDist < Infinity ? curDist.toFixed(0) : '—';
      document.getElementById('hud-best').textContent    = bestDist < Infinity ? bestDist.toFixed(0) : '—';
      document.getElementById('hud-cities').textContent  = cities.length;
      document.getElementById('hud-status').textContent  = frozen ? 'FROZEN' : 'ANNEALING';
    }
  }

  /* ── Main loop ──────────────────────────────────────────────────────── */
  function loop() {
    if (!frozen) {
      const iters = 600 * Math.pow(2, speed - 1);
      for (let i = 0; i < iters; i++) {
        step();
        if (frozen) {
          if (!completedOnce) { localOpt(); completedOnce = true; if (typeof SFX !== 'undefined') SFX.play('complete'); }
          break;
        }
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  /* ── Interaction ────────────────────────────────────────────────────── */
  function addCity(x, y) {
    cities.push({ x, y });
    // Re-insert into current tour at best position
    let bestPos = 0, bestInc = Infinity;
    for (let i = 0; i < tour.length; i++) {
      const j = (i + 1) % tour.length;
      const inc = dist(cities[tour[i]], { x, y }) + dist({ x, y }, cities[tour[j]])
                - dist(cities[tour[i]], cities[tour[j]]);
      if (inc < bestInc) { bestInc = inc; bestPos = i + 1; }
    }
    const newIdx = cities.length - 1;
    tour.splice(bestPos, 0, newIdx);
    curDist = tourDist(tour);
    bestTour = [...tour];
    bestDist = curDist;
    // Reheat
    T_INIT = calibrateTemp();
    T = T_INIT;
    T_MIN = T_INIT * 0.000001;
    frozen = false;
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
      // Rebuild tour indices
      tour = tour.filter(idx => idx !== best).map(idx => idx > best ? idx - 1 : idx);
      if (tour.length >= 3) {
        curDist = tourDist(tour);
        bestTour = [...tour];
        bestDist = curDist;
        T_INIT = calibrateTemp();
        T = T_INIT;
        T_MIN = T_INIT * 0.000001;
        frozen = false;
        completedOnce = false;
      }
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

  /* ── Controls ───────────────────────────────────────────────────────── */
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
    SFX.play('click');
  });

  /* ── Start ──────────────────────────────────────────────────────────── */
  init(60);
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
