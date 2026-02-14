/**
 * Conway's Game of Life - PULSE背景演出
 * エモい雰囲気を演出するライフゲーム
 */
(function() {
  'use strict';

  // 設定
  const CONFIG = {
    cellSize: 15,           // セルのサイズ（px）
    updateInterval: 180,    // 更新間隔（ms）
    initialDensity: 0.12,   // 初期密度
    trailOpacity: 0.92      // 残像効果（1に近いほど長く残る）
  };

  // PULSE のアクセントカラーに合わせた配色
  const COLORS = {
    cell: 'rgba(0, 245, 212, 0.5)',      // --accent
    glow: 'rgba(0, 245, 212, 0.2)',
    cellAlt: 'rgba(123, 44, 191, 0.4)',  // --accent-secondary
    glowAlt: 'rgba(123, 44, 191, 0.15)'
  };

  let canvas, ctx;
  let cols, rows;
  let grid, nextGrid;
  let animationId;
  let lastUpdate = 0;
  let isVisible = true;
  let generation = 0;

  // 初期化
  function init() {
    canvas = document.getElementById('life-game-canvas');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'life-game-canvas';
      document.body.insertBefore(canvas, document.body.firstChild);
    }

    ctx = canvas.getContext('2d');
    
    resize();
    initGrid();
    
    // イベントリスナー
    window.addEventListener('resize', debounce(resize, 200));
    document.addEventListener('visibilitychange', handleVisibility);
    
    // クリックでその位置にセルを追加（インタラクティブ要素）
    canvas.addEventListener('click', handleClick);
    
    // アニメーション開始
    animate();
  }

  // クリックでセル追加
  function handleClick(e) {
    const rect = canvas.getBoundingClientRect();
    const x = Math.floor((e.clientX - rect.left) / CONFIG.cellSize);
    const y = Math.floor((e.clientY - rect.top) / CONFIG.cellSize);
    
    // グライダーを配置
    const patterns = [
      // グライダー
      [[0,0], [1,0], [2,0], [2,1], [1,2]],
      // 軽量級宇宙船 (LWSS)
      [[0,1], [0,3], [1,0], [2,0], [3,0], [4,0], [4,1], [4,2], [3,3]],
      // Rペントミノ
      [[1,0], [2,0], [0,1], [1,1], [1,2]]
    ];
    
    const pattern = patterns[Math.floor(Math.random() * patterns.length)];
    
    pattern.forEach(([dx, dy]) => {
      const nx = (x + dx + cols) % cols;
      const ny = (y + dy + rows) % rows;
      if (grid[nx] && grid[nx][ny] !== undefined) {
        grid[nx][ny] = 1;
      }
    });
  }

  // リサイズ処理
  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    
    const oldCols = cols;
    const oldRows = rows;
    
    cols = Math.ceil(canvas.width / CONFIG.cellSize);
    rows = Math.ceil(canvas.height / CONFIG.cellSize);
    
    if (cols !== oldCols || rows !== oldRows) {
      initGrid();
    }
  }

  // グリッド初期化
  function initGrid() {
    grid = createGrid();
    nextGrid = createGrid();
    generation = 0;
    
    // ランダムに初期配置
    for (let i = 0; i < cols; i++) {
      for (let j = 0; j < rows; j++) {
        grid[i][j] = Math.random() < CONFIG.initialDensity ? 1 : 0;
      }
    }
  }

  // 2次元配列を作成
  function createGrid() {
    const arr = new Array(cols);
    for (let i = 0; i < cols; i++) {
      arr[i] = new Array(rows).fill(0);
    }
    return arr;
  }

  // 隣接セルをカウント
  function countNeighbors(x, y) {
    let count = 0;
    for (let i = -1; i <= 1; i++) {
      for (let j = -1; j <= 1; j++) {
        if (i === 0 && j === 0) continue;
        
        // トーラス
        const ni = (x + i + cols) % cols;
        const nj = (y + j + rows) % rows;
        
        count += grid[ni][nj];
      }
    }
    return count;
  }

  // 次の世代を計算
  function update() {
    let hasLivingCells = false;
    let livingCount = 0;
    
    for (let i = 0; i < cols; i++) {
      for (let j = 0; j < rows; j++) {
        const neighbors = countNeighbors(i, j);
        const current = grid[i][j];
        
        if (current === 1) {
          nextGrid[i][j] = (neighbors === 2 || neighbors === 3) ? 1 : 0;
        } else {
          nextGrid[i][j] = neighbors === 3 ? 1 : 0;
        }
        
        if (nextGrid[i][j] === 1) {
          hasLivingCells = true;
          livingCount++;
        }
      }
    }
    
    generation++;
    
    // 全滅 or 停滞（生存数が少なすぎる）場合は再初期化
    if (!hasLivingCells || (generation > 500 && livingCount < 10)) {
      initGrid();
      return;
    }
    
    [grid, nextGrid] = [nextGrid, grid];
  }

  // 描画
  function draw() {
    // 残像効果
    ctx.fillStyle = `rgba(10, 10, 15, ${1 - CONFIG.trailOpacity})`;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    for (let i = 0; i < cols; i++) {
      for (let j = 0; j < rows; j++) {
        if (grid[i][j] === 1) {
          const x = i * CONFIG.cellSize;
          const y = j * CONFIG.cellSize;
          const size = CONFIG.cellSize - 2;
          
          // 位置によって色を変える（グラデーション効果）
          const ratio = (i + j) / (cols + rows);
          const useAlt = ratio > 0.5;
          
          ctx.shadowBlur = 10;
          ctx.shadowColor = useAlt ? COLORS.glowAlt : COLORS.glow;
          ctx.fillStyle = useAlt ? COLORS.cellAlt : COLORS.cell;
          
          ctx.beginPath();
          ctx.roundRect(x + 1, y + 1, size, size, 2);
          ctx.fill();
        }
      }
    }
    
    ctx.shadowBlur = 0;
  }

  // アニメーションループ
  function animate(timestamp) {
    if (!isVisible) {
      animationId = requestAnimationFrame(animate);
      return;
    }
    
    if (timestamp - lastUpdate >= CONFIG.updateInterval) {
      update();
      draw();
      lastUpdate = timestamp;
    }
    
    animationId = requestAnimationFrame(animate);
  }

  // 可視性変更時の処理
  function handleVisibility() {
    isVisible = !document.hidden;
  }

  // デバウンス
  function debounce(func, wait) {
    let timeout;
    return function(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  }

  // DOM準備完了後に初期化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // 外部API
  window.LifeGame = {
    reset: initGrid,
    pause: function() { isVisible = false; },
    resume: function() { isVisible = true; },
    getGeneration: function() { return generation; }
  };
})();
