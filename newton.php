<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('newton');

$pageName      = 'newton';
$pageTitle     = 'NEWTON - PULSE';
$pageDesc      = 'Newton fractal for z^n - 1. Click to zoom into basins of attraction and adjust the polynomial degree.';
$pageCanonical = 'https://pulse.subara3.com/newton.php';

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
  font-family: "Orbitron","Segoe UI",sans-serif;
  font-size: 0.62rem; color: rgba(255,255,255,0.3);
  line-height: 2; letter-spacing: 0.06em;
}
.hud .v  { color: #00f5d4; font-weight: 700; }
.ctrl-bar {
  position: fixed; bottom: 1.2rem; right: 1.2rem; z-index: 100;
  display: flex; gap: 0.5rem; flex-direction: column; align-items: flex-end;
}
.ctrl-btn {
  background: rgba(10,10,15,0.72); border: 1px solid rgba(0,245,212,0.25);
  color: rgba(255,255,255,0.7); font-family: "Orbitron",sans-serif;
  font-size: 0.58rem; letter-spacing: 0.08em;
  padding: 0.38rem 0.75rem; border-radius: 4px; cursor: pointer;
  backdrop-filter: blur(6px); transition: border-color 0.2s, color 0.2s;
  touch-action: manipulation;
}
.ctrl-btn:hover  { border-color: rgba(0,245,212,0.6); color: #00f5d4; }
.deg-bar {
  display: flex; gap: 0.4rem; align-items: center;
}
.deg-label {
  font-family: "Orbitron",sans-serif; font-size: 0.55rem;
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
  DEGREE:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudDeg"  class="v">3</span><br>
  ZOOM:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudZoom" class="v">1.0x</span><br>
  RENDER:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="hudPct"  class="v">0%</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="deg-bar">
    <button class="ctrl-btn" id="btnDm">DEG-</button>
    <span class="deg-label">DEGREE</span>
    <button class="ctrl-btn" id="btnDp">DEG+</button>
  </div>
  <button class="ctrl-btn" id="btnReset">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'zoom in'],
  ['type' => 'right', 'label' => 'zoom out'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  let W, H;
  let cx = 0, cy = 0, scale = 1.5; // complex plane: center and half-height
  let degree  = 3;
  const MAX_ITER = 64;
  const DEG_MIN  = 3;
  const DEG_MAX  = 8;
  const BASE_SCALE = 1.5; // reference for zoom display

  let imgData, pixels;
  let renderRow = 0;
  let rafId     = null;

  /* HSL → RGB  (h: 0-360, s/l: 0-100) */
  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  /* Build root colors spread across hsl 175 → 285 (teal to purple) */
  function makeRootColors() {
    const cols = [];
    for (let k = 0; k < degree; k++) {
      const hue = 175 + (k / degree) * 110;
      cols.push(hslToRgb(hue, 72, 52));
    }
    return cols;
  }

  /* Complex integer power z^n */
  function cpow(zr, zi, n) {
    let r = 1, i = 0;
    for (let k = 0; k < n; k++) {
      const nr = r * zr - i * zi;
      const ni = r * zi + i * zr;
      r = nr; i = ni;
    }
    return [r, i];
  }

  /* Newton step: z - (z^n - 1) / (n * z^(n-1)) */
  function newtonStep(zr, zi) {
    const [znr, zni]   = cpow(zr, zi, degree);
    const [zn1r, zn1i] = cpow(zr, zi, degree - 1);
    const fr = znr - 1, fi = zni;
    const dr = degree * zn1r, di = degree * zn1i;
    const denom = dr * dr + di * di;
    if (denom < 1e-14) return [zr, zi];
    const qr = (fr * dr + fi * di) / denom;
    const qi = (fi * dr - fr * di) / denom;
    return [zr - qr, zi - qi];
  }

  /* Iterate and return [rootIndex, iterationCount] */
  function iterate(zr0, zi0) {
    let zr = zr0, zi = zi0;
    for (let i = 0; i < MAX_ITER; i++) {
      for (let k = 0; k < degree; k++) {
        const angle = 2 * Math.PI * k / degree;
        const dx = zr - Math.cos(angle);
        const dy = zi - Math.sin(angle);
        if (dx * dx + dy * dy < 1e-6) return [k, i];
      }
      [zr, zi] = newtonStep(zr, zi);
    }
    for (let k = 0; k < degree; k++) {
      const angle = 2 * Math.PI * k / degree;
      const dx = zr - Math.cos(angle);
      const dy = zi - Math.sin(angle);
      if (dx * dx + dy * dy < 0.02) return [k, MAX_ITER];
    }
    return [-1, MAX_ITER];
  }

  /* Render 6 rows per animation frame */
  function renderChunk() {
    const ROWS   = 6;
    const cols   = makeRootColors();
    const aspect = W / H;

    for (let y = renderRow; y < Math.min(renderRow + ROWS, H); y++) {
      for (let x = 0; x < W; x++) {
        const zr = cx + (x / W - 0.5) * 2 * scale * aspect;
        const zi = cy + (y / H - 0.5) * 2 * scale;

        const [root, iter] = iterate(zr, zi);

        let r, g, b;
        if (root < 0) {
          r = g = b = 8;
        } else {
          const [cr, cg, cb] = cols[root % cols.length];
          const t = 1 - (iter / MAX_ITER) * 0.82;
          r = Math.round(cr * t);
          g = Math.round(cg * t);
          b = Math.round(cb * t);
        }

        const idx = (y * W + x) * 4;
        pixels[idx]     = r;
        pixels[idx + 1] = g;
        pixels[idx + 2] = b;
        pixels[idx + 3] = 255;
      }
    }

    ctx.putImageData(imgData, 0, 0);
    renderRow += ROWS;
    document.getElementById('hudPct').textContent =
      Math.min(100, Math.round(renderRow / H * 100)) + '%';

    if (renderRow < H) {
      rafId = requestAnimationFrame(renderChunk);
    }
  }

  function startRender() {
    if (rafId) cancelAnimationFrame(rafId);
    renderRow = 0;
    imgData   = ctx.createImageData(W, H);
    pixels    = imgData.data;
    document.getElementById('hudDeg').textContent  = degree;
    document.getElementById('hudZoom').textContent = (BASE_SCALE / scale).toFixed(1) + 'x';
    document.getElementById('hudPct').textContent  = '0%';
    rafId = requestAnimationFrame(renderChunk);
  }

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    startRender();
  }

  /* Zoom toward a point in screen coordinates */
  function zoomAt(px, py, factor) {
    const aspect = W / H;
    const zr = cx + (px / W - 0.5) * 2 * scale * aspect;
    const zi = cy + (py / H - 0.5) * 2 * scale;
    scale *= factor;
    cx = zr - (px / W - 0.5) * 2 * scale * aspect;
    cy = zi - (py / H - 0.5) * 2 * scale;
    startRender();
  }

  /* Mouse interactions */
  canvas.addEventListener('click', e => {
    if (e.button === 0) { zoomAt(e.clientX, e.clientY, 0.4); SFX.play('ripple', 80); }
  });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    zoomAt(e.clientX, e.clientY, 2.5);
    SFX.play('click');
  });

  /* Touch: single tap = zoom in, double tap = zoom out */
  let lastTap = 0;
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    const t   = e.changedTouches[0];
    const now = Date.now();
    if (now - lastTap < 320) {
      zoomAt(t.clientX, t.clientY, 2.5);
      SFX.play('click');
    } else {
      zoomAt(t.clientX, t.clientY, 0.4);
      SFX.play('ripple', 80);
    }
    lastTap = now;
  }, { passive: false });

  /* Buttons */
  document.getElementById('btnDm').addEventListener('click', () => {
    if (degree > DEG_MIN) { degree--; startRender(); SFX.play('toggle'); }
  });
  document.getElementById('btnDp').addEventListener('click', () => {
    if (degree < DEG_MAX) { degree++; startRender(); SFX.play('toggle'); }
  });
  document.getElementById('btnReset').addEventListener('click', () => {
    cx = 0; cy = 0; scale = 1.5; degree = 3;
    startRender();
    SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
