<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('particlelife');

$pageName = 'particlelife';
$pageTitle = 'PARTICLE LIFE - PULSE';
$pageDesc = 'Particle Life simulation. Attraction and repulsion rules between species self-organize into clusters, vortices, and creature-like emergent structures.';
$pageCanonical = 'https://pulse.subara3.com/particlelife.php';
$extraStyle = '
#hud{position:fixed;top:1rem;left:1rem;font-size:.7rem;color:rgba(0,245,212,.65);font-family:monospace;pointer-events:none;line-height:1.7;z-index:10}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.35rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.3rem .65rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>particles <span id="cnt" style="color:#fff">0</span></div>
  <div>species <span id="spc" style="color:#fff">4</span></div>
</div>
<div id="controls" class="drawer-controls">
  <button class="btn" id="btn-shuffle">SHUFFLE RULES</button>
  <button class="btn" id="btn-reset">RESET</button>
  <button class="btn" id="btn-add">+ SPECIES</button>
  <button class="btn" id="btn-rm">− SPECIES</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag',  'label' => 'stir'],
  ['type' => 'right', 'label' => 'repel'],
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

  /* ── colour palette: all hues in 165-295 range ─────────────────── */
  const COLORS = [
    [0,   232, 200],   // hsl ~174  teal
    [120,  44, 190],   // hsl ~273  purple
    [28,  168, 224],   // hsl ~197  blue-teal
    [155,  55, 215],   // hsl ~282  violet
    [0,   210, 178],   // hsl ~171  teal-green
    [78,   68, 210],   // hsl ~256  indigo
  ];

  /* ── simulation parameters ──────────────────────────────────────── */
  const MAX_R       = 80;   // force interaction radius (px)
  const R_MIN_FRAC  = 0.3;  // repulsion core as fraction of MAX_R
  const FRICTION    = 0.80;
  const MAX_SPEED   = 4.5;
  const F_SCALE     = 0.12; // velocity increment per unit force per frame
  const PER_SPECIES = 68;   // particles spawned per species
  const MAX_TOTAL   = 520;

  let numSpecies = 4;
  let matrix     = [];  // [numSpecies][numSpecies] ∈ [-1, 1]
  let particles  = [];

  /* ── force function (Particle Life kernel) ──────────────────────── */
  function forceKernel(r, a) {
    // r ∈ [0,1]  a ∈ [-1,1]
    if (r < R_MIN_FRAC) return r / R_MIN_FRAC - 1;   // repulsive core
    if (r < 1.0)        return a * (1 - Math.abs(2 * r - 1 - R_MIN_FRAC) / (1 - R_MIN_FRAC));
    return 0;
  }

  /* ── matrix & particles ─────────────────────────────────────────── */
  function randomMatrix() {
    matrix = [];
    for (let i = 0; i < numSpecies; i++) {
      matrix[i] = [];
      for (let j = 0; j < numSpecies; j++) {
        matrix[i][j] = Math.random() * 2 - 1;
      }
    }
  }

  function makeParticle(x, y, s) {
    return { x, y, vx: (Math.random() - 0.5) * 1.5, vy: (Math.random() - 0.5) * 1.5, s };
  }

  function spawnAll() {
    particles = [];
    for (let s = 0; s < numSpecies; s++) {
      for (let k = 0; k < PER_SPECIES; k++) {
        particles.push(makeParticle(
          20 + Math.random() * (W - 40),
          20 + Math.random() * (H - 40),
          s
        ));
      }
    }
    refreshHUD();
  }

  function refreshHUD() {
    document.getElementById('cnt').textContent = particles.length;
    document.getElementById('spc').textContent = numSpecies;
  }

  /* ── physics update ─────────────────────────────────────────────── */
  function update() {
    const n    = particles.length;
    const maxR2 = MAX_R * MAX_R;
    const hw = W * 0.5, hh = H * 0.5;

    for (let i = 0; i < n; i++) {
      const pi = particles[i];
      let fx = 0, fy = 0;

      for (let j = 0; j < n; j++) {
        if (i === j) continue;
        const pj = particles[j];
        let dx = pj.x - pi.x;
        let dy = pj.y - pi.y;

        // minimal-image convention (wrap)
        if (dx >  hw) dx -= W;
        if (dx < -hw) dx += W;
        if (dy >  hh) dy -= H;
        if (dy < -hh) dy += H;

        const d2 = dx * dx + dy * dy;
        if (d2 === 0 || d2 > maxR2) continue;

        const dist = Math.sqrt(d2);
        const f    = forceKernel(dist / MAX_R, matrix[pi.s][pj.s]);
        fx += f * dx / dist;
        fy += f * dy / dist;
      }

      pi.vx = (pi.vx + fx * F_SCALE) * FRICTION;
      pi.vy = (pi.vy + fy * F_SCALE) * FRICTION;

      const spd = Math.sqrt(pi.vx * pi.vx + pi.vy * pi.vy);
      if (spd > MAX_SPEED) {
        const inv = MAX_SPEED / spd;
        pi.vx *= inv;
        pi.vy *= inv;
      }

      pi.x += pi.vx;
      pi.y += pi.vy;

      if (pi.x <  0) pi.x += W;
      if (pi.x >= W) pi.x -= W;
      if (pi.y <  0) pi.y += H;
      if (pi.y >= H) pi.y -= H;
    }
  }

  /* ── draw ───────────────────────────────────────────────────────── */
  function drawMatrix() {
    const cell = 12, pad = 14;
    const sx = pad;
    const sy = H - pad - numSpecies * cell;

    // dim background
    ctx.fillStyle = 'rgba(10,10,15,0.55)';
    ctx.fillRect(sx - 2, sy - 2, (numSpecies + 1) * cell + 4, numSpecies * cell + 4);

    for (let i = 0; i < numSpecies; i++) {
      // species colour dot on left
      const ci = COLORS[i];
      ctx.fillStyle = `rgb(${ci[0]},${ci[1]},${ci[2]})`;
      ctx.fillRect(sx, sy + i * cell, cell - 2, cell - 2);

      for (let j = 0; j < numSpecies; j++) {
        const v = matrix[i][j];
        let r, g, b;
        if (v >= 0) {
          r = 0; g = Math.round(v * 245); b = Math.round(v * 212); // teal
        } else {
          const f = -v;
          r = Math.round(f * 123); g = Math.round(f * 44); b = Math.round(f * 191); // purple
        }
        ctx.fillStyle = `rgba(${r},${g},${b},${0.25 + Math.abs(v) * 0.75})`;
        ctx.fillRect(sx + cell + j * cell, sy + i * cell, cell - 2, cell - 2);
      }
    }
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.22)';
    ctx.fillRect(0, 0, W, H);

    for (const p of particles) {
      const c = COLORS[p.s % COLORS.length];
      ctx.beginPath();
      ctx.arc(p.x, p.y, 2.5, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${c[0]},${c[1]},${c[2]},0.88)`;
      ctx.fill();
    }

    drawMatrix();
  }

  function loop() {
    update();
    draw();
    requestAnimationFrame(loop);
  }

  randomMatrix();
  spawnAll();
  loop();

  /* ── controls ───────────────────────────────────────────────────── */
  document.getElementById('btn-shuffle').addEventListener('click', () => {
    randomMatrix();
    SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', () => {
    randomMatrix();
    spawnAll();
    SFX.play('click');
  });

  document.getElementById('btn-add').addEventListener('click', () => {
    if (numSpecies >= COLORS.length) return;
    numSpecies++;
    randomMatrix();
    spawnAll();
    SFX.play('spawn');
  });

  document.getElementById('btn-rm').addEventListener('click', () => {
    if (numSpecies <= 2) return;
    numSpecies--;
    randomMatrix();
    spawnAll();
    SFX.play('pop');
  });

  /* ── pointer interaction ─────────────────────────────────────────── */
  let drag = false, rclick = false, mx = 0, my = 0;

  function xy(e) {
    return e.touches
      ? { x: e.touches[0].clientX, y: e.touches[0].clientY }
      : { x: e.clientX, y: e.clientY };
  }

  function addParticles(x, y, n) {
    const add = Math.min(n, MAX_TOTAL - particles.length);
    for (let k = 0; k < add; k++) {
      particles.push(makeParticle(
        x + (Math.random() - 0.5) * 26,
        y + (Math.random() - 0.5) * 26,
        Math.floor(Math.random() * numSpecies)
      ));
    }
    refreshHUD();
  }

  function stirAt(x, y, dvx, dvy) {
    const R2 = 72 * 72;
    for (const p of particles) {
      const dx = p.x - x, dy = p.y - y;
      if (dx * dx + dy * dy < R2) {
        p.vx += dvx * 0.3;
        p.vy += dvy * 0.3;
      }
    }
  }

  function repelAt(x, y) {
    const R = 85;
    for (const p of particles) {
      const dx = p.x - x, dy = p.y - y;
      const d = Math.sqrt(dx * dx + dy * dy);
      if (d > 0 && d < R) {
        const f = (1 - d / R) * 5;
        p.vx += (dx / d) * f;
        p.vy += (dy / d) * f;
      }
    }
  }

  canvas.addEventListener('mousedown', e => {
    e.preventDefault();
    const { x, y } = xy(e);
    mx = x; my = y; drag = true; rclick = e.button === 2;
    if (!rclick) { addParticles(x, y, 6); SFX.play('spawn', 80); }
    else         { repelAt(x, y); }
  });

  canvas.addEventListener('mousemove', e => {
    if (!drag) return;
    const { x, y } = xy(e);
    if (rclick) { repelAt(x, y); }
    else        { stirAt(x, y, x - mx, y - my); SFX.play('move', 55); }
    mx = x; my = y;
  });

  canvas.addEventListener('mouseup',      () => { drag = false; });
  canvas.addEventListener('contextmenu',  e  => e.preventDefault());

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const { x, y } = xy(e);
    mx = x; my = y; drag = true; rclick = false;
    addParticles(x, y, 6);
    SFX.play('spawn', 80);
  }, { passive: false });

  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const { x, y } = xy(e);
    stirAt(x, y, x - mx, y - my);
    mx = x; my = y;
    SFX.play('move', 60);
  }, { passive: false });

  canvas.addEventListener('touchend', () => { drag = false; });
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
