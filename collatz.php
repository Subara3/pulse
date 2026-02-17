<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('collatz');

$pageName = 'collatz';
$pageTitle = 'COLLATZ - PULSE';
$pageDesc = 'コラッツ予想。すべての正の整数は1に辿り着く——まだ誰も証明していない。';
$pageCanonical = 'https://pulse.subara3.com/collatz.php';

$extraStyle = '
body {
  background: #0a0a0f;
  min-height: 100vh;
  overflow: hidden;
  margin: 0;
}

#canvas {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.ui {
  position: fixed;
  z-index: 1000;
  font-family: "Orbitron", sans-serif;
  pointer-events: none;
}

.ui * { pointer-events: auto; }

.hud {
  top: 1.5rem;
  right: 1.5rem;
  color: rgba(255,255,255,0.35);
  font-size: 0.65rem;
  text-align: right;
  letter-spacing: 0.05em;
  line-height: 2;
}

.hud .val {
  color: #00f5d4;
  font-weight: 700;
}

.hud .mode-name {
  color: #7b2cbf;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.2em;
  margin-bottom: 0.3rem;
}

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.25);
  font-size: 0.6rem;
  line-height: 1.8;
  pointer-events: none;
}

.formula {
  color: rgba(0,245,212,0.5);
  font-family: serif;
  font-style: italic;
}

.angle-wrap {
  bottom: 6rem;
  left: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.angle-wrap label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  letter-spacing: 0.05em;
  min-width: 5rem;
}

.angle-wrap input[type="range"] {
  -webkit-appearance: none;
  appearance: none;
  width: 120px;
  height: 3px;
  background: rgba(255,255,255,0.15);
  border-radius: 2px;
  outline: none;
}

.angle-wrap input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #00f5d4;
  cursor: pointer;
  border: 2px solid #0a0a0f;
  box-shadow: 0 0 6px rgba(0,245,212,0.4);
}

.angle-wrap input[type="range"]::-moz-range-thumb {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #00f5d4;
  cursor: pointer;
  border: 2px solid #0a0a0f;
  box-shadow: 0 0 6px rgba(0,245,212,0.4);
}

.hide-ui .hud,
.hide-ui .info,
.hide-ui .angle-wrap,
.hide-ui .content-controls,
.hide-ui .floating-nav {
  opacity: 0 !important;
  pointer-events: none;
}

@media (max-width: 600px) {
  .hud {
    font-size: 0.55rem;
    top: 1rem;
    right: 1rem;
  }
  .info {
    bottom: 1rem;
    left: 1rem;
    font-size: 0.5rem;
  }
  .angle-wrap {
    bottom: 5rem;
    left: 1rem;
  }
  .angle-wrap input[type="range"] {
    width: 80px;
  }
}
';

$controls = [
  ['id' => 'modeBtn', 'icon' => 'fa-shuffle', 'label' => 'MODE'],
  ['id' => 'resetBtn', 'icon' => 'fa-rotate-right', 'label' => 'RESET'],
  ['id' => 'colorBtn', 'icon' => 'fa-palette', 'label' => 'COLOR'],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud" id="hud">
  <div class="mode-name" id="modeName">TREE</div>
  <div id="hudStats"></div>
</div>

<div class="ui info">
  Press H to hide UI<br>
  <span class="formula">n &rarr; n / 2 (even)</span><br>
  <span class="formula">n &rarr; 3n + 1 (odd)</span><br>
  <span style="color:rgba(123,44,191,0.5);font-size:0.55rem;">Does it always reach 1?</span>
</div>

<div class="ui angle-wrap" id="angleWrap">
  <label id="angleLabel">ANGLE: 8&deg;</label>
  <input type="range" id="angleSlider" min="2" max="25" value="8">
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const modeName = document.getElementById('modeName');
  const hudStats = document.getElementById('hudStats');
  const angleWrap = document.getElementById('angleWrap');
  const angleSlider = document.getElementById('angleSlider');
  const angleLabel = document.getElementById('angleLabel');

  let width, height;
  const MODES = ['TREE', 'TRAJECTORY', 'STOPPING TIME'];
  let mode = 0;
  let paused = false;

  // Enhanced color schemes with glow colors
  const COLORS = [
    { a: [0, 245, 212], b: [123, 44, 191], glow: [0, 255, 220] },
    { a: [255, 107, 107], b: [254, 202, 87], glow: [255, 150, 150] },
    { a: [72, 219, 251], b: [200, 200, 255], glow: [150, 240, 255] },
    { a: [57, 255, 20], b: [255, 0, 255], glow: [100, 255, 100] },
  ];
  let colorIdx = 0;

  function lerpColor(c, t, alpha) {
    const r = Math.round(c.a[0] + (c.b[0] - c.a[0]) * t);
    const g = Math.round(c.a[1] + (c.b[1] - c.a[1]) * t);
    const b = Math.round(c.a[2] + (c.b[2] - c.a[2]) * t);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function rgb(c, a) {
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
  }

  // Collatz math
  function collatzSeq(n) {
    const seq = [n];
    while (n !== 1 && seq.length < 10000) {
      n = n % 2 === 0 ? n / 2 : 3 * n + 1;
      seq.push(n);
    }
    return seq;
  }

  function stoppingTime(n) {
    var s = 0;
    while (n !== 1 && s < 10000) {
      n = n % 2 === 0 ? n / 2 : 3 * n + 1;
      s++;
    }
    return s;
  }

  // ============================================
  //  Floating particles (background effect)
  // ============================================
  let particles = [];
  const PARTICLE_COUNT = 50;

  function initParticles() {
    particles = [];
    for (let i = 0; i < PARTICLE_COUNT; i++) {
      particles.push({
        x: Math.random() * width,
        y: Math.random() * height,
        vx: (Math.random() - 0.5) * 0.3,
        vy: (Math.random() - 0.5) * 0.3,
        r: Math.random() * 2 + 0.5,
        alpha: Math.random() * 0.3 + 0.1
      });
    }
  }

  function updateParticles() {
    const c = COLORS[colorIdx];
    for (const p of particles) {
      p.x += p.vx;
      p.y += p.vy;
      if (p.x < 0) p.x = width;
      if (p.x > width) p.x = 0;
      if (p.y < 0) p.y = height;
      if (p.y > height) p.y = 0;

      // Subtle glow
      const grad = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r * 4);
      grad.addColorStop(0, rgb(c.glow, p.alpha * 0.5));
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r * 4, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = rgb(c.a, p.alpha);
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  // ============================================
  //  TREE MODE - Enhanced with glow and endpoints
  // ============================================
  let treeN = 2;
  const isMobile = window.innerWidth <= 768;
  const TREE_MAX = isMobile ? 4000 : 8000;
  const TREE_PER_FRAME = isMobile ? 3 : 5;
  let treeAngle = 8;
  let treeEndpoints = []; // Store endpoints for glow effect

  function resetTree() {
    treeN = 2;
    treeEndpoints = [];
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
  }

  function drawTree() {
    const c = COLORS[colorIdx];
    
    // Once done, animate endpoints
    if (treeN > TREE_MAX) {
      // Pulsing base glow
      const ga = 0.15 + Math.sin(Date.now() * 0.003) * 0.08;
      const bx = width / 2, by = height * 0.85;
      const g = ctx.createRadialGradient(bx, by, 0, bx, by, 60);
      g.addColorStop(0, rgb(c.a, ga));
      g.addColorStop(0.5, rgb(c.b, ga * 0.3));
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(bx, by, 60, 0, Math.PI * 2);
      ctx.fill();

      // Animate random endpoint sparkles
      const t = Date.now() * 0.001;
      for (let i = 0; i < 10; i++) {
        const idx = Math.floor((t * 50 + i * 137) % treeEndpoints.length);
        const ep = treeEndpoints[idx];
        if (!ep) continue;
        const sparkle = 0.3 + Math.sin(t * 5 + i) * 0.2;
        ctx.fillStyle = rgb(c.glow, sparkle);
        ctx.beginPath();
        ctx.arc(ep.x, ep.y, 2, 0, Math.PI * 2);
        ctx.fill();
      }
      return;
    }

    const angleRad = treeAngle * Math.PI / 180;
    const len = Math.min(width, height) / 280; // Slightly longer branches

    for (let i = 0; i < TREE_PER_FRAME && treeN <= TREE_MAX; i++) {
      const seq = collatzSeq(treeN);
      seq.reverse();

      let x = width / 2;
      let y = height * 0.85;
      let dir = -Math.PI / 2;
      const progress = treeN / TREE_MAX;
      const seqLen = seq.length;

      // Draw with gradient along path
      ctx.beginPath();
      ctx.moveTo(x, y);

      const points = [{x, y}];
      for (let j = 1; j < seqLen; j++) {
        if (seq[j] % 2 === 0) {
          dir += angleRad;
        } else {
          dir -= angleRad;
        }
        x += Math.cos(dir) * len;
        y += Math.sin(dir) * len;
        points.push({x, y});
      }

      // Draw with varying alpha along path (brighter at tips)
      for (let j = 1; j < points.length; j++) {
        const segProgress = j / points.length;
        const alpha = 0.05 + segProgress * 0.15; // Brighter towards end
        ctx.beginPath();
        ctx.moveTo(points[j-1].x, points[j-1].y);
        ctx.lineTo(points[j].x, points[j].y);
        ctx.strokeStyle = lerpColor(c, progress, alpha);
        ctx.lineWidth = 1;
        ctx.stroke();
      }

      // Store endpoint and draw glow
      const ep = points[points.length - 1];
      treeEndpoints.push(ep);
      
      // Endpoint glow
      const glowGrad = ctx.createRadialGradient(ep.x, ep.y, 0, ep.x, ep.y, 8);
      glowGrad.addColorStop(0, rgb(c.glow, 0.3));
      glowGrad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = glowGrad;
      ctx.beginPath();
      ctx.arc(ep.x, ep.y, 8, 0, Math.PI * 2);
      ctx.fill();

      // Bright endpoint dot
      ctx.fillStyle = rgb(c.a, 0.6);
      ctx.beginPath();
      ctx.arc(ep.x, ep.y, 1.5, 0, Math.PI * 2);
      ctx.fill();

      treeN++;
    }
  }

  // ============================================
  //  TRAJECTORY MODE - Enhanced with trail effects
  // ============================================
  let trajN = 27;
  let trajSeq = [];
  let trajMax = 0;
  let trajStep = 0;
  let trajDone = false;
  let trajWait = 0;
  let flashAlpha = 0;
  let trajHistory = []; // Trail history for glow

  function initTraj(n) {
    if (n < 2) n = 2;
    trajN = n;
    trajSeq = collatzSeq(n);
    trajMax = 0;
    for (let i = 0; i < trajSeq.length; i++) {
      if (trajSeq[i] > trajMax) trajMax = trajSeq[i];
    }
    trajStep = 0;
    trajDone = false;
    trajWait = 0;
    flashAlpha = 1.0;
    trajHistory = [];
  }

  function drawTrajectory() {
    // Fade background instead of clearing
    ctx.fillStyle = 'rgba(10, 10, 15, 0.15)';
    ctx.fillRect(0, 0, width, height);

    const c = COLORS[colorIdx];
    const total = trajSeq.length;

    // Large number watermark with pulse
    if (flashAlpha > 0) {
      const pulse = 1 + Math.sin(Date.now() * 0.01) * 0.05;
      ctx.save();
      ctx.fillStyle = rgb(c.a, flashAlpha * 0.12);
      ctx.font = 'bold ' + (Math.min(width, height) * 0.25 * pulse) + 'px "Orbitron"';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(trajN.toString(), width / 2, height / 2);
      ctx.restore();
      flashAlpha -= 0.012;
    }

    // Auto-advance when done
    if (trajDone) {
      trajWait++;
      if (trajWait > 90) initTraj(trajN + 1);
    }

    // Graph area
    const m = { t: 80, r: 80, b: 60, l: 80 };
    const gw = width - m.l - m.r;
    const gh = height - m.t - m.b;

    // Grid with glow
    ctx.strokeStyle = 'rgba(255,255,255,0.03)';
    ctx.lineWidth = 0.5;
    for (let i = 0; i <= 4; i++) {
      const gy = m.t + (i / 4) * gh;
      ctx.beginPath();
      ctx.moveTo(m.l, gy);
      ctx.lineTo(width - m.r, gy);
      ctx.stroke();
    }

    // Advance animation
    let shown = Math.min(trajStep + 2, total - 1);
    if (!trajDone) trajStep = shown;
    if (shown >= total - 1 && !trajDone) {
      trajDone = true;
      trajWait = 0;
    }

    const drawTo = trajDone ? total - 1 : shown;

    // Update history
    if (!trajDone && drawTo > 0) {
      const px = m.l + (drawTo / Math.max(total - 1, 1)) * gw;
      const py = m.t + gh - (trajSeq[drawTo] / trajMax) * gh;
      trajHistory.push({x: px, y: py, age: 0, val: trajSeq[drawTo], prev: trajSeq[drawTo - 1] || trajSeq[drawTo]});
    }

    // Draw history trail with fade
    for (let i = trajHistory.length - 1; i >= 0; i--) {
      const h = trajHistory[i];
      h.age++;
      const alpha = Math.max(0, 1 - h.age / 60);
      if (alpha <= 0) {
        trajHistory.splice(i, 1);
        continue;
      }
      
      // Color based on direction (up = purple, down = teal)
      const goingUp = h.val > h.prev;
      const col = goingUp ? c.b : c.a;
      
      const grad = ctx.createRadialGradient(h.x, h.y, 0, h.x, h.y, 15);
      grad.addColorStop(0, rgb(col, alpha * 0.4));
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.arc(h.x, h.y, 15, 0, Math.PI * 2);
      ctx.fill();
    }

    // Draw full trajectory path with glow
    ctx.beginPath();
    for (let i = 0; i <= drawTo; i++) {
      const px = m.l + (i / Math.max(total - 1, 1)) * gw;
      const py = m.t + gh - (trajSeq[i] / trajMax) * gh;
      if (i === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    // Outer glow
    ctx.strokeStyle = rgb(c.glow, 0.2);
    ctx.lineWidth = 6;
    ctx.stroke();
    // Main line
    ctx.strokeStyle = rgb(c.a, 0.9);
    ctx.lineWidth = 2;
    ctx.stroke();

    // Current point with enhanced glow
    if (!trajDone) {
      const cx = m.l + (drawTo / Math.max(total - 1, 1)) * gw;
      const cy = m.t + gh - (trajSeq[drawTo] / trajMax) * gh;

      // Outer glow rings
      for (let r = 30; r >= 10; r -= 10) {
        const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r);
        grad.addColorStop(0, rgb(c.glow, 0.15));
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.fill();
      }

      // Core dot
      ctx.beginPath();
      ctx.arc(cx, cy, 4, 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();

      // Value label with background
      ctx.save();
      const label = trajSeq[drawTo].toLocaleString();
      ctx.font = 'bold 11px "Orbitron"';
      const tw = ctx.measureText(label).width;
      ctx.fillStyle = 'rgba(10,10,15,0.8)';
      ctx.fillRect(cx + 10, cy - 20, tw + 8, 16);
      ctx.fillStyle = rgb(c.a, 0.9);
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, cx + 14, cy - 12);
      ctx.restore();
    }

    // Arrival marker with celebration
    if (trajDone) {
      const endX = m.l + gw;
      const endY = m.t + gh;
      
      // Pulsing "1" celebration
      const pulse = 1 + Math.sin(trajWait * 0.1) * 0.1;
      ctx.save();
      ctx.fillStyle = rgb(c.b, 0.8);
      ctx.font = 'bold ' + (20 * pulse) + 'px "Orbitron"';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'top';
      ctx.fillText('→ 1', endX, endY + 8);
      ctx.restore();

      // Sparkle particles at endpoint
      for (let i = 0; i < 3; i++) {
        const angle = (trajWait * 0.05 + i * 2.1);
        const dist = 15 + Math.sin(trajWait * 0.1 + i) * 5;
        const sx = endX - 20 + Math.cos(angle) * dist;
        const sy = endY + Math.sin(angle) * dist;
        ctx.fillStyle = rgb(c.glow, 0.5);
        ctx.beginPath();
        ctx.arc(sx, sy, 2, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  // ============================================
  //  STOPPING TIME MODE - Enhanced scatter
  // ============================================
  let stopN = 1;
  const STOP_MAX = isMobile ? 5000 : 10000;
  const STOP_PER_FRAME = isMobile ? 8 : 15;
  let maxST = 0;
  let stopData = []; // Store for animation

  function resetStop() {
    stopN = 1;
    maxST = 0;
    stopData = [];
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
  }

  function drawStopTime() {
    const c = COLORS[colorIdx];
    const mm = { t: 60, r: 60, b: 60, l: 60 };
    const gw = width - mm.l - mm.r;
    const gh = height - mm.t - mm.b;
    const stCeil = 600;

    // Slight fade for trail effect
    ctx.fillStyle = 'rgba(10, 10, 15, 0.02)';
    ctx.fillRect(0, 0, width, height);

    if (stopN <= STOP_MAX) {
      for (let i = 0; i < STOP_PER_FRAME && stopN <= STOP_MAX; i++) {
        const st = stoppingTime(stopN);
        if (st > maxST) maxST = st;

        const x = mm.l + (stopN / STOP_MAX) * gw;
        const y = mm.t + gh - (st / stCeil) * gh;
        const t = Math.min(st / stCeil, 1);

        stopData.push({n: stopN, st, x, y, t, birth: Date.now()});

        // Glow for new points
        const grad = ctx.createRadialGradient(x, y, 0, x, y, 8);
        grad.addColorStop(0, lerpColor(c, t, 0.6));
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(x, y, 8, 0, Math.PI * 2);
        ctx.fill();

        // Core dot
        ctx.beginPath();
        ctx.arc(x, y, 2, 0, Math.PI * 2);
        ctx.fillStyle = lerpColor(c, t, 0.9);
        ctx.fill();

        stopN++;
      }
    }

    // Animate recent points with sparkle
    const now = Date.now();
    const recent = stopData.slice(-50);
    for (const p of recent) {
      const age = now - p.birth;
      if (age < 500) {
        const alpha = (1 - age / 500) * 0.5;
        ctx.fillStyle = rgb(c.glow, alpha);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4 + (1 - age / 500) * 4, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    // Highlight bands of similar stopping times
    if (stopN > STOP_MAX) {
      const t = Date.now() * 0.0005;
      const highlightST = Math.floor((Math.sin(t) * 0.5 + 0.5) * 400 + 50);
      
      for (const p of stopData) {
        if (Math.abs(p.st - highlightST) < 5) {
          ctx.fillStyle = rgb(c.glow, 0.3);
          ctx.beginPath();
          ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
          ctx.fill();
        }
      }

      // Label
      ctx.save();
      ctx.fillStyle = rgb(c.a, 0.5);
      ctx.font = '10px "Orbitron"';
      ctx.textAlign = 'left';
      ctx.fillText('HIGHLIGHTING: ' + highlightST + ' STEPS', mm.l, mm.t - 10);
      ctx.restore();
    }
  }

  // ============================================
  //  HUD
  // ============================================
  function updateHud() {
    modeName.textContent = MODES[mode];
    let s = '';
    switch (mode) {
      case 0:
        s = 'DRAWN: <span class="val">' + Math.min(treeN - 1, TREE_MAX).toLocaleString() + '</span> / ' + TREE_MAX.toLocaleString() + '<br>';
        s += 'ANGLE: <span class="val">' + treeAngle + '&deg;</span>';
        angleWrap.style.display = 'flex';
        break;
      case 1:
        s = 'N: <span class="val">' + trajN.toLocaleString() + '</span><br>';
        s += 'STEPS: <span class="val">' + (trajSeq.length - 1) + '</span><br>';
        s += 'PEAK: <span class="val">' + trajMax.toLocaleString() + '</span>';
        angleWrap.style.display = 'none';
        break;
      case 2:
        s = 'CHECKED: <span class="val">' + Math.min(stopN - 1, STOP_MAX).toLocaleString() + '</span><br>';
        s += 'MAX STEPS: <span class="val">' + maxST + '</span>';
        angleWrap.style.display = 'none';
        break;
    }
    hudStats.innerHTML = s;
  }

  // ============================================
  //  MAIN LOOP
  // ============================================
  function loop() {
    if (!paused) {
      // Clear for tree mode only (others use fade)
      if (mode === 0) {
        // Tree draws over itself
      }
      
      // Background particles
      updateParticles();

      switch (mode) {
        case 0: drawTree(); break;
        case 1: drawTrajectory(); break;
        case 2: drawStopTime(); break;
      }
    }
    updateHud();
    requestAnimationFrame(loop);
  }

  function switchMode() {
    mode = (mode + 1) % MODES.length;
    resetAll();
    SFX.play('toggle');
  }

  function resetAll() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
    initParticles();
    switch (mode) {
      case 0: resetTree(); break;
      case 1: initTraj(27); break;
      case 2: resetStop(); break;
    }
  }

  // ============================================
  //  EVENTS
  // ============================================
  document.getElementById('modeBtn').addEventListener('click', switchMode);
  document.getElementById('resetBtn').addEventListener('click', function() {
    resetAll();
    SFX.play('click');
  });
  document.getElementById('colorBtn').addEventListener('click', function() {
    colorIdx = (colorIdx + 1) % COLORS.length;
    if (mode !== 1) resetAll();
    SFX.play('click');
  });

  angleSlider.addEventListener('input', function() {
    treeAngle = parseInt(this.value);
    angleLabel.textContent = 'ANGLE: ' + treeAngle + '\u00b0';
    if (mode === 0) resetTree();
  });

  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT') return;
    switch (e.key) {
      case 'h': case 'H':
        document.body.classList.toggle('hide-ui');
        break;
      case ' ':
        e.preventDefault();
        paused = !paused;
        break;
      case 'ArrowRight':
        if (mode === 1) { e.preventDefault(); initTraj(trajN + 1); }
        break;
      case 'ArrowLeft':
        if (mode === 1 && trajN > 2) { e.preventDefault(); initTraj(trajN - 1); }
        break;
      case 'ArrowUp':
        if (mode === 1) { e.preventDefault(); initTraj(trajN + 10); }
        break;
      case 'ArrowDown':
        if (mode === 1 && trajN > 11) { e.preventDefault(); initTraj(trajN - 10); }
        break;
    }
  });

  // Click to add trajectory points in mode 1
  canvas.addEventListener('click', function(e) {
    if (mode === 1) {
      const input = prompt('Enter a number to trace:', trajN + 1);
      if (input) {
        const n = parseInt(input);
        if (!isNaN(n) && n >= 2) {
          initTraj(n);
          SFX.play('spawn');
        }
      }
    }
  });

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    resetAll();
  }

  window.addEventListener('resize', resize);

  // Init
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
