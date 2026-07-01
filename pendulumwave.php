<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pendulumwave');

$pageName = 'pendulumwave';
$pageTitle = 'PENDULUMWAVE - PULSE';
$pageDesc = "周期の異なる振り子群が織りなす集団的位相パターン。60秒で完全再同期。";
$pageCanonical = 'https://pulse.subara3.com/pendulumwave.php';
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
.timer-bar {
  position:fixed; top:0; left:0; right:0;
  height:2px; background:rgba(0,245,212,0.08);
}
.timer-fill {
  height:100%; background:rgba(0,245,212,0.4);
  transition:width 0.1s linear;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="timer-bar"><div class="timer-fill" id="timerFill"></div></div>

<div id="controls" class="controls drawer-controls">
  <button class="btn" id="btnReset">RESET</button>
  <button class="btn" id="btnTrail">TRAIL: ON</button>
  <button class="btn" id="btnCount">N: 20</button>
</div>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<div class="info" id="info">
  T: 0.0s<br>
  PHASE
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'reset'],
  ['type' => 'drag', 'label' => 'speed'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const timerFill = document.getElementById('timerFill');
  const info = document.getElementById('info');

  const T_SYNC = 60;   // seconds to full resync
  const AMPLITUDE = 0.4; // radians (~23 degrees)
  const MAX_TRAIL = 120;
  const TRAIL_MODES = ['WAVE', 'SPIRAL', 'SPLIT', 'CHAOS'];

  let N = 20;
  let startTime = null;
  let timeScale = 1.0;
  let showTrail = true;
  let trails = [];
  let dragStartX = null;
  let dragStartScale = null;

  function buildPendulums() {
    trails = [];
    for (let i = 0; i < N; i++) {
      trails.push([]);
    }
  }

  function getPeriod(i) {
    // pendulum i completes (N+1+i) full oscillations in T_SYNC seconds
    return T_SYNC / (N + 1 + i);
  }

  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
  }

  function getPhaseLabel(t) {
    const cycle = t % T_SYNC;
    const frac = cycle / T_SYNC;
    if (frac < 0.05 || frac > 0.95) return 'SYNC';
    if (frac < 0.20) return 'WAVE';
    if (frac < 0.40) return 'SPLIT';
    if (frac < 0.60) return 'SPIRAL';
    if (frac < 0.80) return 'CHAOS';
    return 'REFORM';
  }

  function draw(timestamp) {
    if (!startTime) startTime = timestamp;
    const elapsed = (timestamp - startTime) / 1000 * timeScale;

    const W = canvas.width, H = canvas.height;
    const pivotY = H * 0.10;
    const displayLen = H * 0.70;
    const bobR = Math.max(4, Math.min(W, H) * 0.011);
    const marginX = W * 0.07;
    const spacing = (W - 2 * marginX) / (N - 1);

    // Background fade
    ctx.fillStyle = showTrail ? 'rgba(10,10,15,0.18)' : '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Compute bob positions
    const bobs = [];
    for (let i = 0; i < N; i++) {
      const period = getPeriod(i);
      const angle = AMPLITUDE * Math.cos(2 * Math.PI * elapsed / period);
      const px = marginX + i * spacing;
      const bx = px + displayLen * Math.sin(angle);
      const by = pivotY + displayLen * Math.cos(angle);
      bobs.push({ px, bx, by, angle });
    }

    // Update trails
    if (showTrail) {
      for (let i = 0; i < N; i++) {
        trails[i].push({ x: bobs[i].bx, y: bobs[i].by });
        if (trails[i].length > MAX_TRAIL) trails[i].shift();
      }
    }

    // Draw trails
    if (showTrail) {
      for (let i = 0; i < N; i++) {
        const tr = trails[i];
        if (tr.length < 2) continue;
        const ratio = i / (N - 1);
        const hue = 175 + ratio * 105;
        for (let j = 1; j < tr.length; j++) {
          const a = (j / tr.length) * 0.35;
          ctx.beginPath();
          ctx.moveTo(tr[j-1].x, tr[j-1].y);
          ctx.lineTo(tr[j].x, tr[j].y);
          ctx.strokeStyle = `hsla(${hue},80%,60%,${a})`;
          ctx.lineWidth = 1.2;
          ctx.stroke();
        }
      }
    }

    // Draw strings
    for (let i = 0; i < N; i++) {
      const ratio = i / (N - 1);
      const hue = 175 + ratio * 105;
      ctx.beginPath();
      ctx.moveTo(bobs[i].px, pivotY);
      ctx.lineTo(bobs[i].bx, bobs[i].by);
      ctx.strokeStyle = `hsla(${hue},60%,50%,0.2)`;
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    // Draw connecting curve through bobs (catmull-rom-like)
    if (bobs.length > 1) {
      ctx.beginPath();
      ctx.moveTo(bobs[0].bx, bobs[0].by);
      for (let i = 1; i < bobs.length - 1; i++) {
        const mx = (bobs[i].bx + bobs[i+1].bx) / 2;
        const my = (bobs[i].by + bobs[i+1].by) / 2;
        ctx.quadraticCurveTo(bobs[i].bx, bobs[i].by, mx, my);
      }
      ctx.lineTo(bobs[bobs.length-1].bx, bobs[bobs.length-1].by);
      ctx.strokeStyle = 'rgba(0,245,212,0.25)';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }

    // Draw pivot bar
    ctx.beginPath();
    ctx.moveTo(marginX - 10, pivotY);
    ctx.lineTo(marginX + (N-1)*spacing + 10, pivotY);
    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // Draw bobs
    for (let i = 0; i < N; i++) {
      const ratio = i / (N - 1);
      const hue = 175 + ratio * 105;
      const { bx, by } = bobs[i];

      // Glow
      const grd = ctx.createRadialGradient(bx, by, 0, bx, by, bobR * 3);
      grd.addColorStop(0, `hsla(${hue},85%,65%,0.25)`);
      grd.addColorStop(1, 'transparent');
      ctx.beginPath();
      ctx.arc(bx, by, bobR * 3, 0, Math.PI * 2);
      ctx.fillStyle = grd;
      ctx.fill();

      // Bob
      ctx.beginPath();
      ctx.arc(bx, by, bobR, 0, Math.PI * 2);
      ctx.fillStyle = `hsl(${hue},80%,65%)`;
      ctx.fill();

      // Pivot dot
      ctx.beginPath();
      ctx.arc(bobs[i].px, pivotY, 2.5, 0, Math.PI * 2);
      ctx.fillStyle = `hsla(${hue},70%,60%,0.5)`;
      ctx.fill();
    }

    // Timer bar
    const cyclePos = (elapsed % T_SYNC) / T_SYNC;
    timerFill.style.width = (cyclePos * 100) + '%';

    // Info
    const label = getPhaseLabel(elapsed);
    info.innerHTML = `T: ${elapsed.toFixed(1)}s<br>${label}`;

    requestAnimationFrame(draw);
  }

  // Controls
  document.getElementById('btnReset').addEventListener('click', () => {
    startTime = null;
    buildPendulums();
    SFX.play('toggle');
  });

  document.getElementById('btnTrail').addEventListener('click', () => {
    showTrail = !showTrail;
    document.getElementById('btnTrail').textContent = 'TRAIL: ' + (showTrail ? 'ON' : 'OFF');
    if (!showTrail) buildPendulums();
    SFX.play('click');
  });

  const nOptions = [10, 15, 20, 25, 30];
  let nIdx = 2;
  document.getElementById('btnCount').addEventListener('click', () => {
    nIdx = (nIdx + 1) % nOptions.length;
    N = nOptions[nIdx];
    document.getElementById('btnCount').textContent = 'N: ' + N;
    buildPendulums();
    startTime = null;
    SFX.play('toggle');
  });

  // Drag to change speed
  canvas.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return;
    dragStartX = e.clientX;
    dragStartScale = timeScale;
  });
  canvas.addEventListener('mousemove', (e) => {
    if (dragStartX === null) return;
    const dx = e.clientX - dragStartX;
    timeScale = Math.max(0.1, Math.min(5.0, dragStartScale + dx / 150));
  });
  canvas.addEventListener('mouseup', () => { dragStartX = null; });

  canvas.addEventListener('touchstart', (e) => {
    dragStartX = e.touches[0].clientX;
    dragStartScale = timeScale;
  }, { passive: true });
  canvas.addEventListener('touchmove', (e) => {
    if (dragStartX === null) return;
    const dx = e.touches[0].clientX - dragStartX;
    timeScale = Math.max(0.1, Math.min(5.0, dragStartScale + dx / 150));
  }, { passive: true });
  canvas.addEventListener('touchend', () => { dragStartX = null; });

  // Click on canvas = reset (not on controls)
  canvas.addEventListener('click', (e) => {
    if (e.target !== canvas) return;
    startTime = null;
    buildPendulums();
    SFX.play('toggle');
  });

  resize();
  window.addEventListener('resize', () => {
    resize();
  });
  buildPendulums();
  requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
