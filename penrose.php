<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('penrose');

$pageName = 'penrose';
$pageTitle = 'PENROSE - PULSE';
$pageDesc = 'ペンローズタイル。非周期的で無限に続くパターン。';
$pageCanonical = 'https://pulse.subara3.com/penrose.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; margin: 0; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 1000; font-family: "Orbitron", sans-serif; }
.title { bottom: 1rem; left: 1rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.hide-ui .title, .hide-ui .content-controls, .hide-ui .floating-nav { opacity: 0 !important; pointer-events: none; }
';

// コントロールボタン設定
$controls = [
  ['id' => 'colorBtn', 'icon' => 'fa-palette', 'label' => 'COLOR'],
  ['id' => 'autoBtn', 'icon' => 'fa-rotate', 'label' => 'ROTATE', 'active' => true],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<div class="ui title">PENROSE TILING</div>

<canvas id="canvas"></canvas>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// 黄金比
const PHI = (1 + Math.sqrt(5)) / 2;

// 状態
let rotation = 0;
let autoRotate = true;
let colorMode = 0;
let hueShift = 0;
const rotateSpeed = 0.0003; // とてもゆっくり

// 色スキーム（ベース色）
const colorSchemes = [
  { bg: '#0a0a0f', base: [180, 60, 40] }, // ティール系 (hue 170-190)
  { bg: '#0a0a12', base: [275, 50, 35] }, // パープル系 (hue 260-290)
  { bg: '#0a0a0f', base: [197, 55, 38] }, // シアン-ブルー系 (hue 185-210)
  { bg: '#0a0a10', base: [230, 52, 35] }, // ティール→パープルグラデ (hue 170-290)
];

// タイルのキャッシュ
let cachedTriangles = null;

function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  cachedTriangles = null;
}

// HSLからRGBへの変換
function hslToRgb(h, s, l) {
  h = h % 360;
  s = s / 100;
  l = l / 100;
  
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs((h / 60) % 2 - 1));
  const m = l - c / 2;
  
  let r, g, b;
  if (h < 60) { r = c; g = x; b = 0; }
  else if (h < 120) { r = x; g = c; b = 0; }
  else if (h < 180) { r = 0; g = c; b = x; }
  else if (h < 240) { r = 0; g = x; b = c; }
  else if (h < 300) { r = x; g = 0; b = c; }
  else { r = c; g = 0; b = x; }
  
  return `rgb(${Math.round((r + m) * 255)}, ${Math.round((g + m) * 255)}, ${Math.round((b + m) * 255)})`;
}

// P3ペンローズタイルの細分化
function subdivide(triangles) {
  const result = [];
  
  for (const [type, a, b, c] of triangles) {
    if (type === 0) {
      const p = [
        a[0] + (b[0] - a[0]) / PHI,
        a[1] + (b[1] - a[1]) / PHI
      ];
      result.push([0, c, p, b]);
      result.push([1, p, c, a]);
    } else {
      const q = [
        b[0] + (a[0] - b[0]) / PHI,
        b[1] + (a[1] - b[1]) / PHI
      ];
      const r = [
        b[0] + (c[0] - b[0]) / PHI,
        b[1] + (c[1] - b[1]) / PHI
      ];
      result.push([1, r, c, a]);
      result.push([1, q, r, b]);
      result.push([0, r, q, a]);
    }
  }
  
  return result;
}

function generateBase() {
  let triangles = [];
  const size = Math.max(canvas.width, canvas.height) * 1.2;
  
  for (let i = 0; i < 10; i++) {
    const angle1 = (i * 2 * Math.PI) / 10;
    const angle2 = ((i + 1) * 2 * Math.PI) / 10;
    
    const a = [0, 0];
    const b = [size * Math.cos(angle1), size * Math.sin(angle1)];
    const c = [size * Math.cos(angle2), size * Math.sin(angle2)];
    
    if (i % 2 === 0) {
      triangles.push([0, a, b, c]);
    } else {
      triangles.push([0, a, c, b]);
    }
  }
  
  // 細分化（6回で十分）
  for (let i = 0; i < 6; i++) {
    triangles = subdivide(triangles);
  }
  
  return triangles;
}

function draw() {
  const scheme = colorSchemes[colorMode];
  const cx = canvas.width / 2;
  const cy = canvas.height / 2;
  
  // 背景
  ctx.fillStyle = scheme.bg;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  
  // キャッシュ
  if (!cachedTriangles) {
    cachedTriangles = generateBase();
  }
  
  // 回転
  ctx.save();
  ctx.translate(cx, cy);
  ctx.rotate(rotation);
  
  // 三角形を描画
  const baseHue = scheme.base[0];
  const baseSat = scheme.base[1];
  const baseLight = scheme.base[2];
  
  cachedTriangles.forEach(([type, a, b, c], idx) => {
    ctx.beginPath();
    ctx.moveTo(a[0], a[1]);
    ctx.lineTo(b[0], b[1]);
    ctx.lineTo(c[0], c[1]);
    ctx.closePath();
    
    // タイプと位置で色相をずらす
    let hue = baseHue + hueShift;
    if (type === 0) {
      hue += 30; // 鋭角は色相をずらす
    }
    hue += (idx % 5) * 8; // バリエーション
    
    const lightness = type === 0 ? baseLight + 15 : baseLight - 5;
    
    ctx.fillStyle = hslToRgb(hue, baseSat, lightness);
    ctx.fill();
    
    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
    ctx.lineWidth = 0.5;
    ctx.stroke();
  });
  
  ctx.restore();
  
  // ビネット効果
  const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.max(cx, cy) * 1.2);
  gradient.addColorStop(0.6, 'transparent');
  gradient.addColorStop(1, scheme.bg);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function animate() {
  if (autoRotate) {
    rotation += rotateSpeed;
    hueShift += 0.05; // 色相もゆっくり変化
  }
  
  draw();
  requestAnimationFrame(animate);
}

// イベント
window.addEventListener('resize', resize);

document.getElementById('colorBtn').addEventListener('click', () => {
  colorMode = (colorMode + 1) % colorSchemes.length;
});

document.getElementById('autoBtn').addEventListener('click', (e) => {
  autoRotate = !autoRotate;
  e.target.classList.toggle('active', autoRotate);
  e.target.textContent = autoRotate ? 'ROTATE' : 'PAUSED';
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'h' || e.key === 'H') {
    document.body.classList.toggle('hide-ui');
  }
  if (e.key === ' ') {
    e.preventDefault();
    autoRotate = !autoRotate;
    const btn = document.getElementById('autoBtn');
    btn.classList.toggle('active', autoRotate);
    btn.textContent = autoRotate ? 'ROTATE' : 'PAUSED';
  }
});

resize();
animate();
</script>

</body>
</html>
