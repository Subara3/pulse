<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pursuit');

$pageName = 'pursuit';
$pageTitle = 'PURSUIT - PULSE';
$pageDesc = 'N点の追跡多角形。各頂点が次の頂点を等速で追い続け、対数螺旋が創発する。';
$pageCanonical = 'https://pulse.subara3.com/pursuit.php';
$extraStyle = '
body { background: #060810; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .vc { color: #00f5d4; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.ctrl button.active { background: rgba(0,245,212,0.25); border-color: #00f5d4; }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 90px; accent-color: #00f5d4; }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .hud { font-size: 0.6rem; }
  .ctrl input[type=range] { width: 70px; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  <div><span class="vc" id="hud-shape">PENTAGON</span></div>
  <div>ACTIVE <span class="vc" id="hud-active">0</span></div>
  <div>SPIRALS <span class="vc" id="hud-total">0</span></div>
</div>

<div class="ctrl">
  <button id="btn-clear">CLEAR</button>
  <button id="btn-auto" class="active">AUTO</button>
  <label>N <span id="lbl-n">5</span>
    <input type="range" id="sl-n" min="3" max="12" step="1" value="5">
  </label>
  <label>SPEED <span id="lbl-spd">1.5</span>
    <input type="range" id="sl-spd" min="0.3" max="4.0" step="0.1" value="1.5">
  </label>
  <label>TRAIL <span id="lbl-trail">L</span>
    <input type="range" id="sl-trail" min="1" max="5" step="1" value="2">
  </label>
</div>

<div class="title">PURSUIT</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
'use strict';
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  let W, H;
  let sets = [];
  let nSides = 5;
  let spd = 1.5;
  let fadeAlpha = 0.012;
  let autoMode = true;
  let autoTimer = null;
  let totalSpawned = 0;
  const MAX_ACTIVE = 10;

  const NAMES = {3:'TRIANGLE',4:'SQUARE',5:'PENTAGON',6:'HEXAGON',
                  7:'HEPTAGON',8:'OCTAGON',9:'NONAGON',10:'DECAGON',
                  11:'HENDECAGON',12:'DODECAGON'};

  // Rich palette — each set gets a hue offset
  let hueBase = 0;
  const HUE_STEP = 47;

  // --- Pursuit set ---
  // No trail storage — background fade creates the spiral naturally
  class PursuitSet {
    constructor(cx, cy, n, r) {
      this.n = n;
      this.hue = hueBase;
      hueBase = (hueBase + HUE_STEP) % 360;
      this.alive = true;
      this.age = 0;
      this.pts = [];
      this.r0 = r;
      for (let i = 0; i < n; i++) {
        const a = (2 * Math.PI * i / n) - Math.PI / 2;
        this.pts.push({ x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) });
      }
    }

    update() {
      if (!this.alive) return;
      const n = this.n;
      const next = [];
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const t = this.pts[(i + 1) % n];
        const dx = t.x - p.x, dy = t.y - p.y;
        const d = Math.hypot(dx, dy);
        if (d < spd * 1.5) {
          this.alive = false;
          SFX.play('collide', 60);
          return;
        }
        next.push({ x: p.x + dx / d * spd, y: p.y + dy / d * spd });
      }
      this.pts = next;
      this.age++;
    }

    draw() {
      if (!this.alive) return;
      const n = this.n;
      const fade = Math.min(1, this.age / 15);

      // Draw polygon edges — each edge gets a slightly different hue
      ctx.lineWidth = 2.5;
      ctx.lineCap = 'round';
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const q = this.pts[(i + 1) % n];
        const h = (this.hue + i * 25) % 360;
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(q.x, q.y);
        ctx.strokeStyle = `hsla(${h},85%,65%,${fade * 0.9})`;
        ctx.stroke();
      }

      // Vertex glow + dots
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const h = (this.hue + i * 25) % 360;

        // Soft glow
        const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 12);
        g.addColorStop(0, `hsla(${h},90%,70%,${fade * 0.45})`);
        g.addColorStop(1, `hsla(${h},90%,70%,0)`);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 12, 0, Math.PI * 2);
        ctx.fillStyle = g;
        ctx.fill();

        // Bright dot
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = `hsla(${h},90%,80%,${fade})`;
        ctx.fill();
      }
    }
  }

  function spawn(cx, cy, n) {
    const aliveCount = sets.filter(s => s.alive).length;
    if (aliveCount >= MAX_ACTIVE) return;
    const minDim = Math.min(W, H);
    const r = minDim * (0.12 + Math.random() * 0.18);
    sets.push(new PursuitSet(cx, cy, n || nSides, r));
    totalSpawned++;
    SFX.play('spawn');
    // Prune dead sets
    if (sets.length > MAX_ACTIVE * 3) {
      sets = sets.filter(s => s.alive);
    }
  }

  function spawnRandom() {
    const pad = Math.min(W, H) * 0.2;
    const cx = pad + Math.random() * (W - pad * 2);
    const cy = pad + Math.random() * (H - pad * 2);
    const n = 3 + Math.floor(Math.random() * 10);
    spawn(cx, cy, n);
  }

  function clearAll() {
    sets.forEach(s => { s.alive = false; });
    sets = [];
    SFX.play('click');
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function init() {
    sets = [];
    hueBase = 170; // start from teal
    totalSpawned = 0;
    autoTimer = null;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);

    // Staggered initial spawns for a nice opening
    spawn(W * 0.5, H * 0.5, 5);
    setTimeout(() => spawn(W * 0.3, H * 0.35, 7), 200);
    setTimeout(() => spawn(W * 0.7, H * 0.6, 4), 500);
    setTimeout(() => spawn(W * 0.5, H * 0.75, 6), 800);
    setTimeout(() => spawn(W * 0.25, H * 0.65, 9), 1100);
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function updateHUD() {
    const alive = sets.filter(s => s.alive).length;
    document.getElementById('hud-active').textContent = alive;
    document.getElementById('hud-total').textContent = totalSpawned;
    document.getElementById('hud-shape').textContent = NAMES[nSides] || nSides + '-GON';
  }

  let frame = 0;
  function loop() {
    requestAnimationFrame(loop);
    frame++;

    // Background fade — creates the spiral trail effect
    ctx.fillStyle = `rgba(6,8,16,${fadeAlpha})`;
    ctx.fillRect(0, 0, W, H);

    // Update + draw all sets
    for (const s of sets) {
      s.update();
      s.draw();
    }

    // Auto-spawn: keep multiple sets running
    if (autoMode) {
      const alive = sets.filter(s => s.alive).length;
      if (alive < 3 && !autoTimer) {
        autoTimer = setTimeout(() => {
          spawnRandom();
          autoTimer = null;
        }, 400);
      }
    }

    if (frame % 10 === 0) updateHUD();
  }

  // --- Events ---
  window.addEventListener('resize', resize);

  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    spawn(e.clientX, e.clientY);
  });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    clearAll();
  });

  // Touch
  let touchPt = null;
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    touchPt = { x: e.touches[0].clientX, y: e.touches[0].clientY };
  }, { passive: false });
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    if (!touchPt) return;
    const dx = e.changedTouches[0].clientX - touchPt.x;
    const dy = e.changedTouches[0].clientY - touchPt.y;
    if (Math.hypot(dx, dy) < 12) spawn(touchPt.x, touchPt.y);
    touchPt = null;
  }, { passive: false });

  // Controls
  const slN = document.getElementById('sl-n');
  const slSpd = document.getElementById('sl-spd');
  const slTrail = document.getElementById('sl-trail');
  const lblN = document.getElementById('lbl-n');
  const lblSpd = document.getElementById('lbl-spd');
  const lblTrail = document.getElementById('lbl-trail');

  const TRAIL_RATES = [0.006, 0.012, 0.025, 0.05, 0.10];
  const TRAIL_LABELS = ['XL', 'L', 'M', 'S', 'XS'];

  slN.addEventListener('input', () => {
    nSides = parseInt(slN.value);
    lblN.textContent = nSides;
    updateHUD();
    SFX.play('click');
  });
  slSpd.addEventListener('input', () => {
    spd = parseFloat(slSpd.value);
    lblSpd.textContent = spd.toFixed(1);
    SFX.play('click');
  });
  slTrail.addEventListener('input', () => {
    const v = parseInt(slTrail.value) - 1;
    fadeAlpha = TRAIL_RATES[v];
    lblTrail.textContent = TRAIL_LABELS[v];
    SFX.play('click');
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  document.getElementById('btn-clear').addEventListener('click', () => {
    clearAll();
    SFX.play('click');
  });

  resize();
  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
