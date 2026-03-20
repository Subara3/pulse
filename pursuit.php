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
  <button id="btn-reset">CLEAR</button>
  <button id="btn-auto" class="active">AUTO</button>
  <label>N <span id="lbl-n">5</span>
    <input type="range" id="sl-n" min="3" max="12" step="1" value="5">
  </label>
  <label>SPEED <span id="lbl-spd">1.2</span>
    <input type="range" id="sl-spd" min="0.3" max="3.0" step="0.1" value="1.2">
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
  let spirals = [];       // completed spiral trails
  let active = [];         // currently animating pursuit sets
  let nSides = 5;
  let spd = 1.2;
  let autoMode = true;
  let autoTimer = null;
  let totalSpawned = 0;
  const MAX_ACTIVE = 6;
  const MAX_SPIRALS = 30;

  const NAMES = {3:'TRIANGLE',4:'SQUARE',5:'PENTAGON',6:'HEXAGON',
                  7:'HEPTAGON',8:'OCTAGON',9:'NONAGON',10:'DECAGON',
                  11:'HENDECAGON',12:'DODECAGON'};

  // HSL-based palette — each pursuit set gets a base hue
  const HUES = [172, 270, 200, 310, 145, 240, 330, 190, 285, 160];
  let hueIdx = 0;

  function hsl(h, s, l, a) { return `hsla(${h},${s}%,${l}%,${a})`; }

  // --- Pursuit set ---
  class PursuitSet {
    constructor(cx, cy, n, r) {
      this.n = n;
      this.hue = HUES[hueIdx++ % HUES.length];
      this.alive = true;
      this.age = 0;
      this.pts = [];
      // Trail: array of arrays, one per vertex
      this.trails = [];
      for (let i = 0; i < n; i++) {
        const a = (2 * Math.PI * i / n) - Math.PI / 2;
        const pt = { x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) };
        this.pts.push(pt);
        this.trails.push([{ x: pt.x, y: pt.y }]);
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
        if (d < spd * 2) {
          this.alive = false;
          SFX.play('collide', 60);
          // Save completed spiral to spirals list
          spirals.push({
            trails: this.trails,
            hue: this.hue,
            n: this.n,
            birth: performance.now(),
            maxAge: 12000 + this.trails[0].length * 8,
          });
          if (spirals.length > MAX_SPIRALS) spirals.shift();
          return;
        }
        const nx = p.x + dx / d * spd;
        const ny = p.y + dy / d * spd;
        next.push({ x: nx, y: ny });
        this.trails[i].push({ x: nx, y: ny });
      }
      this.pts = next;
      this.age++;
    }

    draw() {
      if (!this.alive) return;
      const n = this.n;
      const fade = Math.min(1, this.age / 20);

      // Draw trail paths with gradient
      for (let v = 0; v < n; v++) {
        const trail = this.trails[v];
        const len = trail.length;
        if (len < 2) continue;

        // Draw trail as series of segments with fading alpha
        const step = Math.max(1, (len / 400) | 0);
        ctx.lineWidth = 1.5;
        for (let i = step; i < len; i += step) {
          const t = i / len;
          const alpha = t * 0.5 * fade;
          const lightness = 55 + t * 20;
          ctx.beginPath();
          ctx.moveTo(trail[i - step].x, trail[i - step].y);
          ctx.lineTo(trail[i].x, trail[i].y);
          ctx.strokeStyle = hsl(this.hue + v * (360 / n) * 0.3, 80, lightness, alpha);
          ctx.stroke();
        }
      }

      // Draw current polygon
      ctx.beginPath();
      ctx.moveTo(this.pts[0].x, this.pts[0].y);
      for (let i = 1; i < n; i++) ctx.lineTo(this.pts[i].x, this.pts[i].y);
      ctx.closePath();
      ctx.strokeStyle = hsl(this.hue, 85, 70, fade * 0.9);
      ctx.lineWidth = 2;
      ctx.stroke();

      // Vertex glow + dots
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const vh = this.hue + i * (360 / n) * 0.3;

        // Glow
        const glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 10);
        glow.addColorStop(0, hsl(vh, 90, 70, fade * 0.5));
        glow.addColorStop(1, hsl(vh, 90, 70, 0));
        ctx.beginPath();
        ctx.arc(p.x, p.y, 10, 0, Math.PI * 2);
        ctx.fillStyle = glow;
        ctx.fill();

        // Dot
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = hsl(vh, 90, 80, fade);
        ctx.fill();
      }
    }
  }

  // --- Draw completed spirals (fading out) ---
  function drawSpirals() {
    const now = performance.now();
    for (let si = spirals.length - 1; si >= 0; si--) {
      const sp = spirals[si];
      const elapsed = now - sp.birth;
      if (elapsed > sp.maxAge) {
        spirals.splice(si, 1);
        continue;
      }
      const globalFade = 1 - elapsed / sp.maxAge;

      for (let v = 0; v < sp.n; v++) {
        const trail = sp.trails[v];
        const len = trail.length;
        if (len < 2) continue;

        const step = Math.max(1, (len / 500) | 0);
        ctx.lineWidth = 1.2;
        for (let i = step; i < len; i += step) {
          const t = i / len;
          const alpha = t * 0.35 * globalFade;
          if (alpha < 0.005) continue;
          const lightness = 50 + t * 25;
          ctx.beginPath();
          ctx.moveTo(trail[i - step].x, trail[i - step].y);
          ctx.lineTo(trail[i].x, trail[i].y);
          ctx.strokeStyle = hsl(sp.hue + v * (360 / sp.n) * 0.3, 75, lightness, alpha);
          ctx.stroke();
        }
      }
    }
  }

  function spawn(cx, cy, n) {
    const aliveCount = active.filter(a => a.alive).length;
    if (aliveCount >= MAX_ACTIVE) return;
    const r = Math.min(W, H) * (0.15 + Math.random() * 0.15);
    active.push(new PursuitSet(cx, cy, n || nSides, r));
    totalSpawned++;
    SFX.play('spawn');
    updateHUD();
    // Prune dead
    if (active.length > MAX_ACTIVE * 3) {
      active = active.filter(a => a.alive);
    }
  }

  function spawnRandom() {
    const pad = Math.min(W, H) * 0.25;
    const cx = pad + Math.random() * (W - pad * 2);
    const cy = pad + Math.random() * (H - pad * 2);
    const n = 3 + Math.floor(Math.random() * 9);
    spawn(cx, cy, n);
  }

  function clearAll() {
    active.forEach(a => { a.alive = false; });
    spirals.length = 0;
    SFX.play('click');
  }

  function init() {
    active = [];
    spirals = [];
    hueIdx = 0;
    totalSpawned = 0;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);

    // Staggered initial spawns
    spawn(W / 2, H / 2, 5);
    setTimeout(() => spawn(W * 0.3, H * 0.4, 7), 400);
    setTimeout(() => spawn(W * 0.7, H * 0.6, 4), 800);
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function updateHUD() {
    const alive = active.filter(a => a.alive).length;
    document.getElementById('hud-active').textContent = alive;
    document.getElementById('hud-total').textContent = totalSpawned;
    document.getElementById('hud-shape').textContent = NAMES[nSides] || nSides + '-GON';
  }

  let frame = 0;
  function loop() {
    requestAnimationFrame(loop);
    frame++;

    // Subtle background fade — slow, so spirals persist
    ctx.fillStyle = 'rgba(6,8,16,0.03)';
    ctx.fillRect(0, 0, W, H);

    // Draw persisted spirals
    drawSpirals();

    // Update + draw active sets
    for (const a of active) { a.update(); a.draw(); }

    // Auto-spawn
    if (autoMode) {
      const alive = active.filter(a => a.alive).length;
      if (alive < 2 && !autoTimer) {
        autoTimer = setTimeout(() => {
          spawnRandom();
          autoTimer = null;
        }, 800);
      }
    }

    if (frame % 12 === 0) updateHUD();
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
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
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
  const lblN = document.getElementById('lbl-n');
  const lblSpd = document.getElementById('lbl-spd');

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

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', () => {
    clearAll();
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
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
