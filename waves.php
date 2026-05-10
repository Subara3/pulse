<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('waves');

$pageName = 'waves';
$pageTitle = 'WAVES - PULSE';
$pageDesc = '波を眺める。ただそれだけ。';
$pageCanonical = 'https://pulse.subara3.com/waves.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  background: linear-gradient(180deg, #0a0a0f 0%, #0a0f14 50%, #0a0a0f 100%);
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

.back-link {
  top: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.3);
  text-decoration: none;
  font-size: 0.8rem;
  letter-spacing: 0.1em;
  transition: color 0.3s;
}

.back-link:hover {
  color: rgba(255,255,255,0.7);
}

.title {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'watch'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui title">WAVES</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;

function resize() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
}

const waves = [
  { y: 0.7, amplitude: 30, frequency: 0.01, speed: 0.02, color: 'rgba(0, 150, 130, 0.3)' },
  { y: 0.65, amplitude: 25, frequency: 0.015, speed: 0.025, color: 'rgba(0, 170, 150, 0.3)' },
  { y: 0.6, amplitude: 20, frequency: 0.02, speed: 0.03, color: 'rgba(0, 195, 170, 0.4)' },
  { y: 0.55, amplitude: 15, frequency: 0.025, speed: 0.035, color: 'rgba(0, 220, 190, 0.4)' },
  { y: 0.5, amplitude: 10, frequency: 0.03, speed: 0.04, color: 'rgba(0, 245, 212, 0.3)' }
];

let time = 0;

function drawWave(wave, offset) {
  ctx.beginPath();
  
  const baseY = height * wave.y;
  
  ctx.moveTo(0, height);
  
  for (let x = 0; x <= width; x += 5) {
    const y = baseY + 
      Math.sin(x * wave.frequency + offset) * wave.amplitude +
      Math.sin(x * wave.frequency * 0.5 + offset * 0.7) * wave.amplitude * 0.5;
    
    ctx.lineTo(x, y);
  }
  
  ctx.lineTo(width, height);
  ctx.closePath();
  
  const gradient = ctx.createLinearGradient(0, baseY - wave.amplitude, 0, height);
  gradient.addColorStop(0, wave.color);
  gradient.addColorStop(1, 'rgba(0, 60, 55, 0.1)');
  
  ctx.fillStyle = gradient;
  ctx.fill();
}

function drawMoon() {
  const moonX = width * 0.8;
  const moonY = height * 0.15;
  const moonRadius = 40;

  const glow = ctx.createRadialGradient(moonX, moonY, 0, moonX, moonY, moonRadius * 4);
  glow.addColorStop(0, 'rgba(255, 255, 200, 0.1)');
  glow.addColorStop(1, 'transparent');
  ctx.fillStyle = glow;
  ctx.fillRect(moonX - moonRadius * 4, moonY - moonRadius * 4, moonRadius * 8, moonRadius * 8);

  ctx.beginPath();
  ctx.arc(moonX, moonY, moonRadius, 0, Math.PI * 2);
  const moonGradient = ctx.createRadialGradient(moonX - 10, moonY - 10, 0, moonX, moonY, moonRadius);
  moonGradient.addColorStop(0, '#fffef0');
  moonGradient.addColorStop(0.5, '#f0f0e0');
  moonGradient.addColorStop(1, '#d0d0c0');
  ctx.fillStyle = moonGradient;
  ctx.fill();
}

function drawStars() {
  for (let i = 0; i < 100; i++) {
    const x = (i * 137.5) % width;
    const y = (i * 73.3) % (height * 0.4);
    const size = (Math.sin(i) + 1) * 0.5 + 0.5;
    const twinkle = Math.sin(time * 0.5 + i) * 0.3 + 0.7;
    
    ctx.beginPath();
    ctx.arc(x, y, size, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(255, 255, 255, ${0.3 * twinkle})`;
    ctx.fill();
  }
}

function drawReflection() {
  const moonX = width * 0.8;
  const reflectionY = height * 0.6;
  
  for (let i = 0; i < 20; i++) {
    const y = reflectionY + i * 15;
    const shimmer = Math.sin(time * 2 + i * 0.5) * 10;
    const alpha = 0.1 - i * 0.005;
    
    if (alpha > 0) {
      ctx.beginPath();
      ctx.ellipse(moonX + shimmer, y, 5 + i * 2, 2, 0, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(255, 255, 200, ${alpha})`;
      ctx.fill();
    }
  }
}

function draw() {
  const gradient = ctx.createLinearGradient(0, 0, 0, height);
  gradient.addColorStop(0, '#0a0a0f');
  gradient.addColorStop(0.3, '#0a0f14');
  gradient.addColorStop(1, '#0a0a0f');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, width, height);

  drawStars();
  drawMoon();
  drawReflection();

  waves.forEach((wave, i) => {
    drawWave(wave, time * wave.speed * 50);
  });

  time += 0.016;
  requestAnimationFrame(draw);
}

window.addEventListener('resize', resize);

resize();
draw();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
