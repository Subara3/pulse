const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let cols, rows;
const CELL_SIZE = 4;
let grid;
let isPointerDown = false;
let currentMaterial = 'sand';

const EMPTY = 0;
const SAND = 1;
const WATER = 2;
const STONE = 3;

const COLORS = {
  sand: ['#e6d5a8', '#d4c496', '#c9b885', '#f0e5bf'],
  water: ['#4a90d9', '#5ba0e9', '#3a80c9', '#6ab0f9'],
  stone: ['#666666', '#555555', '#777777', '#606060']
};

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  cols = Math.floor(width / CELL_SIZE);
  rows = Math.floor(height / CELL_SIZE);
  grid = [];
  for (let i = 0; i < cols * rows; i++) {
    grid[i] = { type: EMPTY, color: null };
  }
}

function getIndex(x, y) {
  if (x < 0 || x >= cols || y < 0 || y >= rows) return -1;
  return y * cols + x;
}

function getCell(x, y) {
  const idx = getIndex(x, y);
  return idx >= 0 ? grid[idx] : null;
}

function setCell(x, y, type, color) {
  const idx = getIndex(x, y);
  if (idx >= 0) grid[idx] = { type, color };
}

function isEmpty(x, y) {
  const cell = getCell(x, y);
  return cell && cell.type === EMPTY;
}

function isWater(x, y) {
  const cell = getCell(x, y);
  return cell && cell.type === WATER;
}

function randomColor(material) {
  const colors = COLORS[material];
  return colors[Math.floor(Math.random() * colors.length)];
}

function updateSand(x, y) {
  if (isEmpty(x, y + 1)) {
    const cell = getCell(x, y);
    setCell(x, y + 1, SAND, cell.color);
    setCell(x, y, EMPTY, null);
    return;
  }
  if (isWater(x, y + 1)) {
    const cell = getCell(x, y);
    const waterCell = getCell(x, y + 1);
    setCell(x, y + 1, SAND, cell.color);
    setCell(x, y, WATER, waterCell.color);
    return;
  }
  const dir = Math.random() < 0.5 ? -1 : 1;
  if (isEmpty(x + dir, y + 1)) {
    const cell = getCell(x, y);
    setCell(x + dir, y + 1, SAND, cell.color);
    setCell(x, y, EMPTY, null);
  } else if (isEmpty(x - dir, y + 1)) {
    const cell = getCell(x, y);
    setCell(x - dir, y + 1, SAND, cell.color);
    setCell(x, y, EMPTY, null);
  }
}

function updateWater(x, y) {
  if (isEmpty(x, y + 1)) {
    const cell = getCell(x, y);
    setCell(x, y + 1, WATER, cell.color);
    setCell(x, y, EMPTY, null);
    return;
  }
  const dir = Math.random() < 0.5 ? -1 : 1;
  if (isEmpty(x + dir, y + 1)) {
    const cell = getCell(x, y);
    setCell(x + dir, y + 1, WATER, cell.color);
    setCell(x, y, EMPTY, null);
    return;
  }
  if (isEmpty(x - dir, y + 1)) {
    const cell = getCell(x, y);
    setCell(x - dir, y + 1, WATER, cell.color);
    setCell(x, y, EMPTY, null);
    return;
  }
  if (isEmpty(x + dir, y)) {
    const cell = getCell(x, y);
    setCell(x + dir, y, WATER, cell.color);
    setCell(x, y, EMPTY, null);
  } else if (isEmpty(x - dir, y)) {
    const cell = getCell(x, y);
    setCell(x - dir, y, WATER, cell.color);
    setCell(x, y, EMPTY, null);
  }
}

function update() {
  for (let y = rows - 2; y >= 0; y--) {
    const startX = Math.random() < 0.5 ? 0 : cols - 1;
    const stepX = startX === 0 ? 1 : -1;
    for (let i = 0; i < cols; i++) {
      const x = startX + i * stepX;
      const cell = getCell(x, y);
      if (!cell) continue;
      if (cell.type === SAND) updateSand(x, y);
      else if (cell.type === WATER) updateWater(x, y);
    }
  }
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      const cell = getCell(x, y);
      if (cell && cell.type !== EMPTY) {
        ctx.fillStyle = cell.color;
        ctx.fillRect(x * CELL_SIZE, y * CELL_SIZE, CELL_SIZE, CELL_SIZE);
      }
    }
  }
}

function addMaterial(px, py) {
  const x = Math.floor(px / CELL_SIZE);
  const y = Math.floor(py / CELL_SIZE);
  const radius = 3;
  for (let dy = -radius; dy <= radius; dy++) {
    for (let dx = -radius; dx <= radius; dx++) {
      if (dx * dx + dy * dy <= radius * radius) {
        if (Math.random() < 0.7 && isEmpty(x + dx, y + dy)) {
          let type, color;
          if (currentMaterial === 'sand') { type = SAND; color = randomColor('sand'); }
          else if (currentMaterial === 'water') { type = WATER; color = randomColor('water'); }
          else if (currentMaterial === 'stone') { type = STONE; color = randomColor('stone'); }
          setCell(x + dx, y + dy, type, color);
        }
      }
    }
  }
}

function getPointerPos(e) {
  if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

canvas.addEventListener('mousedown', (e) => { isPointerDown = true; addMaterial(getPointerPos(e).x, getPointerPos(e).y); });
canvas.addEventListener('mousemove', (e) => { if (isPointerDown) addMaterial(getPointerPos(e).x, getPointerPos(e).y); });
canvas.addEventListener('mouseup', () => isPointerDown = false);
canvas.addEventListener('mouseleave', () => isPointerDown = false);
canvas.addEventListener('touchstart', (e) => { isPointerDown = true; addMaterial(getPointerPos(e).x, getPointerPos(e).y); e.preventDefault(); }, { passive: false });
canvas.addEventListener('touchmove', (e) => { if (isPointerDown) addMaterial(getPointerPos(e).x, getPointerPos(e).y); e.preventDefault(); }, { passive: false });
canvas.addEventListener('touchend', () => isPointerDown = false);
canvas.addEventListener('touchcancel', () => isPointerDown = false);

document.querySelectorAll('.control-btn[data-color]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.control-btn[data-color]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentMaterial = btn.dataset.color;
  });
});

document.getElementById('resetBtn').addEventListener('click', init);
window.addEventListener('resize', init);

function loop() { update(); draw(); requestAnimationFrame(loop); }
init();
loop();
