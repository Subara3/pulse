<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chaosgame');

$pageName = 'chaosgame';
$pageTitle = 'CHAOSGAME - PULSE';
$pageDesc = 'カオスゲーム。頂点へランダムにジャンプし続けると、フラクタルが自然に浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/chaosgame.php';

$extraStyle = '
body { background:#0a0a0f; margin:0; overflow:hidden; }
#canvas { position:fixed; inset:0; width:100%; height:100%; cursor:crosshair; }
#hud {
  position:fixed; top:1.1rem; left:1rem; z-index:100;
  pointer-events:none;
  font-family:"Orbitron","Segoe UI",sans-serif;
  font-size:0.62rem; line-height:1.9; letter-spacing:0.06em;
}
#hud .lbl { color:rgba(255,255,255,0.3); margin-right:0.3em; }
#hud .val { color:#00f5d4; font-weight:700; }
#hud .pts { color:rgba(0,245,212,0.45); }
@media(max-width:768px){
  #hud { font-size:0.52rem; top:0.75rem; left:0.75rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  <div><span class="lbl">PRESET</span><span class="val" id="vpreset">SIERPINSKI</span></div>
  <div><span class="lbl">POINTS</span><span class="pts" id="vpts">0</span></div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'set point'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row" id="presetBtns">
    <button class="control-btn active" data-preset="0">SIERPINSKI</button>
    <button class="control-btn" data-preset="1">SQUARE</button>
    <button class="control-btn" data-preset="2">PENTAGON</button>
    <button class="control-btn" data-preset="3">HEXAGON</button>
    <button class="control-btn" data-preset="4">FERN</button>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SPEED</label>
      <input type="range" id="speedSlider" min="100" max="2000" step="50" value="500">
      <span class="slider-value" id="speedVal">500</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function () {
  'use strict';

  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W, H, cx, cy;
  var totalPts = 0;
  var speed = 500;
  var currentPreset = 0;
  var curX = 0, curY = 0;
  var running = true;

  /* ── Preset definitions ── */
  var PRESETS = [
    {
      name: 'SIERPINSKI',
      type: 'chaos',
      vertices: 3,
      ratio: 0.5,
      restriction: 'none'
    },
    {
      name: 'SQUARE',
      type: 'chaos',
      vertices: 4,
      ratio: 0.5,
      restriction: 'no-repeat'
    },
    {
      name: 'PENTAGON',
      type: 'chaos',
      vertices: 5,
      ratio: 0.618,
      restriction: 'none'
    },
    {
      name: 'HEXAGON',
      type: 'chaos',
      vertices: 6,
      ratio: 1 / 3,
      restriction: 'none'
    },
    {
      name: 'FERN',
      type: 'ifs',
      transforms: [
        { a: 0, b: 0, c: 0, d: 0.16, e: 0, f: 0, p: 0.01 },
        { a: 0.85, b: 0.04, c: -0.04, d: 0.85, e: 0, f: 1.6, p: 0.85 },
        { a: 0.2, b: -0.26, c: 0.23, d: 0.22, e: 0, f: 1.6, p: 0.07 },
        { a: -0.15, b: 0.28, c: 0.26, d: 0.24, e: 0, f: 0.44, p: 0.07 }
      ]
    }
  ];

  /* ── Colors: teal-purple palette mapped to vertex index ── */
  function vertexColor(idx, total) {
    var hue;
    if (total <= 1) {
      hue = 175;
    } else {
      hue = 170 + (idx / (total - 1)) * 120;
    }
    return 'hsla(' + hue + ', 85%, 55%, 0.65)';
  }

  /* Fern transform colors */
  var fernColors = [
    'hsla(175, 85%, 55%, 0.6)',
    'hsla(195, 80%, 50%, 0.55)',
    'hsla(230, 70%, 55%, 0.6)',
    'hsla(275, 75%, 55%, 0.6)'
  ];

  /* ── Polygon vertices ── */
  var verts = [];
  var lastVertex = -1;

  function makeVertices(n) {
    verts = [];
    var r = Math.min(W, H) * 0.4;
    for (var i = 0; i < n; i++) {
      var angle = -Math.PI / 2 + (2 * Math.PI * i) / n;
      verts.push({
        x: cx + r * Math.cos(angle),
        y: cy + r * Math.sin(angle)
      });
    }
  }

  /* ── Resize ── */
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    resetGame();
  }

  /* ── Reset ── */
  function resetGame() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    totalPts = 0;
    lastVertex = -1;

    var preset = PRESETS[currentPreset];

    if (preset.type === 'chaos') {
      makeVertices(preset.vertices);
      /* draw vertex markers */
      for (var i = 0; i < verts.length; i++) {
        ctx.fillStyle = vertexColor(i, verts.length);
        ctx.globalAlpha = 0.9;
        ctx.beginPath();
        ctx.arc(verts[i].x, verts[i].y, 3, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      /* start from random point inside polygon */
      curX = cx + (Math.random() - 0.5) * 50;
      curY = cy + (Math.random() - 0.5) * 50;
    } else {
      /* Fern: start at origin */
      curX = 0;
      curY = 0;
    }
    updateHUD();
  }

  /* ── Pick random transform for fern ── */
  function pickFernTransform() {
    var r = Math.random();
    var cum = 0;
    var transforms = PRESETS[currentPreset].transforms;
    for (var i = 0; i < transforms.length; i++) {
      cum += transforms[i].p;
      if (r <= cum) return i;
    }
    return transforms.length - 1;
  }

  /* ── Iterate one frame ── */
  function iterate() {
    var preset = PRESETS[currentPreset];
    var n = speed;

    if (preset.type === 'chaos') {
      var ratio = preset.ratio;
      var numV = verts.length;
      for (var i = 0; i < n; i++) {
        var vi;
        if (preset.restriction === 'no-repeat') {
          do {
            vi = (Math.random() * numV) | 0;
          } while (vi === lastVertex && numV > 1);
        } else {
          vi = (Math.random() * numV) | 0;
        }
        lastVertex = vi;
        curX = curX + (verts[vi].x - curX) * ratio;
        curY = curY + (verts[vi].y - curY) * ratio;
        ctx.fillStyle = vertexColor(vi, numV);
        ctx.fillRect(curX, curY, 1, 1);
      }
    } else {
      /* IFS (Fern) */
      var transforms = preset.transforms;
      var sc = Math.min(W, H) / 11;
      for (var i = 0; i < n; i++) {
        var ti = pickFernTransform();
        var t = transforms[ti];
        var nx = t.a * curX + t.b * curY + t.e;
        var ny = t.c * curX + t.d * curY + t.f;
        curX = nx;
        curY = ny;
        /* Map fern coords to screen */
        var sx = cx + curX * sc;
        var sy = H * 0.9 - ny * sc;
        ctx.fillStyle = fernColors[ti];
        ctx.fillRect(sx, sy, 1, 1);
      }
    }
    totalPts += n;
  }

  /* ── HUD ── */
  function updateHUD() {
    document.getElementById('vpreset').textContent = PRESETS[currentPreset].name;
    var n = totalPts;
    document.getElementById('vpts').textContent =
      n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' :
      n >= 1e3 ? (n / 1e3).toFixed(0) + 'K' : String(n);
  }

  /* ── Preset buttons ── */
  var presetBtns = document.querySelectorAll('#presetBtns .control-btn');
  presetBtns.forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var idx = parseInt(this.getAttribute('data-preset'));
      if (idx === currentPreset) return;
      currentPreset = idx;
      presetBtns.forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      resetGame();
      typeof SFX!=='undefined'&&SFX.play('toggle');
    });
  });

  /* ── Speed slider ── */
  var speedSlider = document.getElementById('speedSlider');
  var speedVal = document.getElementById('speedVal');
  speedSlider.addEventListener('input', function () {
    speed = parseInt(this.value);
    speedVal.textContent = speed;
  });

  /* ── Reset button ── */
  document.getElementById('resetBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    resetGame();
    typeof SFX!=='undefined'&&SFX.play('click');
  });

  /* ── Canvas click: set current point ── */
  function handleSetPoint(px, py) {
    var preset = PRESETS[currentPreset];
    if (preset.type === 'chaos') {
      curX = px;
      curY = py;
    } else {
      /* For fern, map screen coords back to fern space */
      var sc = Math.min(W, H) / 11;
      curX = (px - cx) / sc;
      curY = (H * 0.9 - py) / sc;
    }
    typeof SFX!=='undefined'&&SFX.play('spawn');
  }

  canvas.addEventListener('click', function (e) {
    handleSetPoint(e.clientX, e.clientY);
  });

  canvas.addEventListener('touchend', function (e) {
    if (e.target === canvas && e.changedTouches.length > 0) {
      e.preventDefault();
      var t = e.changedTouches[0];
      handleSetPoint(t.clientX, t.clientY);
    }
  }, { passive: false });

  /* ── Main loop ── */
  function loop() {
    iterate();
    updateHUD();
    requestAnimationFrame(loop);
  }

  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
