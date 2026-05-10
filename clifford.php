<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('clifford');

$pageName      = 'clifford';
$pageTitle     = 'CLIFFORD - PULSE';
$pageDesc      = 'クリフォードアトラクター。4つのパラメータが作る2次元カオス軌道。百万点が積み重なって模様が現れる。';
$pageCanonical = 'https://pulse.subara3.com/clifford.php';

$extraStyle = '
body { background:#0a0a0f; margin:0; overflow:hidden; }
#canvas { position:fixed; inset:0; width:100%; height:100%; cursor:crosshair; }
#hud {
  position:fixed; top:1.1rem; left:1rem; z-index:100;
  pointer-events:none;
  font-family:"Orbitron","Segoe UI",sans-serif;
  font-size:0.62rem; line-height:1.9; letter-spacing:0.06em;
}
#hud .lbl { color:rgba(255,255,255,0.3); margin-right:0.3em; }
#hud .val { color:#00f5d4; font-weight:700; }
#hud .pts { color:rgba(0,245,212,0.45); }
#ctrl {
  position:fixed; bottom:1.2rem; right:1.2rem; z-index:100;
  display:flex; flex-direction:column; gap:0.45rem; align-items:flex-end;
}
.crow { display:flex; align-items:center; gap:0.5rem; }
.crow label {
  font-family:"Orbitron",sans-serif; font-size:0.56rem;
  color:rgba(255,255,255,0.35); letter-spacing:0.07em; min-width:4rem; text-align:right;
}
.crow input[type=range] { width:80px; accent-color:#00f5d4; }
.cbtn {
  background:rgba(10,10,15,0.72); border:1px solid rgba(0,245,212,0.22);
  color:rgba(255,255,255,0.65); font-family:"Orbitron",sans-serif;
  font-size:0.56rem; letter-spacing:0.07em;
  padding:0.35rem 0.7rem; cursor:pointer;
  backdrop-filter:blur(6px); transition:border-color 0.2s,color 0.2s;
  touch-action:manipulation;
}
.cbtn:hover { border-color:rgba(0,245,212,0.55); color:#00f5d4; }
.cbtn.sub { border-color:rgba(123,44,191,0.3); }
.cbtn.sub:hover { border-color:rgba(123,44,191,0.7); color:#a855f7; }
.cbtns { display:flex; gap:0.4rem; justify-content:flex-end; }
@media(max-width:768px){
  #hud { font-size:0.52rem; top:0.75rem; left:0.75rem; }
  #ctrl { bottom:0.9rem; right:0.9rem; }
  .crow input[type=range] { width:55px; }
  .crow label { min-width:3rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  <div><span class="lbl">A</span><span class="val" id="va">—</span></div>
  <div><span class="lbl">B</span><span class="val" id="vb">—</span></div>
  <div><span class="lbl">C</span><span class="val" id="vc">—</span></div>
  <div><span class="lbl">D</span><span class="val" id="vd">—</span></div>
  <div><span class="lbl">PTS</span><span class="pts" id="vpts">0</span></div>
</div>

<div id="ctrl">
  <div class="crow">
    <label>A <span id="lva">-1.40</span></label>
    <input type="range" id="sla" min="-300" max="300" step="1" value="-140">
  </div>
  <div class="crow">
    <label>B <span id="lvb">1.60</span></label>
    <input type="range" id="slb" min="-300" max="300" step="1" value="160">
  </div>
  <div class="crow">
    <label>C <span id="lvc">1.00</span></label>
    <input type="range" id="slc" min="-300" max="300" step="1" value="100">
  </div>
  <div class="crow">
    <label>D <span id="lvd">0.70</span></label>
    <input type="range" id="sld" min="-300" max="300" step="1" value="70">
  </div>
  <div class="cbtns">
    <button class="cbtn sub" id="clearBtn">CLEAR</button>
    <button class="cbtn" id="rndBtn">RANDOM</button>
  </div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'random'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  'use strict';

  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  let W, H, cx, cy, sc;
  let buf, imgData, pixels;

  let a = -1.4, b = 1.6, c = 1.0, d = 0.7;
  let ix = 0, iy = 0;
  let maxVal = 1, totalPts = 0, frame = 0;

  const ITERS        = 30000;
  const RENDER_EVERY = 3;

  /* ── resize & init ── */
  function resize() {
    W  = canvas.width  = window.innerWidth;
    H  = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    sc = Math.min(W, H) / 5.5;
    reset();
  }

  function reset() {
    buf     = new Float32Array(W * H);
    imgData = ctx.createImageData(W, H);
    pixels  = imgData.data;
    for (let i = 0; i < W * H; i++) {
      const p = i * 4;
      pixels[p] = 10; pixels[p+1] = 10; pixels[p+2] = 15; pixels[p+3] = 255;
    }
    /* Warmup: 300 steps to land on the attractor before recording */
    let tx = 0.5, ty = 0.3;
    for (let i = 0; i < 300; i++) {
      const nx = Math.sin(a * ty) + c * Math.cos(a * tx);
      const ny = Math.sin(b * tx) + d * Math.cos(b * ty);
      tx = nx; ty = ny;
    }
    ix = tx; iy = ty;
    maxVal   = 1;
    totalPts = 0;
  }

  /* ── attractor quality check ── */
  function isInteresting(a2, b2, c2, d2) {
    let tx = 0.5, ty = 0.3;
    let sx = 0, sy = 0, sx2 = 0, sy2 = 0;
    for (let i = 0; i < 600; i++) {
      const nx = Math.sin(a2 * ty) + c2 * Math.cos(a2 * tx);
      const ny = Math.sin(b2 * tx) + d2 * Math.cos(b2 * ty);
      tx = nx; ty = ny;
      if (!isFinite(tx) || !isFinite(ty) || Math.abs(tx) > 5 || Math.abs(ty) > 5) return false;
      if (i >= 200) { sx += tx; sy += ty; sx2 += tx*tx; sy2 += ty*ty; }
    }
    const n = 400;
    return (sx2/n - (sx/n)**2) > 0.05 && (sy2/n - (sy/n)**2) > 0.05;
  }

  /* ── randomize params ── */
  function randomize() {
    let na, nb, nc, nd, tries = 0;
    do {
      na = Math.random() * 6 - 3;
      nb = Math.random() * 6 - 3;
      nc = Math.random() * 6 - 3;
      nd = Math.random() * 6 - 3;
    } while (!isInteresting(na, nb, nc, nd) && ++tries < 40);
    a = na; b = nb; c = nc; d = nd;
    syncSliders();
    reset();
    updateHUD();
    SFX.play('toggle');
  }

  /* ── iterate ── */
  function iterate() {
    for (let i = 0; i < ITERS; i++) {
      const nx = Math.sin(a * iy) + c * Math.cos(a * ix);
      const ny = Math.sin(b * ix) + d * Math.cos(b * iy);
      ix = nx; iy = ny;
      const px = (ix * sc + cx) | 0;
      const py = (iy * sc + cy) | 0;
      if (px >= 0 && px < W && py >= 0 && py < H) {
        const v = ++buf[py * W + px];
        if (v > maxVal) maxVal = v;
      }
    }
    totalPts += ITERS;
  }

  /* ── render density buffer to canvas ── */
  function render() {
    const lm = Math.log(1 + maxVal);
    for (let i = 0; i < W * H; i++) {
      if (buf[i] === 0) continue;
      const v = Math.log(1 + buf[i]) / lm;
      const p = i * 4;
      if (v < 0.55) {
        /* dark → teal */
        const t = v / 0.55;
        pixels[p]   = 0;
        pixels[p+1] = (t * 245) | 0;
        pixels[p+2] = (t * 212) | 0;
      } else {
        /* teal → white */
        const t = (v - 0.55) / 0.45;
        pixels[p]   = (t * 255) | 0;
        pixels[p+1] = Math.min(255, (245 + t * 10) | 0);
        pixels[p+2] = 255;
      }
      /* alpha already 255 from reset() */
    }
    ctx.putImageData(imgData, 0, 0);
  }

  /* ── HUD ── */
  function updateHUD() {
    document.getElementById('va').textContent = a.toFixed(2);
    document.getElementById('vb').textContent = b.toFixed(2);
    document.getElementById('vc').textContent = c.toFixed(2);
    document.getElementById('vd').textContent = d.toFixed(2);
    const n = totalPts;
    document.getElementById('vpts').textContent =
      n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' :
      n >= 1e3 ? (n / 1e3).toFixed(0) + 'K' : String(n);
  }

  /* ── slider sync ── */
  function syncSliders() {
    document.getElementById('sla').value = (a * 100) | 0;
    document.getElementById('slb').value = (b * 100) | 0;
    document.getElementById('slc').value = (c * 100) | 0;
    document.getElementById('sld').value = (d * 100) | 0;
    document.getElementById('lva').textContent = a.toFixed(2);
    document.getElementById('lvb').textContent = b.toFixed(2);
    document.getElementById('lvc').textContent = c.toFixed(2);
    document.getElementById('lvd').textContent = d.toFixed(2);
  }

  function bindSlider(slId, lblId, setter) {
    const sl  = document.getElementById(slId);
    const lbl = document.getElementById(lblId);
    sl.addEventListener('input', function () {
      const v = this.value / 100;
      setter(v);
      lbl.textContent = v.toFixed(2);
      reset();
      updateHUD();
      SFX.play('toggle');
    });
  }

  /* ── events ── */
  canvas.addEventListener('click', () => { randomize(); SFX.play('spawn'); });
  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    reset();
    SFX.play('click');
  });
  canvas.addEventListener('touchend', e => {
    if (e.target === canvas) { e.preventDefault(); randomize(); }
  }, { passive: false });

  document.getElementById('rndBtn').addEventListener('click', e => {
    e.stopPropagation();
    randomize();
  });
  document.getElementById('clearBtn').addEventListener('click', e => {
    e.stopPropagation();
    reset();
    SFX.play('click');
  });

  bindSlider('sla', 'lva', v => { a = v; });
  bindSlider('slb', 'lvb', v => { b = v; });
  bindSlider('slc', 'lvc', v => { c = v; });
  bindSlider('sld', 'lvd', v => { d = v; });

  /* ── main loop ── */
  function loop() {
    iterate();
    frame++;
    if (frame % RENDER_EVERY === 0) {
      render();
      updateHUD();
    }
    requestAnimationFrame(loop);
  }

  window.addEventListener('resize', resize);
  resize();
  syncSliders();
  updateHUD();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
