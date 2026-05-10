<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('lissajous');

$pageName = 'lissajous';
$pageTitle = 'LISSAJOUS - PULSE';
$pageDesc = 'リサジュー曲線。2つの振動が出会うとき、数式が曲線を描く。';
$pageCanonical = 'https://pulse.subara3.com/lissajous.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  background: #0a0a0f;
  min-height: 100vh;
  overflow: hidden;
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
  z-index: 100;
  font-family: "Orbitron", sans-serif;
}

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

.hud {
  top: 1.5rem;
  right: 1.5rem;
  text-align: right;
  color: rgba(255,255,255,0.3);
  font-size: 0.7rem;
  letter-spacing: 0.1em;
}

.hud .formula {
  color: #00f5d4;
  margin-bottom: 0.3rem;
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  align-items: flex-end;
}

.slider-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.slider-label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  width: 2rem;
  text-align: right;
}

.slider-value {
  color: #00f5d4;
  font-size: 0.6rem;
  width: 2.5rem;
  text-align: left;
}

input[type="range"] {
  -webkit-appearance: none;
  width: 100px;
  height: 4px;
  background: rgba(255,255,255,0.1);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  background: #00f5d4;
  border-radius: 50%;
  cursor: pointer;
  transition: transform 0.2s;
}

input[type="range"]::-webkit-slider-thumb:hover {
  transform: scale(1.2);
}

.btn-row {
  display: flex;
  gap: 0.5rem;
}

.control-btn {
  padding: 0.5rem 1rem;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.5);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
}

.control-btn:hover {
  background: rgba(0, 245, 212, 0.1);
  border-color: rgba(0, 245, 212, 0.5);
  color: #00f5d4;
}

.control-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: #00f5d4;
  color: #00f5d4;
}

@media (max-width: 768px) {
  .info { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .hud { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
  .controls { bottom: 0.8rem; right: 0.8rem; gap: 0.5rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
  input[type="range"] { width: 70px; }
  .slider-label, .slider-value { font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">LISSAJOUS</div>

<div class="ui hud">
  <div class="formula" id="formula">x = sin(t), y = sin(t)</div>
  <div id="ratioDisplay">Ratio: 1:1</div>
</div>

<div class="ui controls">
  <div class="slider-group">
    <span class="slider-label">A</span>
    <input type="range" id="sliderA" min="1" max="9" step="1" value="3">
    <span class="slider-value" id="valueA">3</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">B</span>
    <input type="range" id="sliderB" min="1" max="9" step="1" value="4">
    <span class="slider-value" id="valueB">4</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">δ</span>
    <input type="range" id="sliderDelta" min="0" max="100" step="1" value="50">
    <span class="slider-value" id="valueDelta">π/2</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="animateBtn">ANIMATE</button>
    <button class="control-btn" id="trailBtn">TRAIL</button>
  </div>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  const sliderA = document.getElementById('sliderA');
  const sliderB = document.getElementById('sliderB');
  const sliderDelta = document.getElementById('sliderDelta');
  const valueA = document.getElementById('valueA');
  const valueB = document.getElementById('valueB');
  const valueDelta = document.getElementById('valueDelta');
  const formulaEl = document.getElementById('formula');
  const ratioDisplay = document.getElementById('ratioDisplay');
  const animateBtn = document.getElementById('animateBtn');
  const trailBtn = document.getElementById('trailBtn');

  let width, height;
  let a = 3, b = 4, delta = Math.PI / 2;
  let animating = false;
  let showTrail = true;
  let phase = 0;
  let trail = [];
  const MAX_TRAIL = 2000;

  // 配色
  const ACCENT = [0, 245, 212];
  const SUB = [123, 44, 191];

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    trail = [];
  }

  function gcd(x, y) {
    x = Math.abs(x);
    y = Math.abs(y);
    while (y) {
      const t = y;
      y = x % y;
      x = t;
    }
    return x;
  }

  function updateDisplay() {
    a = parseInt(sliderA.value);
    b = parseInt(sliderB.value);
    const deltaPercent = parseInt(sliderDelta.value);
    delta = (deltaPercent / 100) * Math.PI;

    valueA.textContent = a;
    valueB.textContent = b;

    // δの表示
    if (deltaPercent === 0) valueDelta.textContent = '0';
    else if (deltaPercent === 25) valueDelta.textContent = 'π/4';
    else if (deltaPercent === 50) valueDelta.textContent = 'π/2';
    else if (deltaPercent === 75) valueDelta.textContent = '3π/4';
    else if (deltaPercent === 100) valueDelta.textContent = 'π';
    else valueDelta.textContent = (deltaPercent / 100).toFixed(2) + 'π';

    // 数式表示
    const g = gcd(a, b);
    const ratioA = a / g;
    const ratioB = b / g;
    formulaEl.textContent = `x = sin(${a}t + δ), y = sin(${b}t)`;
    ratioDisplay.textContent = `Ratio: ${ratioA}:${ratioB}`;

    trail = [];
  }

  function lissajous(t) {
    const x = Math.sin(a * t + delta);
    const y = Math.sin(b * t);
    return { x, y };
  }

  function draw() {
    // 背景
    ctx.fillStyle = showTrail ? 'rgba(10, 10, 15, 0.03)' : '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    const scale = Math.min(width, height) * 0.35;
    const cx = width / 2;
    const cy = height / 2;

    if (animating) {
      // アニメーション: 点を追加
      const pos = lissajous(phase);
      trail.push({
        x: cx + pos.x * scale,
        y: cy + pos.y * scale,
        age: 0
      });

      if (trail.length > MAX_TRAIL) {
        trail.shift();
      }

      phase += 0.02;
      if (phase > Math.PI * 2 * 20) {
        phase = 0;
      }
    } else {
      // 静止: 完全な曲線を描画
      trail = [];
      const steps = 2000;
      const period = Math.PI * 2 * (a * b / gcd(a, b));
      for (let i = 0; i <= steps; i++) {
        const t = (i / steps) * period;
        const pos = lissajous(t);
        trail.push({
          x: cx + pos.x * scale,
          y: cy + pos.y * scale,
          age: i
        });
      }
    }

    // 軌跡を描画
    if (trail.length > 1) {
      ctx.beginPath();
      ctx.moveTo(trail[0].x, trail[0].y);

      for (let i = 1; i < trail.length; i++) {
        const progress = i / trail.length;
        // teal から purple へのグラデーション
        const r = Math.round(ACCENT[0] + (SUB[0] - ACCENT[0]) * progress);
        const g = Math.round(ACCENT[1] + (SUB[1] - ACCENT[1]) * progress);
        const bv = Math.round(ACCENT[2] + (SUB[2] - ACCENT[2]) * progress);

        ctx.strokeStyle = `rgb(${r}, ${g}, ${bv})`;
        ctx.lineWidth = 2;
        ctx.lineTo(trail[i].x, trail[i].y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(trail[i].x, trail[i].y);
      }
    }

    // 現在位置のドット（アニメーション時）
    if (animating && trail.length > 0) {
      const last = trail[trail.length - 1];
      ctx.beginPath();
      ctx.arc(last.x, last.y, 6, 0, Math.PI * 2);
      ctx.fillStyle = '#00f5d4';
      ctx.fill();

      // グロー効果
      ctx.beginPath();
      ctx.arc(last.x, last.y, 12, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0, 245, 212, 0.3)';
      ctx.fill();
    }

    requestAnimationFrame(draw);
  }

  // イベント
  sliderA.addEventListener('input', () => {
    updateDisplay();
    SFX.play('tick', 30);
  });
  sliderB.addEventListener('input', () => {
    updateDisplay();
    SFX.play('tick', 30);
  });
  sliderDelta.addEventListener('input', () => {
    updateDisplay();
    SFX.play('tick', 30);
  });

  animateBtn.addEventListener('click', () => {
    animating = !animating;
    animateBtn.classList.toggle('active', animating);
    if (animating) {
      phase = 0;
      trail = [];
    }
    SFX.play('toggle');
  });

  trailBtn.addEventListener('click', () => {
    showTrail = !showTrail;
    trailBtn.classList.toggle('active', showTrail);
    SFX.play('toggle');
  });

  // タッチ対応
  canvas.addEventListener('click', (e) => {
    // 中央をクリックでアニメーション切り替え
    const cx = width / 2;
    const cy = height / 2;
    const dx = e.clientX - cx;
    const dy = e.clientY - cy;
    if (Math.sqrt(dx * dx + dy * dy) < 100) {
      animating = !animating;
      animateBtn.classList.toggle('active', animating);
      if (animating) {
        phase = 0;
        trail = [];
      }
      SFX.play('toggle');
    }
  });

  window.addEventListener('resize', resize);

  // 初期化
  resize();
  updateDisplay();
  trailBtn.classList.add('active');
  draw();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
