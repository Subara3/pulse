<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hilbert');

$pageName = 'hilbert';
$pageTitle = 'HILBERT - PULSE';
$pageDesc = 'ヒルベルト曲線。1次元の曲線が2次元空間を隙間なく埋め尽くす、空間充填曲線の収束過程。';
$pageCanonical = 'https://pulse.subara3.com/hilbert.php';
$extraStyle = '
#canvas { display: block; }
#hud {
  position: fixed; top: 16px; right: 16px;
  color: var(--accent); font-family: monospace; font-size: 12px;
  text-align: right; line-height: 1.8; pointer-events: none; z-index: 10;
}
#controls {
  position: fixed; bottom: 16px; right: 16px;
  display: flex; flex-direction: column; gap: 6px; z-index: 10;
}
.ctrl-btn {
  background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3);
  color: var(--accent); font-family: monospace; font-size: 11px;
  padding: 6px 14px; cursor: pointer; letter-spacing: 1px;
  transition: background 0.15s; text-align: center;
}
.ctrl-btn:hover { background: rgba(0,245,212,0.18); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>HILBERT CURVE</div>
  <div id="hud-order">ORDER 1</div>
  <div id="hud-pts">4 PTS</div>
</div>
<div id="controls">
  <button class="ctrl-btn" id="btn-next" onclick="nextOrder()">NEXT ORDER</button>
  <button class="ctrl-btn" id="btn-auto" onclick="toggleAuto()">AUTO: ON</button>
  <button class="ctrl-btn" onclick="resetOrder()">RESET</button>
</div>
<?php
$pageInfo = '<div>SPACE-FILLING CURVE';
require_once __DIR__ . '/includes/info-panel.php';
?>
  <div id="info-dim">GRID: 2×2</div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'next order'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  let W, H;
  let order = 1;
  let pts = [];
  let step = 0;
  let autoMode = true;
  let animId = null;
  let waiting = false;
  const MAX_ORDER = 6;

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  // Standard Hilbert curve: distance d -> (x, y) on n×n grid
  function d2xy(n, d) {
    let x = 0, y = 0, s = 1, t = d, rx, ry;
    while (s < n) {
      rx = 1 & (t >> 1);
      ry = 1 & (t ^ rx);
      if (ry === 0) {
        if (rx === 1) { x = s - 1 - x; y = s - 1 - y; }
        const tmp = x; x = y; y = tmp;
      }
      x += s * rx;
      y += s * ry;
      t >>= 2;
      s <<= 1;
    }
    return [x, y];
  }

  function generate(ord) {
    const n = 1 << ord;
    const total = n * n;
    const arr = new Array(total);
    for (let d = 0; d < total; d++) arr[d] = d2xy(n, d);
    return arr;
  }

  function cellToCanvas(gx, gy) {
    const n = 1 << order;
    const sz = Math.min(W, H) * 0.86;
    const ox = (W - sz) / 2;
    const oy = (H - sz) / 2;
    const c = sz / n;
    return [ox + (gx + 0.5) * c, oy + (gy + 0.5) * c];
  }

  function updateHUD() {
    const n = 1 << order;
    document.getElementById('hud-order').textContent = 'ORDER ' + order;
    document.getElementById('hud-pts').textContent = pts.length.toLocaleString() + ' PTS';
    document.getElementById('info-dim').textContent = 'GRID: ' + n + '\xd7' + n;
    document.getElementById('btn-auto').textContent = 'AUTO: ' + (autoMode ? 'ON' : 'OFF');
  }

  function startOrder(ord) {
    if (animId) cancelAnimationFrame(animId);
    waiting = false;
    order = Math.max(1, Math.min(MAX_ORDER, ord));
    pts = generate(order);
    step = 0;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    updateHUD();
    animId = requestAnimationFrame(frame);
  }

  function frame() {
    if (waiting) return;
    const total = pts.length;
    // Target ~2s per order at 60fps → total/120 pts/frame, min 1
    const batch = Math.max(1, Math.ceil(total / 120));
    const n = 1 << order;
    const lw = Math.max(0.5, Math.min(W, H) * 0.86 / n * 0.45);

    ctx.lineCap = 'round';
    ctx.lineWidth = lw;

    for (let i = 0; i < batch && step < total; i++, step++) {
      if (step === 0) continue;
      const t = step / total;
      // teal (175) → purple (280)
      const hue = (175 + t * 105).toFixed(0);
      ctx.strokeStyle = 'hsl(' + hue + ',80%,55%)';
      const [x0, y0] = cellToCanvas(pts[step - 1][0], pts[step - 1][1]);
      const [x1, y1] = cellToCanvas(pts[step][0], pts[step][1]);
      ctx.beginPath();
      ctx.moveTo(x0, y0);
      ctx.lineTo(x1, y1);
      ctx.stroke();
    }

    if (step >= total) {
      if (autoMode && order < MAX_ORDER) {
        waiting = true;
        setTimeout(() => startOrder(order + 1), 900);
      } else if (autoMode && order >= MAX_ORDER) {
        waiting = true;
        setTimeout(() => startOrder(1), 1200);
      }
      return;
    }

    animId = requestAnimationFrame(frame);
  }

  window.nextOrder = function() {
    startOrder(order < MAX_ORDER ? order + 1 : 1);
    SFX.play('toggle');
  };

  window.resetOrder = function() {
    startOrder(1);
    SFX.play('click');
  };

  window.toggleAuto = function() {
    autoMode = !autoMode;
    document.getElementById('btn-auto').textContent = 'AUTO: ' + (autoMode ? 'ON' : 'OFF');
    SFX.play('toggle');
    // If currently waiting and auto just turned on, restart loop
    if (autoMode && waiting) {
      waiting = false;
      startOrder(order < MAX_ORDER ? order + 1 : 1);
    }
  };

  canvas.addEventListener('click', () => {
    window.nextOrder();
  });

  canvas.addEventListener('contextmenu', e => {
    e.preventDefault();
    window.resetOrder();
  });

  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    window.nextOrder();
  }, { passive: false });

  window.addEventListener('resize', () => {
    resize();
    startOrder(order);
  });

  resize();
  startOrder(1);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
