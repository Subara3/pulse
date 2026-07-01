<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tesseract');

$pageName = 'tesseract';
$pageTitle = 'TESSERACT - PULSE';
$pageDesc = "4次元超立方体のリアルタイム投影。6軸回転が次元の向こう側を映し出す。";
$pageCanonical = 'https://pulse.subara3.com/tesseract.php';
$extraStyle = '
canvas { display:block; }
.controls {
  position:fixed; bottom:1.5rem; right:1.5rem;
  display:flex; gap:0.5rem; flex-direction:column; align-items:flex-end;
}
.btn {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); padding:0.35rem 0.8rem;
  font-size:0.65rem; letter-spacing:0.15em; cursor:pointer;
  font-family:inherit; transition:all 0.2s;
}
.btn:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
.btn.active { background:rgba(0,245,212,0.2); color:#00f5d4; border-color:rgba(0,245,212,0.5); }
.info {
  position:fixed; bottom:1.5rem; left:1.5rem;
  font-size:0.65rem; color:rgba(255,255,255,0.2); letter-spacing:0.1em;
  pointer-events:none; line-height:1.8;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="controls drawer-controls">
  <button class="btn" id="btnMode">MODE: AUTO-A</button>
  <button class="btn" id="btnProj">PROJ: PERSP</button>
  <button class="btn" id="btnGlow">GLOW: ON</button>
</div>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<div class="info" id="info">VERTS: 16 · EDGES: 32</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'rotate 4D'],
  ['type' => 'click', 'label' => 'mode'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const info = document.getElementById('info');

  // 16 vertices of the 4D hypercube: all ±1 combinations
  const VERTS = [];
  for (const x of [-1,1]) for (const y of [-1,1]) for (const z of [-1,1]) for (const w of [-1,1])
    VERTS.push([x, y, z, w]);

  // 32 edges: pairs of vertices differing in exactly 1 coordinate
  const EDGES = [];
  for (let i = 0; i < VERTS.length; i++)
    for (let j = i+1; j < VERTS.length; j++) {
      let d = 0;
      for (let k = 0; k < 4; k++) if (VERTS[i][k] !== VERTS[j][k]) d++;
      if (d === 1) EDGES.push([i, j]);
    }

  // Rotation angles: [XY, XZ, XW, YZ, YW, ZW]
  let ang = [0, 0, 0, 0, 0, 0];

  const MODES = [
    { name: 'AUTO-A', sp: [0,      0,      0.006,  0.009,  0,      0     ] },
    { name: 'AUTO-B', sp: [0,      0.005,  0,      0,      0.008,  0.004 ] },
    { name: 'AUTO-C', sp: [0.004,  0.006,  0.009,  0.003,  0.005,  0.007 ] },
    { name: 'MANUAL', sp: [0,      0,      0,      0,      0,      0     ] },
  ];
  let modeIdx = 0;
  let projPersp = true;
  let glowOn = true;

  let drag = null;

  function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
  }

  // Apply all 6 rotation planes sequentially
  function rot4D(v, a) {
    let [x, y, z, w] = v, c, s;
    c = Math.cos(a[0]); s = Math.sin(a[0]); [x, y] = [x*c - y*s, x*s + y*c];
    c = Math.cos(a[1]); s = Math.sin(a[1]); [x, z] = [x*c - z*s, x*s + z*c];
    c = Math.cos(a[2]); s = Math.sin(a[2]); [x, w] = [x*c - w*s, x*s + w*c];
    c = Math.cos(a[3]); s = Math.sin(a[3]); [y, z] = [y*c - z*s, y*s + z*c];
    c = Math.cos(a[4]); s = Math.sin(a[4]); [y, w] = [y*c - w*s, y*s + w*c];
    c = Math.cos(a[5]); s = Math.sin(a[5]); [z, w] = [z*c - w*s, z*s + w*c];
    return [x, y, z, w];
  }

  // 4D→3D perspective, then 3D→2D perspective
  function project(v4, cx, cy, scale) {
    let [x, y, z, w] = v4;
    const d4 = projPersp ? 2.5 : 9999;
    const s4 = d4 / (d4 - w);
    const x3 = x * s4, y3 = y * s4, z3 = z * s4;
    const d3 = projPersp ? 4.0 : 9999;
    const s3 = d3 / (d3 - z3 * 0.45);
    return {
      px: cx + x3 * s3 * scale,
      py: cy + y3 * s3 * scale,
      depth: z3,
      w4: w,
    };
  }

  // w in [-1,1] maps to hue 180 (teal) → 270 (purple)
  function wHue(w) { return 180 + (w + 1) * 45; }

  function draw() {
    const sp = MODES[modeIdx].sp;
    for (let i = 0; i < 6; i++) ang[i] += sp[i];

    const W = canvas.width, H = canvas.height;
    const cx = W / 2, cy = H / 2;
    const scale = Math.min(W, H) * 0.28;

    ctx.fillStyle = 'rgba(10,10,15,0.22)';
    ctx.fillRect(0, 0, W, H);

    // Project all 16 vertices
    const pts = VERTS.map(v => {
      const r = rot4D(v, ang);
      return project(r, cx, cy, scale);
    });

    // Build edge list with depth for painter's sort
    const edgeList = EDGES.map(([i, j]) => ({
      i, j,
      depth: (pts[i].depth + pts[j].depth) * 0.5,
      wAvg: (pts[i].w4  + pts[j].w4)  * 0.5,
    })).sort((a, b) => a.depth - b.depth);

    // Draw edges (back to front)
    for (const e of edgeList) {
      const p0 = pts[e.i], p1 = pts[e.j];
      const hue = wHue(e.wAvg);
      const df = Math.max(0, Math.min(1, (e.depth + 1.6) / 3.2));
      const alpha = 0.25 + df * 0.65;
      const lw    = 0.6 + df * 2.2;

      if (glowOn) {
        ctx.beginPath();
        ctx.moveTo(p0.px, p0.py);
        ctx.lineTo(p1.px, p1.py);
        ctx.strokeStyle = `hsla(${hue},75%,60%,${alpha * 0.18})`;
        ctx.lineWidth = lw * 5;
        ctx.stroke();
      }

      ctx.beginPath();
      ctx.moveTo(p0.px, p0.py);
      ctx.lineTo(p1.px, p1.py);
      ctx.strokeStyle = `hsla(${hue},80%,65%,${alpha})`;
      ctx.lineWidth = lw;
      ctx.stroke();
    }

    // Draw vertices
    for (const p of pts) {
      const hue = wHue(p.w4);
      const df = Math.max(0, Math.min(1, (p.depth + 1.6) / 3.2));
      const r = 1.8 + df * 3.5;

      if (glowOn) {
        const grd = ctx.createRadialGradient(p.px, p.py, 0, p.px, p.py, r * 4);
        grd.addColorStop(0, `hsla(${hue},80%,65%,0.3)`);
        grd.addColorStop(1, 'transparent');
        ctx.beginPath();
        ctx.arc(p.px, p.py, r * 4, 0, Math.PI * 2);
        ctx.fillStyle = grd;
        ctx.fill();
      }

      ctx.beginPath();
      ctx.arc(p.px, p.py, r, 0, Math.PI * 2);
      ctx.fillStyle = `hsl(${hue},85%,72%)`;
      ctx.fill();
    }

    // Info
    const wMax = pts.reduce((m, p) => Math.max(m, p.w4), -9).toFixed(2);
    const wMin = pts.reduce((m, p) => Math.min(m, p.w4),  9).toFixed(2);
    info.innerHTML = `W: ${wMin} ~ ${wMax}<br>${MODES[modeIdx].name}`;

    requestAnimationFrame(draw);
  }

  // Controls
  document.getElementById('btnMode').addEventListener('click', () => {
    modeIdx = (modeIdx + 1) % MODES.length;
    document.getElementById('btnMode').textContent = 'MODE: ' + MODES[modeIdx].name;
    SFX.play('toggle');
  });

  document.getElementById('btnProj').addEventListener('click', () => {
    projPersp = !projPersp;
    document.getElementById('btnProj').textContent = 'PROJ: ' + (projPersp ? 'PERSP' : 'ORTHO');
    SFX.play('click');
  });

  document.getElementById('btnGlow').addEventListener('click', () => {
    glowOn = !glowOn;
    document.getElementById('btnGlow').textContent = 'GLOW: ' + (glowOn ? 'ON' : 'OFF');
    SFX.play('click');
  });

  // Drag rotates XW (left-right) and YW (up-down)
  function startDrag(x, y) {
    drag = { x, y, a2: ang[2], a4: ang[4] };
  }
  function moveDrag(x, y) {
    if (!drag) return;
    const dx = (x - drag.x) / 220;
    const dy = (y - drag.y) / 220;
    ang[2] = drag.a2 + dx;
    ang[4] = drag.a4 + dy;
    SFX.play('move', 80);
  }
  function endDrag() { drag = null; }

  canvas.addEventListener('mousedown', e => {
    if (e.button === 0) { startDrag(e.clientX, e.clientY); SFX.play('click'); }
  });
  canvas.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
  canvas.addEventListener('mouseup', endDrag);
  canvas.addEventListener('mouseleave', endDrag);

  canvas.addEventListener('touchstart', e => {
    startDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: true });
  canvas.addEventListener('touchmove', e => {
    moveDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: true });
  canvas.addEventListener('touchend', endDrag);

  resize();
  window.addEventListener('resize', resize);
  requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
