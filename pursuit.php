<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pursuit');

$pageName = 'pursuit';
$pageTitle = 'PURSUIT - PULSE';
$pageDesc = 'N点の追跡多角形。各頂点が次の頂点を等速で追い続け、対数螺旋が創発する。';
$pageCanonical = 'https://pulse.subara3.com/pursuit.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
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
  <div>TOTAL <span class="vc" id="hud-total">0</span></div>
</div>

<div class="ctrl">
  <button id="btn-reset">RESET</button>
  <button id="btn-auto" class="active">AUTO</button>
  <label>N <span id="lbl-n">5</span>
    <input type="range" id="sl-n" min="3" max="12" step="1" value="5">
  </label>
  <label>SPEED <span id="lbl-spd">1.5</span>
    <input type="range" id="sl-spd" min="0.3" max="4.0" step="0.1" value="1.5">
  </label>
  <label>TRAIL <span id="lbl-trail">M</span>
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
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  let W, H;
  let pursuers = [];
  let nSides = 5;
  let spd = 1.5;
  let fadeRate = 0.025;
  let autoMode = true;
  let spawnTimer = null;
  let colorIdx = 0;
  let totalSpawned = 0;
  const MAX_ACTIVE = 8;

  const NAMES = {3:'TRIANGLE',4:'QUADRILATERAL',5:'PENTAGON',6:'HEXAGON',
                  7:'HEPTAGON',8:'OCTAGON',9:'NONAGON',10:'DECAGON',
                  11:'HENDECAGON',12:'DODECAGON'};

  // teal-purple palette (hue 165–290)
  const PALETTE = [
    [0, 245, 212],   // teal
    [123, 44, 191],  // purple
    [0, 190, 235],   // blue-teal
    [155, 55, 215],  // light purple
    [0, 220, 188],   // green-teal
    [100, 50, 200],  // indigo
  ];

  function rgba(c, a) { return `rgba(${c[0]},${c[1]},${c[2]},${a})`; }

  class Pursuer {
    constructor(cx, cy, r, n, ci) {
      this.n = n;
      this.col = PALETTE[ci % PALETTE.length];
      this.alive = true;
      this.age = 0;
      this.pts = [];
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
        if (d < spd * 2.5) {
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
      const fade = Math.min(1, this.age / 25) * 0.88;
      const n = this.n;
      ctx.beginPath();
      ctx.moveTo(this.pts[0].x, this.pts[0].y);
      for (let i = 1; i < n; i++) ctx.lineTo(this.pts[i].x, this.pts[i].y);
      ctx.closePath();
      ctx.strokeStyle = rgba(this.col, fade);
      ctx.lineWidth = 2;
      ctx.stroke();

      // vertex glow
      const dotFade = Math.min(1, this.age / 10);
      for (const p of this.pts) {
        const glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 8);
        glow.addColorStop(0, rgba(this.col, dotFade * 0.5));
        glow.addColorStop(1, rgba(this.col, 0));
        ctx.beginPath();
        ctx.arc(p.x, p.y, 8, 0, Math.PI * 2);
        ctx.fillStyle = glow;
        ctx.fill();
      }
      // vertex dots
      ctx.fillStyle = rgba(this.col, dotFade);
      for (const p of this.pts) {
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function spawn(cx, cy, n) {
    if (pursuers.filter(p => p.alive).length >= MAX_ACTIVE) return;
    const r = Math.min(W, H) * 0.27;
    const ci = colorIdx++;
    pursuers.push(new Pursuer(cx, cy, r, n || nSides, ci));
    totalSpawned++;
    SFX.play('spawn');
    updateHUD();
    // prune old dead pursuers
    if (pursuers.length > MAX_ACTIVE * 4) {
      pursuers = pursuers.filter(p => p.alive);
    }
  }

  function spawnRandom() {
    const pad = Math.min(W, H) * 0.3;
    const cx = pad / 2 + Math.random() * (W - pad);
    const cy = pad / 2 + Math.random() * (H - pad);
    const n = 3 + Math.floor(Math.random() * 9); // 3..11
    spawn(cx, cy, n);
  }

  function clearAll() {
    pursuers.forEach(p => { p.alive = false; });
    SFX.play('click');
    setTimeout(() => {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, W, H);
    }, 1500);
  }

  function init() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    pursuers = [];
    colorIdx = 0;
    totalSpawned = 0;
    spawnTimer = null;
    spawn(W / 2, H / 2, 5);
    setTimeout(() => spawn(W * 0.3, H * 0.35, 7), 300);
    setTimeout(() => spawn(W * 0.7, H * 0.65, 4), 600);
    setTimeout(() => spawn(W * 0.5, H * 0.3, 6), 900);
    setTimeout(() => spawn(W * 0.4, H * 0.7, 8), 1200);
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
  }

  function updateHUD() {
    const alive = pursuers.filter(p => p.alive).length;
    document.getElementById('hud-active').textContent = alive;
    document.getElementById('hud-total').textContent = totalSpawned;
    document.getElementById('hud-shape').textContent = NAMES[nSides] || nSides + '-GON';
  }

  let frame = 0;
  function loop() {
    requestAnimationFrame(loop);
    frame++;

    // Fade background
    ctx.fillStyle = `rgba(10,10,15,${fadeRate})`;
    ctx.fillRect(0, 0, W, H);

    // Update + draw
    for (const p of pursuers) { p.update(); p.draw(); }

    // Auto-spawn when all dead
    if (autoMode) {
      const alive = pursuers.filter(p => p.alive).length;
      if (alive < 2 && !spawnTimer) {
        spawnTimer = setTimeout(() => {
          spawnRandom();
          spawnTimer = null;
        }, 600);
      }
    }

    if (frame % 15 === 0) updateHUD();
  }

  // Events
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

  const TRAIL_RATES = [0.015, 0.03, 0.05, 0.08, 0.14];
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
    fadeRate = TRAIL_RATES[v];
    lblTrail.textContent = TRAIL_LABELS[v];
    SFX.play('click');
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', () => {
    init();
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
