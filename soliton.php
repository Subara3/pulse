<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('soliton');

$pageName = 'soliton';
$pageTitle = 'SOLITON - PULSE';
$pageDesc = "KdV方程式による孤立波。衝突後も形を保って通り抜ける非線形波動現象。";
$pageCanonical = 'https://pulse.subara3.com/soliton.php';
$extraStyle = '
#hud{position:fixed;top:1.2rem;right:1.2rem;font-family:monospace;font-size:.7rem;color:rgba(0,245,212,.6);text-align:right;pointer-events:none;line-height:1.9}
#eq{position:fixed;bottom:1.2rem;left:1.2rem;font-family:monospace;font-size:.62rem;color:rgba(0,245,212,.3);pointer-events:none;line-height:1.6}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div id="hud-n">SOLITONS: 2</div>
  <div id="hud-t">t = 0.0</div>
</div>
<div id="eq">u<sub>t</sub> − 6uu<sub>x</sub> + u<sub>xxx</sub> = 0</div>
<div id="controls" class="drawer-controls">
  <button id="btnReset" type="button">RESET</button>
  <label class="slider-row">SPEED <span id="speedVal" class="ctrl-val">0.7</span>
    <input type="range" id="speedSlider" min="0.2" max="1.8" step="0.1" value="0.7">
  </label>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'speed'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx    = canvas.getContext('2d');
const hudN   = document.getElementById('hud-n');
const hudT   = document.getElementById('hud-t');
const btnReset = document.getElementById('btnReset');
const speedSlider = document.getElementById('speedSlider');
const speedVal = document.getElementById('speedVal');

let W, H;
function resize() {
  W = canvas.width  = window.innerWidth;
  H = canvas.height = window.innerHeight;
}
window.addEventListener('resize', resize);
resize();

// === KdV: u_t - 6uu_x + u_xxx = 0 ===
// 1-soliton: u(x,t) = 2k² sech²(kx - 4k³t - x0)
// peak position: x_p = 4k²t + x0/k
// velocity: v = 4k²,  amplitude: A = 2k²

const DOMAIN    = 60;   // [0, DOMAIN] 周期空間
const MAX_SOL   = 6;
let timeScale = 0.7;  // time scale
const AMP       = 0.14; // 振幅→ピクセル変換 (u=1 → H*AMP px)
const SAMPLES   = 750;

// sech²(x) — 数値安定版
function sech2(x) {
  if (Math.abs(x) > 30) return 0;
  const e = Math.exp(-2 * Math.abs(x));
  return 4 * e / ((1 + e) * (1 + e));
}

// ソリトン1個の寄与
function solU(sol, x, t) {
  return 2 * sol.k * sol.k * sech2(sol.k * x - 4 * sol.k * sol.k * sol.k * t - sol.x0);
}

// 全ソリトンの合成（周期境界: ±1周期分も足す）
function totalU(x, t) {
  let u = 0;
  for (const s of solitons) {
    u += solU(s, x,          t);
    u += solU(s, x - DOMAIN, t);
    u += solU(s, x + DOMAIN, t);
  }
  return u;
}

// デフォルト: 速い(k=1.2,x_init=10)と遅い(k=0.6,x_init=34)
// 衝突まで約7秒
let solitons = [
  { k: 1.2, x0: 1.2 * 10 },
  { k: 0.6, x0: 0.6 * 34 },
];

// ソリトンカラー (teal–purple 範囲)
const COLORS = [
  [0, 245, 212],
  [100, 80, 240],
  [0, 200, 230],
  [140, 50, 210],
  [0, 220, 180],
  [120, 60, 255],
];

let simTime  = 0;
let lastTs   = null;
let dragStart = null;

function loop(ts) {
  if (lastTs === null) lastTs = ts;
  const dt = Math.min((ts - lastTs) / 1000, 0.05);
  lastTs = ts;
  simTime += dt * timeScale;

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  const baseY = H * 0.62;

  // 基準線
  ctx.strokeStyle = 'rgba(0,245,212,0.07)';
  ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(0, baseY); ctx.lineTo(W, baseY); ctx.stroke();

  // x軸スケール目盛り（薄く）
  ctx.fillStyle = 'rgba(0,245,212,0.1)';
  ctx.font = '9px monospace';
  ctx.textAlign = 'center';
  for (let xk = 0; xk <= DOMAIN; xk += 10) {
    const px = (xk / DOMAIN) * W;
    ctx.fillText(xk, px, baseY + 14);
  }

  // 波形ポイント計算
  const pts = new Float32Array((SAMPLES + 1) * 2);
  for (let i = 0; i <= SAMPLES; i++) {
    const xKdv = (i / SAMPLES) * DOMAIN;
    const u = totalU(xKdv, simTime);
    pts[i * 2]     = (i / SAMPLES) * W;
    pts[i * 2 + 1] = baseY - u * H * AMP;
  }

  // 塗りつぶし（グラデーション）
  const grad = ctx.createLinearGradient(0, baseY - H * 0.45, 0, baseY);
  grad.addColorStop(0, 'rgba(0,245,212,0.18)');
  grad.addColorStop(1, 'rgba(0,245,212,0.01)');
  ctx.beginPath();
  ctx.moveTo(pts[0], baseY);
  for (let i = 0; i <= SAMPLES; i++) ctx.lineTo(pts[i*2], pts[i*2+1]);
  ctx.lineTo(pts[SAMPLES*2], baseY);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // 波形ライン（グロー: 幅広→細）
  for (let g = 3; g >= 0; g--) {
    ctx.beginPath();
    ctx.moveTo(pts[0], pts[1]);
    for (let i = 1; i <= SAMPLES; i++) ctx.lineTo(pts[i*2], pts[i*2+1]);
    if (g === 0) {
      ctx.strokeStyle = '#00f5d4';
      ctx.lineWidth = 1.5;
    } else {
      ctx.strokeStyle = `rgba(0,245,212,${0.05 * g})`;
      ctx.lineWidth = g * 6;
    }
    ctx.stroke();
  }

  // 各ソリトンのピーク位置・ラベル
  for (let i = 0; i < solitons.length; i++) {
    const s = solitons[i];
    const xPeak = ((4 * s.k * s.k * simTime + s.x0 / s.k) % DOMAIN + DOMAIN) % DOMAIN;
    const pxPeak = (xPeak / DOMAIN) * W;
    const amp = 2 * s.k * s.k;
    const vel = 4 * s.k * s.k;
    const pyPeak = baseY - amp * H * AMP - 18;
    const [r, g2, b] = COLORS[i % COLORS.length];

    // ピーク上にドット
    ctx.beginPath();
    ctx.arc(pxPeak, baseY - amp * H * AMP, 3, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(${r},${g2},${b},0.8)`;
    ctx.fill();

    // ラベル
    ctx.fillStyle = `rgba(${r},${g2},${b},0.55)`;
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText(`v=${vel.toFixed(1)}`, pxPeak, Math.max(18, pyPeak));
  }

  // HUD
  hudN.textContent = `SOLITONS: ${solitons.length}`;
  hudT.textContent = `t = ${simTime.toFixed(1)}`;

  requestAnimationFrame(loop);
}

requestAnimationFrame(loop);

function resetSolitons() {
  solitons = [
    { k: 1.2, x0: 1.2 * 10 },
    { k: 0.6, x0: 0.6 * 34 },
  ];
  simTime = 0;
  lastTs = null;
}

// === インタラクション ===
canvas.addEventListener('mousedown', (e) => {
  if (e.button !== 0) return;
  dragStart = { x: e.clientX, y: e.clientY };
});

canvas.addEventListener('mouseup', (e) => {
  if (e.button !== 0 || !dragStart) return;
  const dx = e.clientX - dragStart.x;
  const dy = e.clientY - dragStart.y;
  const dist = Math.hypot(dx, dy);

  if (solitons.length < MAX_SOL) {
    const xKdv = (dragStart.x / W) * DOMAIN;
    if (dist < 8) {
      // クリック: ランダム速度
      const k = 0.4 + Math.random() * 1.2;
      solitons.push({ k, x0: k * xKdv });
    } else {
      // ドラッグ: 距離が速度に対応
      const k = Math.max(0.3, Math.min(1.8, dist / 55));
      solitons.push({ k, x0: k * xKdv });
    }
    SFX.play('spawn');
  }
  dragStart = null;
});

canvas.addEventListener('contextmenu', (e) => {
  e.preventDefault();
  resetSolitons();
  SFX.play('toggle');
});

btnReset.addEventListener('click', () => {
  resetSolitons();
  SFX.play('click');
});

speedSlider.addEventListener('input', () => {
  timeScale = parseFloat(speedSlider.value);
  speedVal.textContent = timeScale.toFixed(1);
});

// タッチ
let touchData = null;
canvas.addEventListener('touchstart', (e) => {
  e.preventDefault();
  touchData = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
}, { passive: false });

canvas.addEventListener('touchend', (e) => {
  e.preventDefault();
  if (!touchData) return;
  const tx   = e.changedTouches[0].clientX;
  const ty   = e.changedTouches[0].clientY;
  const dist = Math.hypot(tx - touchData.x, ty - touchData.y);

  if (solitons.length < MAX_SOL) {
    const xKdv = (touchData.x / W) * DOMAIN;
    const k = dist < 15
      ? 0.4 + Math.random() * 1.2
      : Math.max(0.3, Math.min(1.8, dist / 55));
    solitons.push({ k, x0: k * xKdv });
    SFX.play('spawn');
  }
  touchData = null;
}, { passive: false });

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
