<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('wireworld');

$pageName = 'wireworld';
$pageTitle = 'WIREWORLD - PULSE';
$pageDesc = 'Wireworld cellular automaton. Draw conductive circuits, place electron heads, and watch signal pulses travel.';
$pageCanonical = 'https://pulse.subara3.com/wireworld.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .v { color: #00f5d4; }
.hud .vp { color: rgba(123,44,191,0.9); }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.ctrl button.active { background: rgba(0,245,212,0.25); border-color: #00f5d4; color: #fff; }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 100px; accent-color: #00f5d4; }
@media (max-width: 768px) {
  .hud { font-size: 0.6rem; }
  .ctrl input[type=range] { width: 80px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>STEP <span class="v" id="stepDisplay">0</span></div>
  <div>e<sup>-</sup> <span class="v" id="electronDisplay">0</span></div>
  <div>MODE <span class="v" id="modeDisplay">WIRE</span></div>
</div>
<div id="controls" class="ctrl drawer-controls">
  <button id="btnRun">PAUSE</button>
  <button id="btnStep">STEP</button>
  <button id="btnMode">MODE: WIRE</button>
  <label>SPEED <input type="range" id="speedSlider" min="1" max="10" step="1" value="5"></label>
  <button id="btnClear">CLEAR</button>
  <button id="btnReset">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'draw wire'],
  ['type' => 'click', 'label' => 'signal (E)'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  const EMPTY = 0, WIRE = 1, HEAD = 2, TAIL = 3;
  const COLS = 80, ROWS = 50;

  let W, H, cellW, cellH;
  let grid, next;
  let running    = true;
  let stepCount  = 0;
  let mode       = 'WIRE'; // 'WIRE' | 'ELECTRON'
  let lastCell   = null;
  let stepInterval = 80; // ms between simulation steps

  // ── resize ──────────────────────────────────────────────────
  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cellW = W / COLS;
    cellH = H / ROWS;
  }

  // ── grid helpers ────────────────────────────────────────────
  function mkGrid() {
    return Array.from({length: ROWS}, () => new Uint8Array(COLS));
  }
  function inB(x, y) { return x >= 0 && x < COLS && y >= 0 && y < ROWS; }
  function sw(g, x, y, s) { if (inB(x, y)) g[y][x] = s; }
  function hw(g, x1, x2, y) {
    for (let x = Math.min(x1,x2); x <= Math.max(x1,x2); x++) sw(g, x, y, WIRE);
  }
  function vw(g, x, y1, y2) {
    for (let y = Math.min(y1,y2); y <= Math.max(y1,y2); y++) sw(g, x, y, WIRE);
  }
  function rect(g, x1, y1, x2, y2) {
    hw(g, x1, x2, y1); hw(g, x1, x2, y2);
    vw(g, x1, y1, y2); vw(g, x2, y1, y2);
  }

  // ── initial state ────────────────────────────────────────────
  // Electrons placed as HEAD + TAIL (HEAD = current, TAIL = previous position).
  // Direction of travel: TAIL is one step BEHIND HEAD on the wire path.
  function createInitial() {
    const g = mkGrid();

    // ── Clock A: tiny loop (x=3..11, y=3..8), perimeter 24 ──
    rect(g, 3, 3, 11, 8);
    sw(g, 5,  3, HEAD); sw(g, 4,  3, TAIL); // top row → right

    // ── Clock B: medium loop (x=25..41, y=3..11), perimeter 44 ──
    rect(g, 25, 3, 41, 11);
    sw(g, 27, 3,  HEAD); sw(g, 26, 3,  TAIL); // top → right
    sw(g, 35, 11, HEAD); sw(g, 36, 11, TAIL); // bottom → left (CW)

    // ── Clock C: large loop (x=55..75, y=3..14), perimeter 60 ──
    rect(g, 55, 3, 75, 14);
    sw(g, 57, 3,  HEAD); sw(g, 56, 3,  TAIL); // top → right
    sw(g, 67, 14, HEAD); sw(g, 68, 14, TAIL); // bottom → left
    sw(g, 75, 9,  HEAD); sw(g, 75, 8,  TAIL); // right col → down

    // ── Highway ring (x=3..76, y=19..22), ~5 electrons ──
    rect(g, 3, 19, 76, 22);
    const hxs = [8, 24, 40, 56, 72];
    for (const x of hxs) {
      sw(g, x,   19, HEAD);
      sw(g, x-1, 19, TAIL);
    }

    // ── Figure-8 (x=3..50, y=26..44) with divider at y=35 ──
    rect(g, 3, 26, 50, 44);
    hw(g, 3, 50, 35); // horizontal divider
    sw(g, 6,  26, HEAD); sw(g, 5,  26, TAIL); // top loop → right
    sw(g, 30, 44, HEAD); sw(g, 31, 44, TAIL); // bottom loop → left

    // ── Right standalone loop (x=55..77, y=26..44), perimeter 80 ──
    rect(g, 55, 26, 77, 44);
    sw(g, 57, 26, HEAD); sw(g, 56, 26, TAIL); // top → right
    sw(g, 68, 44, HEAD); sw(g, 69, 44, TAIL); // bottom → left
    sw(g, 77, 35, HEAD); sw(g, 77, 34, TAIL); // right col → down

    return g;
  }

  // ── simulation step ─────────────────────────────────────────
  const DIRS = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];

  function simStep() {
    for (let y = 0; y < ROWS; y++) {
      for (let x = 0; x < COLS; x++) {
        const s = grid[y][x];
        if (s === EMPTY) { next[y][x] = EMPTY; continue; }
        if (s === HEAD)  { next[y][x] = TAIL;  continue; }
        if (s === TAIL)  { next[y][x] = WIRE;  continue; }
        // WIRE: becomes HEAD if 1 or 2 Moore neighbors are HEAD
        let h = 0;
        for (const [dy, dx] of DIRS) {
          const ny = y + dy, nx = x + dx;
          if (inB(nx, ny) && grid[ny][nx] === HEAD) h++;
        }
        next[y][x] = (h === 1 || h === 2) ? HEAD : WIRE;
      }
    }
    // swap buffers
    const tmp = grid; grid = next; next = tmp;
    stepCount++;
  }

  function countElectrons() {
    let c = 0;
    for (let y = 0; y < ROWS; y++)
      for (let x = 0; x < COLS; x++)
        if (grid[y][x] === HEAD) c++;
    return c;
  }

  // ── render ───────────────────────────────────────────────────
  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    const cw = cellW, ch = cellH;
    const gx = Math.max(0.5, cw * 0.1);
    const gy = Math.max(0.5, ch * 0.1);

    for (let y = 0; y < ROWS; y++) {
      for (let x = 0; x < COLS; x++) {
        const s = grid[y][x];
        if (s === EMPTY) continue;

        const px = x * cw + gx;
        const py = y * ch + gy;
        const pw = cw - gx * 2;
        const ph = ch - gy * 2;

        if (s === WIRE) {
          ctx.fillStyle = 'rgba(0,245,212,0.25)';
          ctx.fillRect(px, py, pw, ph);

        } else if (s === HEAD) {
          // soft glow
          ctx.fillStyle = 'rgba(0,245,212,0.13)';
          ctx.fillRect(x * cw - cw * 0.4, y * ch - ch * 0.4, cw * 1.8, ch * 1.8);
          // bright core
          ctx.fillStyle = '#d8fff8';
          ctx.fillRect(px, py, pw, ph);
          // hot white center
          ctx.fillStyle = 'rgba(255,255,255,0.85)';
          const mx = pw * 0.25, my = ph * 0.25;
          ctx.fillRect(px + mx, py + my, pw - mx * 2, ph - my * 2);

        } else { // TAIL
          ctx.fillStyle = 'rgba(123,44,191,0.72)';
          ctx.fillRect(px, py, pw, ph);
        }
      }
    }
  }

  // ── main loop ────────────────────────────────────────────────
  let lastTime = 0, accumulator = 0;

  function loop(ts) {
    requestAnimationFrame(loop);
    const dt = Math.min(ts - lastTime, 100);
    lastTime = ts;

    if (running) {
      accumulator += dt;
      while (accumulator >= stepInterval) {
        simStep();
        accumulator -= stepInterval;
      }
    }

    draw();
    document.getElementById('stepDisplay').textContent = stepCount;
    document.getElementById('electronDisplay').textContent = countElectrons();
  }

  // ── input ────────────────────────────────────────────────────
  function getCellXY(e) {
    const r = canvas.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return [
      Math.floor((src.clientX - r.left) / cellW),
      Math.floor((src.clientY - r.top)  / cellH)
    ];
  }

  function applyTool(x, y, btn) {
    if (!inB(x, y)) return;
    if (btn === 2) {
      if (grid[y][x] !== EMPTY) { grid[y][x] = EMPTY; SFX.play('pop', 20); }
    } else if (mode === 'WIRE') {
      if (grid[y][x] !== WIRE) { grid[y][x] = WIRE; SFX.play('tick', 20); }
    } else {
      // ELECTRON mode: place HEAD only on WIRE cells
      if (grid[y][x] === WIRE) { grid[y][x] = HEAD; SFX.play('spawn'); }
    }
  }

  let mouseDown = false, mouseBtn = 0;

  canvas.addEventListener('mousedown', e => {
    e.preventDefault();
    mouseDown = true; mouseBtn = e.button;
    const [x, y] = getCellXY(e);
    lastCell = [x, y]; applyTool(x, y, e.button);
  });
  canvas.addEventListener('mousemove', e => {
    if (!mouseDown) return;
    const [x, y] = getCellXY(e);
    if (!lastCell || x !== lastCell[0] || y !== lastCell[1]) {
      lastCell = [x, y]; applyTool(x, y, mouseBtn);
    }
  });
  window.addEventListener('mouseup', () => { mouseDown = false; lastCell = null; });
  canvas.addEventListener('contextmenu', e => e.preventDefault());

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const [x, y] = getCellXY(e); lastCell = [x, y]; applyTool(x, y, 0);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const [x, y] = getCellXY(e);
    if (!lastCell || x !== lastCell[0] || y !== lastCell[1]) {
      lastCell = [x, y]; applyTool(x, y, 0);
    }
  }, { passive: false });
  canvas.addEventListener('touchend', () => { lastCell = null; });

  // ── controls ─────────────────────────────────────────────────
  document.getElementById('btnRun').addEventListener('click', () => {
    running = !running;
    document.getElementById('btnRun').textContent = running ? 'PAUSE' : 'RUN';
    SFX.play('toggle');
  });

  document.getElementById('btnStep').addEventListener('click', () => {
    simStep(); SFX.play('tick');
  });

  document.getElementById('btnMode').addEventListener('click', () => {
    mode = (mode === 'WIRE') ? 'ELECTRON' : 'WIRE';
    document.getElementById('btnMode').textContent  = 'MODE: ' + mode;
    document.getElementById('modeDisplay').textContent = mode;
    document.getElementById('btnMode').classList.toggle('active', mode === 'ELECTRON');
    SFX.play('toggle');
  });

  document.getElementById('speedSlider').addEventListener('input', e => {
    const v = parseInt(e.target.value); // 1..10
    stepInterval = Math.round(200 / v); // 200ms (slow) → 20ms (fast)
  });

  document.getElementById('btnClear').addEventListener('click', () => {
    grid = mkGrid(); next = mkGrid(); stepCount = 0; SFX.play('click');
  });

  document.getElementById('btnReset').addEventListener('click', () => {
    grid = createInitial(); next = mkGrid(); stepCount = 0; SFX.play('click');
  });

  // ── init ─────────────────────────────────────────────────────
  resize();
  grid = createInitial();
  next = mkGrid();
  requestAnimationFrame(loop);
  window.addEventListener('resize', resize);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
