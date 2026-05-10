<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lenia');

$pageName = 'lenia';
$pageTitle = 'LENIA - PULSE';
$pageDesc = 'Lenia — a continuous cellular automaton where life-like creatures emerge from convolution and a growth function.';
$pageCanonical = 'https://pulse.subara3.com/lenia.php';
$extraStyle = '
body{overflow:hidden;background:#0a0a0f}
#canvas{display:block;image-rendering:pixelated;image-rendering:crisp-edges}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.28rem .62rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
.ctrl-row{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.ctrl-label{color:rgba(0,245,212,.55);font-family:monospace;font-size:.65rem;letter-spacing:.05em;min-width:2rem;text-align:right}
.ctrl-val{color:rgba(255,255,255,.7);font-family:monospace;font-size:.62rem;min-width:3rem;text-align:right}
input[type=range]{width:80px;accent-color:#00f5d4;cursor:pointer}
#hud{position:fixed;top:1rem;left:1rem;font-size:.7rem;color:rgba(0,245,212,.55);font-family:monospace;pointer-events:none;line-height:1.8;z-index:10}
#hud span.v{color:rgba(255,255,255,.8)}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  <div>LENIA</div>
  <div>R&nbsp;<span class="v" id="hud-r">13</span> &nbsp;μ&nbsp;<span class="v" id="hud-mu">0.150</span> &nbsp;σ&nbsp;<span class="v" id="hud-sig">0.015</span></div>
</div>

<div id="controls">
  <div class="ctrl-row">
    <span class="ctrl-label">R</span>
    <input type="range" id="sl-r" min="5" max="20" value="13" step="1">
    <span class="ctrl-val" id="vl-r">13</span>
  </div>
  <div class="ctrl-row">
    <span class="ctrl-label">μ</span>
    <input type="range" id="sl-mu" min="0.05" max="0.50" value="0.15" step="0.005">
    <span class="ctrl-val" id="vl-mu">0.150</span>
  </div>
  <div class="ctrl-row">
    <span class="ctrl-label">σ</span>
    <input type="range" id="sl-sig" min="0.005" max="0.08" value="0.015" step="0.001">
    <span class="ctrl-val" id="vl-sig">0.015</span>
  </div>
  <button class="btn" id="btn-rand">RANDOM</button>
  <button class="btn" id="btn-clear">CLEAR</button>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'paint'],
  ['type' => 'drag', 'label' => 'draw'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function () {
  const canvas = document.getElementById('canvas');
  const ctx    = canvas.getContext('2d');

  // Grid dimensions (16:9, small for perf — scaled up via CSS)
  const GW = 192, GH = 108;

  let grid = new Float32Array(GW * GH);
  let next = new Float32Array(GW * GH);

  // Parameters
  let R   = 13;
  let mu  = 0.15;
  let sig = 0.015;
  const dt = 0.1;

  // Precomputed kernel
  let kernelData  = null;   // sparse: [{dx,dy,k}, ...]
  let kernelSum   = 0;

  // Bell / Gaussian
  function bell(x, m, s) {
    const d = (x - m) / s;
    return Math.exp(-0.5 * d * d);
  }

  function buildKernel(r) {
    kernelData = [];
    kernelSum  = 0;
    for (let dy = -r; dy <= r; dy++) {
      for (let dx = -r; dx <= r; dx++) {
        const dist = Math.sqrt(dx * dx + dy * dy) / r;
        if (dist > 1) continue;
        const k = bell(dist, 0.5, 0.15);
        if (k < 1e-5) continue;
        kernelData.push({ dx, dy, k });
        kernelSum += k;
      }
    }
  }

  // Initialise a random blob in the centre
  function randomize() {
    grid.fill(0);
    const cx  = Math.floor(GW / 2);
    const cy  = Math.floor(GH / 2);
    const rad = Math.min(GW, GH) * 0.22;
    for (let y = 0; y < GH; y++) {
      for (let x = 0; x < GW; x++) {
        const dx = x - cx, dy = y - cy;
        if (dx * dx + dy * dy < rad * rad) {
          if (Math.random() < 0.45) grid[y * GW + x] = Math.random();
        }
      }
    }
  }

  // One Lenia step
  function step() {
    const ks  = kernelData;
    const kn  = ks.length;
    const inv = 1 / kernelSum;
    for (let y = 0; y < GH; y++) {
      for (let x = 0; x < GW; x++) {
        let sum = 0;
        for (let i = 0; i < kn; i++) {
          const { dx, dy, k } = ks[i];
          const nx = (x + dx + GW) % GW;
          const ny = (y + dy + GH) % GH;
          sum += grid[ny * GW + nx] * k;
        }
        const u = sum * inv;
        const g = 2 * bell(u, mu, sig) - 1;
        const v = grid[y * GW + x] + dt * g;
        next[y * GW + x] = v < 0 ? 0 : v > 1 ? 1 : v;
      }
    }
    // swap buffers
    const tmp = grid; grid = next; next = tmp;
  }

  // Render grid to canvas ImageData (pixelated upscale via CSS)
  function render() {
    const img = ctx.createImageData(GW, GH);
    const d   = img.data;
    for (let i = 0; i < GW * GH; i++) {
      const v  = grid[i];
      const p  = i * 4;
      if (v < 0.001) {
        d[p]   = 10;
        d[p+1] = 10;
        d[p+2] = 15;
      } else if (v < 0.5) {
        // dark → purple
        const t  = v * 2;
        d[p]   = Math.round(10 + (123 - 10) * t) | 0;
        d[p+1] = Math.round(10 + (44  - 10) * t) | 0;
        d[p+2] = Math.round(15 + (191 - 15) * t) | 0;
      } else {
        // purple → teal
        const t  = (v - 0.5) * 2;
        d[p]   = Math.round(123 + (0   - 123) * t) | 0;
        d[p+1] = Math.round(44  + (245 - 44)  * t) | 0;
        d[p+2] = Math.round(191 + (212 - 191) * t) | 0;
      }
      d[p+3] = 255;
    }
    ctx.putImageData(img, 0, 0);
  }

  // Resize: fix canvas pixel size; scale via style
  function resize() {
    canvas.width  = GW;
    canvas.height = GH;
    canvas.style.width  = window.innerWidth  + 'px';
    canvas.style.height = window.innerHeight + 'px';
    canvas.style.position = 'fixed';
    canvas.style.top  = '0';
    canvas.style.left = '0';
  }

  // Paint at canvas pixel → grid coords
  function getGridXY(cx, cy) {
    const rect = canvas.getBoundingClientRect();
    const gx = Math.floor((cx - rect.left) / rect.width  * GW);
    const gy = Math.floor((cy - rect.top)  / rect.height * GH);
    return [gx, gy];
  }

  function paintAt(cx, cy) {
    const [gx, gy] = getGridXY(cx, cy);
    const rad = 4;
    for (let dy = -rad; dy <= rad; dy++) {
      for (let dx = -rad; dx <= rad; dx++) {
        if (dx * dx + dy * dy > rad * rad) continue;
        const nx = (gx + dx + GW) % GW;
        const ny = (gy + dy + GH) % GH;
        const idx = ny * GW + nx;
        grid[idx] = Math.min(1, grid[idx] + 0.35 * Math.random());
      }
    }
    SFX.play('ripple', 80);
  }

  // Controls
  function bindSlider(id, valId, fmt, onChange) {
    const el = document.getElementById(id);
    const vl = document.getElementById(valId);
    el.addEventListener('input', () => {
      const v = parseFloat(el.value);
      vl.textContent = fmt(v);
      onChange(v);
      SFX.play('toggle');
    });
  }

  bindSlider('sl-r',   'vl-r',   v => Math.round(v),    v => {
    R = Math.round(v);
    buildKernel(R);
    document.getElementById('hud-r').textContent = R;
  });
  bindSlider('sl-mu',  'vl-mu',  v => v.toFixed(3),     v => {
    mu = v;
    document.getElementById('hud-mu').textContent = v.toFixed(3);
  });
  bindSlider('sl-sig', 'vl-sig', v => v.toFixed(3),     v => {
    sig = v;
    document.getElementById('hud-sig').textContent = v.toFixed(3);
  });

  document.getElementById('btn-rand').addEventListener('click', () => {
    randomize();
    SFX.play('spawn');
  });
  document.getElementById('btn-clear').addEventListener('click', () => {
    grid.fill(0);
    SFX.play('click');
  });

  // Mouse / touch events for painting
  let painting = false;
  canvas.addEventListener('mousedown', e => {
    if (e.button !== 0) return;
    painting = true;
    paintAt(e.clientX, e.clientY);
  });
  canvas.addEventListener('mousemove', e => {
    if (painting) paintAt(e.clientX, e.clientY);
  });
  canvas.addEventListener('mouseup',   () => { painting = false; });
  canvas.addEventListener('mouseleave',() => { painting = false; });

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    painting = true;
    paintAt(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    if (painting) paintAt(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchend', () => { painting = false; });

  // Main loop — step every frame, render every 2nd frame
  let frame = 0;
  function loop() {
    step();
    if (frame % 2 === 0) render();
    frame++;
    requestAnimationFrame(loop);
  }

  // Init
  window.addEventListener('resize', resize);
  resize();
  buildKernel(R);
  randomize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
