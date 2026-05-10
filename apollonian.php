<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('apollonian');

$pageName = 'apollonian';
$pageTitle = 'APOLLONIAN - PULSE';
$pageDesc = 'アポロニウスのガスケット。3つの接円の隙間を再帰的に埋める円充填フラクタル。デカルトの定理が全体を支配する。';
$pageCanonical = 'https://pulse.subara3.com/apollonian.php';
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
#info {
  position: fixed; bottom: 16px; left: 16px;
  color: rgba(0,245,212,0.5); font-family: monospace; font-size: 11px;
  z-index: 10; pointer-events: none;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>APOLLONIAN</div>
  <div id="circle-count">0 circles</div>
  <div id="depth-display">depth: 7</div>
</div>
<div id="controls">
  <button class="ctrl-btn" onclick="adjustDepth(1)">DEPTH +</button>
  <button class="ctrl-btn" onclick="adjustDepth(-1)">DEPTH -</button>
  <button class="ctrl-btn" onclick="regen()">RESET</button>
</div>
<div id="info">DESCARTES' CIRCLE THEOREM</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'replay'],
  ['type' => 'scroll', 'label' => 'depth'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  let W, H;
  let circles = [];
  let drawOrder = [];
  let renderIdx = 0;
  let animId;
  let maxDepth = 7;

  // Complex square root (principal branch)
  function csqrt(r, i) {
    const m = Math.sqrt(r*r + i*i);
    const re = Math.sqrt((m + r) / 2);
    const im = (i >= 0 ? 1 : -1) * Math.sqrt(Math.max(0, (m - r) / 2));
    return [re, im];
  }

  // Compute the inner Soddy circle given 3 mutually tangent circles
  // Each circle: {x, y, r, k, depth}
  function soddy(a, b, c) {
    const k1 = a.k, k2 = b.k, k3 = c.k;
    const ksum = k1*k2 + k2*k3 + k3*k1;
    if (ksum < -1e-9) return null;
    const k4 = k1 + k2 + k3 + 2 * Math.sqrt(Math.max(0, ksum));
    if (k4 < 1e-9) return null;
    const r4 = 1 / k4;

    // Complex center via Descartes' theorem
    // C = k1k2*z1z2 + k2k3*z2z3 + k3k1*z3z1 (complex)
    const Cr = k1*k2*(a.x*b.x - a.y*b.y) + k2*k3*(b.x*c.x - b.y*c.y) + k3*k1*(c.x*a.x - c.y*a.y);
    const Ci = k1*k2*(a.x*b.y + a.y*b.x) + k2*k3*(b.x*c.y + b.y*c.x) + k3*k1*(c.x*a.y + c.y*a.x);
    const [sqr, sqi] = csqrt(Cr, Ci);

    const K4x = k1*a.x + k2*b.x + k3*c.x + 2*sqr;
    const K4y = k1*a.y + k2*b.y + k3*c.y + 2*sqi;

    return {
      x: K4x / k4,
      y: K4y / k4,
      r: r4,
      k: k4,
      depth: Math.max(a.depth, b.depth, c.depth) + 1
    };
  }

  function circleKey(c) {
    return `${Math.round(c.x)},${Math.round(c.y)},${Math.round(c.r * 100)}`;
  }

  function buildGasket() {
    const cx = W / 2, cy = H / 2;
    const R = Math.min(W, H) * 0.43;
    const minR = Math.max(0.8, R * Math.pow(0.16, maxDepth / 7));

    const outer = { x: cx, y: cy, r: R, k: -1/R, depth: 0 };

    const ir = R * (2 * Math.sqrt(3) - 3);
    const d = R - ir;
    const ang = [Math.PI * 1.5, Math.PI * 1.5 + 2*Math.PI/3, Math.PI * 1.5 + 4*Math.PI/3];
    const [c1, c2, c3] = ang.map(a => ({
      x: cx + d * Math.cos(a),
      y: cy + d * Math.sin(a),
      r: ir, k: 1/ir, depth: 1
    }));

    circles = [outer, c1, c2, c3];
    const seen = new Set(circles.map(circleKey));

    // BFS
    const queue = [
      [outer, c1, c2],
      [outer, c2, c3],
      [outer, c3, c1],
      [c1, c2, c3],
    ];

    let qi = 0;
    while (qi < queue.length) {
      const [a, b, c] = queue[qi++];
      const nc = soddy(a, b, c);
      if (!nc || nc.r < minR) continue;
      const key = circleKey(nc);
      if (seen.has(key)) continue;
      seen.add(key);
      circles.push(nc);
      queue.push([nc, a, b], [nc, b, c], [nc, c, a]);
    }

    // Sort: outer first, then by decreasing radius for nice progressive reveal
    circles.sort((a, b) => b.r - a.r);

    // Draw order: skip outer (index 0), show all others large→small
    drawOrder = circles.slice(1);
  }

  function circleColor(c) {
    // Map depth to hue: teal (175) → purple (275)
    const t = Math.min(1, (c.depth - 1) / (maxDepth + 1));
    const hue = 175 + t * 100;
    const light = 55 + t * 5;
    const alpha = Math.max(0.25, 0.85 - t * 0.55);
    return `hsla(${hue}, 80%, ${light}%, ${alpha})`;
  }

  const RENDER_PER_FRAME = 12;

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Outer circle
    if (circles.length > 0) {
      const o = circles[0];
      ctx.beginPath();
      ctx.arc(o.x, o.y, o.r, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,0.25)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    // Draw revealed circles
    ctx.shadowBlur = 3;
    for (let i = 0; i < renderIdx && i < drawOrder.length; i++) {
      const c = drawOrder[i];
      const col = circleColor(c);
      ctx.beginPath();
      ctx.arc(c.x, c.y, Math.max(0.5, c.r), 0, Math.PI * 2);
      ctx.strokeStyle = col;
      ctx.shadowColor = col;
      ctx.lineWidth = Math.min(1.5, c.r * 0.08 + 0.3);
      ctx.stroke();
    }
    ctx.shadowBlur = 0;

    // HUD update
    const shown = Math.min(renderIdx, drawOrder.length);
    document.getElementById('circle-count').textContent = shown + ' / ' + drawOrder.length + ' circles';
    document.getElementById('depth-display').textContent = 'depth: ' + maxDepth;

    // Advance render
    if (renderIdx < drawOrder.length) {
      renderIdx = Math.min(drawOrder.length, renderIdx + RENDER_PER_FRAME);
    }

    animId = requestAnimationFrame(draw);
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    rebuildAndPlay();
  }

  function rebuildAndPlay() {
    cancelAnimationFrame(animId);
    buildGasket();
    renderIdx = 0;
    draw();
  }

  window.regen = function() {
    rebuildAndPlay();
    SFX.play('click');
  };

  window.adjustDepth = function(delta) {
    maxDepth = Math.max(3, Math.min(10, maxDepth + delta));
    rebuildAndPlay();
    SFX.play('toggle');
  };

  canvas.addEventListener('click', () => {
    renderIdx = 0;
    SFX.play('spawn');
  });

  canvas.addEventListener('wheel', (e) => {
    e.preventDefault();
    adjustDepth(e.deltaY < 0 ? 1 : -1);
  }, { passive: false });

  canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    renderIdx = 0;
    SFX.play('spawn');
  }, { passive: false });

  window.addEventListener('resize', () => {
    cancelAnimationFrame(animId);
    resize();
  });

  resize();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
