<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fluidveil');

$pageName = 'fluidveil';
$pageTitle = 'FLUID VEIL - PULSE';
$pageDesc = '流体シミュレーション。指先で流れを作り、光るインクの渦をゆっくり眺める。';
$pageCanonical = 'https://pulse.subara3.com/fluidveil.php';
$extraStyle = '
#canvas { position: fixed; inset: 0; width: 100vw; height: 100vh; display: block; touch-action: none; }
.hud, .controls, .info { position: fixed; z-index: 10; background: rgba(10,10,15,0.55); border: 1px solid rgba(0,245,212,0.25); backdrop-filter: blur(8px); border-radius: 12px; color: #d9fff9; }
.hud { top: 16px; right: 16px; padding: 10px 12px; min-width: 150px; }
.controls { right: 16px; bottom: 16px; padding: 12px; width: min(280px, calc(100vw - 32px)); }
.info { left: 16px; bottom: 16px; padding: 10px 12px; max-width: min(360px, calc(100vw - 32px)); }
.hud .v { color: #00f5d4; font-weight: 700; }
.controls label { display: block; font-size: 12px; margin: 8px 0 2px; color: #bde7ff; }
.controls input[type=range] { width: 100%; }
.controls button { margin-top: 10px; width: 100%; background: rgba(0,245,212,0.12); color: #d8fff6; border: 1px solid rgba(0,245,212,0.35); border-radius: 10px; padding: 8px 10px; }
.controls button:active { background: rgba(0,245,212,0.24); }
.info p { margin: 0; font-size: 12px; line-height: 1.5; color: #c8d7f7; }
@media (max-width: 768px) {
  .hud { top: 12px; right: 12px; }
  .controls { right: 12px; bottom: 12px; }
  .info { left: 12px; bottom: 148px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud" id="hud">
  <div>speed <span class="v" id="speedVal">0.00</span></div>
  <div>energy <span class="v" id="energyVal">0.00</span></div>
  <div>swirls <span class="v" id="swirlVal">0</span></div>
</div>
<div class="controls">
  <label for="force">drag force</label>
  <input id="force" type="range" min="0.3" max="3.0" value="1.4" step="0.1">
  <label for="diff">ink diffusion</label>
  <input id="diff" type="range" min="0.00005" max="0.0012" value="0.00035" step="0.00005">
  <label for="visc">viscosity</label>
  <input id="visc" type="range" min="0.00001" max="0.0008" value="0.00015" step="0.00001">
  <button id="resetBtn" type="button">reset flow</button>
</div>
<div class="info">
  <p>
    Particles follow streamlines and leave fading tails.<br>
    Drag to inject vortices. Leave it still and viscosity slowly calms the flow.
  </p>
</div>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d', { alpha: false });
  const speedVal = document.getElementById('speedVal');
  const energyVal = document.getElementById('energyVal');
  const swirlVal = document.getElementById('swirlVal');

  const forceInput = document.getElementById('force');
  const diffInput = document.getElementById('diff');
  const viscInput = document.getElementById('visc');
  const resetBtn = document.getElementById('resetBtn');

  const simW = 120;
  const simH = 72;
  const size = simW * simH;

  let dpr = Math.min(2, window.devicePixelRatio || 1);

  const vx = new Float32Array(size);
  const vy = new Float32Array(size);
  const vx0 = new Float32Array(size);
  const vy0 = new Float32Array(size);
  const dye = new Float32Array(size);
  const dye0 = new Float32Array(size);

  const particleCount = 2600;
  const particles = new Array(particleCount).fill(0).map(() => ({
    x: Math.random() * simW,
    y: Math.random() * simH,
    life: Math.random()
  }));

  const pointers = new Map();

  function idx(x, y) {
    x = Math.max(0, Math.min(simW - 1, x));
    y = Math.max(0, Math.min(simH - 1, y));
    return x + y * simW;
  }

  function setBounds(arr, b) {
    for (let x = 1; x < simW - 1; x++) {
      arr[idx(x, 0)] = b === 2 ? -arr[idx(x, 1)] : arr[idx(x, 1)];
      arr[idx(x, simH - 1)] = b === 2 ? -arr[idx(x, simH - 2)] : arr[idx(x, simH - 2)];
    }
    for (let y = 1; y < simH - 1; y++) {
      arr[idx(0, y)] = b === 1 ? -arr[idx(1, y)] : arr[idx(1, y)];
      arr[idx(simW - 1, y)] = b === 1 ? -arr[idx(simW - 2, y)] : arr[idx(simW - 2, y)];
    }
    arr[idx(0, 0)] = 0.5 * (arr[idx(1, 0)] + arr[idx(0, 1)]);
    arr[idx(0, simH - 1)] = 0.5 * (arr[idx(1, simH - 1)] + arr[idx(0, simH - 2)]);
    arr[idx(simW - 1, 0)] = 0.5 * (arr[idx(simW - 2, 0)] + arr[idx(simW - 1, 1)]);
    arr[idx(simW - 1, simH - 1)] = 0.5 * (arr[idx(simW - 2, simH - 1)] + arr[idx(simW - 1, simH - 2)]);
  }

  function linSolve(b, x, x0, a, c) {
    for (let k = 0; k < 10; k++) {
      for (let y = 1; y < simH - 1; y++) {
        for (let xPos = 1; xPos < simW - 1; xPos++) {
          const i = idx(xPos, y);
          x[i] = (x0[i] + a * (x[idx(xPos - 1, y)] + x[idx(xPos + 1, y)] + x[idx(xPos, y - 1)] + x[idx(xPos, y + 1)])) / c;
        }
      }
      setBounds(x, b);
    }
  }

  function diffuse(b, x, x0, diff, dt) {
    const a = dt * diff * (simW - 2) * (simH - 2);
    linSolve(b, x, x0, a, 1 + 4 * a);
  }

  function advect(b, d, d0, u, v, dt) {
    const dt0x = dt * (simW - 2);
    const dt0y = dt * (simH - 2);
    for (let y = 1; y < simH - 1; y++) {
      for (let xPos = 1; xPos < simW - 1; xPos++) {
        const i = idx(xPos, y);
        let x = xPos - dt0x * u[i];
        let yv = y - dt0y * v[i];

        if (x < 0.5) x = 0.5;
        if (x > simW - 1.5) x = simW - 1.5;
        if (yv < 0.5) yv = 0.5;
        if (yv > simH - 1.5) yv = simH - 1.5;

        const i0 = Math.floor(x), i1 = i0 + 1;
        const j0 = Math.floor(yv), j1 = j0 + 1;
        const s1 = x - i0, s0 = 1 - s1;
        const t1 = yv - j0, t0 = 1 - t1;

        d[i] = s0 * (t0 * d0[idx(i0, j0)] + t1 * d0[idx(i0, j1)]) +
               s1 * (t0 * d0[idx(i1, j0)] + t1 * d0[idx(i1, j1)]);
      }
    }
    setBounds(d, b);
  }

  function project(u, v, p, div) {
    for (let y = 1; y < simH - 1; y++) {
      for (let xPos = 1; xPos < simW - 1; xPos++) {
        const i = idx(xPos, y);
        div[i] = -0.5 * (u[idx(xPos + 1, y)] - u[idx(xPos - 1, y)] + v[idx(xPos, y + 1)] - v[idx(xPos, y - 1)]) / simW;
        p[i] = 0;
      }
    }
    setBounds(div, 0);
    setBounds(p, 0);
    linSolve(0, p, div, 1, 4);

    for (let y = 1; y < simH - 1; y++) {
      for (let xPos = 1; xPos < simW - 1; xPos++) {
        const i = idx(xPos, y);
        u[i] -= 0.5 * (p[idx(xPos + 1, y)] - p[idx(xPos - 1, y)]) * simW;
        v[i] -= 0.5 * (p[idx(xPos, y + 1)] - p[idx(xPos, y - 1)]) * simH;
      }
    }
    setBounds(u, 1);
    setBounds(v, 2);
  }

  function addForce(x, y, dx, dy, power = 1) {
    const r = 3;
    for (let oy = -r; oy <= r; oy++) {
      for (let ox = -r; ox <= r; ox++) {
        const gx = Math.round(x + ox);
        const gy = Math.round(y + oy);
        if (gx < 1 || gx >= simW - 1 || gy < 1 || gy >= simH - 1) continue;
        const falloff = Math.max(0, 1 - Math.hypot(ox, oy) / (r + 0.5));
        const i = idx(gx, gy);
        vx[i] += dx * falloff * power;
        vy[i] += dy * falloff * power;
        dye[i] = Math.min(1, dye[i] + 0.08 * falloff * power);
      }
    }
  }

  function step(dt) {
    const diff = parseFloat(diffInput.value);
    const visc = parseFloat(viscInput.value);

    diffuse(1, vx0, vx, visc, dt);
    diffuse(2, vy0, vy, visc, dt);
    project(vx0, vy0, vx, vy);

    advect(1, vx, vx0, vx0, vy0, dt);
    advect(2, vy, vy0, vx0, vy0, dt);
    project(vx, vy, vx0, vy0);

    diffuse(0, dye0, dye, diff, dt);
    advect(0, dye, dye0, vx, vy, dt);

    for (let i = 0; i < size; i++) {
      dye[i] *= 0.9992;
    }
  }

  function toSim(x, y) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: ((x - rect.left) / rect.width) * simW,
      y: ((y - rect.top) / rect.height) * simH
    };
  }

  function seed() {
    for (let i = 0; i < 18; i++) {
      const a = (Math.PI * 2 * i) / 18;
      const cx = simW * 0.5 + Math.cos(a) * simW * 0.19;
      const cy = simH * 0.5 + Math.sin(a) * simH * 0.19;
      addForce(cx, cy, -Math.sin(a) * 0.22, Math.cos(a) * 0.22, 1.4);
    }
    for (let i = 0; i < 120; i++) {
      const x = Math.random() * simW;
      const y = Math.random() * simH;
      dye[idx(Math.floor(x), Math.floor(y))] = Math.random() * 0.35;
    }
  }

  function render() {
    ctx.fillStyle = 'rgba(10,10,15,0.12)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const sx = canvas.width / simW;
    const sy = canvas.height / simH;

    let energy = 0;
    let speedAcc = 0;
    for (let i = 0; i < particleCount; i++) {
      const p = particles[i];
      const xi = Math.max(1, Math.min(simW - 2, p.x | 0));
      const yi = Math.max(1, Math.min(simH - 2, p.y | 0));
      const ii = idx(xi, yi);
      const ux = vx[ii];
      const uy = vy[ii];

      p.x += ux * 0.7;
      p.y += uy * 0.7;
      p.life += 0.003 + dye[ii] * 0.006;

      if (p.x < 1 || p.x > simW - 2 || p.y < 1 || p.y > simH - 2 || p.life > 1) {
        p.x = Math.random() * simW;
        p.y = Math.random() * simH;
        p.life = 0;
      }

      const d = dye[ii];
      const hue = 178 + d * 48;
      const alpha = 0.22 + d * 0.42;
      ctx.fillStyle = `hsla(${hue}, 82%, ${58 - d * 18}%, ${alpha})`;
      ctx.fillRect(p.x * sx, p.y * sy, Math.max(1, sx * 0.55), Math.max(1, sy * 0.55));

      const sp = Math.hypot(ux, uy);
      speedAcc += sp;
      energy += sp * sp;
    }

    speedVal.textContent = (speedAcc / particleCount).toFixed(3);
    energyVal.textContent = (energy / particleCount).toFixed(3);
    swirlVal.textContent = `${pointers.size}`;
  }

  function resize() {
    dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = Math.floor(window.innerWidth * dpr);
    canvas.height = Math.floor(window.innerHeight * dpr);
    canvas.style.width = '100vw';
    canvas.style.height = '100vh';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }

  window.addEventListener('resize', resize, { passive: true });

  function pointerDown(e) {
    const p = toSim(e.clientX, e.clientY);
    pointers.set(e.pointerId, { x: p.x, y: p.y });
    SFX.play('spawn', 80);
  }

  function pointerMove(e) {
    if (!pointers.has(e.pointerId)) return;
    const prev = pointers.get(e.pointerId);
    const p = toSim(e.clientX, e.clientY);
    const mul = parseFloat(forceInput.value);
    addForce(p.x, p.y, (p.x - prev.x) * 0.085 * mul, (p.y - prev.y) * 0.085 * mul, 1.25);
    pointers.set(e.pointerId, { x: p.x, y: p.y });
    SFX.play('move', 40);
  }

  function pointerUp(e) {
    if (pointers.has(e.pointerId)) {
      pointers.delete(e.pointerId);
      SFX.play('ripple', 100);
    }
  }

  canvas.addEventListener('pointerdown', pointerDown);
  canvas.addEventListener('pointermove', pointerMove);
  canvas.addEventListener('pointerup', pointerUp);
  canvas.addEventListener('pointercancel', pointerUp);
  canvas.addEventListener('pointerleave', pointerUp);

  ['force', 'diff', 'visc'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => SFX.play('tick', 60));
  });

  resetBtn.addEventListener('click', () => {
    vx.fill(0); vy.fill(0); vx0.fill(0); vy0.fill(0); dye.fill(0); dye0.fill(0);
    seed();
    SFX.play('click');
  });

  let last = performance.now();
  function loop(now) {
    const dt = Math.min(0.022, (now - last) / 1000);
    last = now;

    // gentle autonomous circulation
    const t = now * 0.00016;
    addForce(simW * (0.5 + Math.cos(t) * 0.2), simH * (0.5 + Math.sin(t * 0.8) * 0.2), Math.sin(t * 2.1) * 0.04, Math.cos(t * 1.7) * 0.04, 0.5);

    step(dt);
    render();
    requestAnimationFrame(loop);
  }

  resize();
  seed();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
