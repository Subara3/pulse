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
  <div>SETS <span class="vc" id="hud-sets">0</span></div>
  <div>CYCLES <span class="vc" id="hud-cycles">0</span></div>
</div>

<div class="ctrl">
  <button id="btn-clear">CLEAR</button>
  <label>N <span id="lbl-n">5</span>
    <input type="range" id="sl-n" min="3" max="12" step="1" value="5">
  </label>
  <label>SPEED <span id="lbl-spd">1.8</span>
    <input type="range" id="sl-spd" min="0.5" max="5.0" step="0.1" value="1.8">
  </label>
  <label>TRAIL <span id="lbl-trail">L</span>
    <input type="range" id="sl-trail" min="1" max="5" step="1" value="2">
  </label>
</div>

<div class="title">PURSUIT</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag',  'label' => 'warp'],
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
  let spd = 1.8;
  let fadeAlpha = 0.01;
  let totalCycles = 0;
  let mx = -9999, my = -9999;
  let mouseDown = false;
  const MAX_SETS = 12;

  const NAMES = {3:'TRI',4:'QUAD',5:'PENT',6:'HEX',7:'HEPT',8:'OCT',
                  9:'NON',10:'DEC',11:'HENDEC',12:'DODEC'};

  let hueBase = 170;

  // --- Pursuit set: perpetual, never dies ---
  class PursuitSet {
    constructor(cx, cy, n, r) {
      this.n = n;
      this.cx = cx;
      this.cy = cy;
      this.r = r;
      this.hue = hueBase;
      hueBase = (hueBase + 53) % 360;
      this.cycles = 0;
      this.phase = 0;       // rotation offset, increases each cycle
      this.pts = [];
      this.expand(0);
    }

    // Reset vertices to polygon around center
    expand(rotOffset) {
      this.pts = [];
      for (let i = 0; i < this.n; i++) {
        const a = (2 * Math.PI * i / this.n) - Math.PI / 2 + rotOffset;
        this.pts.push({
          x: this.cx + this.r * Math.cos(a),
          y: this.cy + this.r * Math.sin(a),
        });
      }
    }

    update() {
      const n = this.n;
      const next = [];

      // Check convergence
      let minDist = Infinity;
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const t = this.pts[(i + 1) % n];
        const d = Math.hypot(t.x - p.x, t.y - p.y);
        if (d < minDist) minDist = d;
      }

      if (minDist < spd * 2) {
        // Converged → rebirth: expand to new polygon, rotated
        this.cycles++;
        totalCycles++;
        this.phase += 0.4 + Math.random() * 0.3;
        this.hue = (this.hue + 30 + Math.random() * 20) % 360;
        this.expand(this.phase);
        SFX.play('collide', 40);
        return;
      }

      // Pursuit step: each vertex chases the next
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const t = this.pts[(i + 1) % n];
        const dx = t.x - p.x, dy = t.y - p.y;
        const d = Math.hypot(dx, dy);
        let nx = p.x + dx / d * spd;
        let ny = p.y + dy / d * spd;

        // Mouse warp: if mouse is held, attract nearest vertices
        if (mouseDown) {
          const mdx = mx - p.x, mdy = my - p.y;
          const md = Math.hypot(mdx, mdy);
          if (md < 250 && md > 1) {
            const pull = 0.8 * (1 - md / 250);
            nx += mdx / md * pull;
            ny += mdy / md * pull;
          }
        }

        next.push({ x: nx, y: ny });
      }
      this.pts = next;
    }

    draw() {
      const n = this.n;

      // Draw edges
      ctx.lineWidth = 2.2;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';

      // Single path for polygon — efficient
      ctx.beginPath();
      ctx.moveTo(this.pts[0].x, this.pts[0].y);
      for (let i = 1; i < n; i++) ctx.lineTo(this.pts[i].x, this.pts[i].y);
      ctx.closePath();
      ctx.strokeStyle = `hsla(${this.hue},80%,65%,0.85)`;
      ctx.stroke();

      // Vertex dots with glow
      for (let i = 0; i < n; i++) {
        const p = this.pts[i];
        const h = (this.hue + i * 20) % 360;

        // Glow
        const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 10);
        g.addColorStop(0, `hsla(${h},90%,75%,0.5)`);
        g.addColorStop(1, `hsla(${h},90%,75%,0)`);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 10, 0, Math.PI * 2);
        ctx.fillStyle = g;
        ctx.fill();

        // Dot
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = `hsla(${h},90%,85%,0.95)`;
        ctx.fill();
      }
    }
  }

  function spawn(cx, cy, n) {
    if (sets.length >= MAX_SETS) return;
    const minDim = Math.min(W, H);
    const r = minDim * (0.08 + Math.random() * 0.14);
    sets.push(new PursuitSet(cx, cy, n || nSides, r));
    SFX.play('spawn');
  }

  function clearAll() {
    sets = [];
    totalCycles = 0;
    hueBase = 170;
    SFX.play('click');
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function init() {
    sets = [];
    totalCycles = 0;
    hueBase = 170;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);

    // Start with several sets at different positions
    const cx = W / 2, cy = H / 2;
    const spread = Math.min(W, H) * 0.25;
    spawn(cx, cy, 5);
    spawn(cx - spread * 0.7, cy - spread * 0.4, 3);
    spawn(cx + spread * 0.7, cy + spread * 0.3, 7);
    spawn(cx - spread * 0.3, cy + spread * 0.6, 4);
    spawn(cx + spread * 0.5, cy - spread * 0.5, 6);
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function updateHUD() {
    document.getElementById('hud-sets').textContent = sets.length;
    document.getElementById('hud-cycles').textContent = totalCycles;
  }

  let frame = 0;
  function loop() {
    requestAnimationFrame(loop);
    frame++;

    // Background fade → spiral trails
    ctx.fillStyle = `rgba(6,8,16,${fadeAlpha})`;
    ctx.fillRect(0, 0, W, H);

    for (const s of sets) {
      s.update();
      s.draw();
    }

    if (frame % 10 === 0) updateHUD();
  }

  // --- Events ---
  window.addEventListener('resize', resize);

  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    mouseDown = true;
    mx = e.clientX; my = e.clientY;
    spawn(e.clientX, e.clientY);
  });
  canvas.addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
  });
  canvas.addEventListener('mouseup', () => { mouseDown = false; });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    clearAll();
  });

  // Touch
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.touches[0];
    mx = t.clientX; my = t.clientY;
    mouseDown = true;
    spawn(t.clientX, t.clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    mx = e.touches[0].clientX;
    my = e.touches[0].clientY;
  }, { passive: false });
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    mouseDown = false;
  }, { passive: false });

  // Controls
  const slN = document.getElementById('sl-n');
  const slSpd = document.getElementById('sl-spd');
  const slTrail = document.getElementById('sl-trail');

  const TRAIL_RATES = [0.005, 0.01, 0.02, 0.04, 0.08];
  const TRAIL_LABELS = ['XL', 'L', 'M', 'S', 'XS'];

  slN.addEventListener('input', () => {
    nSides = parseInt(slN.value);
    document.getElementById('lbl-n').textContent = nSides;
    SFX.play('click');
  });
  slSpd.addEventListener('input', () => {
    spd = parseFloat(slSpd.value);
    document.getElementById('lbl-spd').textContent = spd.toFixed(1);
    SFX.play('click');
  });
  slTrail.addEventListener('input', () => {
    const v = parseInt(slTrail.value) - 1;
    fadeAlpha = TRAIL_RATES[v];
    document.getElementById('lbl-trail').textContent = TRAIL_LABELS[v];
    SFX.play('click');
  });

  document.getElementById('btn-clear').addEventListener('click', () => {
    clearAll();
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
