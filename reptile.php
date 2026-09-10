<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('reptile');

$pageName = 'reptile';
$pageTitle = 'REPTILE - PULSE';
$pageDesc = 'レプタイル。自分自身の拡大コピーを敷き詰められる形。L字タイルが4つの分身に分裂し、自己相似のモザイクが無限に広がる。';
$pageCanonical = 'https://pulse.subara3.com/reptile.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>LEVEL <span id="hud-level">0</span></div>
  <div>TILES <span id="hud-tiles">1</span></div>
</div>
<div id="info-label"><span>REPTILE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inflate'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row"><label>SHAPE</label></div>
    <div class="btn-row">
      <button id="btn-ltro" class="mode-btn active">L-TROMINO</button>
      <button id="btn-tri" class="mode-btn">TRIANGLE</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-auto" class="mode-btn">AUTO</button>
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;
  var hudLevel = document.getElementById('hud-level');
  var hudTiles = document.getElementById('hud-tiles');

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw();
  }
  window.addEventListener('resize', resize);

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2) : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  var COLORS = [
    {h: 175, s: 70, l: 48},
    {h: 195, s: 55, l: 42},
    {h: 270, s: 60, l: 52},
    {h: 285, s: 50, l: 44}
  ];

  // --- State ---
  var tiles = [];
  var level = 0;
  var maxLevel = 7;
  var mode = 0; // 0 = L-tromino, 1 = triangle
  var autoOn = false;
  var autoTimer = null;

  // Camera
  var camX = 0, camY = 0, camScale = 1;

  // --- L-Tromino rep-tile ---
  // Tile: {x, y, rot}
  // rot 0: missing TR (x+1,y+1) — cells (x,y),(x+1,y),(x,y+1)
  // rot 1: missing TL (x,y+1)  — cells (x,y),(x+1,y),(x+1,y+1)
  // rot 2: missing BL (x,y)    — cells (x+1,y),(x,y+1),(x+1,y+1)
  // rot 3: missing BR (x+1,y)  — cells (x,y),(x,y+1),(x+1,y+1)

  function subdivideLTromino(t) {
    var px = t.x, py = t.y, r = t.rot;
    var subs = [];
    subs.push({x: 2*px+1, y: 2*py+1, rot: r});
    if (r !== 2) subs.push({x: 2*px, y: 2*py, rot: 0});
    if (r !== 3) subs.push({x: 2*px+2, y: 2*py, rot: 1});
    if (r !== 1) subs.push({x: 2*px, y: 2*py+2, rot: 3});
    if (r !== 0) subs.push({x: 2*px+2, y: 2*py+2, rot: 2});
    return subs;
  }

  function lTrominoPoly(t, cs) {
    var x = t.x * cs, y = t.y * cs, s = cs;
    var r = t.rot;
    if (r === 0) return [x,y, x+2*s,y, x+2*s,y+s, x+s,y+s, x+s,y+2*s, x,y+2*s];
    if (r === 1) return [x,y, x+2*s,y, x+2*s,y+2*s, x+s,y+2*s, x+s,y+s, x,y+s];
    if (r === 2) return [x+s,y, x+2*s,y, x+2*s,y+2*s, x,y+2*s, x,y+s, x+s,y+s];
    return [x,y, x+s,y, x+s,y+s, x+2*s,y+s, x+2*s,y+2*s, x,y+2*s];
  }

  // --- Right triangle rep-tile ---
  // Triangle: {ax,ay, bx,by, cx,cy, rot}
  // Subdivide by connecting midpoints: 4 similar sub-triangles
  function subdivideTriangle(t) {
    var ax = t.ax, ay = t.ay, bx = t.bx, by = t.by, cx = t.cx, cy = t.cy;
    var mx = (ax+bx)/2, my = (ay+by)/2;
    var nx = (bx+cx)/2, ny = (by+cy)/2;
    var ox = (ax+cx)/2, oy = (ay+cy)/2;
    return [
      {ax:ax, ay:ay, bx:mx, by:my, cx:ox, cy:oy, rot:(t.rot+0)%4},
      {ax:mx, ay:my, bx:bx, by:by, cx:nx, cy:ny, rot:(t.rot+1)%4},
      {ax:ox, ay:oy, bx:nx, by:ny, cx:cx, cy:cy, rot:(t.rot+2)%4},
      {ax:mx, ay:my, bx:nx, by:ny, cx:ox, cy:oy, rot:(t.rot+3)%4}
    ];
  }

  // --- Init ---
  function initLTromino() {
    tiles = [{x: 0, y: 0, rot: 0}];
    level = 0;
    fitCameraL();
  }

  function initTriangle() {
    var s = 300;
    tiles = [{ax: 0, ay: 0, bx: s*2, by: 0, cx: 0, cy: s, rot: 0}];
    level = 0;
    fitCameraT();
  }

  function init() {
    if (mode === 0) initLTromino();
    else initTriangle();
    updateHUD();
    draw();
  }

  function fitCameraL() {
    var gridSize = Math.pow(2, level + 1);
    var margin = 0.8;
    camScale = Math.min(W, H) * margin / gridSize;
    camX = W/2 - gridSize * camScale / 2;
    camY = H/2 - gridSize * camScale / 2;
  }

  function fitCameraT() {
    if (!tiles.length) return;
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (var i = 0; i < tiles.length; i++) {
      var t = tiles[i];
      minX = Math.min(minX, t.ax, t.bx, t.cx);
      minY = Math.min(minY, t.ay, t.by, t.cy);
      maxX = Math.max(maxX, t.ax, t.bx, t.cx);
      maxY = Math.max(maxY, t.ay, t.by, t.cy);
    }
    var tw = maxX - minX, th = maxY - minY;
    if (tw < 1) tw = 1;
    if (th < 1) th = 1;
    var margin = 0.75;
    camScale = Math.min(W * margin / tw, H * margin / th);
    camX = W/2 - (minX + tw/2) * camScale;
    camY = H/2 - (minY + th/2) * camScale;
  }

  // --- Inflate ---
  function inflate() {
    if (level >= maxLevel) return;
    var newTiles = [];
    if (mode === 0) {
      for (var i = 0; i < tiles.length; i++) {
        var subs = subdivideLTromino(tiles[i]);
        for (var j = 0; j < subs.length; j++) newTiles.push(subs[j]);
      }
    } else {
      for (var i = 0; i < tiles.length; i++) {
        var subs = subdivideTriangle(tiles[i]);
        for (var j = 0; j < subs.length; j++) newTiles.push(subs[j]);
      }
    }
    tiles = newTiles;
    level++;
    if (mode === 0) fitCameraL();
    else fitCameraT();
    updateHUD();
    SFX.play('grow');
    draw();
  }

  function deflate() {
    init();
    SFX.play('click');
  }

  // --- Draw ---
  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (mode === 0) drawLTromino();
    else drawTriangle();
  }

  function insetPoly(poly, gap) {
    var n = poly.length / 2;
    var out = [];
    for (var i = 0; i < n; i++) {
      var px = poly[i*2], py = poly[i*2+1];
      var cx = 0, cy = 0;
      for (var j = 0; j < n; j++) { cx += poly[j*2]; cy += poly[j*2+1]; }
      cx /= n; cy /= n;
      var dx = px - cx, dy = py - cy;
      var len = Math.sqrt(dx*dx + dy*dy);
      if (len > 0) { out.push(px - dx/len*gap, py - dy/len*gap); }
      else { out.push(px, py); }
    }
    return out;
  }

  function drawLTromino() {
    var cs = camScale;
    ctx.save();
    ctx.translate(camX, camY);

    var gap = Math.max(0.3, cs * 0.04);

    for (var i = 0; i < tiles.length; i++) {
      var t = tiles[i];
      var c = COLORS[t.rot];
      var poly = lTrominoPoly(t, cs);
      var ip = insetPoly(poly, gap);

      ctx.beginPath();
      ctx.moveTo(ip[0], ip[1]);
      for (var j = 2; j < ip.length; j += 2) ctx.lineTo(ip[j], ip[j+1]);
      ctx.closePath();

      ctx.fillStyle = 'hsla(' + c.h + ',' + c.s + '%,' + c.l + '%,0.85)';
      ctx.fill();
      ctx.strokeStyle = 'hsla(' + c.h + ',' + c.s + '%,' + (c.l + 15) + '%,0.4)';
      ctx.lineWidth = Math.max(0.3, cs * 0.015);
      ctx.stroke();
    }

    ctx.restore();
  }

  function drawTriangle() {
    ctx.save();
    ctx.translate(camX, camY);
    ctx.scale(camScale, camScale);

    for (var i = 0; i < tiles.length; i++) {
      var t = tiles[i];
      var c = COLORS[t.rot];

      ctx.beginPath();
      ctx.moveTo(t.ax, t.ay);
      ctx.lineTo(t.bx, t.by);
      ctx.lineTo(t.cx, t.cy);
      ctx.closePath();

      ctx.fillStyle = 'hsla(' + c.h + ',' + c.s + '%,' + c.l + '%,0.85)';
      ctx.fill();
      ctx.strokeStyle = 'hsla(' + c.h + ',' + c.s + '%,' + (c.l + 15) + '%,0.5)';
      ctx.lineWidth = 1 / camScale;
      ctx.stroke();
    }

    ctx.restore();
  }

  function updateHUD() {
    hudLevel.textContent = level;
    hudTiles.textContent = tiles.length;
  }

  // --- Interaction ---
  var dragging = false, dragStartX, dragStartY, dragX, dragY, didDrag;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) { deflate(); return; }
    if (e.button === 0) {
      dragging = true; didDrag = false;
      dragStartX = dragX = e.clientX;
      dragStartY = dragY = e.clientY;
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var dx = e.clientX - dragX, dy = e.clientY - dragY;
    if (Math.abs(e.clientX - dragStartX) > 4 || Math.abs(e.clientY - dragStartY) > 4) didDrag = true;
    camX += dx; camY += dy;
    dragX = e.clientX; dragY = e.clientY;
    draw();
  });

  C.addEventListener('mouseup', function(e) {
    if (e.button === 0 && dragging) {
      if (!didDrag) inflate();
      dragging = false;
    }
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var mx = e.clientX, my = e.clientY;
    var factor = e.deltaY < 0 ? 1.15 : 1/1.15;
    camX = mx - (mx - camX) * factor;
    camY = my - (my - camY) * factor;
    camScale *= factor;
    draw();
  }, {passive: false});

  // Touch
  var touchId = null, touchStartX, touchStartY, touchMoved;
  var pinchDist = 0;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      pinchDist = Math.sqrt(dx*dx + dy*dy);
      return;
    }
    if (e.touches.length === 1) {
      touchId = e.touches[0].identifier;
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      dragX = touchStartX; dragY = touchStartY;
      touchMoved = false;
    }
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var nd = Math.sqrt(dx*dx + dy*dy);
      if (pinchDist > 0) {
        var factor = nd / pinchDist;
        var mx = (e.touches[0].clientX + e.touches[1].clientX) / 2;
        var my = (e.touches[0].clientY + e.touches[1].clientY) / 2;
        camX = mx - (mx - camX) * factor;
        camY = my - (my - camY) * factor;
        camScale *= factor;
        draw();
      }
      pinchDist = nd;
      return;
    }
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        var tx = e.touches[i].clientX, ty = e.touches[i].clientY;
        var dx = tx - dragX, dy = ty - dragY;
        if (Math.abs(tx - touchStartX) > 8 || Math.abs(ty - touchStartY) > 8) touchMoved = true;
        camX += dx; camY += dy;
        dragX = tx; dragY = ty;
        draw();
        break;
      }
    }
  }, {passive: false});

  C.addEventListener('touchend', function(e) {
    if (e.touches.length < 2) pinchDist = 0;
    if (e.changedTouches.length === 1 && e.changedTouches[0].identifier === touchId) {
      if (!touchMoved) inflate();
      touchId = null;
    }
  });

  // --- Controls ---
  var btnLtro = document.getElementById('btn-ltro');
  var btnTri = document.getElementById('btn-tri');
  var btnAuto = document.getElementById('btn-auto');
  var btnReset = document.getElementById('btn-reset');

  btnLtro.addEventListener('click', function() {
    mode = 0; maxLevel = 8;
    btnLtro.classList.add('active'); btnTri.classList.remove('active');
    SFX.play('toggle');
    init();
  });
  btnTri.addEventListener('click', function() {
    mode = 1; maxLevel = 9;
    btnTri.classList.add('active'); btnLtro.classList.remove('active');
    SFX.play('toggle');
    init();
  });

  btnAuto.addEventListener('click', function() {
    autoOn = !autoOn;
    btnAuto.classList.toggle('active', autoOn);
    SFX.play('toggle');
    if (autoOn) startAuto();
    else stopAuto();
  });

  btnReset.addEventListener('click', function() {
    stopAuto();
    autoOn = false;
    btnAuto.classList.remove('active');
    init();
    SFX.play('click');
  });

  function startAuto() {
    stopAuto();
    autoTimer = setInterval(function() {
      if (level >= maxLevel) {
        init();
      } else {
        inflate();
      }
    }, 1800);
  }

  function stopAuto() {
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
  }

  // Start with auto on
  resize();
  init();
  autoOn = true;
  btnAuto.classList.add('active');
  startAuto();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
