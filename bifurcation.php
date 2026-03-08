<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('bifurcation');

$pageName      = 'bifurcation';
$pageTitle     = 'BIFURCATION - PULSE';
$pageDesc      = 'ロジスティック写像の分岐図。パラメータrの増加とともに周期倍加が起きカオスへ至る。ズームすると自己相似構造が現れる。';
$pageCanonical = 'https://pulse.subara3.com/bifurcation.php';

$extraStyle = '
body { background:#0a0a0f; margin:0; overflow:hidden; }
#canvas { position:fixed; inset:0; width:100%; height:100%; cursor:crosshair; }
#hud {
  position:fixed; bottom:1.2rem; left:1.2rem; z-index:100;
  pointer-events:none;
  font-family:"Courier New",monospace;
  font-size:0.68rem; line-height:2.0; letter-spacing:0.04em;
}
#hud .lbl { color:rgba(255,255,255,0.28); margin-right:0.4em; }
#hud .val { color:#00f5d4; }
#ctrl {
  position:fixed; bottom:1.2rem; right:1.2rem; z-index:100;
  display:flex; flex-direction:column; gap:0.4rem; align-items:flex-end;
}
.cbtn {
  background:rgba(0,245,212,0.07);
  border:1px solid rgba(0,245,212,0.25);
  color:#00f5d4;
  font-family:"Orbitron",sans-serif;
  font-size:0.6rem; letter-spacing:0.07em;
  padding:0.35rem 0.75rem; cursor:pointer;
  transition:background 0.15s;
}
.cbtn:hover { background:rgba(0,245,212,0.18); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'zoom in'],
  ['type' => 'right', 'label' => 'zoom out'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="hud">
  <div><span class="lbl">r</span><span class="val" id="r-range">2.5000 – 4.0000</span></div>
  <div><span class="lbl">x</span><span class="val" id="x-range">0.0000 – 1.0000</span></div>
  <div><span class="lbl">zoom</span><span class="val" id="zoom-val">1×</span></div>
</div>
<div id="ctrl">
  <button class="cbtn" id="btn-reset">RESET</button>
</div>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  const INIT = { rMin: 2.5, rMax: 4.0, xMin: 0.0, xMax: 1.0 };
  let view = { ...INIT };
  let offscreen = null;
  let dirty     = true;
  let mx = -1, my = -1;

  /* ── resize ──────────────────────────────────────────── */
  function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
    dirty = true;
  }
  window.addEventListener('resize', resize);
  resize();

  /* ── render bifurcation diagram to offscreen ─────────── */
  function buildDiagram() {
    const W = canvas.width, H = canvas.height;
    offscreen = document.createElement('canvas');
    offscreen.width  = W;
    offscreen.height = H;
    const oc = offscreen.getContext('2d');

    const imgData = oc.createImageData(W, H);
    const data    = imgData.data;
    const density = new Float32Array(W * H);

    const WARMUP = 400;
    const PLOT   = 400;
    const { rMin, rMax, xMin, xMax } = view;
    const rSpan = rMax - rMin;
    const xSpan = xMax - xMin;

    for (let px = 0; px < W; px++) {
      const r = rMin + (px / W) * rSpan;
      if (r <= 0 || r > 4) continue;

      /* multiple seeds to fill density gaps at high zoom */
      const seeds = (rSpan < 0.05) ? 5 : 1;
      for (let s = 0; s < seeds; s++) {
        let x = 0.3 + s * 0.1;
        for (let i = 0; i < WARMUP; i++) x = r * x * (1 - x);
        for (let i = 0; i < PLOT; i++) {
          x = r * x * (1 - x);
          if (x < xMin || x > xMax) continue;
          const py = Math.floor(H - ((x - xMin) / xSpan) * H);
          if (py < 0 || py >= H) continue;
          density[py * W + px] += 1;
        }
      }
    }

    /* colorize: low density → teal dim, high → teal bright, hot → purple */
    let maxD = 0;
    for (let i = 0; i < density.length; i++) if (density[i] > maxD) maxD = density[i];
    const inv = maxD > 0 ? 1 / maxD : 1;

    for (let i = 0; i < W * H; i++) {
      if (density[i] === 0) continue;
      const t   = density[i] * inv;
      const idx = i * 4;
      if (t < 0.5) {
        /* teal */
        const k = t * 2;
        data[idx]     = Math.round(0   * k);
        data[idx + 1] = Math.round(245 * k);
        data[idx + 2] = Math.round(212 * k);
        data[idx + 3] = Math.round(200 * k + 55);
      } else {
        /* teal → white at very high density */
        const k = (t - 0.5) * 2;
        data[idx]     = Math.round(0   + 255 * k);
        data[idx + 1] = Math.round(245 + 10  * k);
        data[idx + 2] = Math.round(212 + 43  * k);
        data[idx + 3] = 255;
      }
    }
    oc.putImageData(imgData, 0, 0);
    dirty = false;
  }

  /* ── draw frame ──────────────────────────────────────── */
  function drawFrame() {
    if (dirty) buildDiagram();
    const W = canvas.width, H = canvas.height;

    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    if (offscreen) ctx.drawImage(offscreen, 0, 0);

    /* crosshair */
    if (mx >= 0 && mx < W && my >= 0 && my < H) {
      ctx.strokeStyle = 'rgba(0,245,212,0.35)';
      ctx.lineWidth   = 1;
      ctx.setLineDash([3, 5]);
      ctx.beginPath();
      ctx.moveTo(mx, 0); ctx.lineTo(mx, H);
      ctx.moveTo(0, my); ctx.lineTo(W, my);
      ctx.stroke();
      ctx.setLineDash([]);

      const r = view.rMin + (mx / W) * (view.rMax - view.rMin);
      const x = view.xMin + (1 - my / H) * (view.xMax - view.xMin);
      ctx.font      = '11px "Courier New",monospace';
      ctx.fillStyle = 'rgba(0,245,212,0.85)';
      const tx = mx + 10 < W - 90 ? mx + 10 : mx - 95;
      const ty = my - 10 > 14    ? my - 10  : my + 20;
      ctx.fillText(`r=${r.toFixed(5)}`, tx, ty);
      ctx.fillText(`x=${x.toFixed(5)}`, tx, ty + 15);
    }

    updateHUD();
    requestAnimationFrame(drawFrame);
  }

  /* ── HUD ─────────────────────────────────────────────── */
  function updateHUD() {
    const zoom = (INIT.rMax - INIT.rMin) / (view.rMax - view.rMin);
    document.getElementById('r-range').textContent =
      `${view.rMin.toFixed(5)} – ${view.rMax.toFixed(5)}`;
    document.getElementById('x-range').textContent =
      `${view.xMin.toFixed(5)} – ${view.xMax.toFixed(5)}`;
    document.getElementById('zoom-val').textContent =
      `${zoom >= 1 ? Math.round(zoom) : zoom.toFixed(2)}×`;
  }

  /* ── zoom helper ─────────────────────────────────────── */
  function zoomAround(cx, cy, factor) {
    const W = canvas.width, H = canvas.height;
    const r = view.rMin + (cx / W) * (view.rMax - view.rMin);
    const x = view.xMin + (1 - cy / H) * (view.xMax - view.xMin);
    const dr = (view.rMax - view.rMin) / factor / 2;
    const dx = (view.xMax - view.xMin) / factor / 2;
    view = {
      rMin: Math.max(-0.1, r - dr), rMax: Math.min(4.05, r + dr),
      xMin: Math.max(-0.05, x - dx), xMax: Math.min(1.05, x + dx),
    };
    dirty = true;
  }

  /* ── events ──────────────────────────────────────────── */
  canvas.addEventListener('click', e => {
    zoomAround(e.clientX, e.clientY, 3);
    SFX.play('spawn');
  });

  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    zoomAround(e.clientX, e.clientY, 1 / 3);
    SFX.play('toggle');
  });

  canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 2 : 0.5;
    zoomAround(e.clientX, e.clientY, factor);
    SFX.play('spawn', 80);
  }, { passive: false });

  canvas.addEventListener('mousemove', e => { mx = e.clientX; my = e.clientY; });
  canvas.addEventListener('mouseleave', ()=> { mx = my = -1; });

  /* touch: tap = zoom in */
  let lastTap = 0;
  canvas.addEventListener('touchend', e => {
    e.preventDefault();
    const now = Date.now();
    const t   = e.changedTouches[0];
    const factor = (now - lastTap < 350) ? 1 / 3 : 3;
    lastTap = now;
    zoomAround(t.clientX, t.clientY, factor);
    SFX.play('spawn');
  }, { passive: false });

  document.getElementById('btn-reset').addEventListener('click', () => {
    view  = { ...INIT };
    dirty = true;
    SFX.play('click');
  });

  drawFrame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
