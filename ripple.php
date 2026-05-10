<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('ripple');

$pageName = 'ripple';
$pageTitle = 'Ripple - PULSE';
$pageDesc = 'クリックすると波紋が広がる。ただそれだけ。ぼんやり眺めてください。';
$pageCanonical = 'https://pulse.subara3.com/ripple.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  background: #0a0a0f;
  min-height: 100vh;
  overflow: hidden;
  font-family: "Orbitron", sans-serif;
}

.canvas-container {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  cursor: crosshair;
}

canvas {
  display: block;
}

.ui {
  position: fixed;
  top: 2rem;
  left: 2rem;
  z-index: 100;
}

.logo {
  font-size: 1.5rem;
  color: #00f5d4;
  text-decoration: none;
  display: block;
  margin-bottom: 0.5rem;
}

.hint {
  color: #666;
  font-size: 0.75rem;
  letter-spacing: 0.1em;
}

.controls {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  z-index: 100;
  display: flex;
  gap: 0.5rem;
}

.btn {
  padding: 0.5rem 1rem;
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: #00f5d4;
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
}

.btn:hover {
  background: rgba(0, 245, 212, 0.2);
  border-color: #00f5d4;
}

.btn.active {
  background: #00f5d4;
  color: #0a0a0f;
}

.back-link {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  color: #666;
  text-decoration: none;
  font-size: 0.75rem;
  z-index: 100;
}

.back-link:hover {
  color: #00f5d4;
}

.counter {
  position: fixed;
  top: 5rem;
  right: 2rem;
  color: #333;
  font-size: 0.7rem;
  z-index: 100;
}

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
  .counter { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<div class="ui">
  <a href="index.php" class="logo">PULSE</a>
  <p class="hint">CLICK ANYWHERE</p>
</div>

<div class="controls">
  <button class="btn active" data-color="cyan">CYAN</button>
  <button class="btn" data-color="purple">PURPLE</button>
  <button class="btn" data-color="rainbow">RAINBOW</button>
</div>

<div class="counter">ripples: <span id="count">0</span></div>

<a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i></a>

<div class="canvas-container">
  <canvas id="canvas"></canvas>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let ripples = [];
let colorMode = 'cyan';
let totalRipples = 0;

const colors = {
  cyan: ['rgba(0, 245, 212, ', 'rgba(0, 200, 180, '],
  purple: ['rgba(123, 44, 191, ', 'rgba(180, 80, 220, '],
  rainbow: null
};

function resize() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
}

function getRainbowColor(offset) {
  // Constrain hue to 160-300 range (teal through blue to purple)
  const raw = (Date.now() / 20 + offset) % 140;
  const hue = 160 + raw;
  return `hsla(${hue}, 80%, 60%, `;
}

function createRipple(x, y) {
  const count = 3 + Math.floor(Math.random() * 3);
  for (let i = 0; i < count; i++) {
    ripples.push({
      x,
      y,
      radius: i * 10,
      maxRadius: 150 + Math.random() * 150,
      speed: 2 + Math.random() * 2,
      lineWidth: 2 - i * 0.3,
      offset: i * 30
    });
  }
  totalRipples++;
  document.getElementById('count').textContent = totalRipples;
}

function animate() {
  ctx.fillStyle = 'rgba(10, 10, 15, 0.1)';
  ctx.fillRect(0, 0, width, height);

  ripples = ripples.filter(r => {
    r.radius += r.speed;
    
    const progress = r.radius / r.maxRadius;
    const alpha = Math.max(0, 1 - progress);
    
    let color;
    if (colorMode === 'rainbow') {
      color = getRainbowColor(r.offset) + alpha + ')';
    } else {
      const colorSet = colors[colorMode];
      color = colorSet[Math.floor(Math.random() * colorSet.length)] + alpha + ')';
    }
    
    ctx.beginPath();
    ctx.arc(r.x, r.y, r.radius, 0, Math.PI * 2);
    ctx.strokeStyle = color;
    ctx.lineWidth = r.lineWidth * (1 - progress * 0.5);
    ctx.stroke();
    
    return r.radius < r.maxRadius;
  });

  requestAnimationFrame(animate);
}

window.addEventListener('resize', resize);

canvas.addEventListener('click', (e) => {
  createRipple(e.clientX, e.clientY);
  SFX.play('ripple', 80);
});

canvas.addEventListener('touchstart', (e) => {
  e.preventDefault();
  const touch = e.touches[0];
  createRipple(touch.clientX, touch.clientY);
  SFX.play('ripple', 80);
});

document.querySelectorAll('.btn').forEach(btn => {
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    document.querySelectorAll('.btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    colorMode = btn.dataset.color;
    SFX.play('toggle');
  });
});

setInterval(() => {
  if (Math.random() > 0.5) {
    createRipple(
      Math.random() * width,
      Math.random() * height
    );
  }
}, 3000);

resize();
animate();

setTimeout(() => createRipple(width / 2, height / 2), 500);
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
