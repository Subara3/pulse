<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('monotile');

$pageName = 'monotile';
$pageTitle = 'MONOTILE - PULSE';
$pageDesc = '非周期的モノタイル。たった1枚の形が平面を周期なく埋め尽くす——アインシュタイン・タイルの可視化。';
$pageCanonical = 'https://pulse.subara3.com/monotile.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4}
.hud .sub{color:rgba(123,44,191,.9)}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  TILES <b id="h-count">0</b><br>
  DEPTH <b id="h-depth">3</b><br>
  MODE <b id="h-mode">FILL</b><br>
  <span class="sub">ZOOM</span> <b id="h-zoom">1.00</b>
</div>

<div id="info">
  aperiodic monotile<br>
  <span class="v">hat / einstein tile</span><br>
  single shape, no period
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'scroll', 'label' => 'zoom'],
  ['type' => 'click', 'label' => 'highlight'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btnFill">FILL</button>
      <button class="control-btn" id="btnWire">WIRE</button>
      <button class="control-btn" id="btnGlow">GLOW</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">DEPTH</span>
      <input type="range" id="sldDepth" min="1" max="5" value="3">
      <span class="slider-value" id="valDepth">3</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">HUE SHIFT</span>
      <input type="range" id="sldHue" min="0" max="100" value="0">
      <span class="slider-value" id="valHue">0</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET VIEW</button>
      <button class="control-btn active" id="btnSpin">SPIN</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
  function brandHue(t){t=t<0?0:t>1?1:t;var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);return 172+e*118}
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var panX = 0, panY = 0, zoom = 1, spinning = true, spinAngle = 0;
  var mode = 0; // 0=fill, 1=wire, 2=glow
  var depth = 3, hueShift = 0;
  var tiles = [];
  var highlightTile = -1;
  var needRebuild = true;
  var PI = Math.PI, TAU = PI * 2;
  var sqrt3 = Math.sqrt(3);

  // Hat tile vertices on a kite grid
  // The "hat" polykite is 13-sided, defined on a triangular/kite grid
  // Using the canonical hat shape coordinates
  var hatBase = [
    [0, 0],
    [1, 0],
    [1.5, sqrt3/2],
    [1, sqrt3],
    [1.5, 3*sqrt3/2],
    [1, 2*sqrt3],
    [0, 2*sqrt3],
    [-0.5, 3*sqrt3/2],
    [-1, sqrt3],
    [-1.5, 3*sqrt3/2],
    [-2, sqrt3],
    [-1.5, sqrt3/2],
    [-1, 0]
  ];

  // Center the hat
  var cx0 = 0, cy0 = 0;
  for (var i = 0; i < hatBase.length; i++) {
    cx0 += hatBase[i][0]; cy0 += hatBase[i][1];
  }
  cx0 /= hatBase.length; cy0 /= hatBase.length;
  for (var i = 0; i < hatBase.length; i++) {
    hatBase[i][0] -= cx0; hatBase[i][1] -= cy0;
  }

  function transformPoly(poly, tx, ty, angle, sc) {
    var cos = Math.cos(angle) * sc, sin = Math.sin(angle) * sc;
    var out = [];
    for (var i = 0; i < poly.length; i++) {
      out.push([
        poly[i][0] * cos - poly[i][1] * sin + tx,
        poly[i][0] * sin + poly[i][1] * cos + ty
      ]);
    }
    return out;
  }

  function reflectPoly(poly) {
    var out = [];
    for (var i = 0; i < poly.length; i++) {
      out.push([-poly[i][0], poly[i][1]]);
    }
    return out;
  }

  // Build an aperiodic tiling using substitution rules for hat metatiles
  // Simplified approach: place hats in a hierarchical cluster pattern
  // that produces aperiodic coverage

  // H-cluster: 4 hats forming a larger shape
  function buildTiling(cx, cy, sc, d, angle) {
    if (d <= 0) {
      tiles.push(transformPoly(hatBase, cx, cy, angle, sc));
      return;
    }

    var ns = sc * 0.38;
    var r = sc * 3.2;

    // Place sub-clusters in a rotationally varied pattern
    // Using 7-fold arrangement inspired by the hat substitution
    var positions = [
      [0, 0, 0],
      [r, 0, PI/6],
      [r * Math.cos(TAU/7), r * Math.sin(TAU/7), PI/3],
      [r * Math.cos(2*TAU/7), r * Math.sin(2*TAU/7), PI/2],
      [r * Math.cos(3*TAU/7), r * Math.sin(3*TAU/7), 2*PI/3],
      [r * Math.cos(4*TAU/7), r * Math.sin(4*TAU/7), 5*PI/6],
      [r * Math.cos(5*TAU/7), r * Math.sin(5*TAU/7), PI],
      [r * Math.cos(6*TAU/7), r * Math.sin(6*TAU/7), 7*PI/6]
    ];

    // Add reflected hats for aperiodicity
    var r2 = r * 0.62;
    var extraPositions = [
      [r2 * Math.cos(PI/14), r2 * Math.sin(PI/14), -PI/4],
      [r2 * Math.cos(PI/14 + TAU/7), r2 * Math.sin(PI/14 + TAU/7), -PI/12],
      [r2 * Math.cos(PI/14 + 2*TAU/7), r2 * Math.sin(PI/14 + 2*TAU/7), PI/7],
      [r2 * Math.cos(PI/14 + 3*TAU/7), r2 * Math.sin(PI/14 + 3*TAU/7), 3*PI/7],
      [r2 * Math.cos(PI/14 + 4*TAU/7), r2 * Math.sin(PI/14 + 4*TAU/7), 5*PI/7],
      [r2 * Math.cos(PI/14 + 5*TAU/7), r2 * Math.sin(PI/14 + 5*TAU/7), PI],
      [r2 * Math.cos(PI/14 + 6*TAU/7), r2 * Math.sin(PI/14 + 6*TAU/7), 9*PI/7]
    ];

    var cos = Math.cos(angle), sin = Math.sin(angle);
    for (var i = 0; i < positions.length; i++) {
      var p = positions[i];
      var rx = p[0] * cos - p[1] * sin + cx;
      var ry = p[0] * sin + p[1] * cos + cy;
      buildTiling(rx, ry, ns, d - 1, angle + p[2]);
    }
    for (var i = 0; i < extraPositions.length; i++) {
      var p = extraPositions[i];
      var rx = p[0] * cos - p[1] * sin + cx;
      var ry = p[0] * sin + p[1] * cos + cy;
      if (d <= 1) {
        tiles.push(transformPoly(reflectPoly(hatBase), rx, ry, angle + p[2], ns));
      } else {
        buildTiling(rx, ry, ns, d - 1, angle + p[2]);
      }
    }
  }

  function rebuild() {
    tiles = [];
    var sc = Math.min(W, H) / (dpr * 4);
    buildTiling(W / (2 * dpr), H / (2 * dpr), sc * 0.08 * Math.pow(2.6, depth), depth, 0);
    needRebuild = false;
  }

  function resize() {
    dpr = Math.min(2, window.devicePixelRatio || 1);
    W = Math.floor(innerWidth * dpr);
    H = Math.floor(innerHeight * dpr);
    C.width = W; C.height = H;
    C.style.width = innerWidth + 'px';
    C.style.height = innerHeight + 'px';
    needRebuild = true;
  }

  function polyCenter(poly) {
    var x = 0, y = 0;
    for (var i = 0; i < poly.length; i++) { x += poly[i][0]; y += poly[i][1]; }
    return [x / poly.length, y / poly.length];
  }

  function pointInPoly(px, py, poly) {
    var inside = false;
    for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      var xi = poly[i][0], yi = poly[i][1];
      var xj = poly[j][0], yj = poly[j][1];
      if ((yi > py) !== (yj > py) && px < (xj - xi) * (py - yi) / (yj - yi) + xi)
        inside = !inside;
    }
    return inside;
  }

  function tileColor(idx, alpha) {
    var hBase = (idx * 137.508 + hueShift * 3.6) % 360;
    // Clamp to allowed hue ranges: 165-295
    var h = brandHue((hBase % 130) / 130);
    var s = 55 + (idx * 7) % 20;
    var l = 35 + (idx * 3) % 20;
    return 'hsla(' + h + ',' + s + '%,' + l + '%,' + alpha + ')';
  }

  function draw() {
    if (needRebuild) rebuild();

    ctx.save();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, innerWidth, innerHeight);

    if (spinning) spinAngle += 0.0008;

    var cx = innerWidth / 2, cy = innerHeight / 2;
    ctx.translate(cx + panX, cy + panY);
    ctx.scale(zoom, zoom);
    ctx.rotate(spinAngle);
    ctx.translate(-cx, -cy);

    ctx.lineWidth = 1 / zoom;

    for (var i = 0; i < tiles.length; i++) {
      var poly = tiles[i];
      ctx.beginPath();
      ctx.moveTo(poly[0][0], poly[0][1]);
      for (var j = 1; j < poly.length; j++) {
        ctx.lineTo(poly[j][0], poly[j][1]);
      }
      ctx.closePath();

      if (mode === 0) {
        // Fill mode
        var alpha = i === highlightTile ? 0.6 : 0.25;
        ctx.fillStyle = tileColor(i, alpha);
        ctx.fill();
        ctx.strokeStyle = i === highlightTile ? '#00f5d4' : 'rgba(0,245,212,0.3)';
        ctx.stroke();
      } else if (mode === 1) {
        // Wire mode
        ctx.strokeStyle = i === highlightTile ? '#00f5d4' : tileColor(i, 0.55);
        ctx.lineWidth = i === highlightTile ? 2 / zoom : 0.8 / zoom;
        ctx.stroke();
      } else {
        // Glow mode
        var c = polyCenter(poly);
        var dist = Math.sqrt((c[0] - cx) * (c[0] - cx) + (c[1] - cy) * (c[1] - cy));
        var pulse = 0.15 + 0.2 * Math.sin(dist * 0.01 - spinAngle * 20);
        ctx.fillStyle = tileColor(i, pulse + (i === highlightTile ? 0.3 : 0));
        ctx.fill();
        ctx.strokeStyle = 'rgba(0,245,212,' + (0.15 + pulse * 0.3) + ')';
        ctx.lineWidth = 0.6 / zoom;
        ctx.stroke();
      }
    }

    ctx.restore();

    // HUD update
    document.getElementById('h-count').textContent = tiles.length;
    document.getElementById('h-depth').textContent = depth;
    document.getElementById('h-mode').textContent = ['FILL','WIRE','GLOW'][mode];
    document.getElementById('h-zoom').textContent = zoom.toFixed(2);

    requestAnimationFrame(draw);
  }

  // Interaction
  var dragging = false, lastX, lastY;

  C.addEventListener('pointerdown', function(e) {
    dragging = true;
    lastX = e.clientX; lastY = e.clientY;
  });

  C.addEventListener('pointermove', function(e) {
    if (!dragging) return;
    var dx = e.clientX - lastX, dy = e.clientY - lastY;
    panX += dx; panY += dy;
    lastX = e.clientX; lastY = e.clientY;
    if (window.SFX) SFX.play('move', 120);
  });

  C.addEventListener('pointerup', function(e) {
    if (dragging && Math.abs(e.clientX - lastX) < 3 && Math.abs(e.clientY - lastY) < 3) {
      // Click - find tile under cursor
      var mx = (e.clientX - panX - innerWidth / 2) / zoom + innerWidth / 2;
      var my = (e.clientY - panY - innerHeight / 2) / zoom + innerHeight / 2;
      // Account for spin rotation
      var ca = Math.cos(-spinAngle), sa = Math.sin(-spinAngle);
      var cx = innerWidth / 2, cy = innerHeight / 2;
      var rx = (mx - cx) * ca - (my - cy) * sa + cx;
      var ry = (mx - cx) * sa + (my - cy) * ca + cy;

      highlightTile = -1;
      for (var i = tiles.length - 1; i >= 0; i--) {
        if (pointInPoly(rx, ry, tiles[i])) {
          highlightTile = i;
          if (window.SFX) SFX.play('spawn');
          break;
        }
      }
      if (highlightTile === -1 && window.SFX) SFX.play('tick');
    }
    dragging = false;
  });

  C.addEventListener('pointerleave', function() { dragging = false; });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var factor = e.deltaY > 0 ? 0.9 : 1.1;
    var oldZoom = zoom;
    zoom = Math.max(0.1, Math.min(20, zoom * factor));
    // Zoom toward cursor
    var mx = e.clientX - innerWidth / 2;
    var my = e.clientY - innerHeight / 2;
    panX = mx - (mx - panX) * (zoom / oldZoom);
    panY = my - (my - panY) * (zoom / oldZoom);
    if (window.SFX) SFX.play('tick', 80);
  }, { passive: false });

  // Touch pinch zoom
  var lastDist = 0;
  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 2) {
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      lastDist = Math.sqrt(dx * dx + dy * dy);
    }
  }, { passive: true });

  C.addEventListener('touchmove', function(e) {
    if (e.touches.length === 2) {
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      var dist = Math.sqrt(dx * dx + dy * dy);
      if (lastDist > 0) {
        zoom = Math.max(0.1, Math.min(20, zoom * (dist / lastDist)));
      }
      lastDist = dist;
    }
  }, { passive: true });

  // Controls
  var modeNames = ['FILL', 'WIRE', 'GLOW'];
  var modeBtns = [document.getElementById('btnFill'), document.getElementById('btnWire'), document.getElementById('btnGlow')];
  modeBtns.forEach(function(btn, i) {
    btn.addEventListener('click', function() {
      mode = i;
      modeBtns.forEach(function(b, j) { b.classList.toggle('active', j === i); });
      if (window.SFX) SFX.play('toggle');
    });
  });

  document.getElementById('sldDepth').addEventListener('input', function() {
    document.getElementById('valDepth').textContent = this.value;
  });
  document.getElementById('sldDepth').addEventListener('change', function() {
    depth = parseInt(this.value);
    needRebuild = true;
    highlightTile = -1;
    if (window.SFX) SFX.play('toggle');
  });

  document.getElementById('sldHue').addEventListener('input', function() {
    hueShift = parseInt(this.value);
    document.getElementById('valHue').textContent = this.value;
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    panX = 0; panY = 0; zoom = 1; spinAngle = 0;
    highlightTile = -1;
    if (window.SFX) SFX.play('click');
  });

  document.getElementById('btnSpin').addEventListener('click', function() {
    spinning = !spinning;
    this.classList.toggle('active', spinning);
    if (window.SFX) SFX.play('toggle');
  });

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
