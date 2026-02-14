<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('physics');

$pageName = 'physics';
$pageTitle = 'PHYSICS - PULSE';
$pageDesc = '物理シミュレーション。砂鉄、磁石、弾むボール、チェーン。';
$pageCanonical = 'https://pulse.subara3.com/physics.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@300;400&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.4rem; flex-wrap: wrap; justify-content: flex-end; max-width: 400px; }
.control-btn { padding: 0.5rem 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.65rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.6rem; line-height: 1.8; }
.stats { top: 1rem; right: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); text-align: right; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">PHYSICS</div>
<div class="ui stats">
  <div>objects: <span id="objectCount">0</span></div>
  <div>mode: <span id="modeDisplay">magnet</span></div>
</div>
<div class="ui hint">
  Click to add<br>
  Drag to magnet<br>
  Space to reset
</div>
<div class="ui controls">
  <button class="control-btn active" data-mode="magnet">MAGNET</button>
  <button class="control-btn" data-mode="sand">SAND</button>
  <button class="control-btn" data-mode="balls">BALLS</button>
  <button class="control-btn" data-mode="chain">CHAIN</button>
  <button class="control-btn" id="resetBtn">RESET</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height, centerX, centerY;
let mode = 'magnet';
let particles = [];
let balls = [];
let chains = [];
let mouseDown = false;
let mouseX = 0, mouseY = 0;

const GRAVITY = 0.3;
const FRICTION = 0.98;

// 粒子クラス（砂鉄）
class Particle {
  constructor(x, y, vx = 0, vy = 0) {
    this.x = x;
    this.y = y;
    this.vx = vx;
    this.vy = vy;
    this.size = 2 + Math.random() * 1.5;
    this.hue = 180 + Math.random() * 30;
  }

  update() {
    this.vy += GRAVITY * 0.1;
    
    if (mouseDown) {
      const dx = mouseX - this.x;
      const dy = mouseY - this.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < 200 && dist > 5) {
        const force = 50 / (dist * dist);
        this.vx += dx * force;
        this.vy += dy * force;
      }
    }
    
    this.vx *= FRICTION;
    this.vy *= FRICTION;
    this.x += this.vx;
    this.y += this.vy;
    
    if (this.x < this.size) { this.x = this.size; this.vx *= -0.5; }
    if (this.x > width - this.size) { this.x = width - this.size; this.vx *= -0.5; }
    if (this.y < this.size) { this.y = this.size; this.vy *= -0.5; }
    if (this.y > height - this.size) { this.y = height - this.size; this.vy *= -0.3; }
  }

  draw() {
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
    ctx.fillStyle = `hsl(${this.hue}, 70%, 60%)`;
    ctx.fill();
  }
}

// ボールクラス
class Ball {
  constructor(x, y) {
    this.x = x;
    this.y = y;
    this.vx = (Math.random() - 0.5) * 5;
    this.vy = 0;
    this.radius = 15 + Math.random() * 20;
    this.color = `hsl(${Math.random() * 360}, 70%, 60%)`;
    this.restitution = 0.7 + Math.random() * 0.2;
  }

  update() {
    this.vy += GRAVITY;
    this.vx *= 0.995;
    this.x += this.vx;
    this.y += this.vy;
    
    if (this.y > height - this.radius) {
      this.y = height - this.radius;
      this.vy *= -this.restitution;
      this.vx *= 0.9;
    }
    if (this.x < this.radius) { this.x = this.radius; this.vx *= -0.8; }
    if (this.x > width - this.radius) { this.x = width - this.radius; this.vx *= -0.8; }
    
    for (const other of balls) {
      if (other === this) continue;
      const dx = other.x - this.x;
      const dy = other.y - this.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const minDist = this.radius + other.radius;
      
      if (dist < minDist && dist > 0) {
        const overlap = (minDist - dist) / 2;
        const nx = dx / dist;
        const ny = dy / dist;
        this.x -= nx * overlap;
        this.y -= ny * overlap;
        other.x += nx * overlap;
        other.y += ny * overlap;
        
        const dvx = this.vx - other.vx;
        const dvy = this.vy - other.vy;
        const dvn = dvx * nx + dvy * ny;
        this.vx -= dvn * nx * 0.8;
        this.vy -= dvn * ny * 0.8;
        other.vx += dvn * nx * 0.8;
        other.vy += dvn * ny * 0.8;
      }
    }
  }

  draw() {
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
    const grad = ctx.createRadialGradient(
      this.x - this.radius * 0.3, this.y - this.radius * 0.3, 0,
      this.x, this.y, this.radius
    );
    grad.addColorStop(0, this.color.replace('60%', '80%'));
    grad.addColorStop(1, this.color);
    ctx.fillStyle = grad;
    ctx.fill();
  }
}

// チェーンリンク
class ChainLink {
  constructor(x, y, prev = null) {
    this.x = x;
    this.y = y;
    this.vx = 0;
    this.vy = 0;
    this.prev = prev;
    this.linkLength = 20;
    this.fixed = prev === null;
  }

  update() {
    if (this.fixed) {
      if (mouseDown) {
        this.x = mouseX;
        this.y = mouseY;
      }
      return;
    }
    
    this.vy += GRAVITY * 0.5;
    this.x += this.vx;
    this.y += this.vy;
    this.vx *= 0.98;
    this.vy *= 0.98;
    
    if (this.prev) {
      const dx = this.x - this.prev.x;
      const dy = this.y - this.prev.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      
      if (dist > this.linkLength) {
        const diff = (dist - this.linkLength) / dist;
        this.x -= dx * diff * 0.5;
        this.y -= dy * diff * 0.5;
        if (!this.prev.fixed) {
          this.prev.x += dx * diff * 0.5;
          this.prev.y += dy * diff * 0.5;
        }
      }
    }
    
    if (this.y > height - 10) {
      this.y = height - 10;
      this.vy *= -0.3;
    }
  }

  draw() {
    ctx.beginPath();
    ctx.arc(this.x, this.y, 6, 0, Math.PI * 2);
    ctx.fillStyle = '#00f5d4';
    ctx.fill();
    
    if (this.prev) {
      ctx.beginPath();
      ctx.moveTo(this.prev.x, this.prev.y);
      ctx.lineTo(this.x, this.y);
      ctx.strokeStyle = 'rgba(0, 245, 212, 0.5)';
      ctx.lineWidth = 3;
      ctx.stroke();
    }
  }
}

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  centerX = width / 2;
  centerY = height / 2;
  reset();
}

function reset() {
  particles = [];
  balls = [];
  chains = [];
  
  if (mode === 'chain') {
    let prev = null;
    for (let i = 0; i < 20; i++) {
      const link = new ChainLink(centerX, 100 + i * 20, prev);
      chains.push(link);
      prev = link;
    }
  }
}

function addParticles(x, y, count = 20) {
  for (let i = 0; i < count; i++) {
    const angle = Math.random() * Math.PI * 2;
    const speed = Math.random() * 3;
    particles.push(new Particle(
      x + (Math.random() - 0.5) * 30,
      y + (Math.random() - 0.5) * 30,
      Math.cos(angle) * speed,
      Math.sin(angle) * speed
    ));
  }
  while (particles.length > 2000) particles.shift();
}

function draw() {
  ctx.fillStyle = 'rgba(10, 10, 15, 0.2)';
  ctx.fillRect(0, 0, width, height);
  
  if (mode === 'magnet' || mode === 'sand') {
    particles.forEach(p => { p.update(); p.draw(); });
    
    if (mouseDown && mode === 'magnet') {
      ctx.beginPath();
      ctx.arc(mouseX, mouseY, 30, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0, 245, 212, 0.2)';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(mouseX, mouseY, 200, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0, 245, 212, 0.1)';
      ctx.stroke();
    }
  } else if (mode === 'balls') {
    balls.forEach(b => { b.update(); b.draw(); });
  } else if (mode === 'chain') {
    for (let iter = 0; iter < 5; iter++) {
      chains.forEach(c => c.update());
    }
    chains.forEach(c => c.draw());
  }
  
  document.getElementById('objectCount').textContent = 
    particles.length + balls.length + chains.length;
  document.getElementById('modeDisplay').textContent = mode;
  
  requestAnimationFrame(draw);
}

// イベント
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    reset();
    SFX.play('toggle');
  });
});

document.getElementById('resetBtn').addEventListener('click', () => { reset(); SFX.play('click'); });

canvas.addEventListener('mousedown', (e) => {
  mouseDown = true;
  mouseX = e.clientX;
  mouseY = e.clientY;
  
  if (mode === 'sand' || mode === 'magnet') { addParticles(e.clientX, e.clientY, 50); SFX.play('spawn', 60); }
  else if (mode === 'balls') { balls.push(new Ball(e.clientX, e.clientY)); if (balls.length > 50) balls.shift(); SFX.play('spawn'); }
});

canvas.addEventListener('mousemove', (e) => {
  mouseX = e.clientX;
  mouseY = e.clientY;
  if (mouseDown && (mode === 'sand' || mode === 'magnet')) addParticles(e.clientX, e.clientY, 5);
});

canvas.addEventListener('mouseup', () => { mouseDown = false; });
canvas.addEventListener('mouseleave', () => { mouseDown = false; });

canvas.addEventListener('touchstart', (e) => {
  e.preventDefault();
  mouseDown = true;
  mouseX = e.touches[0].clientX;
  mouseY = e.touches[0].clientY;
  if (mode === 'sand' || mode === 'magnet') { addParticles(mouseX, mouseY, 50); SFX.play('spawn', 60); }
  else if (mode === 'balls') { balls.push(new Ball(mouseX, mouseY)); if (balls.length > 50) balls.shift(); SFX.play('spawn'); }
}, { passive: false });

canvas.addEventListener('touchmove', (e) => {
  e.preventDefault();
  mouseX = e.touches[0].clientX;
  mouseY = e.touches[0].clientY;
  if (mouseDown && (mode === 'sand' || mode === 'magnet')) addParticles(mouseX, mouseY, 5);
}, { passive: false });

canvas.addEventListener('touchend', () => { mouseDown = false; });

window.addEventListener('keydown', (e) => {
  if (e.code === 'Space') { e.preventDefault(); reset(); }
});

window.addEventListener('resize', init);

init();
draw();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
