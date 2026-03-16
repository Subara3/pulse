<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('predprey');

$pageName = 'predprey';
$pageTitle = 'PREDPREY - PULSE';
$pageDesc = '捕食と被食。魚とサメが格子を舞台に繁殖・捕食・死滅を繰り返し、Lotka-Volterra振動が創発する。';
$pageCanonical = 'https://pulse.subara3.com/predprey.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .vf { color: #00f5d4; }
.hud .vs { color: #9b4fd4; }
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
  <div>FISH: <span class="vf" id="hFish">0</span></div>
  <div>SHARK: <span class="vs" id="hShark">0</span></div>
  <div>GEN: <span class="vf" id="hGen">0</span></div>
</div>
<div class="ctrl">
  <label>SPEED <input type="range" id="speedSlider" min="1" max="6" step="1" value="2"></label>
  <button id="btnReset">RESET</button>
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
(function() {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  // ---- simulation constants ----
  const CELL         = 8;   // px per cell
  const FISH_BREED   = 4;   // chronons before fish breeds
  const SHARK_BREED  = 10;  // chronons before shark breeds
  const SHARK_INIT   = 6;   // initial shark energy
  const SHARK_EAT    = 5;   // energy gained eating one fish
  const INIT_FISH    = 0.22;
  const INIT_SHARK   = 0.035;
  const HIST_LEN     = 300;

  // cell state constants
  const EMPTY = 0, FISH = 1, SHARK = 2;

  // ABGR (little-endian) packed pixel colors
  const C_BG    = (0xFF000000 | (15  << 16) | (10  << 8) | 10)  >>> 0; // #0a0a0f
  const C_FISH  = (0xFF000000 | (195 << 16) | (220 << 8) | 0)   >>> 0; // teal
  const C_SHARK = (0xFF000000 | (210 << 16) | (45  << 8) | 140) >>> 0; // purple

  let W, H, COLS, ROWS, N;
  let type, fishAge, sharkEnergy, sharkAge, moved, order, nbr;
  let totalFish = 0, totalShark = 0;
  let history = [];
  let simSpeed = 2;
  let generation = 0;
  let imgData, pxData, px32;
  let hudTick = 0;

  // ---- resize / init ----
  function resize() {
    W    = canvas.width  = window.innerWidth;
    H    = canvas.height = window.innerHeight;
    COLS = Math.floor(W / CELL);
    ROWS = Math.floor(H / CELL);
    N    = COLS * ROWS;
    imgData = ctx.createImageData(W, H);
    pxData  = imgData.data;
    px32    = new Uint32Array(pxData.buffer);
    for (let i = 3; i < pxData.length; i += 4) pxData[i] = 255;
    buildNeighbors();
    initSim();
  }

  function buildNeighbors() {
    nbr = new Int32Array(N * 4);
    for (let i = 0; i < N; i++) {
      const x = i % COLS, y = (i / COLS) | 0;
      nbr[i*4  ] = y > 0       ? i - COLS : -1;
      nbr[i*4+1] = y < ROWS-1  ? i + COLS : -1;
      nbr[i*4+2] = x > 0       ? i - 1    : -1;
      nbr[i*4+3] = x < COLS-1  ? i + 1    : -1;
    }
  }

  // Running counters — update whenever type[] changes
  function setType(i, t) {
    const old = type[i];
    if (old === t) return;
    if (old === FISH)  totalFish--;
    else if (old === SHARK) totalShark--;
    type[i] = t;
    if (t === FISH)  totalFish++;
    else if (t === SHARK) totalShark++;
  }

  function initSim() {
    type        = new Uint8Array(N);
    fishAge     = new Uint8Array(N);
    sharkEnergy = new Uint8Array(N);
    sharkAge    = new Uint8Array(N);
    moved       = new Uint8Array(N);
    order       = new Uint32Array(N);
    for (let i = 0; i < N; i++) order[i] = i;
    totalFish = 0; totalShark = 0;

    for (let i = 0; i < N; i++) {
      const r = Math.random();
      if (r < INIT_FISH) {
        type[i] = FISH;
        fishAge[i] = (Math.random() * FISH_BREED) | 0;
        totalFish++;
      } else if (r < INIT_FISH + INIT_SHARK) {
        type[i] = SHARK;
        sharkEnergy[i] = SHARK_INIT + ((Math.random() * 4) | 0);
        sharkAge[i] = (Math.random() * SHARK_BREED) | 0;
        totalShark++;
      }
    }
    history = [];
    generation = 0;
  }

  // Fisher-Yates shuffle
  function shuffle() {
    for (let i = N - 1; i > 0; i--) {
      const j = (Math.random() * (i + 1)) | 0;
      const t = order[i]; order[i] = order[j]; order[j] = t;
    }
  }

  // ---- simulation step ----
  function step() {
    shuffle();
    moved.fill(0);

    for (let oi = 0; oi < N; oi++) {
      const i = order[oi];
      if (moved[i]) continue;
      const t = type[i];
      if (t === EMPTY) continue;

      if (t === FISH) {
        fishAge[i]++;
        // collect empty neighbors (max 4)
        let ec = 0, en = [-1,-1,-1,-1];
        for (let d = 0; d < 4; d++) {
          const j = nbr[i*4+d];
          if (j >= 0 && type[j] === EMPTY && !moved[j]) en[ec++] = j;
        }
        if (ec > 0) {
          const dest = en[(Math.random() * ec) | 0];
          const breed = fishAge[i] >= FISH_BREED;
          setType(dest, FISH);
          fishAge[dest] = 0;
          moved[dest] = 1;
          if (breed) {
            fishAge[i] = 0;
          } else {
            setType(i, EMPTY);
          }
          moved[i] = 1;
        }

      } else { // SHARK
        sharkAge[i]++;
        if (sharkEnergy[i] > 0) sharkEnergy[i]--;
        if (sharkEnergy[i] === 0) {
          setType(i, EMPTY);
          SFX.play('pop', 300);
          continue;
        }
        // prefer fish neighbors, else empty
        let fc = 0, fn = [-1,-1,-1,-1];
        let ec = 0, en = [-1,-1,-1,-1];
        for (let d = 0; d < 4; d++) {
          const j = nbr[i*4+d];
          if (j < 0 || moved[j]) continue;
          if (type[j] === FISH)  fn[fc++] = j;
          else if (type[j] === EMPTY) en[ec++] = j;
        }
        let dest = -1, ate = false;
        if (fc > 0) { dest = fn[(Math.random() * fc) | 0]; ate = true; }
        else if (ec > 0) { dest = en[(Math.random() * ec) | 0]; }

        if (dest >= 0) {
          if (ate) {
            sharkEnergy[i] = Math.min(255, sharkEnergy[i] + SHARK_EAT);
            SFX.play('collide', 120);
          }
          const breed = sharkAge[i] >= SHARK_BREED;
          setType(dest, SHARK);
          sharkEnergy[dest] = sharkEnergy[i];
          sharkAge[dest] = 0;
          moved[dest] = 1;
          if (breed) {
            sharkAge[i] = 0;
            sharkEnergy[i] = Math.max(1, (sharkEnergy[i] / 2) | 0);
          } else {
            setType(i, EMPTY);
          }
          moved[i] = 1;
        }
      }
    }

    history.push({f: totalFish, s: totalShark});
    if (history.length > HIST_LEN) history.shift();
    generation++;
  }

  // ---- render ----
  function render() {
    for (let cy = 0; cy < ROWS; cy++) {
      const py = cy * CELL;
      for (let cx = 0; cx < COLS; cx++) {
        const px = cx * CELL;
        const ci = cy * COLS + cx;
        const color = type[ci] === FISH ? C_FISH : type[ci] === SHARK ? C_SHARK : C_BG;
        for (let dy = 0; dy < CELL && py+dy < H; dy++) {
          const row = (py + dy) * W;
          for (let dx = 0; dx < CELL && px+dx < W; dx++) {
            px32[row + px + dx] = color;
          }
        }
      }
    }
    ctx.putImageData(imgData, 0, 0);

    // population graph (above title label)
    if (history.length > 2) {
      const gw = 200, gh = 55;
      const gx = 20, gy = H - gh - 55;
      ctx.fillStyle = 'rgba(10,10,15,0.75)';
      ctx.fillRect(gx - 2, gy - 2, gw + 4, gh + 4);
      const len = history.length;
      const maxVal = Math.max(100, ...history.map(h => Math.max(h.f, h.s)));
      // fish line
      ctx.beginPath(); ctx.strokeStyle = 'rgba(0,220,180,0.8)'; ctx.lineWidth = 1.5;
      for (let i = 0; i < len; i++) {
        const x = gx + (i / (len - 1)) * gw;
        const y = gy + gh - (history[i].f / maxVal) * gh;
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      }
      ctx.stroke();
      // shark line
      ctx.beginPath(); ctx.strokeStyle = 'rgba(155,79,212,0.8)'; ctx.lineWidth = 1.5;
      for (let i = 0; i < len; i++) {
        const x = gx + (i / (len - 1)) * gw;
        const y = gy + gh - (history[i].s / maxVal) * gh;
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      }
      ctx.stroke();
    }
  }

  // ---- main loop ----
  function loop() {
    for (let s = 0; s < simSpeed; s++) step();
    render();
    if (++hudTick % 3 === 0) {
      document.getElementById('hFish').textContent  = totalFish;
      document.getElementById('hShark').textContent = totalShark;
      document.getElementById('hGen').textContent   = generation;
    }
    requestAnimationFrame(loop);
  }

  // ---- interaction ----
  function canvasPos(e) {
    const r = canvas.getBoundingClientRect();
    return {
      x: ((e.clientX - r.left) / r.width  * W) | 0,
      y: ((e.clientY - r.top)  / r.height * H) | 0
    };
  }

  function addFish(x, y) {
    const cx = (x / CELL) | 0, cy = (y / CELL) | 0;
    for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) {
      if (dx*dx + dy*dy > 16) continue;
      const nx = cx+dx, ny = cy+dy;
      if (nx < 0 || nx >= COLS || ny < 0 || ny >= ROWS) continue;
      const i = ny * COLS + nx;
      if (type[i] !== SHARK) { setType(i, FISH); fishAge[i] = 0; }
    }
    SFX.play('spawn');
  }

  function addShark(x, y) {
    const cx = (x / CELL) | 0, cy = (y / CELL) | 0;
    for (let dy = -3; dy <= 3; dy++) for (let dx = -3; dx <= 3; dx++) {
      if (dx*dx + dy*dy > 9) continue;
      const nx = cx+dx, ny = cy+dy;
      if (nx < 0 || nx >= COLS || ny < 0 || ny >= ROWS) continue;
      const i = ny * COLS + nx;
      setType(i, SHARK);
      sharkEnergy[i] = SHARK_INIT;
      sharkAge[i] = 0;
    }
    SFX.play('pop');
  }

  // mouse
  let dragged = false, downX = 0, downY = 0;
  canvas.addEventListener('mousedown', e => {
    e.preventDefault();
    dragged = false; downX = e.clientX; downY = e.clientY;
    if (e.button === 2) { addShark(...Object.values(canvasPos(e))); }
  });
  canvas.addEventListener('mousemove', e => {
    if (e.buttons === 1) {
      const dx = e.clientX - downX, dy = e.clientY - downY;
      if (dx*dx + dy*dy > 25) dragged = true;
    }
  });
  canvas.addEventListener('mouseup', e => {
    if (e.button === 0 && !dragged) { const p = canvasPos(e); addFish(p.x, p.y); }
  });
  canvas.addEventListener('contextmenu', e => { e.preventDefault(); });

  // touch
  let touchStart = null, touchMoved = false;
  canvas.addEventListener('touchstart', e => {
    e.preventDefault(); touchStart = canvasPos(e.touches[0]); touchMoved = false;
  }, {passive: false});
  canvas.addEventListener('touchmove', e => {
    e.preventDefault(); touchMoved = true;
  }, {passive: false});
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    if (!touchMoved && touchStart) addFish(touchStart.x, touchStart.y);
    touchStart = null;
  }, {passive: false});

  // controls
  document.getElementById('speedSlider').addEventListener('input', e => { simSpeed = +e.target.value; });
  document.getElementById('btnReset').addEventListener('click', () => { initSim(); SFX.play('click'); });

  resize();
  window.addEventListener('resize', resize);
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
