<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('evolution');

$pageName = 'evolution';
$pageTitle = 'EVOLUTION - PULSE';
$pageDesc = '遺伝的アルゴリズムの可視化。生物たちが世代を重ねてゴールを目指す進化を眺める。';
$pageCanonical = 'https://pulse.subara3.com/evolution.php';

$extraHead = '';

$extraStyle = '
body {
  background: #0a0a0f;
  overflow: hidden;
}

.container {
  position: relative;
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
}

canvas {
  flex: 1;
  display: block;
}

/* HUD */
.hud {
  position: fixed;
  top: 1rem;
  left: 1rem;
  z-index: 100;
  pointer-events: none;
}

.hud-panel {
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  backdrop-filter: blur(10px);
}

.hud-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  color: var(--accent);
  letter-spacing: 0.2em;
  margin-bottom: 0.8rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hud-title::before {
  content: "";
  width: 8px;
  height: 8px;
  background: var(--accent);
  border-radius: 50%;
  animation: hud-pulse 1.5s infinite;
}

@keyframes hud-pulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 10px var(--accent); }
  50% { opacity: 0.5; box-shadow: 0 0 5px var(--accent); }
}

.hud-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 0.3rem 0;
  border-bottom: 1px solid rgba(0, 245, 212, 0.1);
}

.hud-row:last-child {
  border-bottom: none;
}

.hud-label {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.7rem;
  color: rgba(255, 255, 255, 0.5);
}

.hud-value {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.9rem;
  color: var(--accent);
  min-width: 60px;
  text-align: right;
}

.hud-value.best {
  color: #00f5d4;
  text-shadow: 0 0 10px rgba(0, 245, 212, 0.5);
}

/* ステージ選択 */
.stage-select {
  position: fixed;
  top: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  gap: 0.5rem;
  pointer-events: auto;
}

.stage-btn {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: rgba(255, 255, 255, 0.5);
  font-family: "Orbitron", sans-serif;
  font-size: 0.8rem;
  cursor: pointer;
  transition: all 0.2s;
  backdrop-filter: blur(10px);
}

.stage-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.stage-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: var(--accent);
  color: var(--accent);
  box-shadow: 0 0 15px rgba(0, 245, 212, 0.3);
}

.stage-btn.cleared {
  border-color: #00f5d4;
  color: #00f5d4;
}

.stage-btn.cleared::after {
  content: "★";
  position: absolute;
  top: -5px;
  right: -5px;
  font-size: 0.6rem;
}

/* コントロール */
.controls {
  position: fixed;
  bottom: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  gap: 0.8rem;
  pointer-events: auto;
}

.ctrl-btn {
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 0.6rem 1.2rem;
  color: var(--accent);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  backdrop-filter: blur(10px);
}

.ctrl-btn:hover {
  background: rgba(0, 245, 212, 0.1);
  border-color: var(--accent);
  box-shadow: 0 0 20px rgba(0, 245, 212, 0.2);
}

.ctrl-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: var(--accent);
}

/* 速度スライダー */
.speed-control {
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 0.8rem 1rem;
  backdrop-filter: blur(10px);
}

.speed-label {
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  color: rgba(255, 255, 255, 0.5);
  margin-bottom: 0.5rem;
  display: flex;
  justify-content: space-between;
}

.speed-label span {
  color: var(--accent);
}

input[type="range"] {
  width: 100px;
  height: 4px;
  -webkit-appearance: none;
  background: rgba(0, 245, 212, 0.2);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 14px;
  height: 14px;
  background: var(--accent);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 0 10px var(--accent);
}

/* ステージ情報 */
.stage-info {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 1rem 1.2rem;
  backdrop-filter: blur(10px);
  max-width: 200px;
}

.stage-info-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.75rem;
  color: var(--accent);
  letter-spacing: 0.1em;
  margin-bottom: 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.stage-info-name {
  font-family: "Orbitron", sans-serif;
  font-size: 1rem;
  color: #fff;
}

/* 凡例 */
.legend {
  position: fixed;
  top: 50%;
  left: 1rem;
  transform: translateY(-50%);
  z-index: 100;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.2);
  border-radius: 8px;
  padding: 0.8rem 1rem;
  backdrop-filter: blur(10px);
}

.legend-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: rgba(255, 255, 255, 0.5);
  margin-bottom: 0.5rem;
  letter-spacing: 0.1em;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.65rem;
  color: rgba(255, 255, 255, 0.6);
  margin-bottom: 0.3rem;
}

.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.legend-dot.alive { background: var(--accent); }
.legend-dot.dead { background: rgba(255, 255, 255, 0.2); }
.legend-dot.best { background: #00f5d4; box-shadow: 0 0 8px #00f5d4; }

/* クリア演出 */
.clear-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.8);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.5s;
}

.clear-overlay.show {
  opacity: 1;
  pointer-events: auto;
}

.clear-title {
  font-family: "Orbitron", sans-serif;
  font-size: 3rem;
  color: #00f5d4;
  text-shadow: 0 0 30px #00f5d4;
  margin-bottom: 1rem;
  animation: clear-pulse 1s infinite;
}

@keyframes clear-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}

.clear-stats {
  font-family: "JetBrains Mono", monospace;
  font-size: 1rem;
  color: rgba(255, 255, 255, 0.8);
  margin-bottom: 2rem;
}

.clear-buttons {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  justify-content: center;
}

.clear-btn {
  padding: 1rem 2rem;
  background: rgba(0, 245, 212, 0.2);
  border: 2px solid #00f5d4;
  border-radius: 8px;
  color: #00f5d4;
  font-family: "Orbitron", sans-serif;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.2s;
}

.clear-btn:hover {
  background: rgba(0, 245, 212, 0.3);
  box-shadow: 0 0 30px rgba(0, 245, 212, 0.3);
}

.clear-btn.secondary {
  background: rgba(0, 245, 212, 0.1);
  border-color: var(--accent);
  color: var(--accent);
}

.clear-btn.secondary:hover {
  background: rgba(0, 245, 212, 0.2);
  box-shadow: 0 0 30px rgba(0, 245, 212, 0.3);
}

@media (max-width: 768px) {
  .legend { display: none; }
  .stage-info { max-width: 150px; padding: 0.8rem; }
  .stage-info-name { font-size: 0.85rem; }
  .stage-info-desc { font-size: 0.6rem; }
  .stage-select { top: auto; bottom: 4rem; }
  .stage-btn { width: 35px; height: 35px; font-size: 0.7rem; }
  .hud-panel { padding: 0.8rem 1rem; }
  .controls { bottom: 0.5rem; gap: 0.5rem; }
  .ctrl-btn { padding: 0.5rem 0.8rem; font-size: 0.6rem; }
  .clear-title { font-size: 2rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<div class="container">
  <canvas id="canvas"></canvas>
</div>

<!-- HUD -->
<div class="hud">
  <div class="hud-panel">
    <div class="hud-title">EVOLUTION</div>
    <div class="hud-row">
      <span class="hud-label">GENERATION</span>
      <span class="hud-value" id="generation">1</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">ALIVE</span>
      <span class="hud-value" id="alive">0</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">BEST FITNESS</span>
      <span class="hud-value best" id="bestFitness">0</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">REACHED</span>
      <span class="hud-value" id="reached">0</span>
    </div>
  </div>
</div>

<!-- ステージ選択 -->
<div class="stage-select" id="stageSelect"></div>

<!-- ステージ情報 -->
<div class="stage-info">
  <div class="stage-info-title"><i class="fa-solid fa-map"></i> STAGE <span id="stageNum">1</span></div>
  <div class="stage-info-name" id="stageName">SIMPLE</div>
</div>

<!-- 凡例 -->
<div class="legend">
  <div class="legend-title">LEGEND</div>
  <div class="legend-item"><span class="legend-dot alive"></span> 生存中</div>
  <div class="legend-item"><span class="legend-dot dead"></span> 死亡</div>
  <div class="legend-item"><span class="legend-dot best"></span> ゴール到達</div>
  <div class="legend-item"><span class="legend-dot" style="background:#7b2cbf;box-shadow:0 0 8px #7b2cbf"></span> 天敵</div>
</div>

<!-- コントロール -->
<div class="controls">
  <button class="ctrl-btn" id="resetBtn"><i class="fa-solid fa-rotate"></i> RESET</button>
  <button class="ctrl-btn" id="pauseBtn"><i class="fa-solid fa-pause"></i> PAUSE</button>
</div>

<!-- 速度 -->
<div class="speed-control">
  <div class="speed-label">SPEED <span id="speedValue">1x</span></div>
  <input type="range" id="speedSlider" min="1" max="5" value="1">
</div>

<!-- クリア演出 -->
<div class="clear-overlay" id="clearOverlay">
  <div class="clear-title">STAGE CLEAR!</div>
  <div class="clear-stats" id="clearStats">Generation: 1</div>
  <div class="clear-buttons">
    <button class="clear-btn secondary" id="keepWatchingBtn"><i class="fa-solid fa-eye"></i> KEEP WATCHING</button>
    <button class="clear-btn" id="nextStageBtn">NEXT STAGE →</button>
  </div>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// ステージ定義
const STAGES = [
  {
    id: 1,
    name: 'SIMPLE',
    desc: '障害物なし。まずはここから。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: () => [],
    lifespan: 300,
    clearThreshold: 10
  },
  {
    id: 2,
    name: 'WALL',
    desc: '壁を回り込め。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: w / 2 - 180, y: h / 2, w: 360, h: 20 }
    ],
    lifespan: 400,
    clearThreshold: 8
  },
  {
    id: 3,
    name: 'MAZE',
    desc: '複数の壁を突破せよ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: w / 2 - 200, y: h * 0.7, w: 250, h: 15 },
      { x: w / 2 - 50, y: h * 0.5, w: 250, h: 15 },
      { x: w / 2 - 200, y: h * 0.3, w: 250, h: 15 }
    ],
    lifespan: 500,
    clearThreshold: 5
  },
  {
    id: 4,
    name: 'NARROW',
    desc: '狭い隙間を通れ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      // 下段の狭い隙間
      { x: 0, y: h * 0.7, w: w / 2 - 30, h: 20 },
      { x: w / 2 + 30, y: h * 0.7, w: w / 2, h: 20 },
      // 上段の狭い隙間（ずれてる）
      { x: 0, y: h * 0.35, w: w / 2 + 80, h: 20 },
      { x: w / 2 + 140, y: h * 0.35, w: w / 2, h: 20 }
    ],
    lifespan: 500,
    clearThreshold: 5
  },
  {
    id: 5,
    name: 'ZIGZAG',
    desc: 'ジグザグに進め。',
    goal: (w, h) => ({ x: w - 100, y: 80 }),
    start: (w, h) => ({ x: 100, y: h - 100 }),
    obstacles: (w, h) => [
      { x: 0, y: h * 0.75, w: w * 0.6, h: 15 },
      { x: w * 0.4, y: h * 0.5, w: w * 0.6, h: 15 },
      { x: 0, y: h * 0.25, w: w * 0.6, h: 15 }
    ],
    lifespan: 1200,
    clearThreshold: 5
  },
  {
    id: 6,
    name: 'GAUNTLET',
    desc: '最終試練。すべてを乗り越えろ。',
    goal: (w, h) => ({ x: w / 2, y: 60 }),
    start: (w, h) => ({ x: w / 2, y: h - 80 }),
    obstacles: (w, h) => [
      // 下段 - 狭い隙間
      { x: 0, y: h * 0.82, w: w / 2 - 35, h: 18 },
      { x: w / 2 + 35, y: h * 0.82, w: w / 2, h: 18 },
      // 中下段 - 左右交互
      { x: w * 0.5, y: h * 0.65, w: w * 0.5, h: 15 },
      // 中段 - 狭い隙間（右寄り）
      { x: 0, y: h * 0.5, w: w * 0.65, h: 18 },
      { x: w * 0.75, y: h * 0.5, w: w * 0.25, h: 18 },
      // 中上段 - 左右交互
      { x: 0, y: h * 0.35, w: w * 0.5, h: 15 },
      // 上段 - 狭い隙間（中央）
      { x: 0, y: h * 0.2, w: w / 2 - 30, h: 18 },
      { x: w / 2 + 30, y: h * 0.2, w: w / 2, h: 18 }
    ],
    lifespan: 1000,
    clearThreshold: 3,
    predators: 0
  },
  // ========== 天敵ステージ ==========
  {
    id: 'A',
    name: 'PREDATOR',
    desc: '天敵登場。赤い球から逃げろ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: () => [],
    lifespan: 400,
    clearThreshold: 8,
    predators: 1,
    predatorSpeed: 2.5
  },
  {
    id: 'B',
    name: 'HUNT',
    desc: '壁を使って逃げろ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: w / 2 - 150, y: h / 2, w: 300, h: 20 }
    ],
    lifespan: 500,
    clearThreshold: 6,
    predators: 1,
    predatorSpeed: 2.8
  },
  {
    id: 'C',
    name: 'SWARM',
    desc: '2体の天敵。挟み撃ちに注意。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: w * 0.2, y: h * 0.6, w: w * 0.25, h: 15 },
      { x: w * 0.55, y: h * 0.6, w: w * 0.25, h: 15 }
    ],
    lifespan: 600,
    clearThreshold: 5,
    predators: 2,
    predatorSpeed: 2.5
  },
  {
    id: 'D',
    name: 'CORRIDOR',
    desc: '狭い通路で天敵から逃げろ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: 0, y: h * 0.65, w: w / 2 - 50, h: 20 },
      { x: w / 2 + 50, y: h * 0.65, w: w / 2, h: 20 },
      { x: 0, y: h * 0.35, w: w / 2 - 50, h: 20 },
      { x: w / 2 + 50, y: h * 0.35, w: w / 2, h: 20 }
    ],
    lifespan: 700,
    clearThreshold: 4,
    predators: 2,
    predatorSpeed: 2.8
  },
  {
    id: 'E',
    name: 'SURVIVAL',
    desc: '最終試練。3体の天敵から生き延びろ。',
    goal: (w, h) => ({ x: w / 2, y: 80 }),
    start: (w, h) => ({ x: w / 2, y: h - 100 }),
    obstacles: (w, h) => [
      { x: w * 0.1, y: h * 0.7, w: w * 0.35, h: 15 },
      { x: w * 0.55, y: h * 0.7, w: w * 0.35, h: 15 },
      { x: w * 0.3, y: h * 0.45, w: w * 0.4, h: 15 },
      { x: w * 0.1, y: h * 0.25, w: w * 0.35, h: 15 },
      { x: w * 0.55, y: h * 0.25, w: w * 0.35, h: 15 }
    ],
    lifespan: 900,
    clearThreshold: 3,
    predators: 3,
    predatorSpeed: 2.6
  }
];

// 設定
const CONFIG = {
  populationSize: 150,
  mutationRate: 0.02,
  maxForce: 0.3,
  agentRadius: 4,
  goalRadius: 25
};

// 状態
let currentStage = 0;
let population = [];
let predators = [];
let generation = 1;
let frame = 0;
let paused = false;
let speed = 1;
let goal = { x: 0, y: 0 };
let start = { x: 0, y: 0 };
let obstacles = [];
let bestEverFitness = 0;
let clearedStages = new Set();
let stageClearPending = false;

// 天敵クラス
class Predator {
  constructor(x, y, speed) {
    this.startPos = { x, y };
    this.pos = { x, y };
    this.vel = { x: 0, y: 0 };
    this.speed = speed;
    this.radius = 12;
  }

  reset() {
    this.pos.x = this.startPos.x;
    this.pos.y = this.startPos.y;
    this.vel.x = 0;
    this.vel.y = 0;
  }

  update(agents) {
    // 最も近い生きている個体を探す
    let closest = null;
    let closestDist = Infinity;

    for (const agent of agents) {
      if (agent.dead || agent.reached) continue;
      const dx = agent.pos.x - this.pos.x;
      const dy = agent.pos.y - this.pos.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < closestDist) {
        closestDist = dist;
        closest = agent;
      }
    }

    if (closest) {
      // 追いかける
      const dx = closest.pos.x - this.pos.x;
      const dy = closest.pos.y - this.pos.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > 0) {
        this.vel.x = (dx / dist) * this.speed;
        this.vel.y = (dy / dist) * this.speed;
      }
    } else {
      // ターゲットなし - ゴール付近をうろつく
      const dx = goal.x - this.pos.x;
      const dy = goal.y - this.pos.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > 50) {
        this.vel.x = (dx / dist) * this.speed * 0.5;
        this.vel.y = (dy / dist) * this.speed * 0.5;
      } else {
        this.vel.x *= 0.95;
        this.vel.y *= 0.95;
      }
    }

    this.pos.x += this.vel.x;
    this.pos.y += this.vel.y;

    // 壁で跳ね返る
    if (this.pos.x < this.radius) { this.pos.x = this.radius; this.vel.x *= -0.5; }
    if (this.pos.x > canvas.width - this.radius) { this.pos.x = canvas.width - this.radius; this.vel.x *= -0.5; }
    if (this.pos.y < this.radius) { this.pos.y = this.radius; this.vel.y *= -0.5; }
    if (this.pos.y > canvas.height - this.radius) { this.pos.y = canvas.height - this.radius; this.vel.y *= -0.5; }

    // 個体との衝突判定
    for (const agent of agents) {
      if (agent.dead || agent.reached) continue;
      const dx = agent.pos.x - this.pos.x;
      const dy = agent.pos.y - this.pos.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < this.radius + CONFIG.agentRadius) {
        agent.dead = true;
        agent.killedByPredator = true;
      }
    }
  }

  draw() {
    // 外側のグロー
    ctx.beginPath();
    ctx.arc(this.pos.x, this.pos.y, this.radius + 8, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(123, 44, 191, 0.15)';
    ctx.fill();

    // 本体
    ctx.beginPath();
    ctx.arc(this.pos.x, this.pos.y, this.radius, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(123, 44, 191, 0.9)';
    ctx.shadowColor = '#7b2cbf';
    ctx.shadowBlur = 20;
    ctx.fill();
    ctx.shadowBlur = 0;
  }
}

// DOM
const genEl = document.getElementById('generation');
const aliveEl = document.getElementById('alive');
const bestFitnessEl = document.getElementById('bestFitness');
const reachedEl = document.getElementById('reached');
const speedValueEl = document.getElementById('speedValue');
const stageNumEl = document.getElementById('stageNum');
const stageNameEl = document.getElementById('stageName');
const clearOverlay = document.getElementById('clearOverlay');
const clearStats = document.getElementById('clearStats');
const stageSelectEl = document.getElementById('stageSelect');

// ステージボタン生成
function createStageButtons() {
  stageSelectEl.innerHTML = '';
  STAGES.forEach((stage, i) => {
    const btn = document.createElement('button');
    btn.className = 'stage-btn';
    if (i === currentStage) btn.classList.add('active');
    if (clearedStages.has(i)) btn.classList.add('cleared');
    btn.textContent = stage.id;
    btn.addEventListener('click', () => selectStage(i));
    stageSelectEl.appendChild(btn);
  });
}

// ステージ選択
function selectStage(index) {
  currentStage = index;
  const stage = STAGES[currentStage];

  stageNumEl.textContent = stage.id;
  stageNameEl.textContent = stage.name;

  setupStage();
  createStageButtons();
}

// リサイズ
function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  setupStage();
}

function setupStage() {
  const stage = STAGES[currentStage];
  goal = stage.goal(canvas.width, canvas.height);
  start = stage.start(canvas.width, canvas.height);
  obstacles = stage.obstacles(canvas.width, canvas.height);

  // 天敵を初期化
  predators = [];
  const numPredators = stage.predators || 0;
  const predatorSpeed = stage.predatorSpeed || 2.5;
  for (let i = 0; i < numPredators; i++) {
    // ゴール付近からスタート（少しばらけさせる）
    const offsetX = (i - (numPredators - 1) / 2) * 50;
    predators.push(new Predator(goal.x + offsetX, goal.y + 60, predatorSpeed));
  }

  generation = 1;
  bestEverFitness = 0;
  stageClearPending = false;
  initPopulation();
}

// DNA
class DNA {
  constructor(genes, lifespan) {
    this.lifespan = lifespan;
    if (genes) {
      this.genes = genes;
    } else {
      this.genes = [];
      for (let i = 0; i < lifespan; i++) {
        const angle = Math.random() * Math.PI * 2;
        const force = Math.random() * CONFIG.maxForce;
        this.genes.push({
          x: Math.cos(angle) * force,
          y: Math.sin(angle) * force
        });
      }
    }
  }

  crossover(partner) {
    const newGenes = [];
    const mid = Math.floor(Math.random() * this.genes.length);
    for (let i = 0; i < this.genes.length; i++) {
      newGenes.push(i < mid ? this.genes[i] : partner.genes[i]);
    }
    return new DNA(newGenes, this.lifespan);
  }

  mutate() {
    for (let i = 0; i < this.genes.length; i++) {
      if (Math.random() < CONFIG.mutationRate) {
        const angle = Math.random() * Math.PI * 2;
        const force = Math.random() * CONFIG.maxForce;
        this.genes[i] = {
          x: Math.cos(angle) * force,
          y: Math.sin(angle) * force
        };
      }
    }
  }
}

// 個体
class Agent {
  constructor(dna) {
    const stage = STAGES[currentStage];
    this.pos = { x: start.x, y: start.y };
    this.vel = { x: 0, y: 0 };
    this.acc = { x: 0, y: 0 };
    this.dna = dna || new DNA(null, stage.lifespan);
    this.fitness = 0;
    this.dead = false;
    this.reached = false;
    this.frames = 0;
  }

  applyForce(force) {
    this.acc.x += force.x;
    this.acc.y += force.y;
  }

  update() {
    if (this.dead || this.reached) return;

    const stage = STAGES[currentStage];
    if (this.frames < stage.lifespan) {
      this.applyForce(this.dna.genes[this.frames]);
    }

    this.vel.x += this.acc.x;
    this.vel.y += this.acc.y;

    const spd = Math.sqrt(this.vel.x ** 2 + this.vel.y ** 2);
    if (spd > 5) {
      this.vel.x = (this.vel.x / spd) * 5;
      this.vel.y = (this.vel.y / spd) * 5;
    }

    this.pos.x += this.vel.x;
    this.pos.y += this.vel.y;
    this.acc = { x: 0, y: 0 };
    this.frames++;

    // 壁
    if (this.pos.x < 0 || this.pos.x > canvas.width ||
        this.pos.y < 0 || this.pos.y > canvas.height) {
      this.dead = true;
    }

    // 障害物
    for (const obs of obstacles) {
      if (this.pos.x > obs.x && this.pos.x < obs.x + obs.w &&
          this.pos.y > obs.y && this.pos.y < obs.y + obs.h) {
        this.dead = true;
      }
    }

    // ゴール
    const dx = this.pos.x - goal.x;
    const dy = this.pos.y - goal.y;
    if (Math.sqrt(dx * dx + dy * dy) < CONFIG.goalRadius) {
      this.reached = true;
    }
  }

  calcFitness() {
    const dx = this.pos.x - goal.x;
    const dy = this.pos.y - goal.y;
    const dist = Math.sqrt(dx * dx + dy * dy);

    this.fitness = 1 / (dist + 1);

    if (this.reached) {
      this.fitness *= 10;
      const stage = STAGES[currentStage];
      this.fitness *= (stage.lifespan / this.frames);
    }

    if (this.dead) {
      this.fitness *= 0.1;
    }

    return this.fitness;
  }

  draw() {
    const alpha = this.dead ? 0.15 : (this.reached ? 1 : 0.6);

    if (this.reached) {
      ctx.fillStyle = `rgba(0, 245, 212, ${alpha})`;
      ctx.shadowColor = '#00f5d4';
      ctx.shadowBlur = 12;
    } else if (this.dead) {
      ctx.fillStyle = `rgba(80, 80, 80, ${alpha})`;
      ctx.shadowBlur = 0;
    } else {
      ctx.fillStyle = `rgba(0, 245, 212, ${alpha})`;
      ctx.shadowColor = '#00f5d4';
      ctx.shadowBlur = 4;
    }

    ctx.beginPath();
    ctx.arc(this.pos.x, this.pos.y, CONFIG.agentRadius, 0, Math.PI * 2);
    ctx.fill();
    ctx.shadowBlur = 0;
  }
}

function initPopulation() {
  population = [];
  for (let i = 0; i < CONFIG.populationSize; i++) {
    population.push(new Agent());
  }
  frame = 0;

  // 天敵を初期位置にリセット
  predators.forEach(p => p.reset());
}

function nextGeneration() {
  const stage = STAGES[currentStage];

  let totalFitness = 0;
  for (const agent of population) {
    agent.calcFitness();
    totalFitness += agent.fitness;
    if (agent.fitness > bestEverFitness) {
      bestEverFitness = agent.fitness;
    }
  }

  const reachedCount = population.filter(a => a.reached).length;

  for (const agent of population) {
    agent.fitness /= totalFitness;
  }

  const pool = [];
  for (const agent of population) {
    const n = Math.floor(agent.fitness * 1000);
    for (let i = 0; i < n; i++) {
      pool.push(agent);
    }
  }

  if (pool.length === 0) {
    initPopulation();
    generation++;
    return;
  }

  const newPop = [];
  for (let i = 0; i < CONFIG.populationSize; i++) {
    const parentA = pool[Math.floor(Math.random() * pool.length)];
    const parentB = pool[Math.floor(Math.random() * pool.length)];
    const childDNA = parentA.dna.crossover(parentB.dna);
    childDNA.mutate();
    newPop.push(new Agent(childDNA));
  }

  population = newPop;
  generation++;
  frame = 0;

  // 天敵を初期位置にリセット
  predators.forEach(p => p.reset());
}

function showClearScreen() {
  clearedStages.add(currentStage);
  clearStats.textContent = `Cleared in Generation ${generation}`;
  clearOverlay.classList.add('show');
  paused = true;
  createStageButtons();
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // グリッド
  ctx.strokeStyle = 'rgba(0, 245, 212, 0.03)';
  ctx.lineWidth = 1;
  for (let x = 0; x < canvas.width; x += 50) {
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, canvas.height);
    ctx.stroke();
  }
  for (let y = 0; y < canvas.height; y += 50) {
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(canvas.width, y);
    ctx.stroke();
  }

  // 障害物
  ctx.fillStyle = 'rgba(123, 44, 191, 0.25)';
  ctx.strokeStyle = 'rgba(123, 44, 191, 0.6)';
  ctx.lineWidth = 2;
  for (const obs of obstacles) {
    ctx.fillRect(obs.x, obs.y, obs.w, obs.h);
    ctx.strokeRect(obs.x, obs.y, obs.w, obs.h);
  }

  // スタート
  ctx.beginPath();
  ctx.arc(start.x, start.y, 18, 0, Math.PI * 2);
  ctx.fillStyle = 'rgba(0, 245, 212, 0.1)';
  ctx.fill();
  ctx.strokeStyle = 'rgba(0, 245, 212, 0.4)';
  ctx.lineWidth = 2;
  ctx.stroke();

  ctx.fillStyle = 'rgba(0, 245, 212, 0.7)';
  ctx.font = '10px Orbitron';
  ctx.textAlign = 'center';
  ctx.fillText('START', start.x, start.y + 32);

  // ゴール
  const glowSize = 8 + Math.sin(Date.now() / 200) * 4;
  ctx.beginPath();
  ctx.arc(goal.x, goal.y, CONFIG.goalRadius + glowSize, 0, Math.PI * 2);
  ctx.strokeStyle = 'rgba(0, 245, 212, 0.15)';
  ctx.lineWidth = 2;
  ctx.stroke();

  ctx.beginPath();
  ctx.arc(goal.x, goal.y, CONFIG.goalRadius, 0, Math.PI * 2);
  ctx.fillStyle = 'rgba(0, 245, 212, 0.2)';
  ctx.fill();
  ctx.strokeStyle = 'rgba(0, 245, 212, 0.8)';
  ctx.lineWidth = 3;
  ctx.stroke();

  ctx.fillStyle = '#00f5d4';
  ctx.font = '11px Orbitron';
  ctx.fillText('GOAL', goal.x, goal.y + 42);

  // ノルマ表示（ゴール下）
  const reachedCount = population.filter(a => a.reached).length;
  const threshold = STAGES[currentStage].clearThreshold;
  ctx.font = '13px JetBrains Mono';
  ctx.fillStyle = reachedCount >= threshold ? 'rgba(0, 245, 212, 1)' : 'rgba(255, 255, 255, 0.6)';
  ctx.fillText(reachedCount + '/' + threshold, goal.x, goal.y + 58);

  // 個体
  for (const agent of population) {
    agent.draw();
  }

  // 天敵
  for (const predator of predators) {
    predator.draw();
  }
}

function update() {
  const stage = STAGES[currentStage];

  for (let s = 0; s < speed; s++) {
    for (const agent of population) {
      agent.update();
    }

    // 天敵を更新
    for (const predator of predators) {
      predator.update(population);
    }

    frame++;

    // クリア判定（フレームごとに即時チェック）
    const reachedNow = population.filter(a => a.reached).length;
    if (reachedNow >= stage.clearThreshold && !stageClearPending) {
      stageClearPending = true;
      showClearScreen();
      return;
    }

    if (frame >= stage.lifespan) {
      nextGeneration();
    }
  }

  const alive = population.filter(a => !a.dead && !a.reached).length;
  const reached = population.filter(a => a.reached).length;
  genEl.textContent = generation;
  aliveEl.textContent = alive;
  bestFitnessEl.textContent = bestEverFitness.toFixed(3);
  reachedEl.textContent = reached;
}

function loop() {
  if (!paused) {
    update();
  }
  draw();
  requestAnimationFrame(loop);
}

// イベント
window.addEventListener('resize', resize);

document.getElementById('resetBtn').addEventListener('click', () => {
  generation = 1;
  bestEverFitness = 0;
  stageClearPending = false;
  initPopulation();
});

document.getElementById('pauseBtn').addEventListener('click', function() {
  paused = !paused;
  this.innerHTML = paused
    ? '<i class="fa-solid fa-play"></i> PLAY'
    : '<i class="fa-solid fa-pause"></i> PAUSE';
  this.classList.toggle('active', paused);
});

document.getElementById('speedSlider').addEventListener('input', function() {
  speed = parseInt(this.value);
  speedValueEl.textContent = speed + 'x';
});

document.getElementById('keepWatchingBtn').addEventListener('click', () => {
  clearOverlay.classList.remove('show');
  paused = false;
  document.getElementById('pauseBtn').innerHTML = '<i class="fa-solid fa-pause"></i> PAUSE';
  document.getElementById('pauseBtn').classList.remove('active');
});

document.getElementById('nextStageBtn').addEventListener('click', () => {
  clearOverlay.classList.remove('show');
  paused = false;
  document.getElementById('pauseBtn').innerHTML = '<i class="fa-solid fa-pause"></i> PAUSE';
  document.getElementById('pauseBtn').classList.remove('active');

  if (currentStage < STAGES.length - 1) {
    selectStage(currentStage + 1);
  } else {
    // 全クリア
    selectStage(0);
  }
});

// 初期化
resize();
createStageButtons();
selectStage(0);
loop();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
