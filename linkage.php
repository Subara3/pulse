<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('linkage');

$pageName = 'linkage';
$pageTitle = 'LINKAGE - PULSE';
$pageDesc = 'Four-bar linkage kinematics. A rotating crank drives a coupler whose traced point carves closed algebraic curves.';
$pageCanonical = 'https://pulse.subara3.com/linkage.php';
$extraStyle = '
body{overflow:hidden;background:#0a0a0f}
#canvas{display:block}
#hud{position:fixed;top:1rem;left:1rem;font-family:monospace;pointer-events:none;line-height:1.8;z-index:10}
#hud .title{font-size:.62rem;color:rgba(0,245,212,.4);letter-spacing:.12em}
#hud .name{font-size:.88rem;color:#00f5d4;letter-spacing:.08em}
#hud .desc{font-size:.6rem;color:rgba(0,245,212,.35);letter-spacing:.04em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  <div class="title">LINKAGE</div>
  <div class="name" id="hud-name">CHEBYSHEV</div>
  <div class="desc" id="hud-desc">approximate straight-line path</div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'next preset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  let W, H, cx, cy, scale;

  // Five four-bar linkage presets
  // a=crank, b=coupler, c=follower, d=ground (all Grashof: crank rotates freely)
  // p=coupler-point distance from A, delta=angle offset from coupler axis
  const PRESETS = [
    { name: 'CHEBYSHEV', desc: 'approximate straight-line path',   a: 1,   b: 2.5, c: 2.5, d: 2,   p: 1.25, delta: 0 },
    { name: 'WIDELOOP',  desc: 'elongated figure-8 curve',         a: 1,   b: 3,   c: 2,   d: 2.5, p: 2.8,  delta: 0.47 },
    { name: 'BUTTERFLY', desc: 'symmetrical double-loop path',     a: 1,   b: 2.8, c: 2.2, d: 2,   p: 2.4,  delta: Math.PI * 0.6 },
    { name: 'TRILOBITE', desc: 'three-lobe algebraic curve',       a: 1,   b: 3.5, c: 2.5, d: 3,   p: 3.2,  delta: Math.PI * 0.3 },
    { name: 'TEARDROP',  desc: 'asymmetric rocker loop',           a: 1,   b: 2,   c: 3,   d: 2.5, p: 1.8,  delta: Math.PI * 0.45 },
  ];

  let pIdx = 0, theta2 = 0, curve = [];

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2; cy = H / 2;
    scale = Math.min(W, H) * 0.12;
    buildCurve();
  }

  // Solve four-bar linkage via Freudenstein's equation
  // Returns joint positions, or null if configuration unreachable
  function solve(pr, th2) {
    const { a, b, c, d } = pr;
    const K1 = d / a, K2 = d / c;
    const K3 = (a * a - b * b + c * c + d * d) / (2 * a * c);
    const A = Math.cos(th2) * (1 - K2) - K1 + K3;
    const B = -2 * Math.sin(th2);
    const C = -(1 + K2) * Math.cos(th2) + K1 + K3;
    const disc = B * B - 4 * A * C;
    if (disc < 0) return null;
    const t = Math.abs(A) < 1e-9 ? -C / B : (-B - Math.sqrt(disc)) / (2 * A);
    const th4 = 2 * Math.atan(t);
    const O2x = -d / 2, O4x = d / 2;
    const Ax = O2x + a * Math.cos(th2), Ay = a * Math.sin(th2);
    const Bx = O4x + c * Math.cos(th4), By = c * Math.sin(th4);
    const th3 = Math.atan2(By - Ay, Bx - Ax);
    return {
      O2: { x: O2x, y: 0 }, O4: { x: O4x, y: 0 },
      A: { x: Ax, y: Ay }, B: { x: Bx, y: By },
      P: { x: Ax + pr.p * Math.cos(th3 + pr.delta), y: Ay + pr.p * Math.sin(th3 + pr.delta) }
    };
  }

  // Pre-compute complete coupler curve (one full crank revolution)
  function buildCurve() {
    const pr = PRESETS[pIdx];
    curve = [];
    for (let i = 0; i <= 600; i++) {
      const sol = solve(pr, (i / 600) * Math.PI * 2);
      if (sol) curve.push(sol.P);
    }
  }

  function scr(p) { return { x: cx + p.x * scale, y: cy - p.y * scale }; }

  function drawCurve() {
    if (curve.length < 2) return;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 1.8;
    for (let i = 1; i < curve.length; i++) {
      const t = i / curve.length;
      // Gradient: teal (hue 175) → purple (hue 270)
      ctx.strokeStyle = `hsla(${175 + t * 95},85%,58%,0.72)`;
      const s1 = scr(curve[i - 1]), s2 = scr(curve[i]);
      ctx.beginPath();
      ctx.moveTo(s1.x, s1.y);
      ctx.lineTo(s2.x, s2.y);
      ctx.stroke();
    }
  }

  function drawMechanism(sol) {
    function seg(p1, p2, col, w) {
      const a = scr(p1), b = scr(p2);
      ctx.strokeStyle = col; ctx.lineWidth = w; ctx.lineCap = 'round';
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
    }
    function circle(p, r, fill) {
      const s = scr(p);
      ctx.beginPath(); ctx.arc(s.x, s.y, r, 0, Math.PI * 2);
      ctx.fillStyle = fill; ctx.fill();
    }
    // Ground link (dashed)
    ctx.setLineDash([3, 6]);
    seg(sol.O2, sol.O4, 'rgba(123,44,191,0.25)', 1);
    ctx.setLineDash([]);

    // Crank, coupler, follower
    seg(sol.O2, sol.A, 'rgba(0,245,212,0.92)', 3.5);
    seg(sol.A, sol.B, 'rgba(255,255,255,0.70)', 2.5);
    seg(sol.O4, sol.B, 'rgba(170,110,240,0.92)', 3.5);

    // Fixed pivots
    circle(sol.O2, 8, '#7b2cbf');
    circle(sol.O4, 8, '#7b2cbf');
    // Moving joints
    circle(sol.A, 5, '#00f5d4');
    circle(sol.B, 5, 'rgba(200,155,255,0.9)');
    // Coupler point
    circle(sol.P, 5, '#ffffff');
    const sp = scr(sol.P);
    ctx.beginPath(); ctx.arc(sp.x, sp.y, 9, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.28)'; ctx.lineWidth = 1; ctx.stroke();
  }

  function frame() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawCurve();
    const sol = solve(PRESETS[pIdx], theta2);
    if (sol) drawMechanism(sol);
    theta2 = (theta2 + 0.018) % (Math.PI * 2);
    requestAnimationFrame(frame);
  }

  function nextPreset() {
    pIdx = (pIdx + 1) % PRESETS.length;
    theta2 = 0;
    buildCurve();
    document.getElementById('hud-name').textContent = PRESETS[pIdx].name;
    document.getElementById('hud-desc').textContent = PRESETS[pIdx].desc;
    SFX.play('toggle');
  }

  canvas.addEventListener('click', nextPreset);
  canvas.addEventListener('touchend', e => { e.preventDefault(); nextPreset(); }, { passive: false });
  window.addEventListener('resize', resize);

  resize();
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
