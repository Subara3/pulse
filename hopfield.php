<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hopfield');

$pageName = 'hopfield';
$pageTitle = 'HOPFIELD - PULSE';
$pageDesc = 'ホップフィールドネットワーク。ヘブ則で記憶したパターンへ、ノイズから自発的に収束する連想記憶の可視化。';
$pageCanonical = 'https://pulse.subara3.com/hopfield.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>NEURONS <span id="hud-n">256</span></div>
  <div>STORED <span id="hud-s">0</span></div>
  <div>ENERGY <span id="hud-e">0</span></div>
  <div>OVERLAP <span id="hud-o">--</span></div>
  <div>STEP <span id="hud-st">0</span></div>
</div>
<div id="info-label"><span>HOPFIELD</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'toggle'],
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MEMORY</label></div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-store">STORE</button>
    <button class="ctrl-btn" id="btn-clear-mem">CLR MEM</button>
  </div>
  <div class="slider-row"><label class="slider-label">RECALL</label></div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-recall">RECALL</button>
    <button class="ctrl-btn" id="btn-corrupt">CORRUPT</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">NOISE</label>
    <input type="range" id="sl-noise" min="5" max="60" value="25">
    <span class="slider-value" id="v-noise">25%</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="50" value="15">
    <span class="slider-value" id="v-speed">15</span>
  </div>
  <div class="slider-row"><label class="slider-label">PRESETS</label></div>
  <div class="btn-row">
    <button class="ctrl-btn preset-btn" data-preset="cross">+</button>
    <button class="ctrl-btn preset-btn" data-preset="diamond">&#9671;</button>
    <button class="ctrl-btn preset-btn" data-preset="ring">&#9675;</button>
    <button class="ctrl-btn preset-btn" data-preset="arrow">&#9650;</button>
    <button class="ctrl-btn preset-btn" data-preset="wave">&#8776;</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
    <button class="ctrl-btn" id="btn-random">RANDOM</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;

  var $n = document.getElementById('hud-n');
  var $s = document.getElementById('hud-s');
  var $e = document.getElementById('hud-e');
  var $o = document.getElementById('hud-o');
  var $st = document.getElementById('hud-st');

  var slNoise = document.getElementById('sl-noise');
  var slSpeed = document.getElementById('sl-speed');
  var vNoise = document.getElementById('v-noise');
  var vSpeed = document.getElementById('v-speed');

  var GS = 16;
  var N = GS * GS;
  var state = new Int8Array(N);
  var weights = new Float32Array(N * N);
  var patterns = [];
  var maxPatterns = 8;
  var recalling = false;
  var recallStep = 0;
  var updatesPerFrame = 15;
  var flash = new Float32Array(N);
  var converged = false;
  var prevEnergy = 0;
  var stableCount = 0;
  var totalSteps = 0;

  var cellSize, gridX, gridY;
  var mDown = false, mRight = false, mPaint = 1;
  var touchId = null;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var maxCell = Math.min((W - 80) / GS, (H - 160) / GS);
    cellSize = Math.min(Math.floor(maxCell), 36);
    gridX = (W - cellSize * GS) / 2;
    gridY = (H - cellSize * GS) / 2;
  }

  function clearState() {
    for (var i = 0; i < N; i++) state[i] = -1;
    recalling = false; converged = false; totalSteps = 0; stableCount = 0;
  }

  function randomState() {
    for (var i = 0; i < N; i++) state[i] = Math.random() < 0.5 ? 1 : -1;
    recalling = false; converged = false; totalSteps = 0; stableCount = 0;
  }

  function applyPreset(name) {
    clearState();
    var cx = GS / 2, cy = GS / 2;
    for (var r = 0; r < GS; r++) {
      for (var c = 0; c < GS; c++) {
        var idx = r * GS + c;
        var dx = c - cx + 0.5, dy = r - cy + 0.5;
        var on = false;
        if (name === 'cross') {
          on = (Math.abs(dx) <= 1.5 && Math.abs(dy) <= 5.5) || (Math.abs(dy) <= 1.5 && Math.abs(dx) <= 5.5);
        } else if (name === 'diamond') {
          on = Math.abs(dx) + Math.abs(dy) <= 5.5;
        } else if (name === 'ring') {
          var d = Math.sqrt(dx * dx + dy * dy);
          on = d >= 4 && d <= 6.5;
        } else if (name === 'arrow') {
          on = (dy >= -1 && dy <= 5 && Math.abs(dx) <= (5 - dy) * 0.7) ||
               (dy >= -6 && dy < -1 && Math.abs(dx) <= 1.5);
        } else if (name === 'wave') {
          var wy = Math.sin(c / GS * Math.PI * 3) * 3;
          on = Math.abs(dy - wy) <= 1.2;
        }
        if (on) state[idx] = 1;
      }
    }
  }

  function storePattern() {
    if (patterns.length >= maxPatterns) return;
    var p = new Int8Array(N);
    for (var i = 0; i < N; i++) p[i] = state[i];
    patterns.push(p);
    computeWeights();
    if (window.SFX) SFX.play('spawn');
  }

  function clearMemory() {
    patterns = [];
    weights.fill(0);
    if (window.SFX) SFX.play('click');
  }

  function computeWeights() {
    weights.fill(0);
    for (var p = 0; p < patterns.length; p++) {
      var pat = patterns[p];
      for (var i = 0; i < N; i++) {
        for (var j = i + 1; j < N; j++) {
          var w = pat[i] * pat[j];
          weights[i * N + j] += w;
          weights[j * N + i] += w;
        }
      }
    }
    for (var i = 0; i < N; i++) {
      for (var j = 0; j < N; j++) {
        weights[i * N + j] /= N;
      }
    }
  }

  function energy() {
    var e = 0;
    for (var i = 0; i < N; i++) {
      for (var j = i + 1; j < N; j++) {
        e -= weights[i * N + j] * state[i] * state[j];
      }
    }
    return e;
  }

  function overlap() {
    if (patterns.length === 0) return { best: -1, val: 0 };
    var bestIdx = 0, bestVal = -Infinity;
    for (var p = 0; p < patterns.length; p++) {
      var pat = patterns[p];
      var sum = 0;
      for (var i = 0; i < N; i++) sum += state[i] * pat[i];
      var ov = sum / N;
      if (ov > bestVal) { bestVal = ov; bestIdx = p; }
    }
    return { best: bestIdx, val: bestVal };
  }

  function corrupt(pct) {
    var nFlip = Math.round(N * pct / 100);
    var indices = [];
    for (var i = 0; i < N; i++) indices.push(i);
    for (var i = N - 1; i > 0; i--) {
      var j = Math.random() * (i + 1) | 0;
      var t = indices[i]; indices[i] = indices[j]; indices[j] = t;
    }
    for (var k = 0; k < nFlip; k++) {
      state[indices[k]] *= -1;
      flash[indices[k]] = 1;
    }
    if (window.SFX) SFX.play('ripple');
  }

  function startRecall() {
    if (patterns.length === 0) return;
    recalling = true;
    converged = false;
    stableCount = 0;
    totalSteps = 0;
    prevEnergy = energy();
  }

  function updateStep() {
    var i = Math.random() * N | 0;
    var h = 0;
    for (var j = 0; j < N; j++) {
      if (j !== i) h += weights[i * N + j] * state[j];
    }
    var newS = h >= 0 ? 1 : -1;
    if (newS !== state[i]) {
      state[i] = newS;
      flash[i] = 1;
      stableCount = 0;
    } else {
      stableCount++;
    }
    totalSteps++;
    if (stableCount > N * 3) {
      converged = true;
      recalling = false;
      if (window.SFX) SFX.play('success');
    }
  }

  function cellAt(px, py) {
    var c = Math.floor((px - gridX) / cellSize);
    var r = Math.floor((py - gridY) / cellSize);
    if (c < 0 || c >= GS || r < 0 || r >= GS) return -1;
    return r * GS + c;
  }

  function toggleCell(px, py) {
    var idx = cellAt(px, py);
    if (idx < 0) return;
    if (mRight) {
      state[idx] = -1;
    } else {
      state[idx] = mPaint;
    }
    flash[idx] = 0.6;
    if (window.SFX) SFX.play('tick', 60);
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var ov = overlap();
    var bestPat = ov.best >= 0 ? patterns[ov.best] : null;

    for (var r = 0; r < GS; r++) {
      for (var c = 0; c < GS; c++) {
        var idx = r * GS + c;
        var x = gridX + c * cellSize;
        var y = gridY + r * cellSize;
        var s = state[idx];
        var f = flash[idx];
        var gap = Math.max(1, cellSize * 0.08);
        var cs = cellSize - gap * 2;

        if (s === 1) {
          var alpha = 0.7 + f * 0.3;
          var g = 180 + f * 75;
          ctx.fillStyle = 'rgba(0,' + (g | 0) + ',' + (170 + f * 42 | 0) + ',' + alpha + ')';
        } else {
          var alpha = 0.15 + f * 0.3;
          ctx.fillStyle = 'rgba(123,44,191,' + alpha + ')';
        }

        var rad = Math.max(2, cs * 0.12);
        var x1 = x + gap, y1 = y + gap;
        ctx.beginPath();
        ctx.moveTo(x1 + rad, y1);
        ctx.lineTo(x1 + cs - rad, y1);
        ctx.quadraticCurveTo(x1 + cs, y1, x1 + cs, y1 + rad);
        ctx.lineTo(x1 + cs, y1 + cs - rad);
        ctx.quadraticCurveTo(x1 + cs, y1 + cs, x1 + cs - rad, y1 + cs);
        ctx.lineTo(x1 + rad, y1 + cs);
        ctx.quadraticCurveTo(x1, y1 + cs, x1, y1 + cs - rad);
        ctx.lineTo(x1, y1 + rad);
        ctx.quadraticCurveTo(x1, y1, x1 + rad, y1);
        ctx.fill();

        if (f > 0.5 && s === 1) {
          ctx.save();
          ctx.shadowColor = 'rgba(0,245,212,0.6)';
          ctx.shadowBlur = cellSize * 0.5 * f;
          ctx.fillStyle = 'rgba(0,245,212,' + (f * 0.3) + ')';
          ctx.fill();
          ctx.restore();
        }

        flash[idx] *= 0.92;
        if (flash[idx] < 0.01) flash[idx] = 0;
      }
    }

    if (patterns.length > 0 && cellSize >= 10) {
      var thumbSize = Math.min(48, cellSize * GS * 0.12);
      var thumbCellSize = thumbSize / GS;
      var thumbY = gridY + cellSize * GS + 20;
      var totalW = patterns.length * (thumbSize + 10) - 10;
      var thumbStartX = (W - totalW) / 2;

      for (var p = 0; p < patterns.length; p++) {
        var tx = thumbStartX + p * (thumbSize + 10);
        var pat = patterns[p];
        var isBest = (p === ov.best);

        if (isBest) {
          ctx.strokeStyle = 'rgba(0,245,212,0.5)';
          ctx.lineWidth = 1.5;
          ctx.strokeRect(tx - 3, thumbY - 3, thumbSize + 6, thumbSize + 6);
        }

        for (var r = 0; r < GS; r++) {
          for (var c = 0; c < GS; c++) {
            var idx2 = r * GS + c;
            if (pat[idx2] === 1) {
              ctx.fillStyle = isBest ? 'rgba(0,245,212,0.6)' : 'rgba(0,245,212,0.3)';
            } else {
              ctx.fillStyle = isBest ? 'rgba(123,44,191,0.25)' : 'rgba(123,44,191,0.1)';
            }
            ctx.fillRect(tx + c * thumbCellSize, thumbY + r * thumbCellSize,
                         Math.max(1, thumbCellSize - 0.5), Math.max(1, thumbCellSize - 0.5));
          }
        }

        ctx.fillStyle = 'rgba(255,255,255,0.3)';
        ctx.font = '9px Orbitron, monospace';
        ctx.textAlign = 'center';
        ctx.fillText('P' + (p + 1), tx + thumbSize / 2, thumbY + thumbSize + 12);
      }
    }

    if (recalling) {
      ctx.fillStyle = 'rgba(0,245,212,0.15)';
      ctx.font = '12px Orbitron, monospace';
      ctx.textAlign = 'center';
      ctx.fillText('RECALLING...', W / 2, gridY - 15);
    } else if (converged) {
      ctx.fillStyle = 'rgba(0,245,212,0.4)';
      ctx.font = '12px Orbitron, monospace';
      ctx.textAlign = 'center';
      ctx.fillText('CONVERGED', W / 2, gridY - 15);
    }

    var en = energy();
    $n.textContent = N;
    $s.textContent = patterns.length + '/' + maxPatterns;
    $e.textContent = en.toFixed(1);
    $o.textContent = ov.val > -Infinity ? (ov.val * 100).toFixed(0) + '%' : '--';
    $st.textContent = totalSteps;
  }

  function loop() {
    if (recalling) {
      updatesPerFrame = parseInt(slSpeed.value);
      for (var k = 0; k < updatesPerFrame; k++) {
        if (!recalling) break;
        updateStep();
      }
      if (recalling && totalSteps % 30 === 0 && window.SFX) {
        SFX.play('ripple', 100);
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  function pointerPos(e) {
    var rect = C.getBoundingClientRect();
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var p = pointerPos(e);
    mRight = (e.button === 2);
    mDown = true;
    var idx = cellAt(p.x, p.y);
    if (idx >= 0 && !mRight) {
      mPaint = state[idx] === 1 ? -1 : 1;
    }
    recalling = false; converged = false;
    toggleCell(p.x, p.y);
  });

  C.addEventListener('mousemove', function(e) {
    if (!mDown) return;
    var p = pointerPos(e);
    toggleCell(p.x, p.y);
  });

  window.addEventListener('mouseup', function() { mDown = false; });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (touchId !== null) return;
    var t = e.changedTouches[0];
    touchId = t.identifier;
    var p = { x: t.clientX - C.getBoundingClientRect().left, y: t.clientY - C.getBoundingClientRect().top };
    mRight = false;
    var idx = cellAt(p.x, p.y);
    if (idx >= 0) mPaint = state[idx] === 1 ? -1 : 1;
    recalling = false; converged = false;
    toggleCell(p.x, p.y);
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.changedTouches.length; i++) {
      if (e.changedTouches[i].identifier === touchId) {
        var t = e.changedTouches[i];
        var p = { x: t.clientX - C.getBoundingClientRect().left, y: t.clientY - C.getBoundingClientRect().top };
        toggleCell(p.x, p.y);
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    for (var i = 0; i < e.changedTouches.length; i++) {
      if (e.changedTouches[i].identifier === touchId) touchId = null;
    }
  });

  slNoise.addEventListener('input', function() {
    vNoise.textContent = this.value + '%';
  });
  slSpeed.addEventListener('input', function() {
    vSpeed.textContent = this.value;
    updatesPerFrame = parseInt(this.value);
  });

  document.getElementById('btn-store').addEventListener('click', function() {
    storePattern();
  });
  document.getElementById('btn-clear-mem').addEventListener('click', function() {
    clearMemory();
  });
  document.getElementById('btn-recall').addEventListener('click', function() {
    startRecall();
    if (window.SFX) SFX.play('toggle');
  });
  document.getElementById('btn-corrupt').addEventListener('click', function() {
    corrupt(parseInt(slNoise.value));
    recalling = false; converged = false; totalSteps = 0; stableCount = 0;
  });
  document.getElementById('btn-clear').addEventListener('click', function() {
    clearState();
    if (window.SFX) SFX.play('click');
  });
  document.getElementById('btn-random').addEventListener('click', function() {
    randomState();
    if (window.SFX) SFX.play('click');
  });

  var presetBtns = document.querySelectorAll('.preset-btn');
  for (var i = 0; i < presetBtns.length; i++) {
    presetBtns[i].addEventListener('click', function() {
      applyPreset(this.getAttribute('data-preset'));
      if (window.SFX) SFX.play('tick');
    });
  }

  function init() {
    resize();
    applyPreset('cross');
    storePattern();
    applyPreset('diamond');
    storePattern();
    applyPreset('ring');
    storePattern();
    corrupt(30);
    startRecall();
  }

  window.addEventListener('resize', resize);
  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
