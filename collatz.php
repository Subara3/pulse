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

.calc-log {
  margin-top: 1rem;
  max-height: 200px;
  overflow: hidden;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.6rem;
  line-height: 1.6;
}

.calc-log .entry {
  opacity: 0.6;
  white-space: nowrap;
}

.calc-log .entry.new {
  opacity: 1;
  color: #00f5d4;
}

.calc-log .op {
  color: #7b2cbf;
}

.calc-log .result {
  color: #00f5d4;
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

.hide-ui .hud,
.hide-ui .info,
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
}
';

$controls = [
  ['id' => 'spawnBtn', 'icon' => 'fa-plus', 'label' => 'DROP'],
  ['id' => 'burstBtn', 'icon' => 'fa-burst', 'label' => 'BURST'],
  ['id' => 'resetBtn', 'icon' => 'fa-rotate-right', 'label' => 'RESET'],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud" id="hud">
  <div>FALLING: <span class="val" id="hudFalling">0</span></div>
  <div>REACHED 1: <span class="val" id="hudReached">0</span></div>
  <div>LONGEST: <span class="val" id="hudLongest">0</span> steps</div>
  <div class="calc-log" id="calcLog"></div>
</div>

<div class="ui info">
  Press H to hide UI<br>
  <span class="formula">n &rarr; n / 2 (even)</span><br>
  <span class="formula">n &rarr; 3n + 1 (odd)</span><br>
  <span style="color:rgba(123,44,191,0.5);font-size:0.55rem;">Does it always reach 1?</span>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudFalling = document.getElementById('hudFalling');
  const hudReached = document.getElementById('hudReached');
  const hudLongest = document.getElementById('hudLongest');
  const calcLog = document.getElementById('calcLog');

  let width, height;
  const logEntries = [];
  const MAX_LOG = 12;

  function addLog(from, to, op) {
    const entry = { from, to, op, age: 0 };
    logEntries.unshift(entry);
    if (logEntries.length > MAX_LOG) logEntries.pop();
    renderLog();
  }

  function renderLog() {
    calcLog.innerHTML = logEntries.map((e, i) => {
      const cls = i === 0 ? 'entry new' : 'entry';
      return `<div class="${cls}">${e.from} <span class="op">${e.op}</span> <span class="result">${e.to}</span></div>`;
    }).join('');
  }
  let particles = [];
  let reachedCount = 0;
  let longestPath = 0;
  let nextN = 2;
  let sinkY, sinkX;

  const isMobile = window.innerWidth <= 768;

  // 色パレット
  function getColor(n, steps) {
    // 大きい数ほど紫、小さいほどティール
    const t = Math.min(1, Math.log10(n) / 4);
    const r = Math.floor(0 + t * 123);
    const g = Math.floor(245 - t * 200);
    const b = Math.floor(212 + t * 40);
    return { r, g, b };
  }

  // パーティクルクラス
  class Particle {
    constructor(n, x, y) {
      this.n = n;
      this.originalN = n;
      this.x = x;
      this.y = y;
      this.targetY = y;
      this.steps = 0;
      this.history = [n];
      this.done = false;
      this.fadeOut = 1;
      this.waitFrames = 0;
    }

    update() {
      if (this.done) {
        this.fadeOut -= 0.02;
        return this.fadeOut > 0;
      }

      // 待機中
      if (this.waitFrames > 0) {
        this.waitFrames--;
        // ゆっくりターゲット位置に移動
        this.y += (this.targetY - this.y) * 0.1;
        this.x += (sinkX - this.x) * 0.02;
        return true;
      }

      // ターゲット位置に近づく
      const dy = this.targetY - this.y;
      if (Math.abs(dy) > 1) {
        this.y += dy * 0.15;
        return true;
      }
      this.y = this.targetY;

      // コラッツ変換
      if (this.n === 1) {
        this.done = true;
        reachedCount++;
        if (this.steps > longestPath) longestPath = this.steps;
        SFX.play('tick', 20);
        return true;
      }

      // 次のステップ
      const prevN = this.n;
      if (this.n % 2 === 0) {
        this.n = this.n / 2;
        addLog(prevN, this.n, '÷2 →');
      } else {
        this.n = 3 * this.n + 1;
        addLog(prevN, this.n, '×3+1 →');
      }
      this.steps++;
      this.history.push(this.n);

      // 新しいターゲット位置を計算
      // n=1 が sinkY、大きい数ほど上
      const maxN = 10000;
      const logN = Math.log10(Math.max(1, this.n));
      const logMax = Math.log10(maxN);
      const ratio = Math.min(1, logN / logMax);
      this.targetY = sinkY - ratio * (sinkY - 80);

      // 横方向も少し揺らす
      this.x += (Math.random() - 0.5) * 20;
      this.x += (sinkX - this.x) * 0.05;

      // 次の更新まで待機
      this.waitFrames = 30; // 0.5秒くらい

      return true;
    }

    draw() {
      const col = getColor(this.n, this.steps);
      const alpha = this.fadeOut * 0.8;
      const size = Math.max(4, 8 - this.steps * 0.1);

      // グロー
      const grad = ctx.createRadialGradient(this.x, this.y, 0, this.x, this.y, size * 3);
      grad.addColorStop(0, `rgba(${col.r},${col.g},${col.b},${alpha * 0.4})`);
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.arc(this.x, this.y, size * 3, 0, Math.PI * 2);
      ctx.fill();

      // コア
      ctx.fillStyle = `rgba(${col.r},${col.g},${col.b},${alpha})`;
      ctx.beginPath();
      ctx.arc(this.x, this.y, size, 0, Math.PI * 2);
      ctx.fill();

      // 数字ラベル（大きい数のみ）
      if (this.n > 10 && this.fadeOut > 0.5) {
        ctx.fillStyle = `rgba(255,255,255,${alpha * 0.6})`;
        ctx.font = '10px "Orbitron"';
        ctx.textAlign = 'center';
        ctx.fillText(this.n.toString(), this.x, this.y - size - 5);
      }
    }
  }

  function spawnParticle(n) {
    if (n === undefined) {
      n = nextN++;
    }
    const x = width * 0.2 + Math.random() * width * 0.6;
    const logN = Math.log10(Math.max(1, n));
    const logMax = Math.log10(10000);
    const ratio = Math.min(1, logN / logMax);
    const y = sinkY - ratio * (sinkY - 80);

    particles.push(new Particle(n, x, y));
    SFX.play('spawn', 15);
  }

  function spawnBurst() {
    for (let i = 0; i < 10; i++) {
      setTimeout(() => spawnParticle(), i * 100);
    }
  }

  function reset() {
    particles = [];
    reachedCount = 0;
    longestPath = 0;
    nextN = 2;
    SFX.play('click');
  }

  function drawSink() {
    // 「1」の吸い込み口
    const pulse = 0.8 + Math.sin(Date.now() * 0.002) * 0.2;

    // 外側のグロー
    const grad = ctx.createRadialGradient(sinkX, sinkY, 0, sinkX, sinkY, 80);
    grad.addColorStop(0, `rgba(0,245,212,${0.2 * pulse})`);
    grad.addColorStop(0.5, `rgba(123,44,191,${0.1 * pulse})`);
    grad.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.arc(sinkX, sinkY, 80, 0, Math.PI * 2);
    ctx.fill();

    // 中央の「1」
    ctx.fillStyle = `rgba(0,245,212,${0.8 * pulse})`;
    ctx.font = 'bold 48px "Orbitron"';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('1', sinkX, sinkY);

    // 説明
    ctx.fillStyle = 'rgba(255,255,255,0.2)';
    ctx.font = '12px "Orbitron"';
    ctx.fillText('ALL PATHS LEAD HERE', sinkX, sinkY + 50);
  }

  function drawScale() {
    // 左側にスケール表示
    ctx.fillStyle = 'rgba(255,255,255,0.1)';
    ctx.font = '10px "JetBrains Mono"';
    ctx.textAlign = 'right';

    const labels = [1, 10, 100, 1000, 10000];
    for (const n of labels) {
      const logN = Math.log10(n);
      const logMax = Math.log10(10000);
      const ratio = logN / logMax;
      const y = sinkY - ratio * (sinkY - 80);
      ctx.fillText(n.toString(), 50, y + 4);

      ctx.strokeStyle = 'rgba(255,255,255,0.05)';
      ctx.beginPath();
      ctx.moveTo(55, y);
      ctx.lineTo(width - 20, y);
      ctx.stroke();
    }
  }

  function update() {
    // パーティクルを更新
    particles = particles.filter(p => p.update());
  }

  function render() {
    // 背景
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    drawScale();
    drawSink();

    // パーティクル描画
    for (const p of particles) {
      p.draw();
    }

    // HUD更新
    hudFalling.textContent = particles.filter(p => !p.done).length;
    hudReached.textContent = reachedCount;
    hudLongest.textContent = longestPath;
  }

  let lastUpdate = 0;
  function loop(timestamp) {
    // 1秒に2回更新（ゆっくり）
    if (timestamp - lastUpdate > 16) { // 60fps for smooth animation
      update();
      lastUpdate = timestamp;
    }
    render();
    requestAnimationFrame(loop);
  }

  // 自動スポーン
  let autoSpawnInterval;
  function startAutoSpawn() {
    autoSpawnInterval = setInterval(() => {
      if (particles.length < 30) {
        spawnParticle();
      }
    }, 2000); // 2秒ごと
  }

  // イベント
  document.getElementById('spawnBtn').addEventListener('click', () => {
    spawnParticle();
  });

  document.getElementById('burstBtn').addEventListener('click', () => {
    spawnBurst();
  });

  document.getElementById('resetBtn').addEventListener('click', () => {
    reset();
  });

  canvas.addEventListener('click', (e) => {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    // クリック位置から数を推定
    const ratio = Math.max(0, (sinkY - y) / (sinkY - 80));
    const n = Math.max(2, Math.floor(Math.pow(10, ratio * 4)));
    spawnParticle(n);
  });

  document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT') return;
    switch (e.key) {
      case 'h': case 'H':
        document.body.classList.toggle('hide-ui');
        break;
      case ' ':
        e.preventDefault();
        spawnBurst();
        break;
    }
  });

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    sinkY = height - 100;
    sinkX = width / 2;
  }

  window.addEventListener('resize', resize);

  // 初期化
  resize();
  startAutoSpawn();
  // 最初にいくつかスポーン
  for (let i = 0; i < 5; i++) {
    setTimeout(() => spawnParticle(), i * 500);
  }
  requestAnimationFrame(loop);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
