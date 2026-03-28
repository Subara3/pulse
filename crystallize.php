<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'CRYSTALLIZE - PULSE';
$pageDesc = '結晶成長シミュレーション。拡散律速凝集に六方対称を課したデンドライト構造の生成。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';
$extraStyle = '
body { overflow:hidden; cursor:crosshair; }
canvas {
  display:block; position:fixed; top:0; left:0;
  width:100%; height:100%; z-index:0;
  image-rendering:pixelated;
  image-rendering:crisp-edges;
}
.controls {
  position:fixed; bottom:1.5rem; right:1.5rem;
  display:flex; gap:0.5rem; flex-direction:column; align-items:flex-end; z-index:10;
}
.btn {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); padding:0.35rem 0.8rem;
  font-size:0.65rem; letter-spacing:0.15em; cursor:pointer;
  font-family:inherit; transition:all 0.2s;
}
.btn:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="controls">
  <button class="btn" id="btnMode">BRANCH</button>
  <button class="btn" id="btnReset">RESET</button>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  // ── Grid ──────────────────────────────────────────────────────
  const GSIZE = 340;
  const GCX = GSIZE >> 1, GCY = GSIZE >> 1;

  let frozen   = new Uint8Array(GSIZE * GSIZE);
  let frozenT  = new Uint32Array(GSIZE * GSIZE);
  let stepCount = 0;
  let iceCount  = 0;
  let maxRadius = 0;
  let growing   = true;
  let fadeAlpha = 0;

  // ── Morphology ───────────────────────────────────────────────
  const MODES = ['BRANCH', 'STELLAR', 'PLATE'];
  const STICK = [1.0, 0.50, 0.12];
  let modeIdx = 0;

  // ── Helpers ──────────────────────────────────────────────────
  const idx   = (x, y) => y * GSIZE + x;
  const valid = (x, y) => x >= 0 && x < GSIZE && y >= 0 && y < GSIZE;

  function isAdj(x, y) {
    for (let dy = -1; dy <= 1; dy++)
      for (let dx = -1; dx <= 1; dx++) {
        if (!dx && !dy) continue;
        const nx = x + dx, ny = y + dy;
        if (valid(nx, ny) && frozen[idx(nx, ny)]) return true;
      }
    return false;
  }

  // Freeze a cell and its 5 rotationally symmetric copies (6-fold symmetry)
  function freeze(x, y) {
    stepCount++;
    const dx = x - GCX, dy = y - GCY;
    const r  = Math.sqrt(dx * dx + dy * dy);
    if (r > maxRadius) maxRadius = r;

    for (let k = 0; k < 6; k++) {
      const a  = k * Math.PI / 3;
      const nx = Math.round(GCX + dx * Math.cos(a) - dy * Math.sin(a));
      const ny = Math.round(GCY + dx * Math.sin(a) + dy * Math.cos(a));
      if (valid(nx, ny)) {
        const i = idx(nx, ny);
        if (!frozen[i]) { frozen[i] = 1; frozenT[i] = stepCount; iceCount++; }
      }
    }
  }

  function reset() {
    frozen.fill(0); frozenT.fill(0);
    stepCount = 0; iceCount = 0; maxRadius = 0;
    growing = true; fadeAlpha = 0;
    freeze(GCX, GCY);
    for (let i = 0; i < 220; i++) stepWalker(); // pre-warm for immediate display
  }

  // ── DLA walker ───────────────────────────────────────────────
  function stepWalker() {
    if (maxRadius >= GCX - 10) { growing = false; return; }

    const p   = STICK[modeIdx];
    const spR = maxRadius + 3 + Math.random() * 6;
    const a0  = Math.random() * Math.PI * 2;
    let wx = GCX + spR * Math.cos(a0);
    let wy = GCY + spR * Math.sin(a0);
    const kR2 = (spR + 40) * (spR + 40);

    for (let s = 0; s < 5000; s++) {
      const ddx = wx - GCX, ddy = wy - GCY;
      if (ddx * ddx + ddy * ddy > kR2) return;

      const dist = Math.sqrt(ddx * ddx + ddy * ddy);
      const step = Math.min(8, Math.max(1, (dist - maxRadius - 1.5) | 0));
      const a    = Math.random() * Math.PI * 2;
      wx += step * Math.cos(a);
      wy += step * Math.sin(a);

      const ix = Math.round(wx), iy = Math.round(wy);
      if (!valid(ix, iy)) return;
      if (!frozen[idx(ix, iy)] && isAdj(ix, iy) && Math.random() < p) {
        freeze(ix, iy);
        SFX.play('tick', 80);
        return;
      }
    }
  }

  // ── Offscreen buffer ─────────────────────────────────────────
  const off  = document.createElement('canvas');
  off.width  = GSIZE;
  off.height = GSIZE;
  const octx = off.getContext('2d');

  // ── Render ───────────────────────────────────────────────────
  function render() {
    const W  = canvas.width, H = canvas.height;
    const sc = Math.min(W, H) / GSIZE;
    const ox = (W - GSIZE * sc) / 2;
    const oy = (H - GSIZE * sc) / 2;

    // Build pixel data
    const img = octx.createImageData(GSIZE, GSIZE);
    const d   = img.data;
    const mx  = stepCount || 1;

    for (let y = 0; y < GSIZE; y++)
      for (let x = 0; x < GSIZE; x++) {
        const i  = idx(x, y);
        const p4 = i * 4;
        if (frozen[i]) {
          const t = frozenT[i] / mx; // 0=old/center → 1=new/tip
          // purple (123,44,191) → teal (0,245,212)
          d[p4]   = Math.round(123 - 123 * t);
          d[p4+1] = Math.round( 44 + 201 * t);
          d[p4+2] = Math.round(191 +  21 * t);
          d[p4+3] = 255;
        } else {
          d[p4] = 10; d[p4+1] = 10; d[p4+2] = 15; d[p4+3] = 255;
        }
      }
    octx.putImageData(img, 0, 0);

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    const blurPx = Math.min(6, Math.round(sc * 2.5));
    ctx.filter      = `blur(${blurPx}px)`;
    ctx.globalAlpha = 0.55;
    ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);
    ctx.filter      = 'none';
    ctx.globalAlpha = 1.0;
    ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);

    // Fade overlay for auto-reset transition
    if (fadeAlpha > 0) {
      ctx.fillStyle = `rgba(10,10,15,${fadeAlpha})`;
      ctx.fillRect(0, 0, W, H);
    }
  }

  // ── Resize ───────────────────────────────────────────────────
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  window.addEventListener('resize', resize);
  resize();

  // ── Animation loop ───────────────────────────────────────────
  function animate() {
    if (!growing) {
      fadeAlpha = Math.min(1, fadeAlpha + 0.012);
      if (fadeAlpha >= 1) { reset(); }
    } else {
      for (let i = 0; i < 20; i++) stepWalker();
    }
    render();
    requestAnimationFrame(animate);
  }

  // ── Coordinate helper ────────────────────────────────────────
  function toGrid(clientX, clientY) {
    const sc = Math.min(canvas.width, canvas.height) / GSIZE;
    const ox = (canvas.width  - GSIZE * sc) / 2;
    const oy = (canvas.height - GSIZE * sc) / 2;
    return [Math.round((clientX - ox) / sc), Math.round((clientY - oy) / sc)];
  }

  // ── Events ───────────────────────────────────────────────────
  canvas.addEventListener('click', (e) => {
    const [gx, gy] = toGrid(e.clientX, e.clientY);
    if (valid(gx, gy) && !frozen[idx(gx, gy)]) {
      freeze(gx, gy);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  });

  canvas.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    reset();
    SFX.play('click');
  });

  canvas.addEventListener('touchend', (e) => {
    e.preventDefault();
    const t = e.changedTouches[0];
    const [gx, gy] = toGrid(t.clientX, t.clientY);
    if (valid(gx, gy) && !frozen[idx(gx, gy)]) {
      freeze(gx, gy);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  }, { passive: false });

  document.getElementById('btnMode').addEventListener('click', () => {
    modeIdx = (modeIdx + 1) % MODES.length;
    document.getElementById('btnMode').textContent = MODES[modeIdx];
    reset();
    SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', () => {
    reset();
    SFX.play('click');
  });

  // ── Start ────────────────────────────────────────────────────
  reset();
  requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
