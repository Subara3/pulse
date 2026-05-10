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
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud" id="hud">
  <div>FALLING: <span class="val" id="hudFalling">0</span></div>
  <div>REACHED 1: <span class="val" id="hudReached">0</span></div>
  <div>LONGEST: <span class="val" id="hudLongest">0</span> steps</div>
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

  let width, height;
  let particles = [];
  let floatingTexts = []; // 計算エフェクト用
  let reachedCount = 0;
  let longestPath = 0;
  let nextN = 2;
  let sinkY, sinkX;

  const isMobile = window.innerWidth <= 768;

  // 色パレット
  function getColor(n, steps) {
    const t = Math.min(1, Math.log10(n) / 4);
    const r = Math.floor(0 + t * 123);
    const g = Math.floor(245 - t * 200);
    const b = Math.floor(212 + t * 40);
    return { r, g, b };
  }

  // フローティングテキスト（計算エフェクト）
  class FloatingText {
    constructor(x, y, text, isUp) {
      this.x = x;
      this.y = y;
      this.text = text;
      this.isUp = isUp; // 上昇（×3+1）か下降（÷2）か
      this.life = 1;
      this.vy = isUp ? -1.5 : 1.5;
    }

    update() {
      this.y += this.vy;
      this.life -= 0.025;
      return this.life > 0;
    }

    draw() {
      const alpha = this.life;
      ctx.save();
      ctx.font = 'bold 14px "JetBrains Mono"';
      ctx.textAlign = 'center';
      
      // 背景（読みやすくするため）
      const metrics = ctx.measureText(this.text);
      const padding = 4;
      ctx.fillStyle = `rgba(10,10,15,${alpha * 0.8})`;
      ctx.fillRect(
        this.x - metrics.width / 2 - padding,
        this.y - 10 - padding,
        metrics.width + padding * 2,
        20 + padding * 2
      );
      
      // テキスト
      if (this.isUp) {
        // ×3+1 は紫（上昇）
        ctx.fillStyle = `rgba(180,100,255,${alpha})`;
      } else {
        // ÷2 はティール（下降）
        ctx.fillStyle = `rgba(0,245,212,${alpha})`;
      }
      ctx.fillText(this.text, this.x, this.y);
      ctx.restore();
    }
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
      let isUp = false;
      
      if (this.n % 2 === 0) {
        this.n = this.n / 2;
        // エフェクト: ÷2
        floatingTexts.push(new FloatingText(
          this.x + 30,
          this.y,
          `${prevN} ÷2 = ${this.n}`,
          false
        ));
      } else {
        this.n = 3 * this.n + 1;
        isUp = true;
        // エフェクト: ×3+1
        floatingTexts.push(new FloatingText(
          this.x + 30,
          this.y,
          `${prevN} ×3+1 = ${this.n}`,
          true
        ));
      }
      this.steps++;
      this.history.push(this.n);

      // 新しいターゲット位置を計算
      const maxN = 10000;
      const logN = Math.log10(Math.max(1, this.n));
      const logMax = Math.log10(maxN);
      const ratio = Math.min(1, logN / logMax);
      this.targetY = sinkY - ratio * (sinkY - 80);

      // 横方向も少し揺らす
      this.x += (Math.random() - 0.5) * 20;
      this.x += (sinkX - this.x) * 0.05;

      // 次の更新まで待機
      this.waitFrames = 40;

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

      // 数字ラベル
      if (this.fadeOut > 0.5) {
        ctx.fillStyle = `rgba(255,255,255,${alpha * 0.9})`;
        ctx.font = 'bold 12px "Orbitron"';
        ctx.textAlign = 'center';
        ctx.fillText(this.n.toString(), this.x, this.y - size - 8);
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
    floatingTexts = [];
    reachedCount = 0;
    longestPath = 0;
    nextN = 2;
    SFX.play('click');
  }

  function drawSink() {
    const pulse = 0.8 + Math.sin(Date.now() * 0.002) * 0.2;

    const grad = ctx.createRadialGradient(sinkX, sinkY, 0, sinkX, sinkY, 80);
    grad.addColorStop(0, `rgba(0,245,212,${0.2 * pulse})`);
    grad.addColorStop(0.5, `rgba(123,44,191,${0.1 * pulse})`);
    grad.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.arc(sinkX, sinkY, 80, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = `rgba(0,245,212,${0.8 * pulse})`;
    ctx.font = 'bold 48px "Orbitron"';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('1', sinkX, sinkY);

    ctx.fillStyle = 'rgba(255,255,255,0.2)';
    ctx.font = '12px "Orbitron"';
    ctx.fillText('ALL PATHS LEAD HERE', sinkX, sinkY + 50);
  }

  function drawScale() {
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
    particles = particles.filter(p => p.update());
    floatingTexts = floatingTexts.filter(t => t.update());
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    drawScale();
    drawSink();

    for (const p of particles) {
      p.draw();
    }

    // フローティングテキスト（計算エフェクト）
    for (const t of floatingTexts) {
      t.draw();
    }

    hudFalling.textContent = particles.filter(p => !p.done).length;
    hudReached.textContent = reachedCount;
    hudLongest.textContent = longestPath;
  }

  let lastUpdate = 0;
  function loop(timestamp) {
    if (timestamp - lastUpdate > 16) {
      update();
      lastUpdate = timestamp;
    }
    render();
    requestAnimationFrame(loop);
  }

  let autoSpawnInterval;
  function startAutoSpawn() {
    autoSpawnInterval = setInterval(() => {
      if (particles.length < 30) {
        spawnParticle();
      }
    }, 2000);
  }

  document.getElementById('spawnBtn').addEventListener('click', () => spawnParticle());
  document.getElementById('burstBtn').addEventListener('click', () => spawnBurst());
  document.getElementById('resetBtn').addEventListener('click', () => reset());

  canvas.addEventListener('click', (e) => {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
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

  resize();
  startAutoSpawn();
  for (let i = 0; i < 5; i++) {
    setTimeout(() => spawnParticle(), i * 500);
  }
  requestAnimationFrame(loop);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
