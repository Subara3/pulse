<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('turing');

$pageName = 'turing';
$pageTitle = 'TURING - PULSE';
$pageDesc = 'チューリングマシン。テープ上を走るヘッドが記号を読み書きし、状態遷移を繰り返す。計算の原型。';
$pageCanonical = 'https://pulse.subara3.com/turing.php';

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

.hud .val.halted {
  color: #7b2cbf;
}

.hide-ui .hud,
.hide-ui .info,
.hide-ui .drawer-controls,
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
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'toggle cell'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div class="ui hud" id="hud">
  <div>STATE: <span class="val" id="hudState">A</span></div>
  <div>STEP: <span class="val" id="hudStep">0</span></div>
  <div>STATUS: <span class="val" id="hudStatus">RUNNING</span></div>
</div>

<div id="controls" class="drawer-controls">
  <div>
    <div style="color:rgba(255,255,255,0.4);font-family:'Orbitron',sans-serif;font-size:0.6rem;margin-bottom:0.4rem;letter-spacing:0.08em;">PRESET</div>
    <div class="preset-group" id="preset-btns">
      <button class="preset-btn active" data-preset="binary">BINARY+</button>
      <button class="preset-btn" data-preset="beaver3">BEAVER3</button>
      <button class="preset-btn" data-preset="beaver4">BEAVER4</button>
      <button class="preset-btn" data-preset="counter">COUNTER</button>
      <button class="preset-btn" data-preset="copy">COPY</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="slider-label">SPEED</label>
      <input type="range" id="speedSlider" min="1" max="50" value="5">
      <span class="slider-value" id="speedVal">5</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="stepBtn">STEP</button>
    <button class="control-btn" id="pauseBtn">PAUSE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var hudState = document.getElementById('hudState');
  var hudStep = document.getElementById('hudStep');
  var hudStatus = document.getElementById('hudStatus');

  var width, height;
  var isMobile = window.innerWidth <= 768;

  // --- Turing Machine State ---
  var tape = {};        // sparse map: position -> symbol (0 or 1)
  var headPos = 0;
  var state = 'A';
  var stepCount = 0;
  var halted = false;
  var running = true;
  var stepsPerFrame = 5;
  var history = [];     // array of {tape snapshot, headPos, state}
  var maxHistory = 600;

  // --- Presets ---
  // Rule format: { 'STATE_SYMBOL': [writeSymbol, direction, nextState] }
  // direction: 'R' or 'L'
  // nextState: 'HALT' means halt
  var presets = {
    binary: {
      name: 'BINARY+',
      desc: 'Binary incrementer',
      rules: {
        'A_0': [1, 'L', 'HALT'],
        'A_1': [0, 'R', 'A'],
        'A_B': [1, 'L', 'HALT']
      },
      initTape: function() {
        // Write 1011 (11 in binary)
        tape = {};
        tape[0] = 1; tape[1] = 0; tape[2] = 1; tape[3] = 1;
        headPos = 3; // start at rightmost
        state = 'A';
      }
    },
    beaver3: {
      name: 'BEAVER3',
      desc: '3-state busy beaver',
      rules: {
        'A_0': [1, 'R', 'B'],
        'A_1': [1, 'L', 'C'],
        'B_0': [1, 'L', 'A'],
        'B_1': [1, 'R', 'B'],
        'C_0': [1, 'L', 'B'],
        'C_1': [1, 'R', 'HALT']
      },
      initTape: function() {
        tape = {};
        headPos = 0;
        state = 'A';
      }
    },
    beaver4: {
      name: 'BEAVER4',
      desc: '4-state busy beaver',
      rules: {
        'A_0': [1, 'R', 'B'],
        'A_1': [1, 'L', 'B'],
        'B_0': [1, 'L', 'A'],
        'B_1': [0, 'L', 'C'],
        'C_0': [1, 'R', 'HALT'],
        'C_1': [1, 'L', 'D'],
        'D_0': [1, 'R', 'D'],
        'D_1': [0, 'R', 'A']
      },
      initTape: function() {
        tape = {};
        headPos = 0;
        state = 'A';
      }
    },
    counter: {
      name: 'COUNTER',
      desc: 'Unary counter (never halts)',
      rules: {
        'A_0': [1, 'R', 'A'],
        'A_1': [1, 'R', 'A'],
        // When blank encountered going right, turn around
        'A_B': [1, 'L', 'B'],
        'B_0': [0, 'L', 'A'],
        'B_1': [1, 'L', 'B'],
        'B_B': [0, 'R', 'A']
      },
      initTape: function() {
        tape = {};
        tape[0] = 1;
        headPos = 0;
        state = 'A';
      }
    },
    copy: {
      name: 'COPY',
      desc: 'Copies a string of 1s',
      rules: {
        // Mark current 1, go right to find gap, place 1 after gap, return
        'A_0': [0, 'R', 'HALT'],  // no more 1s to copy, done
        'A_1': [0, 'R', 'B'],     // erase, move right to find separator
        'B_0': [0, 'R', 'C'],     // found separator (0), skip it
        'B_1': [1, 'R', 'B'],     // skip existing 1s
        'C_0': [1, 'L', 'D'],     // found empty spot, write 1
        'C_1': [1, 'R', 'C'],     // skip copied 1s
        'D_0': [0, 'L', 'E'],     // go back left past separator
        'D_1': [1, 'L', 'D'],
        'E_0': [0, 'L', 'E'],
        'E_1': [1, 'L', 'E'],
        'E_B': [1, 'R', 'A'],     // found the blank before original, restore & restart
        // Handle blanks
        'A_B': [0, 'R', 'HALT'],
        'B_B': [0, 'R', 'C'],
        'C_B': [1, 'L', 'D'],
        'D_B': [0, 'L', 'E']
      },
      initTape: function() {
        tape = {};
        tape[0] = 1; tape[1] = 1; tape[2] = 1;
        // separator gap at position 3 (blank)
        headPos = 0;
        state = 'A';
      }
    }
  };

  var currentPreset = 'binary';
  var currentRules = presets.binary.rules;

  // --- Read tape cell ---
  function readTape(pos) {
    if (tape[pos] === undefined) return 'B'; // blank
    return tape[pos];
  }

  // --- Single step ---
  function step() {
    if (halted) return false;

    var sym = readTape(headPos);
    var key = state + '_' + sym;
    var rule = currentRules[key];

    if (!rule) {
      // No rule defined: halt
      halted = true;
      running = false;
      typeof SFX!=='undefined'&&SFX.play('complete');
      return false;
    }

    var writeSym = rule[0];
    var dir = rule[1];
    var nextState = rule[2];

    // Write
    tape[headPos] = writeSym;

    // Move
    if (dir === 'R') headPos++;
    else headPos--;

    // Transition
    if (nextState === 'HALT') {
      halted = true;
      running = false;
      state = 'H';
      stepCount++;
      saveHistory();
      typeof SFX!=='undefined'&&SFX.play('complete');
      return false;
    }

    state = nextState;
    stepCount++;
    saveHistory();
    return true;
  }

  // --- History snapshot ---
  function saveHistory() {
    // Find tape bounds
    var keys = Object.keys(tape).map(Number);
    if (keys.length === 0) keys = [headPos];
    var lo = Math.min.apply(null, keys.concat([headPos])) - 2;
    var hi = Math.max.apply(null, keys.concat([headPos])) + 2;

    var snap = { lo: lo, hi: hi, cells: {}, headPos: headPos, state: state };
    for (var k in tape) {
      snap.cells[k] = tape[k];
    }
    history.push(snap);
    if (history.length > maxHistory) {
      history.shift();
    }
  }

  // --- Load preset ---
  function loadPreset(name) {
    var p = presets[name];
    if (!p) return;
    currentPreset = name;
    currentRules = p.rules;
    tape = {};
    headPos = 0;
    state = 'A';
    stepCount = 0;
    halted = false;
    running = true;
    history = [];
    p.initTape();
    saveHistory();

    // Update active button
    var btns = document.querySelectorAll('#preset-btns .preset-btn');
    btns.forEach(function(b) {
      b.classList.toggle('active', b.dataset.preset === name);
    });

    updateHUD();
    typeof SFX!=='undefined'&&SFX.play('toggle');
  }

  // --- HUD ---
  function updateHUD() {
    hudState.textContent = state;
    hudStep.textContent = stepCount;
    if (halted) {
      hudStatus.textContent = 'HALTED';
      hudStatus.className = 'val halted';
    } else if (running) {
      hudStatus.textContent = 'RUNNING';
      hudStatus.className = 'val';
    } else {
      hudStatus.textContent = 'PAUSED';
      hudStatus.className = 'val';
    }
  }

  // --- Rendering ---
  var CELL_SIZE = 30;
  var HIST_CELL = 4;
  var lastSfxTime = 0;

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    var tapeAreaH = Math.floor(height * 0.4);
    var histAreaY = tapeAreaH;
    var histAreaH = height - tapeAreaH;

    drawTape(tapeAreaH);
    drawHistory(histAreaY, histAreaH);
  }

  function drawTape(areaH) {
    var centerY = areaH * 0.55;
    var cellW = isMobile ? 24 : CELL_SIZE;
    var cellH = cellW;
    var visibleCells = Math.ceil(width / cellW) + 4;
    var halfVisible = Math.floor(visibleCells / 2);

    // Draw cells centered on head
    for (var i = -halfVisible; i <= halfVisible; i++) {
      var pos = headPos + i;
      var x = width / 2 + i * cellW - cellW / 2;
      var y = centerY - cellH / 2;
      var sym = readTape(pos);

      // Cell background
      if (pos === headPos) {
        // Head cell: bright teal glow
        ctx.shadowColor = '#00f5d4';
        ctx.shadowBlur = 20;
        ctx.fillStyle = 'rgba(0, 245, 212, 0.25)';
        ctx.fillRect(x, y, cellW - 1, cellH);
        ctx.shadowBlur = 0;

        ctx.strokeStyle = '#00f5d4';
        ctx.lineWidth = 2;
        ctx.strokeRect(x, y, cellW - 1, cellH);
      } else {
        if (sym === 1) {
          ctx.fillStyle = 'rgba(0, 245, 212, 0.15)';
        } else {
          ctx.fillStyle = 'rgba(255, 255, 255, 0.03)';
        }
        ctx.fillRect(x, y, cellW - 1, cellH);

        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1;
        ctx.strokeRect(x, y, cellW - 1, cellH);
      }

      // Symbol
      if (sym === 1) {
        ctx.fillStyle = pos === headPos ? '#00f5d4' : 'rgba(0, 245, 212, 0.8)';
        ctx.font = 'bold ' + Math.floor(cellW * 0.5) + 'px "JetBrains Mono", monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('1', x + cellW / 2 - 0.5, centerY);
      } else if (sym === 0) {
        ctx.fillStyle = pos === headPos ? 'rgba(255,255,255,0.4)' : 'rgba(255,255,255,0.15)';
        ctx.font = 'bold ' + Math.floor(cellW * 0.5) + 'px "JetBrains Mono", monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('0', x + cellW / 2 - 0.5, centerY);
      }
    }

    // Head pointer triangle above
    var headX = width / 2;
    var triY = centerY - cellH / 2 - 12;
    ctx.beginPath();
    ctx.moveTo(headX, triY + 10);
    ctx.lineTo(headX - 8, triY);
    ctx.lineTo(headX + 8, triY);
    ctx.closePath();
    ctx.fillStyle = '#00f5d4';
    ctx.fill();

    // State label above triangle
    ctx.fillStyle = '#7b2cbf';
    ctx.font = 'bold ' + (isMobile ? 14 : 18) + 'px "Orbitron", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    ctx.fillText('STATE: ' + state, headX, triY - 6);

    // Position label below tape
    ctx.fillStyle = 'rgba(255,255,255,0.15)';
    ctx.font = '10px "JetBrains Mono", monospace';
    ctx.textAlign = 'center';
    for (var i = -halfVisible; i <= halfVisible; i++) {
      var pos = headPos + i;
      var x = width / 2 + i * cellW;
      if (pos % 5 === 0) {
        ctx.fillText(pos, x, centerY + cellH / 2 + 14);
      }
    }

    // Divider line
    var divY = Math.floor(height * 0.4) - 1;
    ctx.strokeStyle = 'rgba(0, 245, 212, 0.1)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, divY);
    ctx.lineTo(width, divY);
    ctx.stroke();
  }

  function drawHistory(startY, areaH) {
    if (history.length === 0) return;

    var cellSize = isMobile ? 3 : HIST_CELL;
    var maxRows = Math.floor(areaH / cellSize);
    var startIdx = Math.max(0, history.length - maxRows);
    var rows = history.slice(startIdx);

    // Find global tape bounds across visible history
    var globalLo = Infinity, globalHi = -Infinity;
    for (var r = 0; r < rows.length; r++) {
      if (rows[r].lo < globalLo) globalLo = rows[r].lo;
      if (rows[r].hi > globalHi) globalHi = rows[r].hi;
    }
    // Ensure minimum width
    var tapeWidth = globalHi - globalLo + 1;
    if (tapeWidth < 40) {
      var center = Math.floor((globalLo + globalHi) / 2);
      globalLo = center - 20;
      globalHi = center + 20;
      tapeWidth = 41;
    }

    var totalPixelW = tapeWidth * cellSize;
    var offsetX = Math.floor((width - totalPixelW) / 2);

    for (var r = 0; r < rows.length; r++) {
      var snap = rows[r];
      var y = startY + r * cellSize;

      for (var pos = globalLo; pos <= globalHi; pos++) {
        var x = offsetX + (pos - globalLo) * cellSize;
        var val = snap.cells[pos];

        if (val === 1) {
          // Head position gets brighter
          if (pos === snap.headPos) {
            ctx.fillStyle = '#00f5d4';
          } else {
            ctx.fillStyle = 'rgba(0, 245, 212, 0.6)';
          }
          ctx.fillRect(x, y, cellSize - 0.5, cellSize - 0.5);
        } else if (pos === snap.headPos) {
          // Head on blank/0 cell
          ctx.fillStyle = 'rgba(123, 44, 191, 0.5)';
          ctx.fillRect(x, y, cellSize - 0.5, cellSize - 0.5);
        } else if (val === 0) {
          ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
          ctx.fillRect(x, y, cellSize - 0.5, cellSize - 0.5);
        }
        // Blank cells: leave as background
      }
    }

    // Label
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.font = '10px "Orbitron", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('HISTORY', 12, startY + 16);
  }

  // --- Main loop ---
  var lastFrame = 0;

  function loop(timestamp) {
    if (timestamp - lastFrame >= 16) {
      // Run steps
      if (running && !halted) {
        var now = performance.now();
        for (var i = 0; i < stepsPerFrame; i++) {
          if (!step()) break;
        }
        // Throttled tick sound
        if (now - lastSfxTime > 80) {
          typeof SFX!=='undefined'&&SFX.play('tick', 30);
          lastSfxTime = now;
        }
      }

      updateHUD();
      render();
      lastFrame = timestamp;
    }
    requestAnimationFrame(loop);
  }

  // --- Controls ---
  document.querySelectorAll('#preset-btns .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      loadPreset(this.dataset.preset);
    });
  });

  var speedSlider = document.getElementById('speedSlider');
  var speedVal = document.getElementById('speedVal');
  speedSlider.addEventListener('input', function() {
    stepsPerFrame = parseInt(this.value);
    speedVal.textContent = stepsPerFrame;
  });

  document.getElementById('stepBtn').addEventListener('click', function() {
    running = false;
    if (!halted) {
      step();
      typeof SFX!=='undefined'&&SFX.play('tick', 30);
      updateHUD();
    }
  });

  document.getElementById('pauseBtn').addEventListener('click', function() {
    if (halted) return;
    running = !running;
    this.textContent = running ? 'PAUSE' : 'PLAY';
    typeof SFX!=='undefined'&&SFX.play('toggle');
  });

  document.getElementById('resetBtn').addEventListener('click', function() {
    loadPreset(currentPreset);
    document.getElementById('pauseBtn').textContent = 'PAUSE';
    typeof SFX!=='undefined'&&SFX.play('click');
  });

  // --- Canvas click: toggle cell ---
  canvas.addEventListener('click', function(e) {
    var rect = canvas.getBoundingClientRect();
    var cx = e.clientX - rect.left;
    var cy = e.clientY - rect.top;
    var tapeAreaH = Math.floor(height * 0.4);

    // Only handle clicks in tape area
    if (cy > tapeAreaH) return;

    var cellW = isMobile ? 24 : CELL_SIZE;
    var cellCenterY = tapeAreaH * 0.55;
    var cellH = cellW;

    // Check vertical range
    if (Math.abs(cy - cellCenterY) > cellH / 2 + 5) return;

    // Which cell?
    var offset = cx - width / 2;
    var cellIdx = Math.round(offset / cellW);
    var pos = headPos + cellIdx;

    // Toggle
    var current = readTape(pos);
    if (current === 1) {
      tape[pos] = 0;
    } else {
      tape[pos] = 1;
    }
    typeof SFX!=='undefined'&&SFX.play('tick');
  });

  // Touch support
  canvas.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      var touch = e.touches[0];
      var rect = canvas.getBoundingClientRect();
      var cx = touch.clientX - rect.left;
      var cy = touch.clientY - rect.top;
      var tapeAreaH = Math.floor(height * 0.4);

      if (cy > tapeAreaH) return;

      var cellW = isMobile ? 24 : CELL_SIZE;
      var cellCenterY = tapeAreaH * 0.55;
      var cellH = cellW;

      if (Math.abs(cy - cellCenterY) > cellH / 2 + 10) return;

      var offset = cx - width / 2;
      var cellIdx = Math.round(offset / cellW);
      var pos = headPos + cellIdx;

      var current = readTape(pos);
      tape[pos] = (current === 1) ? 0 : 1;
      typeof SFX!=='undefined'&&SFX.play('tick');
    }
  }, { passive: true });

  // Keyboard
  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT') return;
    switch (e.key) {
      case 'h': case 'H':
        document.body.classList.toggle('hide-ui');
        break;
      case ' ':
        e.preventDefault();
        if (halted) return;
        running = !running;
        document.getElementById('pauseBtn').textContent = running ? 'PAUSE' : 'PLAY';
        typeof SFX!=='undefined'&&SFX.play('toggle');
        break;
      case 'ArrowRight':
        e.preventDefault();
        running = false;
        document.getElementById('pauseBtn').textContent = 'PLAY';
        if (!halted) { step(); typeof SFX!=='undefined'&&SFX.play('tick', 30); }
        break;
      case 'r': case 'R':
        loadPreset(currentPreset);
        document.getElementById('pauseBtn').textContent = 'PAUSE';
        typeof SFX!=='undefined'&&SFX.play('click');
        break;
    }
  });

  // --- Resize ---
  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    isMobile = window.innerWidth <= 768;
  }

  window.addEventListener('resize', resize);

  // --- Init ---
  resize();
  loadPreset('binary');
  requestAnimationFrame(loop);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
