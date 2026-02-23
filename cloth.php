<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cloth');

$pageName = 'cloth';
$pageTitle = 'CLOTH - PULSE';
$pageDesc = '布のシミュレーション。掴んで、引っ張って、引き裂く。';
$pageCanonical = 'https://pulse.subara3.com/cloth.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: grab; }
#canvas.dragging { cursor: grabbing; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.info { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.2); font-size: 0.6rem; letter-spacing: 0.05em; line-height: 1.8; }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.5rem; right: 0.5rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">
  DRAG TO PULL<br>
  RIGHT-CLICK TO TEAR<br>
  WATCH IT FALL
</div>

<div class="ui hud">
  POINTS: <span id="hudPoints" class="highlight">0</span><br>
  LINKS: <span id="hudLinks" class="highlight">0</span><br>
  WIND: <span id="hudWind" class="highlight">OFF</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>GRAVITY</label>
    <input type="range" id="gravSlider" min="1" max="20" value="8">
  </div>
  <div class="slider-wrap">
    <label>STIFFNESS</label>
    <input type="range" id="stiffSlider" min="1" max="10" value="5">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="windBtn">WIND</button>
    <button class="control-btn" id="colorBtn">COLOR</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var hudPoints = document.getElementById('hudPoints');
  var hudLinks = document.getElementById('hudLinks');
  var hudWind = document.getElementById('hudWind');
  var gravSlider = document.getElementById('gravSlider');
  var stiffSlider = document.getElementById('stiffSlider');
  var windBtn = document.getElementById('windBtn');
  var colorBtn = document.getElementById('colorBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W, H;
  var points = [];
  var sticks = [];
  var COLS, ROWS, SPACING;
  var wind = false;
  var windTime = 0;
  var paletteIdx = 0;
  var mouse = { x: 0, y: 0, px: 0, py: 0, down: false, right: false };
  var TEAR_DIST = 0;
  var INFLUENCE = 0;

  var PALETTES = [
    // teal gradient
    function(u, v, stress) {
      var h = 175 + v * 25 + stress * 15;
      var s = 60 + stress * 20;
      var l = 30 + u * 15 + stress * 15;
      return 'hsl(' + h + ',' + s + '%,' + l + '%)';
    },
    // purple gradient
    function(u, v, stress) {
      var h = 265 + v * 20 - stress * 10;
      var s = 50 + stress * 25;
      var l = 25 + u * 20 + stress * 10;
      return 'hsl(' + h + ',' + s + '%,' + l + '%)';
    },
    // teal-purple mix
    function(u, v, stress) {
      var h = 175 + (u + v) * 60;
      var s = 55 + stress * 25;
      var l = 28 + stress * 20;
      return 'hsl(' + h + ',' + s + '%,' + l + '%)';
    }
  ];

  function init() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;

    var isMobile = W < 768;
    SPACING = isMobile ? 14 : 10;
    var clothW = Math.min(W * 0.7, isMobile ? 300 : 500);
    var clothH = Math.min(H * 0.5, isMobile ? 250 : 400);
    COLS = Math.floor(clothW / SPACING);
    ROWS = Math.floor(clothH / SPACING);
    TEAR_DIST = SPACING * 2.2;
    INFLUENCE = isMobile ? 50 : 35;

    var startX = (W - COLS * SPACING) / 2;
    var startY = H * 0.12;

    points = [];
    sticks = [];

    for (var y = 0; y <= ROWS; y++) {
      for (var x = 0; x <= COLS; x++) {
        var px = startX + x * SPACING;
        var py = startY + y * SPACING;
        var p = {
          x: px, y: py,
          ox: px, oy: py,
          pinned: true,
          realPinned: y === 0 && x % 3 === 0,
          u: x / COLS,
          v: y / ROWS
        };
        points.push(p);

        if (x > 0) {
          sticks.push({ a: points.length - 2, b: points.length - 1, len: SPACING, broken: false });
        }
        if (y > 0) {
          sticks.push({ a: points.length - 1 - (COLS + 1), b: points.length - 1, len: SPACING, broken: false });
        }
      }
    }
  }

  function simulate() {
    var gravity = parseFloat(gravSlider.value) * 0.05;
    var iterations = Math.max(2, Math.round(parseFloat(stiffSlider.value) * 0.8));

    // wind
    if (wind) {
      windTime += 0.008;
    }

    // verlet integration
    for (var i = 0; i < points.length; i++) {
      var p = points[i];
      if (p.pinned) continue;

      var vx = (p.x - p.ox) * 0.99;
      var vy = (p.y - p.oy) * 0.99;

      p.ox = p.x;
      p.oy = p.y;
      p.x += vx;
      p.y += vy + gravity;

      // wind force
      if (wind) {
        var wx = Math.sin(windTime + p.v * 3) * 0.8 + Math.sin(windTime * 0.7 + p.u * 2) * 0.4;
        p.x += wx;
      }

      // mouse interaction
      if (mouse.down) {
        var dx = p.x - mouse.x;
        var dy = p.y - mouse.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < INFLUENCE) {
          if (mouse.right) {
            // tear nearby sticks
          } else {
            var force = (INFLUENCE - dist) / INFLUENCE;
            p.x += (mouse.x - mouse.px) * force * 0.8;
            p.y += (mouse.y - mouse.py) * force * 0.8;
          }
        }
      }

      // bounds
      if (p.y > H - 5) { p.y = H - 5; p.oy = p.y; }
      if (p.x < 5) { p.x = 5; }
      if (p.x > W - 5) { p.x = W - 5; }
    }

    // constraint solving
    for (var iter = 0; iter < iterations; iter++) {
      for (var i = 0; i < sticks.length; i++) {
        var s = sticks[i];
        if (s.broken) continue;
        var a = points[s.a];
        var b = points[s.b];
        var dx = b.x - a.x;
        var dy = b.y - a.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 0.001) continue;

        // tear check
        if (dist > TEAR_DIST) {
          s.broken = true;
          if (typeof SFX !== 'undefined') SFX.play('pop', 30);
          continue;
        }

        var diff = (dist - s.len) / dist * 0.5;
        var ox = dx * diff;
        var oy = dy * diff;

        if (!a.pinned) { a.x += ox; a.y += oy; }
        if (!b.pinned) { b.x -= ox; b.y -= oy; }
      }
    }

    // tear via right-click / long press
    if (mouse.down && mouse.right) {
      for (var i = 0; i < sticks.length; i++) {
        var s = sticks[i];
        if (s.broken) continue;
        var a = points[s.a];
        var b = points[s.b];
        var mx = (a.x + b.x) / 2;
        var my = (a.y + b.y) / 2;
        var dx = mx - mouse.x;
        var dy = my - mouse.y;
        if (dx * dx + dy * dy < INFLUENCE * INFLUENCE) {
          s.broken = true;
          if (typeof SFX !== 'undefined') SFX.play('pop', 20);
        }
      }
    }
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var getPal = PALETTES[paletteIdx];

    // draw sticks as fabric triangles
    ctx.lineWidth = 1;
    for (var i = 0; i < sticks.length; i++) {
      var s = sticks[i];
      if (s.broken) continue;
      var a = points[s.a];
      var b = points[s.b];

      var dx = b.x - a.x;
      var dy = b.y - a.y;
      var dist = Math.sqrt(dx * dx + dy * dy);
      var stress = Math.min(1, dist / TEAR_DIST);

      var u = (a.u + b.u) / 2;
      var v = (a.v + b.v) / 2;

      ctx.beginPath();
      ctx.moveTo(a.x, a.y);
      ctx.lineTo(b.x, b.y);
      ctx.strokeStyle = getPal(u, v, stress);
      ctx.globalAlpha = 0.4 + stress * 0.5;
      ctx.stroke();
    }
    ctx.globalAlpha = 1;

    // draw pinned points
    for (var i = 0; i < points.length; i++) {
      if (points[i].pinned) {
        ctx.beginPath();
        ctx.arc(points[i].x, points[i].y, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#00f5d4';
        ctx.fill();
      }
    }

    // HUD update
    var activeLinks = 0;
    for (var i = 0; i < sticks.length; i++) {
      if (!sticks[i].broken) activeLinks++;
    }
    hudPoints.textContent = points.length;
    hudLinks.textContent = activeLinks;
    hudWind.textContent = wind ? 'ON' : 'OFF';
  }

  function loop() {
    simulate();
    render();
    requestAnimationFrame(loop);
  }

  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    var clientX = e.clientX !== undefined ? e.clientX : e.touches[0].clientX;
    var clientY = e.clientY !== undefined ? e.clientY : e.touches[0].clientY;
    return { x: (clientX - r.left) * (W / r.width), y: (clientY - r.top) * (H / r.height) };
  }

    var clothReleased = false;
  function releaseCloth() {
    if (clothReleased) return;
    clothReleased = true;
    for (var i = 0; i < points.length; i++) {
      points[i].pinned = points[i].realPinned;
    }
  }

  // Mouse events
  canvas.addEventListener('mousedown', function(e) {
    releaseCloth();
    var p = getPos(e);
    mouse.x = mouse.px = p.x;
    mouse.y = mouse.py = p.y;
    mouse.down = true;
    mouse.right = e.button === 2;
    canvas.classList.add('dragging');
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  canvas.addEventListener('mousemove', function(e) {
    var p = getPos(e);
    mouse.px = mouse.x;
    mouse.py = mouse.y;
    mouse.x = p.x;
    mouse.y = p.y;
    if (mouse.down && typeof SFX !== 'undefined') SFX.play('move', 80);
  });

  canvas.addEventListener('mouseup', function() {
    mouse.down = false;
    mouse.right = false;
    canvas.classList.remove('dragging');
  });

  canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // Touch events
  var longPressTimer = null;
  canvas.addEventListener('touchstart', function(e) {
    releaseCloth();
    e.preventDefault();
    var p = getPos(e);
    mouse.x = mouse.px = p.x;
    mouse.y = mouse.py = p.y;
    mouse.down = true;
    mouse.right = false;
    canvas.classList.add('dragging');
    if (typeof SFX !== 'undefined') SFX.play('spawn');

    // Long press = tear
    longPressTimer = setTimeout(function() {
      mouse.right = true;
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    }, 400);
  }, { passive: false });

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    var p = getPos({ clientX: t.clientX, clientY: t.clientY });
    mouse.px = mouse.x;
    mouse.py = mouse.y;
    mouse.x = p.x;
    mouse.y = p.y;

    // cancel long press if moved
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }
    if (mouse.down && typeof SFX !== 'undefined') SFX.play('move', 100);
  }, { passive: false });

  canvas.addEventListener('touchend', function() {
    mouse.down = false;
    mouse.right = false;
    canvas.classList.remove('dragging');
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }
  });

  // Controls
  windBtn.addEventListener('click', function() {
    wind = !wind;
    windBtn.classList.toggle('active', wind);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resetBtn.addEventListener('click', function() {
    clothReleased = false;
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', function() {
    clothReleased = false;
    init();
  });

  init();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
