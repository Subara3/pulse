<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('magnetpendulum');

$pageName = 'magnetpendulum';
$pageTitle = 'MAGNETPENDULUM - PULSE';
$pageDesc = "3つの磁石が作る引力圏フラクタル。わずかな初期位置の差が全く異なる磁石へ向かう振り子を生む。";
$pageCanonical = 'https://pulse.subara3.com/magnetpendulum.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; display: block; }
.hud { position: fixed; top: 1rem; left: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: left; line-height: 1.8; z-index: 10; pointer-events: none; }
.hud .v0 { color: #00f5d4; }
.hud .v1 { color: #8c32d2; }
.hud .v2 { color: #4664e6; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .hud { font-size: 0.6rem; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>A <span class="v0">■</span></div>
  <div>B <span class="v1">■</span></div>
  <div>C <span class="v2">■</span></div>
  <div id="hStat" style="margin-top:0.5rem;font-size:0.6rem;color:rgba(255,255,255,0.22)"></div>
</div>
<div id="controls" class="ctrl drawer-controls">
  <button id="btnReset">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<div class="title">MAGNETPENDULUM</div>
<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'move magnet'],
  ['type' => 'click', 'label' => 'trace path'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');
  const hStat  = document.getElementById('hStat');
  let W = 0, H = 0;

  /* ── Coordinate system ───────────────────────────────────── */
  const RANGE_Y = 1.4; // world units from centre to top/bottom
  let PX = 1;          // pixels per world unit

  function s2w(sx, sy) {
    return [(sx - W * 0.5) / PX, (sy - H * 0.5) / PX];
  }
  function w2s(wx, wy) {
    return [wx * PX + W * 0.5, wy * PX + H * 0.5];
  }

  /* ── Physics constants ───────────────────────────────────── */
  const DAMP  = 0.25;   // velocity damping
  const SPR   = 0.5;    // pendulum restoring spring
  const MSTR  = 1.2;    // magnet strength
  const MH2   = 0.05;   // (magnet height)² — keeps force finite at centre
  const DT    = 0.06;   // Euler time step
  const MAX_I = 200;    // max integration steps
  const CONV2 = 5e-4;   // convergence threshold (speed²)

  /* ── Magnets ─────────────────────────────────────────────── */
  // Teal (hue 172), Purple (hue 272), Blue-violet (hue 230 – transition zone)
  const INIT_M = [
    { x:  0,      y: -0.55  },
    { x:  0.476,  y:  0.275 },
    { x: -0.476,  y:  0.275 },
  ];
  const MCOL = [[0, 245, 212], [140, 50, 210], [70, 100, 230]];
  const MCSS = ['#00f5d4', '#8c32d2', '#4664e6'];
  let mags = INIT_M.map(m => ({ ...m }));

  /* ── Simulation ──────────────────────────────────────────── */
  function simPt(x0, y0) {
    let x = x0, y = y0, vx = 0, vy = 0;
    for (let i = 0; i < MAX_I; i++) {
      let ax = -DAMP * vx - SPR * x;
      let ay = -DAMP * vy - SPR * y;
      for (let j = 0; j < 3; j++) {
        const dx = mags[j].x - x, dy = mags[j].y - y;
        const r2 = dx * dx + dy * dy + MH2;
        const f  = MSTR / (r2 * Math.sqrt(r2));
        ax += f * dx;  ay += f * dy;
      }
      vx += ax * DT;  vy += ay * DT;
      x  += vx * DT;  y  += vy * DT;
      if (i > 30 && vx * vx + vy * vy < CONV2) return [nearM(x, y), i];
    }
    return [nearM(x, y), MAX_I];
  }

  function nearM(x, y) {
    let b = 0, md = Infinity;
    for (let j = 0; j < 3; j++) {
      const d = (x - mags[j].x) ** 2 + (y - mags[j].y) ** 2;
      if (d < md) { md = d; b = j; }
    }
    return b;
  }

  /* ── Progressive rendering ───────────────────────────────── */
  let imd = null, rS = 4, rI = 0, rW = 0, rH = 0;
  let rDone = false, rRestart = false;

  function startRender() {
    rS = 4;  rI = 0;
    rW = Math.ceil(W / rS);  rH = Math.ceil(H / rS);
    imd = ctx.createImageData(W, H);
    const d = imd.data;
    for (let i = 0; i < d.length; i += 4) { d[i] = 10; d[i+1] = 10; d[i+2] = 15; d[i+3] = 255; }
    rDone = false;  rRestart = false;
    if (hStat) hStat.textContent = 'RENDERING...';
  }

  function renderBatch() {
    if (rRestart) { startRender(); return; }
    if (rDone)    return;

    const BATCH = rS === 4 ? 600 : 500;
    const total = rW * rH;
    const end   = Math.min(rI + BATCH, total);
    const d = imd.data;
    const s = rS;

    for (let i = rI; i < end; i++) {
      const gx = i % rW, gy = (i / rW) | 0;
      const px = gx * s,  py = gy * s;
      const [wx, wy] = s2w(px + s * 0.5, py + s * 0.5);
      const [m, iter] = simPt(wx, wy);
      const c = MCOL[m];
      const t = 0.35 + 0.65 * (1 - iter / MAX_I); // darker near boundaries
      const r = (c[0] * t) | 0, g = (c[1] * t) | 0, b = (c[2] * t) | 0;
      for (let dy = 0; dy < s; dy++) {
        for (let dx = 0; dx < s; dx++) {
          const ppx = px + dx, ppy = py + dy;
          if (ppx < W && ppy < H) {
            const idx = (ppy * W + ppx) * 4;
            d[idx] = r;  d[idx+1] = g;  d[idx+2] = b;
          }
        }
      }
    }

    rI = end;
    if (hStat) hStat.textContent = (rS === 4 ? 'RENDERING ' : 'REFINING ') + ((rI / total * 100) | 0) + '%';

    if (rI >= total) {
      if (rS > 2) {
        rS = 2;  rI = 0;
        rW = Math.ceil(W / rS);  rH = Math.ceil(H / rS);
      } else {
        rDone = true;
        if (hStat) hStat.textContent = '';
      }
    }
  }

  /* ── Pendulum trace ──────────────────────────────────────── */
  let trace = null;

  function computeTrace(x0, y0) {
    const pts = [[x0, y0]];
    let x = x0, y = y0, vx = 0, vy = 0;
    for (let i = 0; i < MAX_I; i++) {
      let ax = -DAMP * vx - SPR * x, ay = -DAMP * vy - SPR * y;
      for (let j = 0; j < 3; j++) {
        const dx = mags[j].x - x, dy = mags[j].y - y;
        const r2 = dx * dx + dy * dy + MH2;
        const f  = MSTR / (r2 * Math.sqrt(r2));
        ax += f * dx;  ay += f * dy;
      }
      vx += ax * DT;  vy += ay * DT;
      x  += vx * DT;  y  += vy * DT;
      if (i % 2 === 0) pts.push([x, y]);
      if (i > 30 && vx * vx + vy * vy < CONV2) break;
    }
    return { pts, mag: nearM(x, y), t0: performance.now(), played: false };
  }

  /* ── Overlay drawing ─────────────────────────────────────── */
  function drawOverlay(ts) {
    // Magnets
    for (let j = 0; j < 3; j++) {
      const [sx, sy] = w2s(mags[j].x, mags[j].y);
      const col = MCSS[j];

      // Soft glow
      ctx.save();
      ctx.globalAlpha = 0.18;
      ctx.beginPath();
      ctx.arc(sx, sy, 24, 0, Math.PI * 2);
      ctx.fillStyle = col;
      ctx.fill();
      ctx.restore();

      // Pulsing outer ring (3 magnets phase-offset by 120°)
      const pulse = 0.5 + 0.5 * Math.sin(ts * 0.003 + j * 2.094);
      ctx.beginPath();
      ctx.arc(sx, sy, 13 + pulse * 5, 0, Math.PI * 2);
      ctx.strokeStyle = col;
      ctx.globalAlpha = 0.15 + 0.25 * pulse;
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.globalAlpha = 1;

      // Solid core
      ctx.beginPath();
      ctx.arc(sx, sy, 7, 0, Math.PI * 2);
      ctx.fillStyle = col;
      ctx.fill();
    }

    // Trace animation
    if (trace) {
      const dur = 3.0;
      const p   = Math.min((ts - trace.t0) / 1000 / dur, 1);
      const n   = trace.pts.length;
      const idx = Math.floor(p * (n - 1));
      const col = MCSS[trace.mag];

      // Fading trail
      ctx.lineWidth = 1.5;
      for (let k = 1; k <= idx; k++) {
        const alpha = 0.08 + 0.92 * (k / n);
        const [x1, y1] = w2s(trace.pts[k-1][0], trace.pts[k-1][1]);
        const [x2, y2] = w2s(trace.pts[k][0],   trace.pts[k][1]);
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.strokeStyle = col;
        ctx.globalAlpha = alpha * 0.75;
        ctx.stroke();
      }
      ctx.globalAlpha = 1;

      // Moving bob
      if (idx < n) {
        const [bx, by] = w2s(trace.pts[idx][0], trace.pts[idx][1]);
        ctx.beginPath();
        ctx.arc(bx, by, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.beginPath();
        ctx.arc(bx, by, 8, 0, Math.PI * 2);
        ctx.strokeStyle = col;
        ctx.globalAlpha = 0.75;
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.globalAlpha = 1;
      }

      if (p >= 1) {
        if (!trace.played) { trace.played = true; SFX.play('success'); }
        if (ts - trace.t0 > dur * 1000 + 2500) trace = null;
      }
    }
  }

  /* ── Event handling ──────────────────────────────────────── */
  let dragMag = -1;

  function screenPos(e) {
    if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    return { x: e.clientX, y: e.clientY };
  }

  function hitMag(sx, sy) {
    for (let j = 0; j < 3; j++) {
      const [mx, my] = w2s(mags[j].x, mags[j].y);
      if ((sx - mx) ** 2 + (sy - my) ** 2 < 625) return j; // 25 px radius
    }
    return -1;
  }

  canvas.addEventListener('mousemove', e => {
    const { x, y } = screenPos(e);
    canvas.style.cursor = hitMag(x, y) >= 0 ? 'grab' : (dragMag >= 0 ? 'grabbing' : 'crosshair');
    if (dragMag < 0) return;
    const [wx, wy] = s2w(x, y);
    mags[dragMag].x = wx;  mags[dragMag].y = wy;
    rRestart = true;
    SFX.play('move', 50);
  });

  canvas.addEventListener('mousedown', e => {
    if (e.button === 2) return;
    const { x, y } = screenPos(e);
    const j = hitMag(x, y);
    if (j >= 0) {
      dragMag = j;
      canvas.style.cursor = 'grabbing';
    } else {
      const [wx, wy] = s2w(x, y);
      trace = computeTrace(wx, wy);
      SFX.play('spawn');
    }
  });

  canvas.addEventListener('mouseup', () => {
    if (dragMag >= 0) SFX.play('click');
    dragMag = -1;
  });
  canvas.addEventListener('mouseleave', () => { dragMag = -1; });

  canvas.addEventListener('contextmenu', e => { e.preventDefault(); reset(); });

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const { x, y } = screenPos(e);
    const j = hitMag(x, y);
    if (j >= 0) {
      dragMag = j;
    } else {
      const [wx, wy] = s2w(x, y);
      trace = computeTrace(wx, wy);
      SFX.play('spawn');
    }
  }, { passive: false });

  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    if (dragMag < 0) return;
    const { x, y } = screenPos(e);
    const [wx, wy] = s2w(x, y);
    mags[dragMag].x = wx;  mags[dragMag].y = wy;
    rRestart = true;
    SFX.play('move', 50);
  }, { passive: false });

  canvas.addEventListener('touchend', () => {
    if (dragMag >= 0) SFX.play('click');
    dragMag = -1;
  });

  function reset() {
    mags = INIT_M.map(m => ({ ...m }));
    trace = null;
    rRestart = true;
    SFX.play('toggle');
  }

  document.getElementById('btnReset').addEventListener('click', reset);

  /* ── Resize & main loop ──────────────────────────────────── */
  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    PX = H / (2 * RANGE_Y);
    startRender();
  }
  window.addEventListener('resize', resize);

  function loop(ts) {
    renderBatch();
    if (imd) ctx.putImageData(imd, 0, 0);
    drawOverlay(ts);
    requestAnimationFrame(loop);
  }

  resize();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
