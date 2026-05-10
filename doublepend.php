<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('doublepend');

$pageName = 'doublepend';
$pageTitle = 'DOUBLEPEND - PULSE';
$pageDesc = 'Double pendulum chaos. Nearly identical initial conditions diverge exponentially. Lyapunov instability made visible.';
$pageCanonical = 'https://pulse.subara3.com/doublepend.php';
$extraStyle = '
#hud{position:fixed;top:3.5rem;right:1rem;font-size:.7rem;color:rgba(0,245,212,.65);font-family:monospace;pointer-events:none;line-height:1.9;z-index:10;}
#hud span.v{color:#fff}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.28rem .62rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
.ctrl-row{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.ctrl-label{color:rgba(0,245,212,.55);font-family:monospace;font-size:.65rem;letter-spacing:.05em;min-width:3.5rem;text-align:right}
.ctrl-val{color:rgba(255,255,255,.7);font-family:monospace;font-size:.62rem;min-width:2.6rem;text-align:right}
input[type=range]{width:88px;accent-color:#00f5d4;cursor:pointer}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>t &nbsp;&nbsp;&nbsp;&nbsp;<span class="v" id="hud-t">0.00</span> s</div>
  <div>count &nbsp;<span class="v" id="hud-n">2</span></div>
</div>
<div id="controls">
  <div class="ctrl-row">
    <span class="ctrl-label">speed</span>
    <input type="range" id="slider-speed" min="0.2" max="4" step="0.1" value="1">
    <span class="ctrl-val" id="val-speed">1.0×</span>
  </div>
  <button class="btn" id="btn-add">+ ADD</button>
  <button class="btn" id="btn-reset">RESET</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add pendulum'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  const GR = 9.8;
  let W, H, cx, cy, SC;

  // Teal–purple palette — all hues 170–285
  const COLS = [
    [0,   245, 212],  // teal
    [148,  60, 210],  // purple
    [0,   200, 235],  // cyan
    [180,  50, 255],  // violet
    [0,   225, 175],  // mint
    [110,  80, 230],  // indigo
  ];

  // ── Double pendulum (m1=m2=1, L1=L2=1) ─────────────────────────────────────
  class Pend {
    constructor(th1, th2, w1, w2, ci) {
      this.th1 = th1; this.th2 = th2;
      this.w1  = w1;  this.w2  = w2;
      this.ci  = ci;
      this.trail = [];   // [[x,y], ...]
      this.TRAIL_MAX = 700;
    }

    // Equations of motion (equal mass, equal length, g=GR)
    _deriv(th1, th2, w1, w2) {
      const d   = th1 - th2;
      const sd  = Math.sin(d), cd = Math.cos(d);
      const den = 3 - cd * cd;
      const dw1 = (-3*GR*Math.sin(th1) - GR*Math.sin(th1 - 2*th2) - 2*sd*(w2*w2 + w1*w1*cd)) / den;
      const dw2 = ( 2*sd*(2*w1*w1 + 2*GR*Math.cos(th1) + w2*w2*cd)) / den;
      return [w1, w2, dw1, dw2];
    }

    // RK4 integration
    step(dt) {
      const [a1,b1,c1,d1] = this._deriv(this.th1,         this.th2,         this.w1,         this.w2);
      const h = dt / 2;
      const [a2,b2,c2,d2] = this._deriv(this.th1+h*a1, this.th2+h*b1, this.w1+h*c1, this.w2+h*d1);
      const [a3,b3,c3,d3] = this._deriv(this.th1+h*a2, this.th2+h*b2, this.w1+h*c2, this.w2+h*d2);
      const [a4,b4,c4,d4] = this._deriv(this.th1+dt*a3, this.th2+dt*b3, this.w1+dt*c3, this.w2+dt*d3);
      this.th1 += dt/6*(a1+2*a2+2*a3+a4);
      this.th2 += dt/6*(b1+2*b2+2*b3+b4);
      this.w1  += dt/6*(c1+2*c2+2*c3+c4);
      this.w2  += dt/6*(d1+2*d2+2*d3+d4);
    }

    pos() {
      const x1 = cx + SC * Math.sin(this.th1);
      const y1 = cy + SC * Math.cos(this.th1);
      const x2 = x1 + SC * Math.sin(this.th2);
      const y2 = y1 + SC * Math.cos(this.th2);
      return { x1, y1, x2, y2 };
    }

    tick(dt) {
      const SUB = 6;
      for (let i = 0; i < SUB; i++) this.step(dt / SUB);
      const { x2, y2 } = this.pos();
      this.trail.push([x2, y2]);
      if (this.trail.length > this.TRAIL_MAX) this.trail.shift();
    }

    drawTrail() {
      const tr = this.trail;
      const n  = tr.length;
      if (n < 2) return;
      const [r, g, b] = COLS[this.ci];
      const BANDS = 10;
      const bsz = Math.ceil(n / BANDS);
      for (let band = 0; band < BANDS; band++) {
        const alpha = ((band + 1) / BANDS) * 0.70;
        const s = band * bsz;
        const e = Math.min(s + bsz + 1, n);
        if (s >= n - 1) break;
        ctx.beginPath();
        ctx.moveTo(tr[s][0], tr[s][1]);
        for (let i = s + 1; i < e; i++) ctx.lineTo(tr[i][0], tr[i][1]);
        ctx.strokeStyle = `rgba(${r},${g},${b},${alpha.toFixed(2)})`;
        ctx.lineWidth = 1.2;
        ctx.stroke();
      }
    }

    drawArm() {
      const { x1, y1, x2, y2 } = this.pos();
      const [r, g, b] = COLS[this.ci];
      ctx.strokeStyle = `rgba(${r},${g},${b},0.85)`;
      ctx.lineWidth = 1.8;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.lineTo(x1, y1);
      ctx.lineTo(x2, y2);
      ctx.stroke();
      // joint 1
      ctx.fillStyle = `rgba(${r},${g},${b},0.9)`;
      ctx.beginPath(); ctx.arc(x1, y1, 4, 0, Math.PI * 2); ctx.fill();
      // bob 2
      ctx.beginPath(); ctx.arc(x2, y2, 7, 0, Math.PI * 2); ctx.fill();
    }
  }

  // ── State ────────────────────────────────────────────────────────────────────
  let pends = [];
  let simTime = 0;
  let speed   = 1;

  const TH1_0 = 2.0;   // ~115° from downward vertical
  const TH2_0 = 0.5;   // ~29°

  function init() {
    pends = [
      new Pend(TH1_0,         TH2_0,         0, 0, 0),
      new Pend(TH1_0 + 0.001, TH2_0 + 0.001, 0, 0, 1),
    ];
    simTime = 0;
    document.getElementById('hud-n').textContent = '2';
  }

  function addPend() {
    if (pends.length >= COLS.length) return;
    const ci  = pends.length;
    const eps = ci * 0.001;
    pends.push(new Pend(TH1_0 + eps, TH2_0 + eps, 0, 0, ci));
    SFX.play('spawn');
    document.getElementById('hud-n').textContent = pends.length;
  }

  // ── Resize ───────────────────────────────────────────────────────────────────
  function resize() {
    W  = canvas.width  = window.innerWidth;
    H  = canvas.height = window.innerHeight;
    cx = W * 0.5;
    cy = H * 0.30;
    SC = Math.min(W, H) * 0.22;
  }

  // ── Main loop ────────────────────────────────────────────────────────────────
  function loop() {
    const dt = 0.016 * speed;

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    for (const p of pends) p.tick(dt);
    simTime += dt;

    // Trails beneath arms
    for (const p of pends) p.drawTrail();
    for (const p of pends) p.drawArm();

    // Pivot
    ctx.fillStyle = 'rgba(0,245,212,0.95)';
    ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI * 2); ctx.fill();

    // HUD
    document.getElementById('hud-t').textContent = simTime.toFixed(2);

    requestAnimationFrame(loop);
  }

  // ── Controls ─────────────────────────────────────────────────────────────────
  const sliderSpeed = document.getElementById('slider-speed');
  sliderSpeed.addEventListener('input', () => {
    speed = parseFloat(sliderSpeed.value);
    document.getElementById('val-speed').textContent = speed.toFixed(1) + '×';
    SFX.play('toggle');
  });

  document.getElementById('btn-add').addEventListener('click', () => {
    addPend();
  });

  document.getElementById('btn-reset').addEventListener('click', () => {
    init();
    sliderSpeed.value = '1';
    speed = 1;
    document.getElementById('val-speed').textContent = '1.0×';
    SFX.play('click');
  });

  // ── Canvas interaction ────────────────────────────────────────────────────────
  canvas.addEventListener('click', () => { addPend(); });

  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    init();
    sliderSpeed.value = '1';
    speed = 1;
    document.getElementById('val-speed').textContent = '1.0×';
    SFX.play('click');
  });

  // Touch: tap = add, swipe-down = reset
  let tStartX, tStartY;
  canvas.addEventListener('touchstart', e => {
    tStartX = e.touches[0].clientX;
    tStartY = e.touches[0].clientY;
  }, { passive: true });
  canvas.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - tStartX;
    const dy = e.changedTouches[0].clientY - tStartY;
    if (Math.hypot(dx, dy) < 12) addPend();
  }, { passive: true });

  // ── Init ─────────────────────────────────────────────────────────────────────
  window.addEventListener('resize', resize);
  resize();
  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
