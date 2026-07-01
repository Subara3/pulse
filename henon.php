<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('henon');

$pageName = 'henon';
$pageTitle = 'HENON - PULSE';
$pageDesc = 'Hénon attractor. A two-dimensional chaotic map traces fractal orbits between order and escape.';
$pageCanonical = 'https://pulse.subara3.com/henon.php';
$extraStyle = '
#hud{position:fixed;top:1rem;left:1rem;font-size:.7rem;color:rgba(0,245,212,.65);font-family:monospace;pointer-events:none;line-height:1.9;z-index:10}
#hud span.v{color:#fff}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.28rem .62rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
.ctrl-row{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.ctrl-label{color:rgba(0,245,212,.55);font-family:monospace;font-size:.65rem;letter-spacing:.05em;min-width:1rem;text-align:right}
.ctrl-val{color:rgba(255,255,255,.7);font-family:monospace;font-size:.62rem;min-width:2.8rem;text-align:right}
input[type=range]{width:88px;accent-color:#00f5d4;cursor:pointer}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>a &nbsp;= <span class="v" id="hud-a">1.4000</span></div>
  <div>b &nbsp;= <span class="v" id="hud-b">0.3000</span></div>
  <div>iter <span class="v" id="hud-iter">0</span></div>
</div>
<div id="controls" class="drawer-controls">
  <div class="ctrl-row">
    <span class="ctrl-label">a</span>
    <input type="range" id="slider-a" min="0.4" max="1.8" step="0.001" value="1.4">
    <span class="ctrl-val" id="val-a">1.400</span>
  </div>
  <div class="ctrl-row">
    <span class="ctrl-label">b</span>
    <input type="range" id="slider-b" min="0.0" max="0.5" step="0.001" value="0.3">
    <span class="ctrl-val" id="val-b">0.300</span>
  </div>
  <button class="btn" id="btn-reset">RESET</button>
</div>
<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
  ['type' => 'right', 'label' => 'reset view'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  // ── Hénon-space density buffer ─────────────────────────────────────────────
  // Fixed domain covers the attractor and some surroundings
  const XMIN = -2.0, XMAX = 2.0, YMIN = -1.0, YMAX = 1.0;
  const DRES_X = 1024, DRES_Y = 512;
  const DX_SCALE = DRES_X / (XMAX - XMIN);
  const DY_SCALE = DRES_Y / (YMAX - YMIN);
  let density = new Float32Array(DRES_X * DRES_Y);

  // ── Parameters ─────────────────────────────────────────────────────────────
  let a = 1.4, b = 0.3;

  // ── View state ─────────────────────────────────────────────────────────────
  let viewCX = 0, viewCY = 0, zoom = 1;
  let W, H;

  function getScale() { return Math.min(W, H) * 0.28 * zoom; }

  function fromPixel(px, py) {
    const s = getScale();
    return [(px - W / 2) / s + viewCX, -(py - H / 2) / s + viewCY];
  }

  // ── Orbit state ─────────────────────────────────────────────────────────────
  let hx = 0.1, hy = 0.1;
  let totalIter = 0;

  function burnin() {
    for (let i = 0; i < 500; i++) {
      const nx = 1 - a * hx * hx + hy;
      const ny = b * hx;
      hx = nx; hy = ny;
      if (!isFinite(hx) || Math.abs(hx) > 1e6) { hx = 0.1; hy = 0.1; }
    }
  }

  function resetDensity() {
    density.fill(0);
    hx = 0.1; hy = 0.1;
    totalIter = 0;
    burnin();
  }

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    resetDensity();
  }

  // ── Iterate ─────────────────────────────────────────────────────────────────
  const ITERS_PER_FRAME = 25000;
  const MAX_ITER = 8_000_000;

  function runIter(n) {
    for (let i = 0; i < n; i++) {
      const nx = 1 - a * hx * hx + hy;
      const ny = b * hx;
      hx = nx; hy = ny;

      if (!isFinite(hx) || Math.abs(hx) > 1e6) {
        // orbit escaped — restart near origin
        hx = (Math.random() - 0.5) * 0.4;
        hy = (Math.random() - 0.5) * 0.2;
        for (let j = 0; j < 100; j++) {
          const nx2 = 1 - a * hx * hx + hy;
          const ny2 = b * hx;
          hx = nx2; hy = ny2;
          if (!isFinite(hx)) { hx = 0.1; hy = 0.1; break; }
        }
        continue;
      }

      const dx = ((hx - XMIN) * DX_SCALE) | 0;
      const dy = ((hy - YMIN) * DY_SCALE) | 0;
      if (dx >= 0 && dx < DRES_X && dy >= 0 && dy < DRES_Y) {
        density[dy * DRES_X + dx]++;
      }
    }
    totalIter += n;
  }

  // ── Render ──────────────────────────────────────────────────────────────────
  let imgData = null;

  function render() {
    const s = getScale();
    if (!imgData || imgData.width !== W || imgData.height !== H) {
      imgData = ctx.createImageData(W, H);
    }

    // find max density for log-normalisation
    let maxD = 0;
    for (let i = 0; i < DRES_X * DRES_Y; i++) {
      if (density[i] > maxD) maxD = density[i];
    }
    const logMax = maxD > 0 ? Math.log(maxD + 1) : 1;

    const d = imgData.data;
    const invXRange = DRES_X / (XMAX - XMIN);
    const invYRange = DRES_Y / (YMAX - YMIN);
    // precompute column → Hénon X → density X
    const colDX = new Int32Array(W);
    for (let px = 0; px < W; px++) {
      const hxc = (px - W / 2) / s + viewCX;
      colDX[px] = ((hxc - XMIN) * invXRange) | 0;
    }

    for (let py = 0; py < H; py++) {
      const hyc  = -(py - H / 2) / s + viewCY;
      const dy   = ((hyc - YMIN) * invYRange) | 0;
      const inY  = (dy >= 0 && dy < DRES_Y);
      const rowOff = dy * DRES_X;

      for (let px = 0; px < W; px++) {
        const base = (py * W + px) * 4;
        let cnt = 0;
        if (inY) {
          const dx = colDX[px];
          if (dx >= 0 && dx < DRES_X) cnt = density[rowOff + dx];
        }

        if (cnt === 0) {
          d[base]   = 10;
          d[base+1] = 10;
          d[base+2] = 15;
          d[base+3] = 255;
        } else {
          const t = Math.log(cnt + 1) / logMax;
          let r, g, bl;
          if (t < 0.55) {
            // purple → teal
            const tt = t / 0.55;
            r  = (123 * (1 - tt)) | 0;
            g  = (44  * (1 - tt) + 245 * tt) | 0;
            bl = (191 + (212 - 191) * tt)     | 0;
          } else {
            // teal → white
            const tt = (t - 0.55) / 0.45;
            r  = (255 * tt)             | 0;
            g  = (245 + 10 * tt)        | 0;
            bl = (212 + 43 * tt)        | 0;
          }
          d[base]   = r;
          d[base+1] = g;
          d[base+2] = bl;
          d[base+3] = 255;
        }
      }
    }

    ctx.putImageData(imgData, 0, 0);
  }

  // ── HUD ─────────────────────────────────────────────────────────────────────
  function drawHUD() {
    document.getElementById('hud-a').textContent = a.toFixed(4);
    document.getElementById('hud-b').textContent = b.toFixed(4);
    const m = totalIter >= 1e6
      ? (totalIter / 1e6).toFixed(2) + 'M'
      : totalIter >= 1000
        ? (totalIter / 1000).toFixed(0) + 'K'
        : String(totalIter);
    document.getElementById('hud-iter').textContent = m;
  }

  // ── Main loop ────────────────────────────────────────────────────────────────
  let frame = 0;
  function loop() {
    if (totalIter < MAX_ITER) runIter(ITERS_PER_FRAME);
    if (frame % 2 === 0) render();
    drawHUD();
    frame++;
    requestAnimationFrame(loop);
  }

  // ── Sliders ──────────────────────────────────────────────────────────────────
  const sliderA = document.getElementById('slider-a');
  const sliderB = document.getElementById('slider-b');
  const valA    = document.getElementById('val-a');
  const valB    = document.getElementById('val-b');

  sliderA.addEventListener('input', () => {
    a = parseFloat(sliderA.value);
    valA.textContent = a.toFixed(3);
    resetDensity();
    SFX.play('toggle');
  });
  sliderB.addEventListener('input', () => {
    b = parseFloat(sliderB.value);
    valB.textContent = b.toFixed(3);
    resetDensity();
    SFX.play('toggle');
  });

  // ── Pan ──────────────────────────────────────────────────────────────────────
  let dragging = false, dsx, dsy, dcx, dcy;

  function startDrag(x, y) { dragging = true; dsx = x; dsy = y; dcx = viewCX; dcy = viewCY; }
  function moveDrag(x, y) {
    if (!dragging) return;
    const s = getScale();
    viewCX = dcx - (x - dsx) / s;
    viewCY = dcy + (y - dsy) / s;
  }

  canvas.addEventListener('mousedown', e => { if (e.button === 0) startDrag(e.clientX, e.clientY); });
  canvas.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
  canvas.addEventListener('mouseup',   () => { dragging = false; });
  canvas.addEventListener('mouseleave',() => { dragging = false; });

  // ── Zoom ─────────────────────────────────────────────────────────────────────
  canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const factor = e.deltaY > 0 ? 0.87 : 1.15;
    const s = getScale();
    const [hxm, hym] = fromPixel(e.clientX, e.clientY);
    zoom *= factor;
    const s2 = getScale();
    viewCX = hxm - (e.clientX - W / 2) / s2;
    viewCY = hym + (e.clientY - H / 2) / s2;
  }, { passive: false });

  // ── Touch ─────────────────────────────────────────────────────────────────────
  let pinchDist = null, pinchMidX = 0, pinchMidY = 0;

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    if (e.touches.length === 1) {
      startDrag(e.touches[0].clientX, e.touches[0].clientY);
    } else if (e.touches.length === 2) {
      dragging = false;
      pinchDist = Math.hypot(
        e.touches[0].clientX - e.touches[1].clientX,
        e.touches[0].clientY - e.touches[1].clientY
      );
      pinchMidX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      pinchMidY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
    }
  }, { passive: false });

  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    if (e.touches.length === 1) {
      moveDrag(e.touches[0].clientX, e.touches[0].clientY);
    } else if (e.touches.length === 2 && pinchDist !== null) {
      const d   = Math.hypot(
        e.touches[0].clientX - e.touches[1].clientX,
        e.touches[0].clientY - e.touches[1].clientY
      );
      const mx  = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      const my  = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      const [hxm, hym] = fromPixel(mx, my);
      zoom *= d / pinchDist;
      const s2 = getScale();
      viewCX = hxm - (mx - W / 2) / s2;
      viewCY = hym + (my - H / 2) / s2;
      pinchDist = d;
    }
  }, { passive: false });

  canvas.addEventListener('touchend', () => { dragging = false; pinchDist = null; });

  // ── Right-click: reset view ──────────────────────────────────────────────────
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    viewCX = 0; viewCY = 0; zoom = 1;
    SFX.play('click');
  });

  // ── Reset button ─────────────────────────────────────────────────────────────
  document.getElementById('btn-reset').addEventListener('click', () => {
    a = 1.4; b = 0.3;
    viewCX = 0; viewCY = 0; zoom = 1;
    sliderA.value = '1.4'; valA.textContent = '1.400';
    sliderB.value = '0.3'; valB.textContent = '0.300';
    resetDensity();
    SFX.play('click');
  });

  // ── Init ─────────────────────────────────────────────────────────────────────
  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
