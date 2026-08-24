<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('epidemic');

$pageName      = 'epidemic';
$pageTitle     = 'EPIDEMIC - PULSE';
$pageDesc      = 'SIR epidemic diffusion on a grid. Infect cells by painting, then tune infection and recovery rates.';
$pageCanonical = 'https://pulse.subara3.com/epidemic.php';

$extraStyle = '
body { background:#0a0a0f; margin:0; overflow:hidden; }
#canvas {
  position:fixed; inset:0; width:100%; height:100%;
  image-rendering:pixelated; image-rendering:crisp-edges;
  cursor:crosshair;
}
#hud {
  position:fixed; top:1.1rem; left:1rem; z-index:100;
  pointer-events:none;
  font-family:"Space Grotesk","Segoe UI",sans-serif;
  font-size:0.62rem; line-height:1.9; letter-spacing:0.06em;
}
#hud .lbl { color:rgba(255,255,255,0.3); margin-right:0.3em; }
#hud .vs  { color:rgba(0,245,212,0.4); }
#hud .vi  { color:#00f5d4; font-weight:700; }
#hud .vr  { color:#a855f7; font-weight:700; }
#graph {
  position:fixed; bottom:3.8rem; left:1rem; z-index:100;
  border:1px solid rgba(0,245,212,0.18);
  pointer-events:none;
}
#ctrl {
  position:fixed; bottom:1.2rem; right:1.2rem; z-index:100;
  display:flex; flex-direction:column; gap:0.45rem; align-items:flex-end;
}
.crow { display:flex; align-items:center; gap:0.5rem; }
.crow label {
  font-family:"Space Grotesk",sans-serif; font-size:0.56rem;
  color:rgba(255,255,255,0.35); letter-spacing:0.07em; min-width:5.5rem; text-align:right;
}
.crow input[type=range] { width:80px; accent-color:#00f5d4; }
.cbtn {
  background:rgba(10,10,15,0.72); border:1px solid rgba(0,245,212,0.22);
  color:rgba(255,255,255,0.65); font-family:"Space Grotesk",sans-serif;
  font-size:0.56rem; letter-spacing:0.07em;
  padding:0.35rem 0.7rem; cursor:pointer;
  backdrop-filter:blur(6px); transition:border-color 0.2s,color 0.2s;
  touch-action:manipulation;
}
.cbtn:hover { border-color:rgba(0,245,212,0.55); color:#00f5d4; }
@media(max-width:768px){
  #hud{font-size:0.52rem;top:0.75rem;left:0.75rem;}
  #ctrl{bottom:0.9rem;right:0.9rem;}
  .crow input[type=range]{width:60px;}
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  <div><span class="lbl">S</span><span class="vs" id="sv">0%</span></div>
  <div><span class="lbl">I</span><span class="vi" id="iv">0%</span></div>
  <div><span class="lbl">R</span><span class="vr" id="rv">0%</span></div>
</div>

<canvas id="graph" width="160" height="56"></canvas>

<div id="controls" class="drawer-controls">
  <div class="crow">
    <label>BETA <span id="bv">0.25</span></label>
    <input type="range" id="betaR" min="5" max="50" step="5" value="25">
  </div>
  <div class="crow">
    <label>RECOVERY <span id="gv">50</span></label>
    <input type="range" id="gammaR" min="10" max="100" step="5" value="50">
  </div>
  <button class="cbtn" id="resetBtn">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'infect'],
  ['type' => 'drag',  'label' => 'spread'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  'use strict';

  /* ── canvas & context ── */
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');
  const gcanv  = document.getElementById('graph');
  const gctx   = gcanv.getContext('2d');

  /* ── constants ── */
  const CELL    = 6;     // px per grid cell
  const S = 0, I = 1, R = 2;
  const HIST    = 160;   // graph history length

  /* ── sim state ── */
  let W, H, cols, rows, N;
  let sa, ta, sb, tb;    // double buffers: state (Uint8), timer (Int16)
  let cur = 0;           // which buffer is active (0 or 1)
  let beta  = 0.25;
  let gamma = 50;

  /* ── render state ── */
  let pixels, bgPx, imgData;
  let history = [];

  /* ── resize ── */
  function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
    W    = canvas.width;
    H    = canvas.height;
    cols = (W / CELL) | 0;
    rows = (H / CELL) | 0;
    N    = cols * rows;
    initRender();
    initSim();
  }

  function initRender() {
    const len = W * H * 4;
    bgPx = new Uint8ClampedArray(len);
    for (let i = 0; i < len; i += 4) {
      bgPx[i] = 10; bgPx[i+1] = 10; bgPx[i+2] = 15; bgPx[i+3] = 255;
    }
    pixels  = new Uint8ClampedArray(len);
    imgData = new ImageData(pixels, W, H);
  }

  function initSim() {
    sa = [new Uint8Array(N), new Uint8Array(N)];
    ta = [new Int16Array(N), new Int16Array(N)];
    cur = 0;
    history = [];
    for (let k = 0; k < 6; k++) {
      const r = (Math.random() * rows) | 0;
      const c = (Math.random() * cols) | 0;
      const idx = r * cols + c;
      sa[0][idx] = I;
      ta[0][idx] = gamma;
    }
  }

  /* ── simulation step ── */
  function step() {
    const next = 1 - cur;
    const s0 = sa[cur], t0 = ta[cur];
    const s1 = sa[next], t1 = ta[next];
    s1.set(s0);
    t1.set(t0);

    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        const idx = r * cols + c;
        const st  = s0[idx];

        if (st === S) {
          // infect if any of 4 neighbours is I
          let inf = false;
          if (!inf && r > 0      && s0[(r-1)*cols + c] === I && Math.random() < beta) inf = true;
          if (!inf && r < rows-1 && s0[(r+1)*cols + c] === I && Math.random() < beta) inf = true;
          if (!inf && c > 0      && s0[r*cols + c - 1] === I && Math.random() < beta) inf = true;
          if (!inf && c < cols-1 && s0[r*cols + c + 1] === I && Math.random() < beta) inf = true;
          if (inf) {
            s1[idx] = I;
            t1[idx] = gamma + ((Math.random() * 20 - 10) | 0);
          }
        } else if (st === I) {
          const t = t0[idx] - 1;
          if (t <= 0) { s1[idx] = R; t1[idx] = 0; }
          else t1[idx] = t;
        }
      }
    }
    cur = next;
  }

  /* ── draw ── */
  function draw() {
    pixels.set(bgPx);
    const s0 = sa[cur];
    let sC = 0, iC = 0, rC = 0;

    for (let r = 0; r < rows; r++) {
      const y0 = r * CELL + 1;
      for (let c = 0; c < cols; c++) {
        const st = s0[r * cols + c];
        if (st === S) { sC++; continue; }

        let rr, gg, bb;
        if (st === I) { rr = 0;   gg = 245; bb = 212; iC++; }
        else          { rr = 55;  gg = 18;  bb = 85;  rC++; }

        const x0 = c * CELL + 1;
        const cw = CELL - 2, ch = CELL - 2;
        for (let dy = 0; dy < ch; dy++) {
          const base = ((y0 + dy) * W + x0) * 4;
          for (let dx = 0; dx < cw; dx++) {
            const p = base + dx * 4;
            pixels[p]   = rr;
            pixels[p+1] = gg;
            pixels[p+2] = bb;
          }
        }
      }
    }

    ctx.putImageData(imgData, 0, 0);

    /* HUD */
    document.getElementById('sv').textContent = ((sC / N * 100) | 0) + '%';
    document.getElementById('iv').textContent = ((iC / N * 100) | 0) + '%';
    document.getElementById('rv').textContent = ((rC / N * 100) | 0) + '%';

    /* history */
    history.push([sC / N, iC / N, rC / N]);
    if (history.length > HIST) history.shift();

    /* auto-reseed if epidemic burned out */
    if (iC === 0 && sC > N * 0.05) {
      const r = (Math.random() * rows) | 0;
      const c = (Math.random() * cols) | 0;
      const idx = r * cols + c;
      if (sa[cur][idx] === S) {
        sa[cur][idx] = I;
        ta[cur][idx] = gamma;
        SFX.play('spawn');
      }
    }

    drawGraph();
  }

  function drawGraph() {
    const W2 = 160, H2 = 56;
    gctx.fillStyle = 'rgba(0,0,0,0.65)';
    gctx.fillRect(0, 0, W2, H2);
    if (history.length < 2) return;

    function line(idx, color, lw) {
      gctx.beginPath();
      gctx.strokeStyle = color;
      gctx.lineWidth   = lw;
      for (let i = 0; i < history.length; i++) {
        const x = i * W2 / HIST;
        const y = H2 - history[i][idx] * H2;
        i === 0 ? gctx.moveTo(x, y) : gctx.lineTo(x, y);
      }
      gctx.stroke();
    }

    line(0, 'rgba(0,245,212,0.28)', 1);
    line(1, '#00f5d4',               1.5);
    line(2, 'rgba(168,85,247,0.75)', 1);
  }

  /* ── interaction ── */
  function infectAt(px, py) {
    const c = (px / CELL) | 0;
    const r = (py / CELL) | 0;
    const rad = 3;
    for (let dr = -rad; dr <= rad; dr++) {
      for (let dc = -rad; dc <= rad; dc++) {
        if (dr*dr + dc*dc > rad*rad + 1) continue;
        const nr = r + dr, nc = c + dc;
        if (nr >= 0 && nr < rows && nc >= 0 && nc < cols) {
          const idx = nr * cols + nc;
          if (sa[cur][idx] === S) {
            sa[cur][idx] = I;
            ta[cur][idx] = gamma;
          }
        }
      }
    }
  }

  let isDown = false;

  function onMove(e) {
    if (!isDown) return;
    const rect = canvas.getBoundingClientRect();
    if (e.touches) {
      for (const t of e.touches) infectAt(t.clientX - rect.left, t.clientY - rect.top);
    } else {
      infectAt(e.clientX - rect.left, e.clientY - rect.top);
    }
    SFX.play('ripple', 80);
  }

  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) { initSim(); SFX.play('click'); return; }
    isDown = true;
    const rect = canvas.getBoundingClientRect();
    infectAt(e.clientX - rect.left, e.clientY - rect.top);
    SFX.play('spawn');
  });
  canvas.addEventListener('mousemove', onMove);
  canvas.addEventListener('mouseup',   () => isDown = false);
  canvas.addEventListener('contextmenu', e => e.preventDefault());

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    isDown = true;
    const rect = canvas.getBoundingClientRect();
    const t = e.touches[0];
    infectAt(t.clientX - rect.left, t.clientY - rect.top);
    SFX.play('spawn');
  }, { passive: false });
  canvas.addEventListener('touchmove',  e => { e.preventDefault(); onMove(e); }, { passive: false });
  canvas.addEventListener('touchend',   () => isDown = false);

  document.getElementById('betaR').addEventListener('input', function () {
    beta = this.value / 100;
    document.getElementById('bv').textContent = beta.toFixed(2);
    SFX.play('toggle');
  });
  document.getElementById('gammaR').addEventListener('input', function () {
    gamma = parseInt(this.value);
    document.getElementById('gv').textContent = gamma;
    SFX.play('toggle');
  });
  document.getElementById('resetBtn').addEventListener('click', () => {
    initSim();
    SFX.play('click');
  });

  /* ── main loop ── */
  let frame = 0;
  function loop() {
    frame++;
    if (frame % 2 === 0) step();
    draw();
    requestAnimationFrame(loop);
  }

  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
