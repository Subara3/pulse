<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('predprey');

$pageName = 'predprey';
$pageTitle = 'PREDPREY - PULSE';
$pageDesc = '捕食と被食。魚の群れをサメが追い回す粒子シミュレーション。Lotka-Volterra振動が創発する。';
$pageCanonical = 'https://pulse.subara3.com/predprey.php';
$extraStyle = '
body { background: #060810; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud {position:fixed;top:3.5rem;right:1rem;font-family: "JetBrains Mono", monospace;font-size: 0.7rem;color: rgba(255,255,255,0.4);text-align: left;line-height: 1.8;z-index: 10;pointer-events: none;}
.hud .vf { color: #00f5d4; }
.hud .vs { color: #c455ff; }
.graph-box { position: fixed; bottom: 4rem; left: 1.5rem; z-index: 10; pointer-events: none; }
.graph-box canvas { border: 1px solid rgba(255,255,255,0.06); border-radius: 4px; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 100px; accent-color: #00f5d4; }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .hud {font-size: 0.6rem;}
  .ctrl input[type=range] { width: 80px; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .graph-box { bottom: 3.5rem; left: 1rem; }
  .graph-box canvas { width: 150px; height: 50px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>FISH <span class="vf" id="hFish">0</span></div>
  <div>SHARK <span class="vs" id="hShark">0</span></div>
  <div>GEN <span class="vf" id="hGen">0</span></div>
</div>
<div class="graph-box"><canvas id="graph" width="220" height="65"></canvas></div>
<div class="ctrl">
  <label>SPEED <input type="range" id="sl-speed" min="0.5" max="3" step="0.1" value="1"></label>
  <button id="btn-reset">RESET</button>
</div>
<div class="title">PREDPREY</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'fish'],
  ['type' => 'right', 'label' => 'shark'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
'use strict';
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const gCanvas = document.getElementById('graph');
  const gCtx = gCanvas.getContext('2d');

  let W, H;
  let fish = [];
  let sharks = [];
  let gen = 0;
  let simSpeed = 1;
  const history = [];
  const HIST_LEN = 300;

  // Spatial hash for fast neighbor lookups
  const GRID_SIZE = 40;
  let gridCols, gridRows;
  let grid;

  // --- Config ---
  const FISH_MAX = 600;
  const SHARK_MAX = 80;
  const FISH_INIT = 200;
  const SHARK_INIT = 15;
  const FISH_SPEED = 2.8;
  const SHARK_SPEED = 3.6;
  const FISH_COHESION = 0.008;
  const FISH_ALIGN = 0.05;
  const FISH_SEPARATION = 12;
  const FISH_SEP_FORCE = 0.4;
  const FISH_FLEE_DIST = 120;
  const FISH_FLEE_FORCE = 0.6;
  const FISH_BREED_AGE = 200;
  const SHARK_HUNT_DIST = 180;
  const SHARK_HUNT_FORCE = 0.15;
  const SHARK_EAT_DIST = 10;
  const SHARK_ENERGY_INIT = 500;
  const SHARK_ENERGY_EAT = 300;
  const SHARK_ENERGY_MAX = 800;
  const SHARK_BREED_ENERGY = 600;

  // --- Entity ---
  function makeFish(x, y) {
    const a = Math.random() * Math.PI * 2;
    return {
      x, y,
      vx: Math.cos(a) * FISH_SPEED,
      vy: Math.sin(a) * FISH_SPEED,
      age: (Math.random() * FISH_BREED_AGE) | 0,
      size: 2.5 + Math.random() * 1.5,
    };
  }

  function makeShark(x, y) {
    const a = Math.random() * Math.PI * 2;
    return {
      x, y,
      vx: Math.cos(a) * SHARK_SPEED * 0.5,
      vy: Math.sin(a) * SHARK_SPEED * 0.5,
      energy: SHARK_ENERGY_INIT,
      size: 6 + Math.random() * 2,
      huntAngle: a,
    };
  }

  // --- Spatial hash ---
  function buildGrid() {
    gridCols = Math.ceil(W / GRID_SIZE);
    gridRows = Math.ceil(H / GRID_SIZE);
    grid = new Array(gridCols * gridRows);
    for (let i = 0; i < grid.length; i++) grid[i] = [];
  }

  function clearGrid() {
    for (let i = 0; i < grid.length; i++) grid[i].length = 0;
  }

  function insertGrid(entity) {
    const gx = Math.min(gridCols - 1, Math.max(0, (entity.x / GRID_SIZE) | 0));
    const gy = Math.min(gridRows - 1, Math.max(0, (entity.y / GRID_SIZE) | 0));
    grid[gy * gridCols + gx].push(entity);
  }

  function queryGrid(x, y, radius, list) {
    const gxMin = Math.max(0, ((x - radius) / GRID_SIZE) | 0);
    const gxMax = Math.min(gridCols - 1, ((x + radius) / GRID_SIZE) | 0);
    const gyMin = Math.max(0, ((y - radius) / GRID_SIZE) | 0);
    const gyMax = Math.min(gridRows - 1, ((y + radius) / GRID_SIZE) | 0);
    const r2 = radius * radius;
    const result = [];
    for (let gy = gyMin; gy <= gyMax; gy++) {
      for (let gx = gxMin; gx <= gxMax; gx++) {
        const cell = grid[gy * gridCols + gx];
        for (let i = 0; i < cell.length; i++) {
          const e = cell[i];
          const dx = e.x - x, dy = e.y - y;
          if (dx * dx + dy * dy < r2) result.push(e);
        }
      }
    }
    return result;
  }

  // --- Init ---
  function init() {
    fish = [];
    sharks = [];
    gen = 0;
    history.length = 0;
    for (let i = 0; i < FISH_INIT; i++) {
      fish.push(makeFish(Math.random() * W, Math.random() * H));
    }
    for (let i = 0; i < SHARK_INIT; i++) {
      sharks.push(makeShark(Math.random() * W, Math.random() * H));
    }
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    buildGrid();
  }

  // --- Simulation step ---
  function step() {
    clearGrid();
    // Insert fish into spatial hash
    for (let i = 0; i < fish.length; i++) insertGrid(fish[i]);

    // Build shark grid separately for fish flee
    const sharkList = sharks;

    // --- Update fish ---
    const newFish = [];
    for (let i = fish.length - 1; i >= 0; i--) {
      const f = fish[i];
      f.age++;

      // Flocking: cohesion, alignment, separation
      const neighbors = queryGrid(f.x, f.y, 50, fish);
      let cx = 0, cy = 0, ax = 0, ay = 0, sx = 0, sy = 0, nc = 0;
      for (let j = 0; j < neighbors.length; j++) {
        const n = neighbors[j];
        if (n === f) continue;
        nc++;
        cx += n.x; cy += n.y;
        ax += n.vx; ay += n.vy;
        const dx = f.x - n.x, dy = f.y - n.y;
        const d = Math.sqrt(dx * dx + dy * dy);
        if (d < FISH_SEPARATION && d > 0.1) {
          sx += dx / d / d * FISH_SEP_FORCE;
          sy += dy / d / d * FISH_SEP_FORCE;
        }
      }
      if (nc > 0) {
        // Cohesion
        f.vx += (cx / nc - f.x) * FISH_COHESION;
        f.vy += (cy / nc - f.y) * FISH_COHESION;
        // Alignment
        f.vx += (ax / nc - f.vx) * FISH_ALIGN;
        f.vy += (ay / nc - f.vy) * FISH_ALIGN;
        // Separation
        f.vx += sx;
        f.vy += sy;
      }

      // Flee from sharks
      for (let s = 0; s < sharkList.length; s++) {
        const sh = sharkList[s];
        const dx = f.x - sh.x, dy = f.y - sh.y;
        const d = Math.sqrt(dx * dx + dy * dy);
        if (d < FISH_FLEE_DIST && d > 0.1) {
          const force = FISH_FLEE_FORCE * (1 - d / FISH_FLEE_DIST);
          f.vx += dx / d * force;
          f.vy += dy / d * force;
        }
      }

      // Random jitter
      f.vx += (Math.random() - 0.5) * 0.2;
      f.vy += (Math.random() - 0.5) * 0.2;

      // Clamp speed
      const sp = Math.sqrt(f.vx * f.vx + f.vy * f.vy);
      if (sp > FISH_SPEED) {
        f.vx = f.vx / sp * FISH_SPEED;
        f.vy = f.vy / sp * FISH_SPEED;
      }

      f.x += f.vx * simSpeed;
      f.y += f.vy * simSpeed;

      // Wrap edges
      if (f.x < 0) f.x += W; else if (f.x >= W) f.x -= W;
      if (f.y < 0) f.y += H; else if (f.y >= H) f.y -= H;

      // Breed
      if (f.age >= FISH_BREED_AGE && fish.length + newFish.length < FISH_MAX) {
        f.age = 0;
        newFish.push(makeFish(f.x + (Math.random() - 0.5) * 10, f.y + (Math.random() - 0.5) * 10));
      }
    }
    for (let i = 0; i < newFish.length; i++) fish.push(newFish[i]);

    // --- Update sharks ---
    const newSharks = [];
    for (let i = sharks.length - 1; i >= 0; i--) {
      const s = sharks[i];
      s.energy -= simSpeed;

      if (s.energy <= 0) {
        sharks.splice(i, 1);
        SFX.play('pop', 300);
        continue;
      }

      // Hunt nearest fish
      let nearestDist = Infinity, nearestFish = null;
      for (let j = 0; j < fish.length; j++) {
        let dx = fish[j].x - s.x, dy = fish[j].y - s.y;
        // Wrap-aware distance
        if (dx > W / 2) dx -= W; else if (dx < -W / 2) dx += W;
        if (dy > H / 2) dy -= H; else if (dy < -H / 2) dy += H;
        const d = dx * dx + dy * dy;
        if (d < nearestDist) { nearestDist = d; nearestFish = { dx, dy, idx: j }; }
      }

      if (nearestFish && nearestDist < SHARK_HUNT_DIST * SHARK_HUNT_DIST) {
        const d = Math.sqrt(nearestDist);
        s.vx += nearestFish.dx / d * SHARK_HUNT_FORCE;
        s.vy += nearestFish.dy / d * SHARK_HUNT_FORCE;
        s.huntAngle = Math.atan2(nearestFish.dy, nearestFish.dx);

        // Eat fish
        if (d < SHARK_EAT_DIST) {
          fish.splice(nearestFish.idx, 1);
          s.energy = Math.min(SHARK_ENERGY_MAX, s.energy + SHARK_ENERGY_EAT);
          SFX.play('collide', 120);
        }
      } else {
        // Wander
        s.huntAngle += (Math.random() - 0.5) * 0.3;
        s.vx += Math.cos(s.huntAngle) * 0.05;
        s.vy += Math.sin(s.huntAngle) * 0.05;
      }

      // Shark separation
      for (let j = 0; j < sharks.length; j++) {
        if (j === i) continue;
        const dx = s.x - sharks[j].x, dy = s.y - sharks[j].y;
        const d = Math.sqrt(dx * dx + dy * dy);
        if (d < 40 && d > 0.1) {
          s.vx += dx / d * 0.1;
          s.vy += dy / d * 0.1;
        }
      }

      // Clamp speed
      const sp = Math.sqrt(s.vx * s.vx + s.vy * s.vy);
      if (sp > SHARK_SPEED) {
        s.vx = s.vx / sp * SHARK_SPEED;
        s.vy = s.vy / sp * SHARK_SPEED;
      }

      s.x += s.vx * simSpeed;
      s.y += s.vy * simSpeed;

      // Wrap
      if (s.x < 0) s.x += W; else if (s.x >= W) s.x -= W;
      if (s.y < 0) s.y += H; else if (s.y >= H) s.y -= H;

      // Breed
      if (s.energy >= SHARK_BREED_ENERGY && sharks.length + newSharks.length < SHARK_MAX) {
        s.energy = (s.energy / 2) | 0;
        newSharks.push(makeShark(s.x + (Math.random() - 0.5) * 20, s.y + (Math.random() - 0.5) * 20));
      }
    }
    for (let i = 0; i < newSharks.length; i++) sharks.push(newSharks[i]);

    // Auto-balance: if fish extinct, respawn some
    if (fish.length === 0 && sharks.length > 0) {
      for (let i = 0; i < 40; i++) fish.push(makeFish(Math.random() * W, Math.random() * H));
    }
    // If sharks extinct, respawn a few
    if (sharks.length === 0 && fish.length > 20) {
      for (let i = 0; i < 3; i++) sharks.push(makeShark(Math.random() * W, Math.random() * H));
    }

    gen++;
    history.push({ f: fish.length, s: sharks.length });
    if (history.length > HIST_LEN) history.shift();
  }

  // --- Render ---
  function render() {
    // Fade background
    ctx.fillStyle = 'rgba(6,8,16,0.25)';
    ctx.fillRect(0, 0, W, H);

    // Draw fish — directional triangles
    for (let i = 0; i < fish.length; i++) {
      const f = fish[i];
      const angle = Math.atan2(f.vy, f.vx);
      const sz = f.size;

      // Glow
      ctx.beginPath();
      ctx.arc(f.x, f.y, sz * 3, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,0.04)';
      ctx.fill();

      // Body (triangle pointing in direction of movement)
      ctx.save();
      ctx.translate(f.x, f.y);
      ctx.rotate(angle);
      ctx.beginPath();
      ctx.moveTo(sz * 1.8, 0);
      ctx.lineTo(-sz, -sz * 0.7);
      ctx.lineTo(-sz * 0.4, 0);
      ctx.lineTo(-sz, sz * 0.7);
      ctx.closePath();
      ctx.fillStyle = 'rgba(0,245,212,0.85)';
      ctx.fill();
      ctx.restore();
    }

    // Draw sharks — larger, menacing shapes
    for (let i = 0; i < sharks.length; i++) {
      const s = sharks[i];
      const angle = Math.atan2(s.vy, s.vx);
      const sz = s.size;
      const energyRatio = s.energy / SHARK_ENERGY_MAX;

      // Glow — bigger and more intense when well-fed
      ctx.beginPath();
      ctx.arc(s.x, s.y, sz * 4, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(196,85,255,${0.06 + energyRatio * 0.06})`;
      ctx.fill();

      // Body
      ctx.save();
      ctx.translate(s.x, s.y);
      ctx.rotate(angle);
      ctx.beginPath();
      ctx.moveTo(sz * 2, 0);
      ctx.lineTo(-sz * 0.5, -sz * 1.1);
      ctx.lineTo(-sz * 1.2, -sz * 0.3);
      ctx.lineTo(-sz * 1.6, -sz * 0.8);
      ctx.lineTo(-sz * 1.2, 0);
      ctx.lineTo(-sz * 1.6, sz * 0.8);
      ctx.lineTo(-sz * 1.2, sz * 0.3);
      ctx.lineTo(-sz * 0.5, sz * 1.1);
      ctx.closePath();
      const alpha = 0.6 + energyRatio * 0.35;
      ctx.fillStyle = `rgba(196,85,255,${alpha})`;
      ctx.fill();
      ctx.restore();

      // Eye
      const ex = s.x + Math.cos(angle) * sz * 0.8;
      const ey = s.y + Math.sin(angle) * sz * 0.8;
      ctx.beginPath();
      ctx.arc(ex, ey, 1.5, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(255,255,255,${0.5 + energyRatio * 0.4})`;
      ctx.fill();
    }
  }

  // --- Population graph ---
  function drawGraph() {
    const gw = gCanvas.width, gh = gCanvas.height;
    gCtx.fillStyle = 'rgba(6,8,16,0.85)';
    gCtx.fillRect(0, 0, gw, gh);

    if (history.length < 3) return;
    const len = history.length;
    const maxVal = Math.max(50, ...history.map(h => Math.max(h.f, h.s)));

    // Fish line
    gCtx.beginPath();
    gCtx.strokeStyle = 'rgba(0,245,212,0.7)';
    gCtx.lineWidth = 1.5;
    for (let i = 0; i < len; i++) {
      const x = (i / (len - 1)) * gw;
      const y = gh - (history[i].f / maxVal) * (gh - 4) - 2;
      i === 0 ? gCtx.moveTo(x, y) : gCtx.lineTo(x, y);
    }
    gCtx.stroke();

    // Shark line
    gCtx.beginPath();
    gCtx.strokeStyle = 'rgba(196,85,255,0.7)';
    gCtx.lineWidth = 1.5;
    for (let i = 0; i < len; i++) {
      const x = (i / (len - 1)) * gw;
      const y = gh - (history[i].s / maxVal) * (gh - 4) - 2;
      i === 0 ? gCtx.moveTo(x, y) : gCtx.lineTo(x, y);
    }
    gCtx.stroke();
  }

  // --- HUD ---
  let hudTick = 0;
  function updateHUD() {
    document.getElementById('hFish').textContent = fish.length;
    document.getElementById('hShark').textContent = sharks.length;
    document.getElementById('hGen').textContent = gen;
  }

  // --- Main loop ---
  function loop() {
    requestAnimationFrame(loop);
    step();
    render();
    if (++hudTick % 8 === 0) {
      updateHUD();
      drawGraph();
    }
  }

  // --- Interaction ---
  function addFishAt(x, y) {
    for (let i = 0; i < 15; i++) {
      if (fish.length >= FISH_MAX) break;
      fish.push(makeFish(x + (Math.random() - 0.5) * 40, y + (Math.random() - 0.5) * 40));
    }
    SFX.play('spawn');
  }

  function addSharkAt(x, y) {
    if (sharks.length >= SHARK_MAX) return;
    sharks.push(makeShark(x, y));
    SFX.play('pop');
  }

  // Mouse
  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    addFishAt(e.clientX, e.clientY);
  });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    addSharkAt(e.clientX, e.clientY);
  });

  // Touch
  let touchPt = null, touchMoved = false;
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    touchPt = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    touchMoved = false;
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    touchMoved = true;
  }, { passive: false });
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    if (!touchMoved && touchPt) addFishAt(touchPt.x, touchPt.y);
    touchPt = null;
  }, { passive: false });

  // Controls
  document.getElementById('sl-speed').addEventListener('input', e => {
    simSpeed = parseFloat(e.target.value);
  });
  document.getElementById('btn-reset').addEventListener('click', () => {
    init();
    ctx.fillStyle = '#060810';
    ctx.fillRect(0, 0, W, H);
    SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
