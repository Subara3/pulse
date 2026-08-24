<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ulam');

$pageName = 'ulam';
$pageTitle = 'ULAM - PULSE';
$pageDesc = 'ウラムの螺旋。整数を渦巻き状に並べると、素数が不思議な対角線パターンを描く。';
$pageCanonical = 'https://pulse.subara3.com/ulam.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
#tooltip{position:fixed;display:none;padding:6px 10px;background:rgba(10,10,15,0.92);border:1px solid rgba(0,245,212,0.4);border-radius:4px;color:rgba(255,255,255,0.8);font-family:"JetBrains Mono",monospace;font-size:0.65rem;line-height:1.5;pointer-events:none;z-index:200;white-space:nowrap}
#tooltip .tp{color:#00f5d4}
#tooltip .ts{color:#7b2cbf}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  VISIBLE: <span id="hudVis" class="v">0</span><br>
  PRIMES: <span id="hudPri" class="v">0</span><br>
  DENSITY: <span id="hudDen" class="v">0%</span><br>
  CELL: <span id="hudCell" class="v">8</span>px
</div>

<div id="info">
  ulam spiral<br>
  integers on a spiral &rarr; <span class="v">primes form diagonals</span><br>
  Euler's n&sup2;+n+41 &amp; other polynomials
</div>

<div id="tooltip"></div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inspect'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" data-mode="primes">PRIMES</button>
    <button class="control-btn" data-mode="twins">TWINS</button>
    <button class="control-btn" data-mode="density">HEAT</button>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">CELL SIZE</label>
    <div class="slider-row">
      <input type="range" id="sldCell" min="2" max="20" value="8">
      <span class="slider-value" id="valCell">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">GLOW</label>
    <div class="slider-row">
      <input type="range" id="sldGlow" min="0" max="12" value="4">
      <span class="slider-value" id="valGlow">4</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnCenter">RECENTER</button>
    <button class="control-btn" id="btnAnimate">GROW</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;

  var MAX = 500000;
  var sieve = new Uint8Array(MAX + 1);
  sieve[0] = 1; sieve[1] = 1;
  for (var i = 2; i * i <= MAX; i++) {
    if (!sieve[i]) for (var j = i * i; j <= MAX; j += i) sieve[j] = 1;
  }
  function ip(n) { return n >= 2 && n <= MAX && !sieve[n]; }

  var spiralX = new Int16Array(MAX + 1);
  var spiralY = new Int16Array(MAX + 1);
  (function buildSpiral() {
    var x = 0, y = 0, dx = 1, dy = 0;
    var steps = 1, stepsInDir = 0, turnCount = 0;
    spiralX[0] = 0; spiralY[0] = 0;
    for (var n = 1; n <= MAX; n++) {
      x += dx; y += dy;
      spiralX[n] = x; spiralY[n] = y;
      stepsInDir++;
      if (stepsInDir >= steps) {
        stepsInDir = 0;
        var tmp = dx; dx = -dy; dy = tmp;
        turnCount++;
        if (turnCount % 2 === 0) steps++;
      }
    }
  })();

  var panX = 0, panY = 0;
  var cellSize = 8;
  var mode = 'primes';
  var glowStr = 4;
  var animating = false;
  var animN = 0;
  var animMax = 0;

  function resize() {
    var dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  function isTwin(n) {
    return (ip(n) && (ip(n - 2) || ip(n + 2)));
  }

  function neighborPrimeCount(sx, sy) {
    var count = 0;
    var r = 2;
    for (var ddx = -r; ddx <= r; ddx++) {
      for (var ddy = -r; ddy <= r; ddy++) {
        if (ddx === 0 && ddy === 0) continue;
        var tx = sx + ddx, ty = sy + ddy;
        var tn = coordToN(tx, ty);
        if (tn >= 0 && ip(tn)) count++;
      }
    }
    return count;
  }

  var RANGE = 450;
  var SIDE = 2 * RANGE + 1;
  var coordGrid = new Int32Array(SIDE * SIDE);
  (function buildCoordGrid() {
    for (var n = 0; n <= MAX; n++) {
      var gx = spiralX[n] + RANGE, gy = spiralY[n] + RANGE;
      if (gx >= 0 && gx < SIDE && gy >= 0 && gy < SIDE) {
        coordGrid[gy * SIDE + gx] = n + 1;
      }
    }
  })();

  function coordToN(sx, sy) {
    var gx = sx + RANGE, gy = sy + RANGE;
    if (gx < 0 || gx >= SIDE || gy < 0 || gy >= SIDE) return -1;
    return coordGrid[gy * SIDE + gx] - 1;
  }

  var hudVis = document.getElementById('hudVis');
  var hudPri = document.getElementById('hudPri');
  var hudDen = document.getElementById('hudDen');
  var hudCell = document.getElementById('hudCell');

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cx = W / 2 + panX;
    var cy = H / 2 + panY;
    var cs = cellSize;
    var half = cs / 2;

    var minSX = Math.floor((-cx) / cs) - 1;
    var maxSX = Math.ceil((W - cx) / cs) + 1;
    var minSY = Math.floor((-cy) / cs) - 1;
    var maxSY = Math.ceil((H - cy) / cs) + 1;

    var visCount = 0, priCount = 0;
    var limit = animating ? animN : MAX;

    if (glowStr > 0 && cs >= 3) {
      ctx.shadowBlur = glowStr * 2;
    }

    if (mode === 'density') {
      for (var sx = minSX; sx <= maxSX; sx++) {
        for (var sy = minSY; sy <= maxSY; sy++) {
          var n = coordToN(sx, sy);
          if (n < 0 || n > limit) continue;
          visCount++;
          var px = cx + sx * cs - half;
          var py = cy + sy * cs - half;
          if (ip(n)) {
            priCount++;
            var density = neighborPrimeCount(sx, sy);
            var t = Math.min(density / 8, 1);
            var r = Math.round(123 * t);
            var g = Math.round(245 * (1 - t) + 44 * t);
            var b = Math.round(212 * (1 - t) + 191 * t);
            var a = 0.4 + 0.6 * t;
            ctx.shadowColor = 'rgba(' + r + ',' + g + ',' + b + ',' + (a * 0.6) + ')';
            ctx.fillStyle = 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
            ctx.fillRect(px, py, cs - 1, cs - 1);
          } else {
            ctx.shadowColor = 'transparent';
            ctx.fillStyle = 'rgba(255,255,255,0.02)';
            ctx.fillRect(px, py, cs - 1, cs - 1);
          }
        }
      }
    } else if (mode === 'twins') {
      for (var sx = minSX; sx <= maxSX; sx++) {
        for (var sy = minSY; sy <= maxSY; sy++) {
          var n = coordToN(sx, sy);
          if (n < 0 || n > limit) continue;
          visCount++;
          var px = cx + sx * cs - half;
          var py = cy + sy * cs - half;
          if (ip(n)) {
            priCount++;
            if (isTwin(n)) {
              ctx.shadowColor = 'rgba(123,44,191,0.5)';
              ctx.fillStyle = 'rgba(123,44,191,0.85)';
            } else {
              ctx.shadowColor = 'rgba(0,245,212,0.3)';
              ctx.fillStyle = 'rgba(0,245,212,0.6)';
            }
            ctx.fillRect(px, py, cs - 1, cs - 1);
          } else {
            ctx.shadowColor = 'transparent';
            ctx.fillStyle = 'rgba(255,255,255,0.02)';
            ctx.fillRect(px, py, cs - 1, cs - 1);
          }
        }
      }
    } else {
      for (var sx = minSX; sx <= maxSX; sx++) {
        for (var sy = minSY; sy <= maxSY; sy++) {
          var n = coordToN(sx, sy);
          if (n < 0 || n > limit) continue;
          visCount++;
          var px = cx + sx * cs - half;
          var py = cy + sy * cs - half;
          if (ip(n)) {
            priCount++;
            ctx.shadowColor = 'rgba(0,245,212,0.4)';
            ctx.fillStyle = 'rgba(0,245,212,0.8)';
            ctx.fillRect(px, py, cs - 1, cs - 1);
          } else {
            ctx.shadowColor = 'transparent';
            ctx.fillStyle = 'rgba(255,255,255,0.02)';
            ctx.fillRect(px, py, cs - 1, cs - 1);
          }
        }
      }
    }

    ctx.shadowBlur = 0;
    ctx.shadowColor = 'transparent';

    if (cs >= 16) {
      ctx.font = Math.max(7, cs * 0.4) + 'px JetBrains Mono, monospace';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      for (var sx = minSX; sx <= maxSX; sx++) {
        for (var sy = minSY; sy <= maxSY; sy++) {
          var n = coordToN(sx, sy);
          if (n < 0 || n > limit) continue;
          var px = cx + sx * cs;
          var py = cy + sy * cs;
          ctx.fillStyle = ip(n) ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.12)';
          ctx.fillText(n, px, py);
        }
      }
    }

    hudVis.textContent = visCount;
    hudPri.textContent = priCount;
    hudDen.textContent = visCount > 0 ? (priCount / visCount * 100).toFixed(1) + '%' : '0%';
    hudCell.textContent = cs;
  }

  var dragging = false, lastMX = 0, lastMY = 0;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) { dragging = true; lastMX = e.clientX; lastMY = e.clientY; }
  });
  window.addEventListener('mousemove', function(e) {
    if (dragging) {
      panX += e.clientX - lastMX;
      panY += e.clientY - lastMY;
      lastMX = e.clientX; lastMY = e.clientY;
      needDraw = true;
    }
    showTooltip(e.clientX, e.clientY);
  });
  window.addEventListener('mouseup', function() { dragging = false; });

  C.addEventListener('click', function(e) {
    var info = hitTest(e.clientX, e.clientY);
    if (info && info.n >= 0) {
      SFX.play('tick');
    }
  });

  var tooltip = document.getElementById('tooltip');
  function showTooltip(mx, my) {
    var info = hitTest(mx, my);
    if (!info || info.n < 0) { tooltip.style.display = 'none'; return; }
    var n = info.n;
    var prime = ip(n);
    var twin = prime && isTwin(n);
    var html = '<span class="tp">' + n + '</span>';
    if (prime) html += ' &mdash; <span class="tp">PRIME</span>';
    if (twin) html += ' <span class="ts">(TWIN)</span>';
    if (!prime && n > 1) {
      var factors = [];
      var tmp = n;
      for (var d = 2; d * d <= tmp; d++) {
        while (tmp % d === 0) { factors.push(d); tmp /= d; }
      }
      if (tmp > 1) factors.push(tmp);
      html += '<br>' + factors.join(' &times; ');
    }
    tooltip.innerHTML = html;
    tooltip.style.display = 'block';
    var tx = mx + 14, ty = my - 10;
    if (tx + 160 > W) tx = mx - 160;
    if (ty < 0) ty = my + 20;
    tooltip.style.left = tx + 'px';
    tooltip.style.top = ty + 'px';
  }

  function hitTest(mx, my) {
    var cx = W / 2 + panX;
    var cy = H / 2 + panY;
    var cs = cellSize;
    var sx = Math.round((mx - cx) / cs);
    var sy = Math.round((my - cy) / cs);
    var n = coordToN(sx, sy);
    return { n: n, sx: sx, sy: sy };
  }

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var old = cellSize;
    if (e.deltaY < 0) cellSize = Math.min(40, cellSize + (cellSize < 6 ? 1 : 2));
    else cellSize = Math.max(2, cellSize - (cellSize <= 6 ? 1 : 2));
    if (cellSize !== old) {
      var mx = e.clientX, my = e.clientY;
      var cx = W / 2 + panX, cy = H / 2 + panY;
      var worldX = (mx - cx) / old;
      var worldY = (my - cy) / old;
      panX = mx - W / 2 - worldX * cellSize;
      panY = my - H / 2 - worldY * cellSize;
      sldCell.value = cellSize;
      valCell.textContent = cellSize;
      needDraw = true;
      SFX.play('tick', 80);
    }
  }, { passive: false });

  var touchDist0 = 0, touchPan = false, touchPinch = false;
  var touch0 = { x: 0, y: 0 }, touchLast = { x: 0, y: 0 };

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      touchPan = true; touchPinch = false;
      touch0.x = touchLast.x = e.touches[0].clientX;
      touch0.y = touchLast.y = e.touches[0].clientY;
    } else if (e.touches.length === 2) {
      touchPan = false; touchPinch = true;
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      touchDist0 = Math.sqrt(dx * dx + dy * dy);
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (touchPan && e.touches.length === 1) {
      var mx = e.touches[0].clientX, my = e.touches[0].clientY;
      panX += mx - touchLast.x;
      panY += my - touchLast.y;
      touchLast.x = mx; touchLast.y = my;
      needDraw = true;
    } else if (touchPinch && e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var dist = Math.sqrt(dx * dx + dy * dy);
      var ratio = dist / touchDist0;
      var newSize = Math.round(cellSize * ratio);
      newSize = Math.max(2, Math.min(40, newSize));
      if (newSize !== cellSize) {
        cellSize = newSize;
        sldCell.value = cellSize;
        valCell.textContent = cellSize;
        coordMap = null;
        needDraw = true;
      }
      touchDist0 = dist;
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (touchPan) {
        var dx = touchLast.x - touch0.x, dy = touchLast.y - touch0.y;
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) {
          showTooltip(touchLast.x, touchLast.y);
          SFX.play('tick');
          setTimeout(function() { tooltip.style.display = 'none'; }, 2500);
        }
      }
      touchPan = false; touchPinch = false;
    }
  });

  var modeBtns = document.querySelectorAll('[data-mode]');
  modeBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      modeBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      mode = btn.dataset.mode;
      needDraw = true;
      SFX.play('toggle');
    });
  });

  var sldCell = document.getElementById('sldCell');
  var valCell = document.getElementById('valCell');
  sldCell.addEventListener('input', function() {
    cellSize = +this.value;
    valCell.textContent = cellSize;
    coordMap = null;
    needDraw = true;
  });

  var sldGlow = document.getElementById('sldGlow');
  var valGlow = document.getElementById('valGlow');
  sldGlow.addEventListener('input', function() {
    glowStr = +this.value;
    valGlow.textContent = glowStr;
    needDraw = true;
  });

  document.getElementById('btnCenter').addEventListener('click', function() {
    panX = 0; panY = 0;
    needDraw = true;
    SFX.play('click');
  });

  var btnAnimate = document.getElementById('btnAnimate');
  btnAnimate.addEventListener('click', function() {
    if (animating) {
      animating = false;
      btnAnimate.classList.remove('active');
      btnAnimate.textContent = 'GROW';
      needDraw = true;
    } else {
      animating = true;
      animN = 1;
      animMax = Math.min(MAX, 50000);
      btnAnimate.classList.add('active');
      btnAnimate.textContent = 'STOP';
      SFX.play('spawn');
    }
    SFX.play('toggle');
  });

  var needDraw = true;
  var lastTime = 0;

  function loop(t) {
    requestAnimationFrame(loop);
    if (animating) {
      var step = Math.max(10, Math.floor(animN * 0.02));
      animN = Math.min(animN + step, animMax);
      needDraw = true;
      if (animN >= animMax) {
        animating = false;
        btnAnimate.classList.remove('active');
        btnAnimate.textContent = 'GROW';
        SFX.play('complete');
      } else if (animN % 500 < step) {
        SFX.play('tick', 120);
      }
    }
    if (needDraw) {
      draw();
      needDraw = false;
    }
  }

  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
