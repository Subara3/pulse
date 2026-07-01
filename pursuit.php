<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pursuit');

$pageName = 'pursuit';
$pageTitle = 'PURSUIT - PULSE';
$pageDesc = "N点の追跡多角形。各頂点が次の頂点を等速で追い続け、対数螺旋が創発する。";
$pageCanonical = 'https://pulse.subara3.com/pursuit.php';
$extraStyle = '
body { background: #060810; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .vc { color: #00f5d4; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
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
  <div>CHAINS <span class="vc" id="hud-chains">0</span></div>
  <div>NODES <span class="vc" id="hud-nodes">0</span></div>
</div>

<div id="controls" class="ctrl drawer-controls">
  <button id="btn-clear">CLEAR</button>
  <label>SPEED <span id="lbl-spd">1.0</span>
    <input type="range" id="sl-spd" min="0.3" max="3.0" step="0.1" value="1.0">
  </label>
  <label>TRAIL <span id="lbl-trail">L</span>
    <input type="range" id="sl-trail" min="1" max="5" step="1" value="2">
  </label>
</div>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<div class="title">PURSUIT</div>

<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'paint'],
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
  let chains = [];
  let spdMul = 1.0;
  let fadeAlpha = 0.008;
  let mx, my;           // mouse position
  let mouseActive = false;
  let hueCounter = 170;
  const MAX_CHAINS = 15;
  const CHAIN_LEN = 60;

  // --- Chain: a sequence of particles, each pursuing the one ahead ---
  class Chain {
    constructor(x, y, hue) {
      this.hue = hue;
      this.len = CHAIN_LEN;
      this.headSpeed = 4 + Math.random() * 3;
      this.stiffness = 0.06 + Math.random() * 0.04;
      this.pts = [];
      for (let i = 0; i < this.len; i++) {
        this.pts.push({ x: x + (Math.random() - 0.5) * 4, y: y + (Math.random() - 0.5) * 4 });
      }
      // Autonomous wandering state
      this.angle = Math.random() * Math.PI * 2;
      this.turnRate = (Math.random() - 0.5) * 0.06;
      this.wanderSpeed = 2 + Math.random() * 2;
    }

    update() {
      const head = this.pts[0];
      const speed = this.headSpeed * spdMul;
      const stiff = this.stiffness * spdMul;

      if (mouseActive) {
        // Head chases mouse
        const dx = mx - head.x, dy = my - head.y;
        const d = Math.hypot(dx, dy);
        if (d > 2) {
          const s = Math.min(speed, d * 0.15);
          head.x += dx / d * s;
          head.y += dy / d * s;
        }
      } else {
        // Autonomous wandering
        this.angle += this.turnRate + (Math.random() - 0.5) * 0.04;
        head.x += Math.cos(this.angle) * this.wanderSpeed * spdMul;
        head.y += Math.sin(this.angle) * this.wanderSpeed * spdMul;

        // Soft edge bounce
        const margin = 60;
        if (head.x < margin) this.angle += 0.08;
        else if (head.x > W - margin) this.angle -= 0.08;
        if (head.y < margin) this.angle = Math.abs(this.angle) < Math.PI / 2 ? this.angle : -this.angle + Math.PI;
        else if (head.y > H - margin) this.angle = Math.abs(this.angle) < Math.PI / 2 ? this.angle : -this.angle - Math.PI;

        head.x = Math.max(0, Math.min(W, head.x));
        head.y = Math.max(0, Math.min(H, head.y));
      }

      // Each node pursues the one ahead — THIS is the pursuit
      for (let i = 1; i < this.len; i++) {
        const prev = this.pts[i - 1];
        const curr = this.pts[i];
        curr.x += (prev.x - curr.x) * stiff;
        curr.y += (prev.y - curr.y) * stiff;
      }
    }

    draw() {
      const pts = this.pts;
      const len = this.len;

      // Draw chain as segments: thick & bright at head, thin & dim at tail
      // Batch into groups of 4 for efficiency
      const GROUP = 4;
      for (let i = 0; i < len - 1; i += GROUP) {
        const end = Math.min(i + GROUP, len - 1);
        const t = 1 - i / len;
        const alpha = t * t * 0.85;
        const lw = t * 3.5 + 0.5;
        const hue = (this.hue + i * 1.5) % 360;

        ctx.beginPath();
        ctx.moveTo(pts[i].x, pts[i].y);
        for (let j = i + 1; j <= end; j++) {
          ctx.lineTo(pts[j].x, pts[j].y);
        }
        ctx.strokeStyle = `hsla(${hue},80%,${45 + t * 30}%,${alpha})`;
        ctx.lineWidth = lw;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke();
      }

      // Head glow
      const h = pts[0];
      const g = ctx.createRadialGradient(h.x, h.y, 0, h.x, h.y, 14);
      g.addColorStop(0, `hsla(${this.hue},90%,80%,0.6)`);
      g.addColorStop(1, `hsla(${this.hue},90%,80%,0)`);
      ctx.beginPath();
      ctx.arc(h.x, h.y, 14, 0, Math.PI * 2);
      ctx.fillStyle = g;
      ctx.fill();

      // Head dot
      ctx.beginPath();
      ctx.arc(h.x, h.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = `hsla(${this.hue},90%,90%,0.95)`;
      ctx.fill();
    }
  }

  function spawnChain(x, y) {
    if (chains.length >= MAX_CHAINS) return;
    const hue = hueCounter;
    hueCounter = (hueCounter + 47) % 360;
    chains.push(new Chain(x, y, hue));
    typeof SFX !== 'undefined' && SFX.play('spawn');
  }

  function clearAll() {
    chains = [];
    hueCounter = 170;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
    typeof SFX !== 'undefined' && SFX.play('click');
  }

  function init() {
    chains = [];
    hueCounter = 170;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);

    // Spawn initial chains spread across the screen
    const spots = [
      [0.5, 0.5], [0.25, 0.3], [0.75, 0.7],
      [0.3, 0.7], [0.7, 0.3], [0.5, 0.2],
    ];
    for (const [rx, ry] of spots) {
      spawnChain(W * rx, H * ry);
    }
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
  }

  function updateHUD() {
    document.getElementById('hud-chains').textContent = chains.length;
    document.getElementById('hud-nodes').textContent = chains.length * CHAIN_LEN;
  }

  let frame = 0;
  function loop() {
    requestAnimationFrame(loop);

    // Background fade — the trail effect
    ctx.fillStyle = `rgba(6,8,16,${fadeAlpha})`;
    ctx.fillRect(0, 0, W, H);

    for (const c of chains) {
      c.update();
      c.draw();
    }

    if (++frame % 15 === 0) updateHUD();
  }

  // --- Events ---
  window.addEventListener('resize', resize);

  canvas.addEventListener('mouseenter', () => { mouseActive = true; });
  canvas.addEventListener('mouseleave', () => { mouseActive = false; });
  canvas.addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
    mouseActive = true;
  });
  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    mx = e.clientX; my = e.clientY;
    spawnChain(e.clientX, e.clientY);
  });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    clearAll();
  });

  // Touch
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.touches[0];
    mx = t.clientX; my = t.clientY;
    mouseActive = true;
    spawnChain(t.clientX, t.clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    mx = e.touches[0].clientX;
    my = e.touches[0].clientY;
  }, { passive: false });
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    mouseActive = false;
  }, { passive: false });

  // Controls
  document.getElementById('sl-spd').addEventListener('input', e => {
    spdMul = parseFloat(e.target.value);
    document.getElementById('lbl-spd').textContent = spdMul.toFixed(1);
    typeof SFX !== 'undefined' && SFX.play('click');
  });

  const TRAIL_RATES = [0.004, 0.008, 0.018, 0.04, 0.08];
  const TRAIL_LABELS = ['XL', 'L', 'M', 'S', 'XS'];
  document.getElementById('sl-trail').addEventListener('input', e => {
    const v = parseInt(e.target.value) - 1;
    fadeAlpha = TRAIL_RATES[v];
    document.getElementById('lbl-trail').textContent = TRAIL_LABELS[v];
    typeof SFX !== 'undefined' && SFX.play('click');
  });

  document.getElementById('btn-clear').addEventListener('click', clearAll);

  resize();
  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
