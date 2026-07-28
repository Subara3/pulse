<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('sdf');

$pageName = 'sdf';
$pageTitle = 'SDF - PULSE';
$pageDesc = '符号付き距離場。幾何プリミティブの距離関数をCSG演算で合成し、等距離線として描く。';
$pageCanonical = 'https://pulse.subara3.com/sdf.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Orbitron",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>SHAPES <span id="hud-n">0</span></div>
  <div>OP <span id="hud-op">UNION</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-shape">CIRCLE</button>
    <button class="ctrl-btn active" id="btn-op">UNION</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">BLEND</label>
    <input type="range" id="sl-blend" min="0" max="80" value="30" step="1">
    <span class="slider-value" id="sv-blend">30</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">CONTOUR</label>
    <input type="range" id="sl-spacing" min="8" max="50" value="20" step="1">
    <span class="slider-value" id="sv-spacing">20</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr, SCALE, rW, rH;
  var off = document.createElement('canvas');
  var O = off.getContext('2d');
  var img, pix;

  var shapes = [];
  var typeNames = ['CIRCLE', 'BOX', 'STAR'];
  var opNames = ['UNION', 'INTERSECT', 'SUBTRACT'];
  var curType = 0, curOp = 0;
  var blendK = 30, spacing = 20;
  var autoMode = true, time = 0;

  var dragIdx = -1, dragOX = 0, dragOY = 0;
  var pDown = false, startX = 0, startY = 0, moved = false;
  var longTimer = 0;

  var hudN = document.getElementById('hud-n');
  var hudOp = document.getElementById('hud-op');

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    SCALE = W > 1200 ? 3 : (W > 600 ? 4 : 5);
    rW = Math.ceil(W / SCALE);
    rH = Math.ceil(H / SCALE);
    off.width = rW; off.height = rH;
    img = O.createImageData(rW, rH);
    pix = img.data;
  }

  function sdCircle(px, py, s) {
    var dx = px - s.x, dy = py - s.y;
    return Math.sqrt(dx * dx + dy * dy) - s.r;
  }

  function sdBox(px, py, s) {
    var dx = px - s.x, dy = py - s.y;
    var lx = Math.abs(s.cos * dx + s.sin * dy) - s.hw;
    var ly = Math.abs(-s.sin * dx + s.cos * dy) - s.hh;
    var ox = lx > 0 ? lx : 0, oy = ly > 0 ? ly : 0;
    var inner = lx > ly ? lx : ly;
    return Math.sqrt(ox * ox + oy * oy) + (inner < 0 ? inner : 0) - s.cr;
  }

  function sdStar(px, py, s) {
    var dx = px - s.x, dy = py - s.y;
    var rdx = s.cos * dx + s.sin * dy;
    var rdy = -s.sin * dx + s.cos * dy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    var angle = Math.atan2(rdy, rdx);
    return dist - s.r * (1 - s.depth + s.depth * Math.cos(s.pts * angle));
  }

  function evalShape(px, py, s) {
    if (s.type === 0) return sdCircle(px, py, s);
    if (s.type === 1) return sdBox(px, py, s);
    return sdStar(px, py, s);
  }

  function smin(a, b, k) {
    if (k < 1) return a < b ? a : b;
    var h = k - (a < b ? b - a : a - b);
    if (h <= 0) return a < b ? a : b;
    h /= k;
    return (a < b ? a : b) - h * h * h * k / 6;
  }

  function combine(d1, d2, op, k) {
    if (op === 0) return smin(d1, d2, k);
    if (op === 1) return -smin(-d1, -d2, k);
    return -smin(-d1, d2, k);
  }

  function evalScene(px, py) {
    if (shapes.length === 0) return 1e5;
    var d = evalShape(px, py, shapes[0]);
    for (var i = 1; i < shapes.length; i++)
      d = combine(d, evalShape(px, py, shapes[i]), curOp, blendK);
    return d;
  }

  function updateShapeCache(s) {
    s.cos = Math.cos(s.rot);
    s.sin = Math.sin(s.rot);
  }

  function makeShape(x, y, type) {
    var r = Math.min(W, H) * 0.1;
    var s;
    if (type === 0) {
      s = { type: 0, x: x, y: y, r: r, rot: 0, baseX: x, baseY: y, cos: 1, sin: 0 };
    } else if (type === 1) {
      s = { type: 1, x: x, y: y, hw: r * 0.9, hh: r * 0.6, cr: r * 0.12, rot: 0, baseX: x, baseY: y, cos: 1, sin: 0 };
    } else {
      s = { type: 2, x: x, y: y, r: r, pts: 5, depth: 0.42, rot: 0, baseX: x, baseY: y, cos: 1, sin: 0 };
    }
    updateShapeCache(s);
    return s;
  }

  function init() {
    shapes = [];
    var cx = W / 2, cy = H / 2;
    var r = Math.min(W, H) * 0.15;
    shapes.push(makeShape(cx - r * 0.8, cy - r * 0.4, 0));
    shapes.push(makeShape(cx + r * 0.6, cy + r * 0.1, 1));
    shapes.push(makeShape(cx - r * 0.1, cy + r * 1.0, 2));
    hudN.textContent = shapes.length;
    hudOp.textContent = opNames[curOp];
  }

  function renderField() {
    var n = shapes.length;
    if (n === 0) {
      for (var i = 0; i < pix.length; i += 4) {
        pix[i] = 10; pix[i + 1] = 10; pix[i + 2] = 15; pix[i + 3] = 255;
      }
      O.putImageData(img, 0, 0);
      X.fillStyle = '#0a0a0f';
      X.fillRect(0, 0, W, H);
      X.imageSmoothingEnabled = true;
      X.drawImage(off, 0, 0, rW, rH, 0, 0, W, H);
      return;
    }

    var phase = time * 10;
    var sp = spacing;

    for (var py = 0; py < rH; py++) {
      var sy = (py + 0.5) * SCALE;
      for (var px = 0; px < rW; px++) {
        var sx = (px + 0.5) * SCALE;
        var d = evalScene(sx, sy);

        var dp = d - phase;
        var frac = ((dp / sp) % 1 + 1) % 1;
        var cls = frac < 0.5 ? frac : 1 - frac;
        cls *= sp;
        var lineA = cls < 1.5 ? (1 - cls / 1.5) : 0;
        lineA *= lineA;

        var ad = d < 0 ? -d : d;
        var edgeG = ad < 4 ? 1 - ad / 4 : 0;
        edgeG *= edgeG * edgeG;

        var at = ad < 300 ? Math.exp(-ad * 0.007) : 0;

        var r, g, b;
        if (d <= 0) {
          var v = at * 0.22 + lineA * at * 0.55 + edgeG * 0.85;
          r = (10 + v * 25) | 0;
          g = (10 + v * 235) | 0;
          b = (15 + v * 197) | 0;
        } else {
          var v = at * 0.15 + lineA * at * 0.4 + edgeG * 0.6;
          r = (10 + v * 113) | 0;
          g = (10 + v * 34 + edgeG * 70) | 0;
          b = (15 + v * 176) | 0;
        }

        var idx = (py * rW + px) << 2;
        pix[idx] = r > 255 ? 255 : r;
        pix[idx + 1] = g > 255 ? 255 : g;
        pix[idx + 2] = b > 255 ? 255 : b;
        pix[idx + 3] = 255;
      }
    }

    O.putImageData(img, 0, 0);
    X.imageSmoothingEnabled = true;
    X.imageSmoothingQuality = 'high';
    X.drawImage(off, 0, 0, rW, rH, 0, 0, W, H);
  }

  function render() {
    renderField();
    X.globalAlpha = 0.35;
    for (var i = 0; i < shapes.length; i++) {
      var s = shapes[i];
      X.beginPath();
      X.arc(s.x, s.y, 3, 0, 6.2832);
      X.fillStyle = '#00f5d4';
      X.fill();
    }
    X.globalAlpha = 1;
  }

  function nearestShape(mx, my) {
    var best = -1, bestD = 1e18;
    for (var i = 0; i < shapes.length; i++) {
      var dx = mx - shapes[i].x, dy = my - shapes[i].y;
      var dd = dx * dx + dy * dy;
      if (dd < bestD) { bestD = dd; best = i; }
    }
    var thr = Math.min(W, H) * 0.15;
    return bestD < thr * thr ? best : -1;
  }

  function gp(e) {
    return e.touches && e.touches.length > 0
      ? { x: e.touches[0].clientX, y: e.touches[0].clientY }
      : { x: e.clientX, y: e.clientY };
  }

  function onDown(e, p) {
    pDown = true; startX = p.x; startY = p.y; moved = false;
    dragIdx = nearestShape(p.x, p.y);
    if (dragIdx >= 0) {
      dragOX = shapes[dragIdx].x - p.x;
      dragOY = shapes[dragIdx].y - p.y;
    }
  }

  function onMove(p) {
    if (!pDown) return;
    var dx = p.x - startX, dy = p.y - startY;
    if (dx * dx + dy * dy > 25) moved = true;
    if (dragIdx >= 0 && moved) {
      shapes[dragIdx].x = p.x + dragOX;
      shapes[dragIdx].y = p.y + dragOY;
      shapes[dragIdx].baseX = shapes[dragIdx].x;
      shapes[dragIdx].baseY = shapes[dragIdx].y;
      if (typeof SFX !== 'undefined') SFX.play('move', 80);
    }
  }

  function onUp() {
    if (!moved && pDown && dragIdx < 0) {
      if (shapes.length < 12) {
        shapes.push(makeShape(startX, startY, curType));
        hudN.textContent = shapes.length;
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }
    pDown = false; dragIdx = -1;
    clearTimeout(longTimer);
  }

  function removeAt(px, py) {
    var idx = nearestShape(px, py);
    if (idx >= 0) {
      shapes.splice(idx, 1);
      hudN.textContent = shapes.length;
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    if (e.button === 2) return;
    onDown(e, gp(e));
  });
  C.addEventListener('mousemove', function(e) { onMove(gp(e)); });
  C.addEventListener('mouseup', function(e) { if (e.button !== 2) onUp(); });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    removeAt(e.clientX, e.clientY);
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var p = gp(e);
    onDown(e, p);
    longTimer = setTimeout(function() {
      removeAt(p.x, p.y);
      pDown = false; dragIdx = -1;
    }, 500);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    onMove(gp(e));
    if (moved) clearTimeout(longTimer);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    onUp();
  });

  var btnShape = document.getElementById('btn-shape');
  btnShape.addEventListener('click', function() {
    curType = (curType + 1) % typeNames.length;
    this.textContent = typeNames[curType];
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  var btnOp = document.getElementById('btn-op');
  btnOp.addEventListener('click', function() {
    curOp = (curOp + 1) % opNames.length;
    this.textContent = opNames[curOp];
    hudOp.textContent = opNames[curOp];
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  var slBlend = document.getElementById('sl-blend');
  var svBlend = document.getElementById('sv-blend');
  slBlend.addEventListener('input', function() {
    blendK = parseInt(this.value);
    svBlend.textContent = this.value;
  });

  var slSp = document.getElementById('sl-spacing');
  var svSp = document.getElementById('sv-spacing');
  slSp.addEventListener('input', function() {
    spacing = parseInt(this.value);
    svSp.textContent = this.value;
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  function updateAuto() {
    if (!autoMode || pDown) return;
    for (var i = 0; i < shapes.length; i++) {
      var s = shapes[i];
      var a = time * 0.25 + i * 2.094;
      var r = Math.min(W, H) * 0.04;
      s.x = s.baseX + Math.sin(a) * r;
      s.y = s.baseY + Math.cos(a * 0.73) * r * 0.8;
      s.rot = time * 0.15 + i * 1.047;
      updateShapeCache(s);
    }
  }

  window.addEventListener('resize', function() { resize(); init(); });
  resize();
  init();

  (function loop() {
    time += 0.016;
    updateAuto();
    render();
    requestAnimationFrame(loop);
  })();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
