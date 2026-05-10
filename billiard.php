<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('billiard');

$pageName = 'billiard';
$pageTitle = 'BILLIARD - PULSE';
$pageDesc = 'カオスビリヤード。スタジアム型テーブルではカオスが、楕円・円形では規則的な軌跡が生まれる。';
$pageCanonical = 'https://pulse.subara3.com/billiard.php';
$extraStyle = '
#hud{position:fixed;top:1.2rem;right:1.2rem;font-family:monospace;font-size:.7rem;color:rgba(0,245,212,.6);text-align:right;pointer-events:none;line-height:1.7}

#controls button{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.25);color:rgba(0,245,212,.8);font-family:monospace;font-size:.65rem;padding:.3rem .65rem;cursor:pointer;letter-spacing:.05em;transition:background .15s}
#controls button:hover{background:rgba(0,245,212,.15)}
#controls button.active{background:rgba(0,245,212,.18);color:#00f5d4;border-color:rgba(0,245,212,.5)}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div id="mode-label">STADIUM</div>
  <div id="ball-count">25 BALLS</div>
</div>
<div id="controls" class="drawer-controls">
  <button id="btn-0" class="mode-btn active" onclick="setMode(0)">STADIUM</button>
  <button id="btn-1" class="mode-btn" onclick="setMode(1)">CIRCLE</button>
  <button id="btn-2" class="mode-btn" onclick="setMode(2)">ELLIPSE</button>
  <button id="btn-3" class="mode-btn" onclick="setMode(3)">SQUARE</button>
  <button onclick="resetBalls()">RESET</button>
</div>
<?php
$pageInfo = [
  'billiard dynamics · chaos · ergodic theory',
];
require_once __DIR__ . '/includes/info-panel.php';
?>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add ball'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  const cv = document.getElementById('canvas');
  const ctx = cv.getContext('2d');
  let W, H, cx, cy, tW, tH, tR;

  const MODES = ['STADIUM','CIRCLE','ELLIPSE','SQUARE'];
  let mode = 0;
  const SPEED = 2.0, SUB = 5, MAX_N = 40, INIT_N = 25;
  let balls = [];

  function resize() {
    cv.width = W = window.innerWidth;
    cv.height = H = window.innerHeight;
    cx = W / 2; cy = H / 2;
    const m = Math.min(W, H);
    tR = m * 0.40;
    tW = m * 0.76;
    tH = m * 0.44;
    resetBalls(false);
  }

  // Stadium helpers
  const sr = () => tH / 2;
  const ssw = () => (tW - tH) / 2;

  function inside(x, y) {
    const lx = x - cx, ly = y - cy;
    if (mode === 0) {
      const r = sr(), s = ssw();
      if (lx < -s) return (lx + s) * (lx + s) + ly * ly < r * r;
      if (lx >  s) return (lx - s) * (lx - s) + ly * ly < r * r;
      return Math.abs(ly) < r;
    }
    if (mode === 1) return lx * lx + ly * ly < tR * tR;
    if (mode === 2) { const a = tW / 2, b = tH / 2; return (lx/a)*(lx/a) + (ly/b)*(ly/b) < 1; }
    return Math.abs(lx) < tW / 2 && Math.abs(ly) < tH / 2;
  }

  function norm(lx, ly) {
    if (mode === 0) {
      const s = ssw();
      if (lx <= -s) { const d = Math.hypot(lx+s, ly) || 1; return [(lx+s)/d, ly/d]; }
      if (lx >=  s) { const d = Math.hypot(lx-s, ly) || 1; return [(lx-s)/d, ly/d]; }
      return [0, Math.sign(ly) || 1];
    }
    if (mode === 1) { const d = Math.hypot(lx, ly) || 1; return [lx/d, ly/d]; }
    if (mode === 2) {
      const a = tW/2, b = tH/2, gx = lx/(a*a), gy = ly/(b*b);
      const d = Math.hypot(gx, gy) || 1; return [gx/d, gy/d];
    }
    const ex = Math.abs(lx) - tW/2, ey = Math.abs(ly) - tH/2;
    return ex > ey ? [Math.sign(lx) || 1, 0] : [0, Math.sign(ly) || 1];
  }

  function proj(lx, ly) {
    const e = 1.5;
    if (mode === 0) {
      const r = sr() - e, s = ssw();
      if (lx <= -s) { const d = Math.hypot(lx+s, ly)||1; return [-s+(lx+s)/d*r, ly/d*r]; }
      if (lx >=  s) { const d = Math.hypot(lx-s, ly)||1; return [ s+(lx-s)/d*r, ly/d*r]; }
      return [lx, Math.sign(ly || 1) * r];
    }
    if (mode === 1) { const d = Math.hypot(lx, ly)||1, r = tR - e; return [lx/d*r, ly/d*r]; }
    if (mode === 2) {
      const a = tW/2 - e, b = tH/2 - e;
      const t = Math.hypot(lx/a, ly/b) || 1; return [lx/t, ly/t];
    }
    const hw = tW/2 - e, hh = tH/2 - e;
    return [Math.max(-hw, Math.min(hw, lx)), Math.max(-hh, Math.min(hh, ly))];
  }

  function refl(vx, vy, nx, ny) {
    const d = vx*nx + vy*ny;
    return [vx - 2*d*nx, vy - 2*d*ny];
  }

  function stepBall(b) {
    const px = b.x, py = b.y;
    let lx = px - cx + b.vx;
    let ly = py - cy + b.vy;
    if (!inside(cx + lx, cy + ly)) {
      const [nx, ny] = norm(lx, ly);
      [b.vx, b.vy] = refl(b.vx, b.vy, nx, ny);
      [lx, ly] = proj(lx, ly);
      if (!inside(cx + lx, cy + ly)) { lx *= 0.88; ly *= 0.88; }
      SFX.play('collide', 80);
    }
    b.x = cx + lx; b.y = cy + ly;
    return {x1: px, y1: py, x2: b.x, y2: b.y};
  }

  function makeBall(x, y) {
    const a = Math.random() * Math.PI * 2;
    // Avoid very shallow angles to prevent edge sticking
    const vx = Math.cos(a) * SPEED;
    const vy = Math.sin(a) * SPEED;
    return {x, y, vx, vy, hue: 165 + Math.random() * 115, segs: []};
  }

  function rndIn() {
    for (let i = 0; i < 400; i++) {
      const x = cx + (Math.random() - 0.5) * tW * 0.7;
      const y = cy + (Math.random() - 0.5) * tH * 0.7;
      if (inside(x, y)) return [x, y];
    }
    return [cx, cy];
  }

  window.resetBalls = function(sfx = true) {
    balls = Array.from({length: INIT_N}, () => { const [x, y] = rndIn(); return makeBall(x, y); });
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    if (sfx) SFX.play('click');
    document.getElementById('ball-count').textContent = INIT_N + ' BALLS';
  };

  window.setMode = function(idx) {
    mode = idx;
    document.getElementById('mode-label').textContent = MODES[idx];
    document.querySelectorAll('#controls button.mode-btn').forEach((b, i) => b.classList.toggle('active', i === idx));
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    balls = Array.from({length: INIT_N}, () => { const [x, y] = rndIn(); return makeBall(x, y); });
    document.getElementById('ball-count').textContent = INIT_N + ' BALLS';
    SFX.play('toggle');
  };

  function drawBorder() {
    ctx.save();
    ctx.strokeStyle = 'rgba(0,245,212,.2)';
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 6]);
    ctx.beginPath();
    if (mode === 0) {
      const r = sr(), s = ssw();
      ctx.moveTo(cx - s, cy - r); ctx.lineTo(cx + s, cy - r);
      ctx.arc(cx + s, cy, r, -Math.PI/2, Math.PI/2);
      ctx.lineTo(cx - s, cy + r);
      ctx.arc(cx - s, cy, r, Math.PI/2, -Math.PI/2);
      ctx.closePath();
    } else if (mode === 1) {
      ctx.arc(cx, cy, tR, 0, Math.PI * 2);
    } else if (mode === 2) {
      ctx.ellipse(cx, cy, tW/2, tH/2, 0, 0, Math.PI * 2);
    } else {
      ctx.rect(cx - tW/2, cy - tH/2, tW, tH);
    }
    ctx.stroke();
    ctx.restore();
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,.055)';
    ctx.fillRect(0, 0, W, H);
    drawBorder();
    for (const b of balls) {
      if (!b.segs || !b.segs.length) continue;
      ctx.strokeStyle = `hsl(${b.hue},75%,55%)`;
      ctx.lineWidth = 0.85;
      ctx.beginPath();
      for (const s of b.segs) { ctx.moveTo(s.x1, s.y1); ctx.lineTo(s.x2, s.y2); }
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(b.x, b.y, 1.7, 0, Math.PI * 2);
      ctx.fillStyle = `hsl(${b.hue},90%,80%)`;
      ctx.fill();
    }
  }

  function loop() {
    for (const b of balls) {
      b.segs = [];
      for (let s = 0; s < SUB; s++) b.segs.push(stepBall(b));
    }
    draw();
    requestAnimationFrame(loop);
  }

  cv.addEventListener('click', e => {
    if (balls.length >= MAX_N) return;
    balls.push(makeBall(e.clientX, e.clientY));
    document.getElementById('ball-count').textContent = balls.length + ' BALLS';
    SFX.play('spawn');
  });

  cv.addEventListener('contextmenu', e => {
    e.preventDefault();
    balls = [];
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    document.getElementById('ball-count').textContent = '0 BALLS';
    SFX.play('click');
  });

  cv.addEventListener('touchstart', e => {
    e.preventDefault();
    if (balls.length >= MAX_N) return;
    const t = e.changedTouches[0];
    balls.push(makeBall(t.clientX, t.clientY));
    document.getElementById('ball-count').textContent = balls.length + ' BALLS';
    SFX.play('spawn');
  }, {passive: false});

  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
