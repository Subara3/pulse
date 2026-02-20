<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('sort');

$pageName = 'sort';
$pageTitle = 'SORT - PULSE';
$pageDesc = 'ソートアルゴリズムの可視化。眺めるだけ。';
$pageCanonical = 'https://pulse.subara3.com/sort.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.info { top: 1rem; right: 1rem; text-align: right; }
.algorithm-name { font-family: "Orbitron", sans-serif; font-size: 1rem; color: #00f5d4; letter-spacing: 0.1em; margin-bottom: 0.5rem; }
.stats { font-family: "JetBrains Mono", monospace; font-size: 0.75rem; color: rgba(255,255,255,0.4); }

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .info { top: 0.5rem; right: 0.5rem; }
  .algorithm-name { font-size: 0.75rem; }
  .stats { font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui title">SORT</div>

<div class="ui info">
  <div class="algorithm-name" id="algoName">BUBBLE SORT</div>
  <div class="stats">
    <div>comparisons: <span id="comparisons">0</span></div>
    <div>swaps: <span id="swaps">0</span></div>
  </div>
</div>

<div id="controls" class="drawer-controls">
  <button class="control-btn active" data-algo="bubble">BUBBLE</button>
  <button class="control-btn" data-algo="selection">SELECTION</button>
  <button class="control-btn" data-algo="insertion">INSERTION</button>
  <button class="control-btn" data-algo="quick">QUICK</button>
  <button class="control-btn" id="shuffleBtn">SHUFFLE</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

const isMobile = window.innerWidth <= 768;
let width, height;
let array = [];
let arraySize = isMobile ? 50 : 100;
let comparing = [-1, -1];
let swapping = [-1, -1];
let sorted = [];
let comparisons = 0;
let swapCount = 0;
let currentAlgo = 'bubble';
let sorting = false;
let generator = null;

function resize() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
}

function initArray() {
  array = [];
  for (let i = 0; i < arraySize; i++) {
    array.push(Math.random() * 0.8 + 0.1);
  }
  sorted = [];
  comparisons = 0;
  swapCount = 0;
  comparing = [-1, -1];
  swapping = [-1, -1];
  updateStats();
}

function updateStats() {
  document.getElementById('comparisons').textContent = comparisons;
  document.getElementById('swaps').textContent = swapCount;
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  
  const barWidth = width / arraySize;
  const maxHeight = height * 0.8;
  
  for (let i = 0; i < array.length; i++) {
    const x = i * barWidth;
    const h = array[i] * maxHeight;
    const y = height - h;
    
    let color;
    if (swapping.includes(i)) {
      color = '#7b2cbf';
    } else if (comparing.includes(i)) {
      color = '#ff6b6b';
    } else if (sorted.includes(i)) {
      color = '#00f5d4';
    } else {
      const t = i / arraySize;
      const r = Math.round(0 + t * 123);
      const g = Math.round(245 - t * 201);
      const b = Math.round(212 + t * (191 - 212));
      color = `rgb(${r},${g},${b})`;
    }
    
    ctx.fillStyle = color;
    ctx.fillRect(x, y, barWidth - 1, h);
  }
}

function* bubbleSort() {
  for (let i = 0; i < array.length; i++) {
    for (let j = 0; j < array.length - i - 1; j++) {
      comparing = [j, j + 1];
      comparisons++;
      yield;
      
      if (array[j] > array[j + 1]) {
        swapping = [j, j + 1];
        [array[j], array[j + 1]] = [array[j + 1], array[j]];
        swapCount++;
        if (typeof SFX !== 'undefined') SFX.play('tick', 30);
        yield;
        swapping = [-1, -1];
      }
    }
    sorted.push(array.length - i - 1);
  }
  comparing = [-1, -1];
  sorted = array.map((_, i) => i);
  if (typeof SFX !== 'undefined') SFX.play('complete');
}

function* selectionSort() {
  for (let i = 0; i < array.length; i++) {
    let minIdx = i;
    for (let j = i + 1; j < array.length; j++) {
      comparing = [minIdx, j];
      comparisons++;
      yield;
      
      if (array[j] < array[minIdx]) {
        minIdx = j;
      }
    }
    
    if (minIdx !== i) {
      swapping = [i, minIdx];
      [array[i], array[minIdx]] = [array[minIdx], array[i]];
      swapCount++;
      if (typeof SFX !== 'undefined') SFX.play('tick', 30);
      yield;
      swapping = [-1, -1];
    }
    sorted.push(i);
  }
  comparing = [-1, -1];
  sorted = array.map((_, i) => i);
  if (typeof SFX !== 'undefined') SFX.play('complete');
}

function* insertionSort() {
  sorted.push(0);
  for (let i = 1; i < array.length; i++) {
    let j = i;
    while (j > 0) {
      comparing = [j - 1, j];
      comparisons++;
      yield;
      
      if (array[j - 1] > array[j]) {
        swapping = [j - 1, j];
        [array[j - 1], array[j]] = [array[j], array[j - 1]];
        swapCount++;
        if (typeof SFX !== 'undefined') SFX.play('tick', 30);
        yield;
        swapping = [-1, -1];
        j--;
      } else {
        break;
      }
    }
    sorted.push(i);
  }
  comparing = [-1, -1];
  sorted = array.map((_, i) => i);
  if (typeof SFX !== 'undefined') SFX.play('complete');
}

function* quickSort(low = 0, high = array.length - 1) {
  if (low < high) {
    let pivot = array[high];
    let i = low - 1;
    
    for (let j = low; j < high; j++) {
      comparing = [j, high];
      comparisons++;
      yield;
      
      if (array[j] < pivot) {
        i++;
        if (i !== j) {
          swapping = [i, j];
          [array[i], array[j]] = [array[j], array[i]];
          swapCount++;
          if (typeof SFX !== 'undefined') SFX.play('tick', 30);
          yield;
          swapping = [-1, -1];
        }
      }
    }
    
    swapping = [i + 1, high];
    [array[i + 1], array[high]] = [array[high], array[i + 1]];
    swapCount++;
    if (typeof SFX !== 'undefined') SFX.play('tick', 30);
    yield;
    swapping = [-1, -1];
    
    const pi = i + 1;
    sorted.push(pi);
    
    yield* quickSort(low, pi - 1);
    yield* quickSort(pi + 1, high);
  } else if (low === high) {
    sorted.push(low);
  }
  
  if (low === 0 && high === array.length - 1) {
    comparing = [-1, -1];
    sorted = array.map((_, i) => i);
    if (typeof SFX !== 'undefined') SFX.play('complete');
  }
}

function startSort() {
  sorting = true;
  sorted = [];
  comparisons = 0;
  swapCount = 0;
  
  switch (currentAlgo) {
    case 'bubble':
      generator = bubbleSort();
      break;
    case 'selection':
      generator = selectionSort();
      break;
    case 'insertion':
      generator = insertionSort();
      break;
    case 'quick':
      generator = quickSort();
      break;
  }
}

function step() {
  if (generator) {
    const result = generator.next();
    if (result.done) {
      sorting = false;
      generator = null;
    }
    updateStats();
  }
}

let lastStep = 0;
const stepInterval = 20;

function animate(time) {
  requestAnimationFrame(animate);
  
  if (sorting && time - lastStep > stepInterval) {
    step();
    lastStep = time;
  }
  
  draw();
}

// イベント
window.addEventListener('resize', resize);

document.querySelectorAll('[data-algo]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('[data-algo]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentAlgo = btn.dataset.algo;
    document.getElementById('algoName').textContent = currentAlgo.toUpperCase() + ' SORT';
    if (typeof SFX !== 'undefined') SFX.play('toggle');
    initArray();
    startSort();
  });
});

document.getElementById('shuffleBtn').addEventListener('click', () => {
  if (typeof SFX !== 'undefined') SFX.play('click');
  initArray();
  startSort();
});

// 初期化
resize();
initArray();
startSort();
animate(0);
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
