<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('antcolony');

$pageName = 'antcolony';
$pageTitle = 'ANTCOLONY - PULSE';
$pageDesc = 'アリコロニー最適化。フェロモントレイルの強化によって最短経路が自然発生する。';
$pageCanonical = 'https://pulse.subara3.com/antcolony.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .v { color: #00f5d4; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 100px; accent-color: #00f5d4; }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .hud { font-size: 0.6rem; }
  .ctrl input[type=range] { width: 80px; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>ANTS: <span class="v" id="hAnts">200</span></div>
  <div>CARRYING: <span class="v" id="hCarrying">0</span></div>
  <div>FOOD: <span class="v" id="hFood">3</span></div>
</div>
<div class="ctrl">
  <label>SPEED <input type="range" id="speedSlider" min="1" max="8" step="1" value="2"></label>
  <button id="btnAddFood">+FOOD</button>
  <button id="btnReset">RESET</button>
</div>
<div class="title">ANTCOLONY</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'food'],
  ['type' => 'drag',  'label' => 'wall'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  // Simulation constants
  const CELL      = 4;     // pheromone grid resolution (px)
  const NUM_ANTS  = 200;
  const EVAP      = 0.997; // per-frame pheromone decay
  const SPEED     = 2.0;   // pixels per step
  const SENSE_D   = 28;    // pheromone sense distance (px)
  const SENSE_A   = 0.5;   // sense cone half-angle (rad)
  const TURN      = 0.35;  // turn strength
  const RAND      = 0.42;  // random wobble
  const FOOD_R2   = 196;   // food pickup radius^2 (14px)
  const NEST_R2   = 324;   // nest return radius^2 (18px)
  const MAX_AGE   = 2800;  // search age before reset
  const DEP_FOOD  = 6.0;   // returning ant deposit per step
  const DEP_HOME  = 1.5;   // searching ant deposit per step

  let W, H, cols, rows;
  let foodPhero, homePhero, wallGrid;
  let nest = {x: 0, y: 0};
  let foods = [];
  let ants = [];
  let simSpeed = 2;
  let imgData, pxData, px32;
  let hudTick = 0;

  // Interaction state
  let mouseDown = false, rightDown = false;
  let dragStartX = 0, dragStartY = 0, dragged = false;
  let touchStartPos = null, touchMoved = false;

  // -------------------------
  // Init
  // -------------------------
  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cols = Math.ceil(W / CELL);
    rows = Math.ceil(H / CELL);
    imgData = ctx.createImageData(W, H);
    pxData  = imgData.data;
    px32    = new Uint32Array(pxData.buffer);
    // Pre-fill alpha channel
    for (let i = 3; i < pxData.length; i += 4) pxData[i] = 255;
  }

  function initGrids() {
    foodPhero = new Float32Array(cols * rows);
    homePhero = new Float32Array(cols * rows);
    wallGrid  = new Uint8Array(cols * rows);
  }

  function initSim() {
    nest = {x: W / 2, y: H / 2};
    foods = [];
    const r = Math.min(W, H) * 0.28;
    for (let i = 0; i < 3; i++) {
      const a = (i / 3) * Math.PI * 2 + 0.4;
      foods.push({x: nest.x + Math.cos(a) * r, y: nest.y + Math.sin(a) * r});
    }
    ants = [];
    for (let i = 0; i < NUM_ANTS; i++) ants.push(newAnt());
    initGrids();
    document.getElementById('hAnts').textContent = NUM_ANTS;
    document.getElementById('hFood').textContent = foods.length;
  }

  function newAnt() {
    return {
      x: nest.x + (Math.random() - 0.5) * 12,
      y: nest.y + (Math.random() - 0.5) * 12,
      angle: Math.random() * Math.PI * 2,
      state: 'search', // 'search' | 'return'
      age: 0
    };
  }

  // -------------------------
  // Grid helpers
  // -------------------------
  function gIdx(x, y) {
    const cx = (x / CELL) | 0;
    const cy = (y / CELL) | 0;
    if (cx < 0 || cx >= cols || cy < 0 || cy >= rows) return -1;
    return cy * cols + cx;
  }

  function deposit(grid, x, y, amount) {
    const i = gIdx(x, y);
    if (i >= 0 && !wallGrid[i]) grid[i] = Math.min(255, grid[i] + amount);
  }

  function sense(grid, x, y, angle, offset) {
    const sx = x + Math.cos(angle + offset) * SENSE_D;
    const sy = y + Math.sin(angle + offset) * SENSE_D;
    const i = gIdx(sx, sy);
    if (i < 0 || wallGrid[i]) return 0;
    return grid[i];
  }

  // -------------------------
  // Ant update
  // -------------------------
  function updateAnt(ant) {
    const grid = (ant.state === 'search') ? foodPhero : homePhero;

    // 3-sensor steering
    const fwd = sense(grid, ant.x, ant.y, ant.angle, 0);
    const lft = sense(grid, ant.x, ant.y, ant.angle, -SENSE_A);
    const rgt = sense(grid, ant.x, ant.y, ant.angle,  SENSE_A);

    if (fwd >= lft && fwd >= rgt) {
      ant.angle += (Math.random() - 0.5) * RAND;
    } else if (lft > rgt) {
      ant.angle -= TURN + (Math.random() - 0.5) * 0.1;
    } else if (rgt > lft) {
      ant.angle += TURN + (Math.random() - 0.5) * 0.1;
    } else {
      ant.angle += (Math.random() - 0.5) * Math.PI;
    }

    let nx = ant.x + Math.cos(ant.angle) * SPEED;
    let ny = ant.y + Math.sin(ant.angle) * SPEED;

    // Boundary bounce
    if (nx < 2)     { nx = 2;     ant.angle = Math.PI - ant.angle; }
    else if (nx > W - 2) { nx = W - 2; ant.angle = Math.PI - ant.angle; }
    if (ny < 2)     { ny = 2;     ant.angle = -ant.angle; }
    else if (ny > H - 2) { ny = H - 2; ant.angle = -ant.angle; }

    // Wall collision
    const wi = gIdx(nx, ny);
    if (wi >= 0 && wallGrid[wi]) {
      ant.angle += Math.PI + (Math.random() - 0.5) * 1.8;
      return;
    }

    ant.x = nx;
    ant.y = ny;
    ant.age++;

    if (ant.state === 'search') {
      deposit(homePhero, ant.x, ant.y, DEP_HOME);
      // Check food proximity
      for (const f of foods) {
        const dx = ant.x - f.x, dy = ant.y - f.y;
        if (dx * dx + dy * dy < FOOD_R2) {
          ant.state = 'return';
          ant.age = 0;
          ant.angle += Math.PI;
          SFX.play('tick', 120);
          return;
        }
      }
      // Lost ant: warp back to nest
      if (ant.age > MAX_AGE) {
        ant.x = nest.x + (Math.random() - 0.5) * 12;
        ant.y = nest.y + (Math.random() - 0.5) * 12;
        ant.angle = Math.random() * Math.PI * 2;
        ant.age = 0;
      }
    } else {
      deposit(foodPhero, ant.x, ant.y, DEP_FOOD);
      const dx = ant.x - nest.x, dy = ant.y - nest.y;
      if (dx * dx + dy * dy < NEST_R2) {
        ant.state = 'search';
        ant.age = 0;
        ant.angle = Math.random() * Math.PI * 2;
        SFX.play('ripple', 180);
      }
    }
  }

  function evaporate() {
    for (let i = 0; i < foodPhero.length; i++) {
      foodPhero[i] *= EVAP;
      homePhero[i] *= EVAP;
    }
  }

  // -------------------------
  // Render
  // -------------------------
  const BG32 = (0xFF << 24 | 15 << 16 | 10 << 8 | 10) >>> 0; // #0A0A0F ABGR LE

  function render() {
    // Fill background (fast typed array fill)
    px32.fill(BG32);

    // Pheromone pass (write teal food trails, dim purple home trails)
    for (let cy = 0; cy < rows; cy++) {
      const py = cy * CELL;
      if (py >= H) break;
      for (let cx = 0; cx < cols; cx++) {
        const px = cx * CELL;
        if (px >= W) break;
        const gi = cy * cols + cx;

        if (wallGrid[gi]) {
          // Wall: dark grey
          const wallC = (0xFF << 24 | 50 << 16 | 42 << 8 | 38) >>> 0;
          for (let dy = 0; dy < CELL && py + dy < H; dy++) {
            for (let dx = 0; dx < CELL && px + dx < W; dx++) {
              px32[(py + dy) * W + (px + dx)] = wallC;
            }
          }
          continue;
        }

        const fp = foodPhero[gi];
        const hp = homePhero[gi];
        if (fp < 0.8 && hp < 0.8) continue;

        // Teal for food trail, hint of purple for home trail
        const fS = Math.min(1, fp / 70);
        const hS = Math.min(1, hp / 45);
        const R = (hS * 90) | 0;
        const G = (fS * 220 + hS * 30) | 0;
        const B = (fS * 190 + hS * 170) | 0;
        const color = (0xFF000000 | ((B & 0xFF) << 16) | ((G & 0xFF) << 8) | (R & 0xFF)) >>> 0;

        for (let dy = 0; dy < CELL && py + dy < H; dy++) {
          for (let dx = 0; dx < CELL && px + dx < W; dx++) {
            px32[(py + dy) * W + (px + dx)] = color;
          }
        }
      }
    }

    ctx.putImageData(imgData, 0, 0);

    // Nest glow (purple)
    const ng = ctx.createRadialGradient(nest.x, nest.y, 3, nest.x, nest.y, 24);
    ng.addColorStop(0, 'rgba(160,80,255,0.95)');
    ng.addColorStop(0.4, 'rgba(123,44,191,0.6)');
    ng.addColorStop(1, 'rgba(123,44,191,0)');
    ctx.fillStyle = ng;
    ctx.beginPath();
    ctx.arc(nest.x, nest.y, 24, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(nest.x, nest.y, 5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(200,130,255,0.95)';
    ctx.fill();

    // Food sources (teal)
    for (const f of foods) {
      const fg = ctx.createRadialGradient(f.x, f.y, 2, f.x, f.y, 16);
      fg.addColorStop(0, 'rgba(0,245,212,0.9)');
      fg.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = fg;
      ctx.beginPath();
      ctx.arc(f.x, f.y, 16, 0, Math.PI * 2);
      ctx.fill();
      ctx.beginPath();
      ctx.arc(f.x, f.y, 5, 0, Math.PI * 2);
      ctx.fillStyle = '#ffffff';
      ctx.fill();
    }

    // Ants
    for (const ant of ants) {
      ctx.beginPath();
      ctx.arc(ant.x, ant.y, 1.5, 0, Math.PI * 2);
      ctx.fillStyle = (ant.state === 'return') ? '#00f5d4' : 'rgba(210,210,240,0.5)';
      ctx.fill();
    }

    // HUD update (throttled)
    if (++hudTick % 20 === 0) {
      let carrying = 0;
      for (const a of ants) if (a.state === 'return') carrying++;
      document.getElementById('hCarrying').textContent = carrying;
    }
  }

  // -------------------------
  // Main loop
  // -------------------------
  function loop() {
    for (let s = 0; s < simSpeed; s++) {
      evaporate();
      for (const ant of ants) updateAnt(ant);
    }
    render();
    requestAnimationFrame(loop);
  }

  // -------------------------
  // Interaction: wall drawing
  // -------------------------
  function drawWall(x, y, erase) {
    const br = 3;
    const cx0 = (x / CELL) | 0;
    const cy0 = (y / CELL) | 0;
    for (let dy = -br; dy <= br; dy++) {
      for (let dx = -br; dx <= br; dx++) {
        if (dx * dx + dy * dy > br * br) continue;
        const cx = cx0 + dx, cy = cy0 + dy;
        if (cx < 0 || cx >= cols || cy < 0 || cy >= rows) continue;
        const i = cy * cols + cx;
        wallGrid[i] = erase ? 0 : 1;
        if (!erase) { foodPhero[i] = 0; homePhero[i] = 0; }
      }
    }
    if (!erase) SFX.play('tick', 60);
  }

  function placeFood(x, y) {
    foods.push({x, y});
    document.getElementById('hFood').textContent = foods.length;
    SFX.play('spawn');
  }

  function eraseAt(x, y) {
    // Erase walls
    drawWall(x, y, true);
    // Erase food sources near cursor
    const prev = foods.length;
    foods = foods.filter(f => {
      const dx = f.x - x, dy = f.y - y;
      return dx * dx + dy * dy > 600;
    });
    if (foods.length !== prev) {
      document.getElementById('hFood').textContent = foods.length;
      SFX.play('pop');
    }
  }

  function canvasPos(e) {
    const r = canvas.getBoundingClientRect();
    const scaleX = W / r.width;
    const scaleY = H / r.height;
    return {x: (e.clientX - r.left) * scaleX, y: (e.clientY - r.top) * scaleY};
  }

  // Mouse events
  canvas.addEventListener('mousedown', e => {
    e.preventDefault();
    const {x, y} = canvasPos(e);
    if (e.button === 2) {
      rightDown = true;
      eraseAt(x, y);
    } else {
      mouseDown = true;
      dragged = false;
      dragStartX = x;
      dragStartY = y;
    }
  });

  canvas.addEventListener('mousemove', e => {
    const {x, y} = canvasPos(e);
    if (mouseDown) {
      const dx = x - dragStartX, dy = y - dragStartY;
      if (!dragged && dx * dx + dy * dy > 16) dragged = true;
      if (dragged) drawWall(x, y, false);
    }
    if (rightDown) eraseAt(x, y);
  });

  canvas.addEventListener('mouseup', e => {
    if (e.button === 0 && mouseDown && !dragged) {
      const {x, y} = canvasPos(e);
      placeFood(x, y);
    }
    mouseDown = false;
    rightDown = false;
    dragged = false;
  });

  canvas.addEventListener('contextmenu', e => e.preventDefault());

  // Touch events
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.touches[0];
    const {x, y} = canvasPos(t);
    touchStartPos = {x, y};
    touchMoved = false;
  }, {passive: false});

  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    touchMoved = true;
    const t = e.touches[0];
    const {x, y} = canvasPos(t);
    drawWall(x, y, false);
  }, {passive: false});

  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    if (!touchMoved && touchStartPos) {
      placeFood(touchStartPos.x, touchStartPos.y);
    }
    touchStartPos = null;
    touchMoved = false;
  }, {passive: false});

  // Controls
  document.getElementById('speedSlider').addEventListener('input', e => {
    simSpeed = +e.target.value;
  });

  document.getElementById('btnReset').addEventListener('click', () => {
    initSim();
    SFX.play('click');
  });

  document.getElementById('btnAddFood').addEventListener('click', () => {
    const a = Math.random() * Math.PI * 2;
    const r = Math.min(W, H) * (0.18 + Math.random() * 0.22);
    placeFood(nest.x + Math.cos(a) * r, nest.y + Math.sin(a) * r);
  });

  // Startup
  resize();
  initSim();
  window.addEventListener('resize', () => { resize(); initSim(); });
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
