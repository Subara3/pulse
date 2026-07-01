<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('vortex');

$pageName = 'vortex';
$pageTitle = 'VORTEX - PULSE';
$pageDesc = '点渦の流体力学。クリックで渦を追加すると、トレーサーが流れ場に乗ってらせんを描く。ヘルムホルツ渦定理の可視化。';
$pageCanonical = 'https://pulse.subara3.com/vortex.php';
$extraStyle = '
#hud { position:fixed; top:16px; right:16px; font-family:monospace; font-size:11px;
       color:rgba(0,245,212,0.7); text-align:right; pointer-events:none; line-height:1.8; }
#hud .val { color:#00f5d4; }
#controls { position:fixed; bottom:20px; right:16px; display:flex; flex-direction:column;
            align-items:flex-end; gap:6px; }
.ctrl-btn { background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.3);
            color:#00f5d4; font-family:monospace; font-size:11px; padding:5px 10px;
            cursor:pointer; transition:background 0.15s; }
.ctrl-btn:hover { background:rgba(0,245,212,0.18); }
canvas { display:block; cursor:crosshair; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  VORTICES <span class="val" id="hud-count">3</span> / 12<br>
  TRACERS <span class="val" id="hud-tracers">500</span><br>
  <span style="color:rgba(0,245,212,0.45)">CCW <span style="color:#00f5d4">■</span>  CW <span style="color:#7b2cbf">■</span></span>
</div>

<div id="controls" class="drawer-controls">
  <button class="ctrl-btn" id="btn-reset">RESET</button>
  <button class="ctrl-btn" id="btn-clear">CLEAR ALL</button>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add vortex'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudCount = document.getElementById('hud-count');
  const hudTracers = document.getElementById('hud-tracers');

  let W, H, cx, cy;

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2; cy = H / 2;
  }
  window.addEventListener('resize', () => { resize(); initTracers(); });
  resize();

  /* ---- physics constants ---- */
  const GAMMA_BASE = 160000; // px²/s  (circulation strength)
  const EPS        = 1500;   // px²    (softening to avoid singularity)
  const TRACER_N   = 500;
  const TRACER_LIFE_MIN = 5000; // ms
  const TRACER_LIFE_RNG = 4000;
  const MAX_VORTICES = 12;
  const DT_CAP = 32; // ms – cap frame dt

  /* ---- vortex state ---- */
  let vortices = [];
  let nextSign = 1;

  /* ---- tracer state ---- */
  let tracers = [];

  /* ---- velocity field ---- */
  function velAt(px, py, skipIdx) {
    let u = 0, v = 0;
    for (let i = 0; i < vortices.length; i++) {
      if (i === skipIdx) continue;
      const vx = vortices[i];
      const dx = px - vx.x;
      const dy = py - vx.y;
      const r2 = dx * dx + dy * dy + EPS;
      const f  = vx.gamma / (6.2831853 * r2);
      u -= f * dy;
      v += f * dx;
    }
    return { u, v };
  }

  /* ---- RK4 velocity at arbitrary positions ---- */
  function velAtPos(pxs, pys, idx) {
    let u = 0, v = 0;
    for (let j = 0; j < vortices.length; j++) {
      if (j === idx) continue;
      const dx = pxs[idx] - pxs[j];
      const dy = pys[idx] - pys[j];
      const r2 = dx * dx + dy * dy + EPS;
      const f  = vortices[j].gamma / (6.2831853 * r2);
      u -= f * dy;
      v += f * dx;
    }
    return { u, v };
  }

  /* ---- update ---- */
  function update(dt) {
    const s = dt / 1000; // seconds
    const N = vortices.length;

    /* RK4 for vortices */
    if (N > 0) {
      const xs = new Float64Array(N), ys = new Float64Array(N);
      for (let i = 0; i < N; i++) { xs[i] = vortices[i].x; ys[i] = vortices[i].y; }

      function kv(pxs, pys) {
        const ku = new Float64Array(N), kv2 = new Float64Array(N);
        for (let i = 0; i < N; i++) {
          let u = 0, v2 = 0;
          for (let j = 0; j < N; j++) {
            if (j === i) continue;
            const dx = pxs[i] - pxs[j];
            const dy = pys[i] - pys[j];
            const r2 = dx * dx + dy * dy + EPS;
            const f  = vortices[j].gamma / (6.2831853 * r2);
            u  -= f * dy;
            v2 += f * dx;
          }
          ku[i] = u; kv2[i] = v2;
        }
        return { ku, kv: kv2 };
      }

      const { ku: k1u, kv: k1v } = kv(xs, ys);
      const xs2 = xs.map((x, i) => x + k1u[i] * s * 0.5);
      const ys2 = ys.map((y, i) => y + k1v[i] * s * 0.5);
      const { ku: k2u, kv: k2v } = kv(xs2, ys2);
      const xs3 = xs.map((x, i) => x + k2u[i] * s * 0.5);
      const ys3 = ys.map((y, i) => y + k2v[i] * s * 0.5);
      const { ku: k3u, kv: k3v } = kv(xs3, ys3);
      const xs4 = xs.map((x, i) => x + k3u[i] * s);
      const ys4 = ys.map((y, i) => y + k3v[i] * s);
      const { ku: k4u, kv: k4v } = kv(xs4, ys4);

      for (let i = 0; i < N; i++) {
        vortices[i].x += (s / 6) * (k1u[i] + 2 * k2u[i] + 2 * k3u[i] + k4u[i]);
        vortices[i].y += (s / 6) * (k1v[i] + 2 * k2v[i] + 2 * k3v[i] + k4v[i]);
      }
    }

    /* Euler for tracers */
    for (const t of tracers) {
      const { u, v } = velAt(t.x, t.y, -1);
      t.x += u * s;
      t.y += v * s;
      t.age += dt;
      if (t.age > t.life || t.x < -20 || t.x > W + 20 || t.y < -20 || t.y > H + 20) {
        respawnTracer(t);
      }
    }
  }

  function respawnTracer(t) {
    t.x = Math.random() * W;
    t.y = Math.random() * H;
    t.age = 0;
    t.life = TRACER_LIFE_MIN + Math.random() * TRACER_LIFE_RNG;
  }

  /* ---- draw ---- */
  function draw() {
    // Persistent fade overlay – tail length ≈ 2.5 s at 60fps
    ctx.fillStyle = 'rgba(10,10,15,0.18)';
    ctx.fillRect(0, 0, W, H);

    // Tracers
    for (const t of tracers) {
      const frac = t.age / t.life;
      const alpha = (1 - frac) * 0.85;
      if (alpha < 0.01) continue;

      // Color: blend teal → purple based on nearest vortex sign
      let near = null, nearD = Infinity;
      for (const vx of vortices) {
        const d = (t.x - vx.x) ** 2 + (t.y - vx.y) ** 2;
        if (d < nearD) { nearD = d; near = vx; }
      }
      const c = near && near.gamma < 0 ? `rgba(123,44,191,${alpha})` : `rgba(0,245,212,${alpha})`;
      ctx.fillStyle = c;
      ctx.fillRect(t.x - 0.5, t.y - 0.5, 1, 1);
    }

    // Vortices
    for (const vx of vortices) {
      const isPos = vx.gamma > 0;
      const r = 9;
      const [cr, cg, cb] = isPos ? [0, 245, 212] : [123, 44, 191];

      // Glow
      const g = ctx.createRadialGradient(vx.x, vx.y, 0, vx.x, vx.y, r * 5);
      g.addColorStop(0, `rgba(${cr},${cg},${cb},0.35)`);
      g.addColorStop(1, `rgba(${cr},${cg},${cb},0)`);
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(vx.x, vx.y, r * 5, 0, 6.2832);
      ctx.fill();

      // Core disc
      ctx.beginPath();
      ctx.arc(vx.x, vx.y, r, 0, 6.2832);
      ctx.fillStyle = `rgb(${cr},${cg},${cb})`;
      ctx.fill();

      // Rotation arc indicator
      const arcEnd = isPos ? Math.PI * 1.5 : -Math.PI * 1.5;
      ctx.beginPath();
      ctx.arc(vx.x, vx.y, r + 5, -0.3, arcEnd - 0.3, !isPos);
      ctx.strokeStyle = `rgba(${cr},${cg},${cb},0.7)`;
      ctx.lineWidth = 1.5;
      ctx.stroke();

      // Arrow tip
      const tipAngle = isPos ? Math.PI * 1.5 - 0.3 : -Math.PI * 1.5 - 0.3;
      const tx = vx.x + (r + 5) * Math.cos(tipAngle);
      const ty = vx.y + (r + 5) * Math.sin(tipAngle);
      const ta = isPos ? tipAngle + Math.PI * 0.5 : tipAngle - Math.PI * 0.5;
      ctx.beginPath();
      ctx.moveTo(tx + 3 * Math.cos(ta), ty + 3 * Math.sin(ta));
      ctx.lineTo(tx - 3 * Math.cos(ta - 0.5), ty - 3 * Math.sin(ta - 0.5));
      ctx.lineTo(tx - 3 * Math.cos(ta + 0.5), ty - 3 * Math.sin(ta + 0.5));
      ctx.closePath();
      ctx.fillStyle = `rgba(${cr},${cg},${cb},0.9)`;
      ctx.fill();
    }
  }

  /* ---- init ---- */
  function initVortices() {
    const r = Math.min(W, H) * 0.2;
    vortices = [
      { x: cx - r,       y: cy,       gamma:  GAMMA_BASE },
      { x: cx + r,       y: cy,       gamma:  GAMMA_BASE },
      { x: cx,           y: cy + r,   gamma: -GAMMA_BASE * 1.3 },
    ];
    nextSign = 1;
  }

  function initTracers() {
    tracers = [];
    for (let i = 0; i < TRACER_N; i++) {
      tracers.push({
        x: Math.random() * W,
        y: Math.random() * H,
        age: Math.random() * TRACER_LIFE_MIN,
        life: TRACER_LIFE_MIN + Math.random() * TRACER_LIFE_RNG
      });
    }
  }

  function reset() {
    ctx.clearRect(0, 0, W, H);
    initVortices();
    initTracers();
    SFX.play('click');
  }

  function clearAll() {
    ctx.clearRect(0, 0, W, H);
    vortices = [];
    initTracers();
    SFX.play('click');
  }

  /* ---- main loop ---- */
  let lastTime = null;
  function loop(ts) {
    if (lastTime === null) lastTime = ts;
    const dt = Math.min(ts - lastTime, DT_CAP);
    lastTime = ts;

    update(dt);
    draw();

    hudCount.textContent = vortices.length;
    requestAnimationFrame(loop);
  }

  /* ---- event handlers ---- */
  function addVortex(x, y) {
    if (vortices.length >= MAX_VORTICES) return;
    vortices.push({ x, y, gamma: nextSign * GAMMA_BASE });
    nextSign *= -1;
    SFX.play('spawn');
  }

  function removeNearest(x, y) {
    if (vortices.length === 0) return;
    let minD = Infinity, minI = -1;
    vortices.forEach((v, i) => {
      const d = (x - v.x) ** 2 + (y - v.y) ** 2;
      if (d < minD) { minD = d; minI = i; }
    });
    if (minI >= 0 && Math.sqrt(minD) < 80) {
      vortices.splice(minI, 1);
      SFX.play('pop');
    }
  }

  canvas.addEventListener('click', e => addVortex(e.clientX, e.clientY));
  canvas.addEventListener('contextmenu', e => { e.preventDefault(); removeNearest(e.clientX, e.clientY); });

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    for (const t of e.changedTouches) addVortex(t.clientX, t.clientY);
  }, { passive: false });

  canvas.addEventListener('touchend', e => e.preventDefault(), { passive: false });

  document.getElementById('btn-reset').addEventListener('click', reset);
  document.getElementById('btn-clear').addEventListener('click', clearAll);

  /* ---- start ---- */
  initVortices();
  initTracers();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
