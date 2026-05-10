<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('drift');

$pageName = 'drift';
$pageTitle = 'DRIFT - PULSE';
$pageDesc = 'ランダムウォーク。ゆっくり彷徨う点が残す、淡い軌跡。';
$pageCanonical = 'https://pulse.subara3.com/drift.php';
$extraStyle = '
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
#hud { position: fixed; top: 60px; right: 20px; color: var(--accent); font-family: var(--font-mono); font-size: 13px; text-align: right; z-index: 10; pointer-events: none; }

.control-row { display: flex; align-items: center; gap: 10px; justify-content: flex-end; color: var(--text-dim); font-size: 12px; }
.control-row label { min-width: 60px; text-align: right; }
.control-row input[type="range"] { width: 100px; accent-color: var(--accent); }
.btn { background: rgba(0,245,212,0.1); border: 1px solid var(--accent); color: var(--accent); padding: 8px 16px; font-size: 12px; cursor: pointer; transition: all 0.2s; border-radius: 4px; }
.btn:hover { background: rgba(0,245,212,0.2); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>WALKERS: <span id="walkerCount">0</span></div>
  <div>STEPS: <span id="stepCount">0</span></div>
</div>
<div id="controls" class="drawer-controls">
  <div class="control-row">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="10" value="3">
  </div>
  <div class="control-row">
    <label>TRAIL</label>
    <input type="range" id="trailSlider" min="50" max="500" value="200">
  </div>
  <div class="control-row">
    <button class="btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php
$pageInfo = [
  'Click to add walkers',
];
require_once __DIR__ . '/includes/info-panel.php';
?>
<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const walkerCountEl = document.getElementById('walkerCount');
  const stepCountEl = document.getElementById('stepCount');
  const speedSlider = document.getElementById('speedSlider');
  const trailSlider = document.getElementById('trailSlider');
  const resetBtn = document.getElementById('resetBtn');
  
  let W, H;
  let walkers = [];
  let totalSteps = 0;
  let lastTime = 0;
  let stepInterval = 50;
  
  const COLORS = [
    [0, 245, 212],
    [0, 200, 180],
    [50, 220, 200],
    [100, 180, 220],
    [123, 44, 191],
    [150, 80, 200],
    [80, 150, 220]
  ];
  
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  
  class Walker {
    constructor(x, y) {
      this.x = x;
      this.y = y;
      this.color = COLORS[Math.floor(Math.random() * COLORS.length)];
      this.trail = [{x: x, y: y, age: 0}];
      this.stepSize = 2 + Math.random() * 3;
      this.id = Math.random();
    }
    
    step() {
      const angle = Math.random() * Math.PI * 2;
      const speed = parseInt(speedSlider.value);
      const dx = Math.cos(angle) * this.stepSize * speed * 0.5;
      const dy = Math.sin(angle) * this.stepSize * speed * 0.5;
      
      this.x += dx;
      this.y += dy;
      
      // Wrap around
      if (this.x < 0) this.x += W;
      if (this.x > W) this.x -= W;
      if (this.y < 0) this.y += H;
      if (this.y > H) this.y -= H;
      
      this.trail.push({x: this.x, y: this.y, age: 0});
      
      const maxTrail = parseInt(trailSlider.value);
      if (this.trail.length > maxTrail) {
        this.trail.shift();
      }
      
      totalSteps++;
    }
    
    draw() {
      const len = this.trail.length;
      if (len < 2) return;
      
      for (let i = 1; i < len; i++) {
        const p0 = this.trail[i - 1];
        const p1 = this.trail[i];
        
        // Skip if wrapped
        if (Math.abs(p1.x - p0.x) > W/2 || Math.abs(p1.y - p0.y) > H/2) continue;
        
        const alpha = (i / len) * 0.7;
        const r = this.color[0];
        const g = this.color[1];
        const b = this.color[2];
        
        ctx.beginPath();
        ctx.moveTo(p0.x, p0.y);
        ctx.lineTo(p1.x, p1.y);
        ctx.strokeStyle = `rgba(${r},${g},${b},${alpha})`;
        ctx.lineWidth = 1.5 * (i / len) + 0.5;
        ctx.stroke();
      }
      
      // Draw head
      const head = this.trail[len - 1];
      ctx.beginPath();
      ctx.arc(head.x, head.y, 3, 0, Math.PI * 2);
      ctx.fillStyle = `rgb(${this.color[0]},${this.color[1]},${this.color[2]})`;
      ctx.fill();
    }
  }
  
  function addWalker(x, y) {
    walkers.push(new Walker(x, y));
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  
  function addRandomWalkers(n) {
    for (let i = 0; i < n; i++) {
      addWalker(Math.random() * W, Math.random() * H);
    }
  }
  
  function reset() {
    walkers = [];
    totalSteps = 0;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    addRandomWalkers(5);
    if (typeof SFX !== 'undefined') SFX.play('click');
  }
  
  function checkProximity() {
    for (let i = 0; i < walkers.length; i++) {
      for (let j = i + 1; j < walkers.length; j++) {
        const dx = walkers[i].x - walkers[j].x;
        const dy = walkers[i].y - walkers[j].y;
        const dist = Math.sqrt(dx*dx + dy*dy);
        if (dist < 30) {
          if (typeof SFX !== 'undefined') SFX.play('ripple', 200);
          return;
        }
      }
    }
  }
  
  function update(time) {
    const speed = parseInt(speedSlider.value);
    stepInterval = 80 - speed * 6;
    
    if (time - lastTime > stepInterval) {
      lastTime = time;
      
      for (const w of walkers) {
        w.step();
      }
      
      if (Math.random() < 0.05) {
        checkProximity();
      }
    }
    
    walkerCountEl.textContent = walkers.length;
    stepCountEl.textContent = totalSteps.toLocaleString();
  }
  
  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.03)';
    ctx.fillRect(0, 0, W, H);
    
    for (const w of walkers) {
      w.draw();
    }
  }
  
  function loop(time) {
    update(time);
    draw();
    requestAnimationFrame(loop);
  }
  
  // Events
  canvas.addEventListener('click', (e) => {
    addWalker(e.clientX, e.clientY);
  });
  
  canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    const touch = e.touches[0];
    addWalker(touch.clientX, touch.clientY);
  });
  
  resetBtn.addEventListener('click', reset);
  
  window.addEventListener('resize', resize);
  
  // Init
  resize();
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  addRandomWalkers(5);
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
