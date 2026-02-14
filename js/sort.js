const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let array = [];
let arraySize = 100;
let comparing = [-1, -1];
let swapping = [-1, -1];
let sorted = [];
let comparisons = 0;
let swapCount = 0;
let sorting = false;
let currentAlgo = 'bubble';
let sortGenerator = null;
let speed = 5;

const algoNames = {
  bubble: 'BUBBLE SORT',
  selection: 'SELECTION SORT',
  insertion: 'INSERTION SORT',
  quick: 'QUICK SORT'
};

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  arraySize = Math.min(Math.floor(width / 8), 150);
  shuffle();
}

function shuffle() {
  sorting = false;
  sortGenerator = null;
  array = [];
  sorted = [];
  comparing = [-1, -1];
  swapping = [-1, -1];
  comparisons = 0;
  swapCount = 0;
  for (let i = 0; i < arraySize; i++) array[i] = Math.random() * 0.9 + 0.1;
  updateStats();
  setTimeout(() => startSort(), 500);
}

function updateStats() {
  document.getElementById('comparisons').textContent = comparisons;
  document.getElementById('swaps').textContent = swapCount;
}

function* bubbleSort() {
  for (let i = 0; i < array.length; i++) {
    for (let j = 0; j < array.length - i - 1; j++) {
      comparing = [j, j + 1]; comparisons++; yield;
      if (array[j] > array[j + 1]) {
        swapping = [j, j + 1];
        [array[j], array[j + 1]] = [array[j + 1], array[j]];
        swapCount++; yield; swapping = [-1, -1];
      }
    }
    sorted.push(array.length - i - 1);
  }
  comparing = [-1, -1];
}

function* selectionSort() {
  for (let i = 0; i < array.length; i++) {
    let minIdx = i;
    for (let j = i + 1; j < array.length; j++) {
      comparing = [minIdx, j]; comparisons++; yield;
      if (array[j] < array[minIdx]) minIdx = j;
    }
    if (minIdx !== i) {
      swapping = [i, minIdx];
      [array[i], array[minIdx]] = [array[minIdx], array[i]];
      swapCount++; yield; swapping = [-1, -1];
    }
    sorted.push(i);
  }
  comparing = [-1, -1];
}

function* insertionSort() {
  sorted.push(0);
  for (let i = 1; i < array.length; i++) {
    let j = i;
    while (j > 0) {
      comparing = [j - 1, j]; comparisons++; yield;
      if (array[j - 1] > array[j]) {
        swapping = [j - 1, j];
        [array[j - 1], array[j]] = [array[j], array[j - 1]];
        swapCount++; yield; swapping = [-1, -1]; j--;
      } else break;
    }
    sorted.push(i);
  }
  comparing = [-1, -1];
}

function* quickSort(low = 0, high = array.length - 1) {
  if (low < high) {
    let pivot = array[high];
    let i = low - 1;
    for (let j = low; j < high; j++) {
      comparing = [j, high]; comparisons++; yield;
      if (array[j] < pivot) {
        i++;
        if (i !== j) {
          swapping = [i, j];
          [array[i], array[j]] = [array[j], array[i]];
          swapCount++; yield; swapping = [-1, -1];
        }
      }
    }
    if (i + 1 !== high) {
      swapping = [i + 1, high];
      [array[i + 1], array[high]] = [array[high], array[i + 1]];
      swapCount++; yield; swapping = [-1, -1];
    }
    sorted.push(i + 1);
    yield* quickSort(low, i);
    yield* quickSort(i + 2, high);
  } else if (low === high) sorted.push(low);
  comparing = [-1, -1];
}

function startSort() {
  sorting = true; sorted = []; comparing = [-1, -1]; swapping = [-1, -1]; comparisons = 0; swapCount = 0;
  switch (currentAlgo) {
    case 'bubble': sortGenerator = bubbleSort(); break;
    case 'selection': sortGenerator = selectionSort(); break;
    case 'insertion': sortGenerator = insertionSort(); break;
    case 'quick': sortGenerator = quickSort(); break;
  }
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  const barWidth = width / array.length;
  const maxHeight = height * 0.8;
  for (let i = 0; i < array.length; i++) {
    const barHeight = array[i] * maxHeight;
    const x = i * barWidth;
    const y = height - barHeight;
    let color;
    if (swapping.includes(i)) color = '#ff6b6b';
    else if (comparing.includes(i)) color = '#ffd93d';
    else if (sorted.includes(i)) color = '#00f5d4';
    else color = 'rgba(255, 255, 255, 0.6)';
    ctx.fillStyle = color;
    ctx.fillRect(x, y, barWidth - 1, barHeight);
  }
}

function loop() {
  if (sorting && sortGenerator) {
    for (let i = 0; i < speed; i++) {
      const result = sortGenerator.next();
      if (result.done) {
        sorting = false; sortGenerator = null;
        sorted = array.map((_, i) => i);
        setTimeout(shuffle, 2000);
        break;
      }
    }
    updateStats();
  }
  draw();
  requestAnimationFrame(loop);
}

document.querySelectorAll('.control-btn[data-algo]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.control-btn[data-algo]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentAlgo = btn.dataset.algo;
    document.getElementById('algoName').textContent = algoNames[currentAlgo];
    shuffle();
  });
});

document.getElementById('shuffleBtn').addEventListener('click', shuffle);
window.addEventListener('resize', () => { width = canvas.width = window.innerWidth; height = canvas.height = window.innerHeight; });

init();
loop();
