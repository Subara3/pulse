<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('clock');

$pageName = 'clock';
$pageTitle = 'CLOCK - PULSE';
$pageDesc = '砂鉄時計。粒子がデジタル時計を形成する。';
$pageCanonical = 'https://pulse.subara3.com/clock.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@300;400&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">IRON SAND CLOCK</div>
<div class="ui stats">particles: <span id="particleCount">0</span></div>
<button class="ui sound-btn" id="soundBtn" style="top: 1rem; left: 1.5rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: 'Orbitron', sans-serif; font-size: 0.6rem; padding: 0.4rem 0.6rem; border-radius: 4px; cursor: pointer;">🔇 SOUND</button>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height, centerX, centerY;
let particles = [];
let attractors = [];
let lastTimeStr = '';
let soundEnabled = localStorage.getItem('pulse_clock_sound') !== 'false'; // デフォルトでON
let audioCtx = null;
let soundInitialized = false;

const PARTICLE_COUNT = 1600;

// サウンド初期化
function initSound() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (audioCtx.state === 'suspended') {
    audioCtx.resume();
  }
  soundInitialized = true;
  updateSoundBtn();
}

// UIを更新
function updateSoundBtn() {
  const btn = document.getElementById('soundBtn');
  btn.textContent = soundEnabled ? '🔊 SOUND' : '🔇 SOUND';
  btn.style.borderColor = soundEnabled ? '#00f5d4' : 'rgba(255,255,255,0.2)';
  btn.style.color = soundEnabled ? '#00f5d4' : 'rgba(255,255,255,0.5)';
}

// チック音を鳴らす
function playTick() {
  if (!soundEnabled || !audioCtx || audioCtx.state === 'suspended') return;

  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();

  osc.connect(gain);
  gain.connect(audioCtx.destination);

  osc.frequency.value = 1800;
  osc.type = 'sine';

  gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
  gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.05);

  osc.start(audioCtx.currentTime);
  osc.stop(audioCtx.currentTime + 0.05);
}

// ページ内どこでも操作したら音を自動有効化（ブラウザ制限対策）
function enableSoundOnInteraction() {
  if (!soundInitialized && soundEnabled) {
    initSound();
    playTick(); // フィードバック
  }
}

// 初回のユーザー操作で音を有効化
['click', 'keydown', 'touchstart'].forEach(event => {
  document.addEventListener(event, enableSoundOnInteraction, { once: true });
});

// サウンドボタン
document.getElementById('soundBtn').addEventListener('click', function(e) {
  e.stopPropagation(); // 二重発火防止
  if (!soundInitialized) initSound();
  soundEnabled = !soundEnabled;
  localStorage.setItem('pulse_clock_sound', soundEnabled);
  updateSoundBtn();
  if (soundEnabled) playTick(); // テスト音
});

// 初期表示
updateSoundBtn();
const FRICTION = 0.92;

// 7セグメントパターン: 上, 右上, 右下, 下, 左下, 左上, 中央
const SEGMENTS = {
  '0': [1,1,1,1,1,1,0],
  '1': [0,1,1,0,0,0,0],
  '2': [1,1,0,1,1,0,1],
  '3': [1,1,1,1,0,0,1],
  '4': [0,1,1,0,0,1,1],
  '5': [1,0,1,1,0,1,1],
  '6': [1,0,1,1,1,1,1],
  '7': [1,1,1,0,0,0,0],
  '8': [1,1,1,1,1,1,1],
  '9': [1,1,1,1,0,1,1],
  ':': 'colon'
};

function getSegmentPoints(x, y, w, h, digit) {
  const points = [];
  const pattern = SEGMENTS[digit];
  
  if (pattern === 'colon') {
    for (let i = 0; i < 4; i++) {
      points.push({ x: x + w/2 + (Math.random()-0.5)*4, y: y + h * 0.33 + (Math.random()-0.5)*4 });
      points.push({ x: x + w/2 + (Math.random()-0.5)*4, y: y + h * 0.67 + (Math.random()-0.5)*4 });
    }
    return points;
  }
  
  const hDensity = 15;  // 横セグメント用
  const vDensity = 12;  // 縦セグメント用
  const thick = w * 0.15;
  
  // 上
  if (pattern[0]) {
    for (let i = 0; i < hDensity; i++) {
      const px = x + w * 0.1 + (w * 0.8 / hDensity) * i;
      points.push({ x: px, y: y + thick/2 });
    }
  }
  // 右上
  if (pattern[1]) {
    for (let i = 0; i < vDensity; i++) {
      const py = y + thick * 0.8 + (h * 0.43 / vDensity) * i;
      points.push({ x: x + w - thick/2, y: py });
    }
  }
  // 右下
  if (pattern[2]) {
    for (let i = 0; i < vDensity; i++) {
      const py = y + h * 0.53 + (h * 0.43 / vDensity) * i;
      points.push({ x: x + w - thick/2, y: py });
    }
  }
  // 下
  if (pattern[3]) {
    for (let i = 0; i < hDensity; i++) {
      const px = x + w * 0.1 + (w * 0.8 / hDensity) * i;
      points.push({ x: px, y: y + h - thick/2 });
    }
  }
  // 左下
  if (pattern[4]) {
    for (let i = 0; i < vDensity; i++) {
      const py = y + h * 0.53 + (h * 0.43 / vDensity) * i;
      points.push({ x: x + thick/2, y: py });
    }
  }
  // 左上
  if (pattern[5]) {
    for (let i = 0; i < vDensity; i++) {
      const py = y + thick * 0.8 + (h * 0.43 / vDensity) * i;
      points.push({ x: x + thick/2, y: py });
    }
  }
  // 中央
  if (pattern[6]) {
    for (let i = 0; i < hDensity; i++) {
      const px = x + w * 0.1 + (w * 0.8 / hDensity) * i;
      points.push({ x: px, y: y + h * 0.5 });
    }
  }
  
  return points;
}

function updateAttractors() {
  const now = new Date();
  const timeStr = [
    now.getHours().toString().padStart(2, '0'),
    ':',
    now.getMinutes().toString().padStart(2, '0'),
    ':',
    now.getSeconds().toString().padStart(2, '0')
  ].join('');
  
  if (timeStr === lastTimeStr) return false;
  lastTimeStr = timeStr;
  
  playTick(); // 秒が変わったら音を鳴らす
  
  attractors = [];
  
  const digitW = Math.min(width * 0.09, 70);
  const digitH = digitW * 1.5;
  const colonW = digitW * 0.35;
  const gap = digitW * 0.12;
  const totalW = digitW * 6 + colonW * 2 + gap * 7;
  let offsetX = centerX - totalW / 2;
  const startY = centerY - digitH / 2;
  
  for (const char of timeStr) {
    const charW = char === ':' ? colonW : digitW;
    const pts = getSegmentPoints(offsetX, startY, charW, digitH, char);
    attractors.push(...pts);
    offsetX += charW + gap;
  }
  
  return true;
}

class Particle {
  constructor(x, y) {
    this.x = x;
    this.y = y;
    this.vx = (Math.random() - 0.5) * 2;
    this.vy = (Math.random() - 0.5) * 2;
    this.targetX = null;
    this.targetY = null;
    this.size = 1.5 + Math.random() * 1.5;
    this.hue = Math.random() < 0.8 ? 170 + Math.random() * 30 : 260 + Math.random() * 30;
  }

  update() {
    if (this.targetX !== null) {
      const dx = this.targetX - this.x;
      const dy = this.targetY - this.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      
      if (dist > 0.5) {
        const force = Math.min(dist * 0.05, 4);
        this.vx += (dx / dist) * force;
        this.vy += (dy / dist) * force;
      }
    }
    
    this.vx *= FRICTION;
    this.vy *= FRICTION;
    this.x += this.vx;
    this.y += this.vy;
    
    // 画面内に留める
    if (this.x < 0) this.x = 0;
    if (this.x > width) this.x = width;
    if (this.y < 0) this.y = 0;
    if (this.y > height) this.y = height;
  }

  draw() {
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
    ctx.fillStyle = `hsl(${this.hue}, 75%, 55%)`;
    ctx.fill();
  }
}

function assignTargets() {
  if (attractors.length === 0) return;
  
  // 粒子をシャッフルしてアトラクターに均等に振り分け
  const shuffled = [...particles].sort(() => Math.random() - 0.5);
  const perAttractor = Math.ceil(shuffled.length / attractors.length);
  
  for (let i = 0; i < shuffled.length; i++) {
    const targetIdx = i % attractors.length;  // 順番に振り分け
    const target = attractors[targetIdx];
    shuffled[i].targetX = target.x + (Math.random() - 0.5) * 5;
    shuffled[i].targetY = target.y + (Math.random() - 0.5) * 5;
  }
}

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  centerX = width / 2;
  centerY = height / 2;
  
  particles = [];
  lastTimeStr = '';
  
  for (let i = 0; i < PARTICLE_COUNT; i++) {
    particles.push(new Particle(
      Math.random() * width,
      Math.random() * height
    ));
  }
  
  updateAttractors();
  assignTargets();
}

function draw() {
  // 残像効果
  ctx.fillStyle = 'rgba(10, 10, 15, 0.15)';
  ctx.fillRect(0, 0, width, height);
  
  // 時刻更新
  if (updateAttractors()) {
    assignTargets();
  }
  
  // 粒子
  particles.forEach(p => {
    p.update();
    p.draw();
  });
  
  // うっすらガイド
  ctx.font = `bold ${Math.min(width * 0.06, 50)}px 'JetBrains Mono', monospace`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillStyle = 'rgba(0, 245, 212, 0.03)';
  ctx.fillText(lastTimeStr, centerX, centerY);
  
  document.getElementById('particleCount').textContent = particles.length;
  
  requestAnimationFrame(draw);
}

window.addEventListener('resize', init);
init();
draw();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
