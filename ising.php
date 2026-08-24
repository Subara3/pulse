<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ising');

$pageName      = 'ising';
$pageTitle     = 'ISING - PULSE';
$pageDesc      = 'Two-dimensional Ising model. Paint spin noise into the lattice and tune temperature around the critical point.';
$pageCanonical = 'https://pulse.subara3.com/ising.php';

$extraStyle = '
body { background: #0a0a0f; margin: 0; overflow: hidden; }
#canvas {
  position: fixed; inset: 0; width: 100%; height: 100%;
  image-rendering: pixelated; image-rendering: crisp-edges;
  cursor: crosshair;
}
.hud {
  position: fixed; top: 1rem; left: 1rem; z-index: 100;
  pointer-events: none;
  font-family: "Space Grotesk","Segoe UI",sans-serif;
  font-size: 0.62rem; color: rgba(255,255,255,0.3);
  line-height: 2; letter-spacing: 0.06em;
}
.hud .v  { color: #00f5d4; font-weight: 700; }
.hud .v2 { color: #a855f7; font-weight: 700; }
.ctrl-bar {
  position: fixed; bottom: 1.2rem; right: 1.2rem; z-index: 100;
  display: flex; gap: 0.5rem; flex-direction: column; align-items: flex-end;
}
.ctrl-btn {
  background: rgba(10,10,15,0.72); border: 1px solid rgba(0,245,212,0.25);
  color: rgba(255,255,255,0.7); font-family: "Space Grotesk",sans-serif;
  font-size: 0.58rem; letter-spacing: 0.08em;
  padding: 0.38rem 0.75rem; border-radius: 4px; cursor: pointer;
  backdrop-filter: blur(6px); transition: border-color 0.2s, color 0.2s;
  touch-action: manipulation;
}
.ctrl-btn:hover  { border-color: rgba(0,245,212,0.6); color: #00f5d4; }
.ctrl-btn.active { border-color: #00f5d4; color: #00f5d4; }
.t-bar {
  display: flex; gap: 0.4rem; align-items: center;
}
.t-label {
  font-family: "Space Grotesk",sans-serif; font-size: 0.55rem;
  color: rgba(255,255,255,0.4); letter-spacing: 0.06em;
}
@media (max-width: 768px) {
  .hud { font-size: 0.52rem; top: 0.7rem; left: 0.7rem; }
  .ctrl-bar { bottom: 0.9rem; right: 0.9rem; gap: 0.4rem; }
  .ctrl-btn { font-size: 0.5rem; padding: 0.3rem 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  T:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudT"  class="v">2.27</span><br>
  T_c:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudTc" class="v">2.27</span><br>
  M:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudM"  class="v">0.00</span><br>
  PHASE:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudP"  class="v">CRITICAL</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="t-bar">
    <button class="ctrl-btn" id="btnTm">T-</button>
    <span class="t-label">TEMP</span>
    <button class="ctrl-btn" id="btnTp">T+</button>
  </div>
  <button class="ctrl-btn" id="btnOrder">ORDERED</button>
  <button class="ctrl-btn" id="btnReset">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'flip'],
  ['type' => 'drag',  'label' => 'paint'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  const GRID    = 128;
  const TC      = 2 / Math.log(1 + Math.sqrt(2)); // ~2.2691
  const T_MIN   = 0.5;
  const T_MAX   = 5.0;
  const T_STEP  = 0.14;
  const SWEEPS  = 4;   // metropolis sweeps per frame
  const BRUSH   = 6;   // flip brush radius (grid cells)

  const canvas  = document.getElementById('canvas');
  const ctx     = canvas.getContext('2d');

  /* offscreen at grid resolution for speed */
  const off     = document.createElement('canvas');
  off.width = GRID; off.height = GRID;
  const offCtx  = off.getContext('2d');
  const img     = offCtx.createImageData(GRID, GRID);

  let T      = TC;
  let spins  = new Int8Array(GRID * GRID);
  let drag   = false;

  /* teal = [0,245,212], purple = [123,44,191] */
  const COL_UP   = [0,   245, 212];
  const COL_DOWN = [123,  44, 191];

  function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
  }

  function initRandom() {
    for (let i = 0; i < spins.length; i++) {
      spins[i] = Math.random() < 0.5 ? 1 : -1;
    }
  }

  function initOrdered() {
    spins.fill(1);
  }

  function metropolis() {
    const len = spins.length;
    for (let sweep = 0; sweep < SWEEPS; sweep++) {
      for (let k = 0; k < len; k++) {
        const idx = (Math.random() * len) | 0;
        const r   = (idx / GRID) | 0;
        const c   = idx % GRID;
        const s   = spins[idx];
        const nb  =
          spins[((r - 1 + GRID) % GRID) * GRID + c] +
          spins[((r + 1) % GRID)         * GRID + c] +
          spins[r * GRID + ((c - 1 + GRID) % GRID)] +
          spins[r * GRID + ((c + 1) % GRID)];
        const dE  = 2 * s * nb;
        if (dE <= 0 || Math.random() < Math.exp(-dE / T)) {
          spins[idx] = -s;
        }
      }
    }
  }

  function magnetization() {
    let sum = 0;
    for (let i = 0; i < spins.length; i++) sum += spins[i];
    return sum / spins.length;
  }

  function renderSpins() {
    const d = img.data;
    for (let i = 0; i < spins.length; i++) {
      const p   = i * 4;
      const up  = spins[i] > 0;
      d[p]   = up ? COL_UP[0]   : COL_DOWN[0];
      d[p+1] = up ? COL_UP[1]   : COL_DOWN[1];
      d[p+2] = up ? COL_UP[2]   : COL_DOWN[2];
      d[p+3] = 255;
    }
    offCtx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(off, 0, 0, canvas.width, canvas.height);
  }

  function updateHUD(M) {
    document.getElementById('hudT').textContent  = T.toFixed(2);
    document.getElementById('hudTc').textContent = TC.toFixed(2);
    document.getElementById('hudM').textContent  = M.toFixed(3);
    let phase, el = document.getElementById('hudP');
    if (T < TC - 0.15)      { phase = 'ORDERED';   el.className = 'v'; }
    else if (T > TC + 0.15) { phase = 'DISORDER';  el.className = 'v2'; }
    else                    { phase = 'CRITICAL';   el.className = 'v'; }
    el.textContent = phase;
  }

  let frameCount = 0;
  function loop() {
    metropolis();
    renderSpins();
    if (frameCount % 6 === 0) updateHUD(magnetization());
    frameCount++;
    requestAnimationFrame(loop);
  }

  /* --- interaction --- */
  function gridPos(px, py) {
    return {
      r: Math.floor(py / canvas.height * GRID),
      c: Math.floor(px / canvas.width  * GRID)
    };
  }

  function paintAt(px, py) {
    const { r, c } = gridPos(px, py);
    for (let dr = -BRUSH; dr <= BRUSH; dr++) {
      for (let dc = -BRUSH; dc <= BRUSH; dc++) {
        if (dr*dr + dc*dc > BRUSH*BRUSH) continue;
        const nr = (r + dr + GRID) % GRID;
        const nc = (c + dc + GRID) % GRID;
        spins[nr * GRID + nc] = Math.random() < 0.5 ? 1 : -1;
      }
    }
  }

  canvas.addEventListener('mousedown', e => {
    drag = true;
    paintAt(e.clientX, e.clientY);
    SFX.play('ripple', 80);
  });
  canvas.addEventListener('mousemove', e => {
    if (drag) paintAt(e.clientX, e.clientY);
  });
  canvas.addEventListener('mouseup',   () => { drag = false; });
  canvas.addEventListener('mouseleave',() => { drag = false; });

  canvas.addEventListener('touchstart', e => {
    e.preventDefault(); drag = true;
    const t = e.touches[0];
    paintAt(t.clientX, t.clientY);
    SFX.play('ripple', 80);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const t = e.touches[0];
    if (drag) paintAt(t.clientX, t.clientY);
  }, { passive: false });
  canvas.addEventListener('touchend', () => { drag = false; });

  /* buttons */
  document.getElementById('btnTm').addEventListener('click', () => {
    T = Math.max(T_MIN, +(T - T_STEP).toFixed(2));
    SFX.play('toggle');
  });
  document.getElementById('btnTp').addEventListener('click', () => {
    T = Math.min(T_MAX, +(T + T_STEP).toFixed(2));
    SFX.play('toggle');
  });
  document.getElementById('btnOrder').addEventListener('click', () => {
    initOrdered(); SFX.play('click');
  });
  document.getElementById('btnReset').addEventListener('click', () => {
    initRandom(); SFX.play('click');
  });

  window.addEventListener('resize', resize);

  /* start */
  resize();
  initRandom();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
