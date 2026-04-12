<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('efield');

$pageName = 'efield';
$pageTitle = 'EFIELD - PULSE';
$pageDesc = '電場の可視化。点電荷を配置すると、電気力線が空間の構造を描き出す。';
$pageCanonical = 'https://pulse.subara3.com/efield.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
.hud { top: 3.2rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
.hud .v.neg { color: #7b2cbf; }
@media (max-width: 768px) {
  .hud { top: 2.8rem; right: 0.65rem; font-size: 0.54rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  CHARGES: <span id="hudCount" class="v">2</span><br>
  MODE: <span id="hudMode" class="v">+</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add +'],
  ['type' => 'right', 'label' => 'add −'],
  ['type' => 'drag', 'label' => 'move charge'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="chargeBtn">CHARGE: +</button>
    <button class="control-btn" id="clearBtn">CLEAR</button>
  </div>
  <div class="slider-wrap">
    <label>DENSITY</label>
    <input type="range" id="densitySlider" min="8" max="24" value="16">
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudCount = document.getElementById('hudCount');
  var hudMode = document.getElementById('hudMode');
  var chargeBtn = document.getElementById('chargeBtn');
  var clearBtn = document.getElementById('clearBtn');
  var densitySlider = document.getElementById('densitySlider');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0;
  var MAX_CHARGES = 8;
  var placePositive = true;

  var charges = [];
  var fieldLines = [];
  var needsRedraw = true;

  var dragging = null;
  var dragOffX = 0, dragOffY = 0;
  var pointerDown = false;
  var pointerStartX = 0, pointerStartY = 0;
  var pointerMoved = false;
  var longPressTimer = null;
  var longPressTriggered = false;

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    needsRedraw = true;
  }

  function addCharge(x, y, sign) {
    if (charges.length >= MAX_CHARGES) return;
    charges.push({ x: x, y: y, q: sign });
    updateHUD();
    computeFieldLines();
    needsRedraw = true;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function removeCharge(idx) {
    charges.splice(idx, 1);
    updateHUD();
    computeFieldLines();
    needsRedraw = true;
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }

  function updateHUD() {
    hudCount.textContent = charges.length;
    hudMode.textContent = placePositive ? '+' : '−';
    hudMode.className = placePositive ? 'v' : 'v neg';
    chargeBtn.textContent = 'CHARGE: ' + (placePositive ? '+' : '−');
  }

  function nearestCharge(px, py, threshold) {
    var best = -1, bestD = threshold * threshold;
    for (var i = 0; i < charges.length; i++) {
      var dx = px - charges[i].x;
      var dy = py - charges[i].y;
      var d2 = dx * dx + dy * dy;
      if (d2 < bestD) { bestD = d2; best = i; }
    }
    return best;
  }

  // Electric field at point (px, py)
  function eField(px, py) {
    var ex = 0, ey = 0;
    for (var i = 0; i < charges.length; i++) {
      var c = charges[i];
      var dx = px - c.x;
      var dy = py - c.y;
      var d2 = dx * dx + dy * dy;
      if (d2 < 4) d2 = 4; // avoid singularity
      var d = Math.sqrt(d2);
      var f = c.q / d2;
      ex += f * dx / d;
      ey += f * dy / d;
    }
    return { x: ex, y: ey };
  }

  // Potential at point
  function potential(px, py) {
    var v = 0;
    for (var i = 0; i < charges.length; i++) {
      var c = charges[i];
      var dx = px - c.x;
      var dy = py - c.y;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < 2) d = 2;
      v += c.q / d;
    }
    return v;
  }

  function computeFieldLines() {
    fieldLines = [];
    var density = parseInt(densitySlider.value, 10);
    var stepSize = 3;
    var maxSteps = 600;
    var margin = 30;

    for (var ci = 0; ci < charges.length; ci++) {
      var c = charges[ci];
      if (c.q <= 0) continue; // lines start from positive charges

      for (var li = 0; li < density; li++) {
        var angle = (li / density) * Math.PI * 2;
        var startR = 14;
        var sx = c.x + Math.cos(angle) * startR;
        var sy = c.y + Math.sin(angle) * startR;

        var line = [];
        var px = sx, py = sy;
        var terminated = false;

        for (var step = 0; step < maxSteps; step++) {
          line.push({ x: px, y: py });

          var e = eField(px, py);
          var mag = Math.sqrt(e.x * e.x + e.y * e.y);
          if (mag < 0.00001) break;

          var nx = px + (e.x / mag) * stepSize;
          var ny = py + (e.y / mag) * stepSize;

          // Check if we hit a negative charge
          for (var ni = 0; ni < charges.length; ni++) {
            if (charges[ni].q >= 0) continue;
            var ddx = nx - charges[ni].x;
            var ddy = ny - charges[ni].y;
            if (ddx * ddx + ddy * ddy < 144) { // radius 12
              line.push({ x: charges[ni].x, y: charges[ni].y });
              terminated = true;
              break;
            }
          }
          if (terminated) break;

          // Check bounds
          if (nx < -margin || nx > W + margin || ny < -margin || ny > H + margin) break;

          px = nx;
          py = ny;
        }

        if (line.length > 2) {
          fieldLines.push(line);
        }
      }
    }

    // Also draw lines going INTO negative charges that aren't sourced from positive
    // (for isolated negative charges, draw lines coming inward)
    var hasPositive = false;
    for (var i = 0; i < charges.length; i++) {
      if (charges[i].q > 0) { hasPositive = true; break; }
    }

    if (!hasPositive) {
      // All negative: draw lines coming inward from edges
      for (var ci = 0; ci < charges.length; ci++) {
        var c = charges[ci];
        for (var li = 0; li < density; li++) {
          var angle = (li / density) * Math.PI * 2;
          // Start from far away and trace inward (reverse field direction)
          var farR = Math.max(W, H) * 0.8;
          var sx = c.x + Math.cos(angle) * farR;
          var sy = c.y + Math.sin(angle) * farR;

          var line = [];
          var px = sx, py = sy;

          for (var step = 0; step < maxSteps; step++) {
            line.push({ x: px, y: py });

            var e = eField(px, py);
            var mag = Math.sqrt(e.x * e.x + e.y * e.y);
            if (mag < 0.00001) break;

            // Move against field (toward negative)
            var nx = px - (e.x / mag) * stepSize;
            var ny = py - (e.y / mag) * stepSize;

            var ddx = nx - c.x;
            var ddy = ny - c.y;
            if (ddx * ddx + ddy * ddy < 144) {
              line.push({ x: c.x, y: c.y });
              break;
            }

            px = nx;
            py = ny;
          }

          if (line.length > 2) {
            fieldLines.push(line);
          }
        }
      }
    }
  }

  function draw() {
    if (!needsRedraw) return;
    needsRedraw = false;

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Draw equipotential hints (faint background grid)
    drawEquipotentials();

    // Draw field lines
    drawFieldLines();

    // Draw charges
    drawCharges();
  }

  function drawEquipotentials() {
    if (charges.length === 0) return;
    var step = 18;
    var cols = Math.ceil(W / step) + 1;
    var rows = Math.ceil(H / step) + 1;
    var grid = new Float32Array(cols * rows);

    for (var r = 0; r < rows; r++) {
      for (var cc = 0; cc < cols; cc++) {
        grid[r * cols + cc] = potential(cc * step, r * step);
      }
    }

    // Find range
    var minV = Infinity, maxV = -Infinity;
    for (var i = 0; i < grid.length; i++) {
      var v = grid[i];
      if (isFinite(v)) {
        if (v < minV) minV = v;
        if (v > maxV) maxV = v;
      }
    }
    var span = maxV - minV;
    if (span < 0.001) return;

    // Draw a few contour levels
    var levels = 12;
    ctx.lineWidth = 0.6;

    for (var l = 0; l < levels; l++) {
      var t = (l + 1) / (levels + 1);
      var iso = minV + span * t;
      var alpha = 0.04 + 0.03 * Math.sin(t * Math.PI);
      ctx.strokeStyle = 'rgba(123,44,191,' + alpha + ')';

      for (var r = 0; r < rows - 1; r++) {
        for (var cc = 0; cc < cols - 1; cc++) {
          var i0 = r * cols + cc;
          var v0 = grid[i0], v1 = grid[i0 + 1];
          var v2 = grid[i0 + cols + 1], v3 = grid[i0 + cols];

          if (!isFinite(v0) || !isFinite(v1) || !isFinite(v2) || !isFinite(v3)) continue;

          var mask = (v0 > iso ? 1 : 0) | (v1 > iso ? 2 : 0) | (v2 > iso ? 4 : 0) | (v3 > iso ? 8 : 0);
          if (mask === 0 || mask === 15) continue;

          var px = cc * step, py = r * step;
          var top = lerp2(px, py, v0, px + step, py, v1, iso);
          var right = lerp2(px + step, py, v1, px + step, py + step, v2, iso);
          var bottom = lerp2(px, py + step, v3, px + step, py + step, v2, iso);
          var left = lerp2(px, py, v0, px, py + step, v3, iso);

          ctx.beginPath();
          switch (mask) {
            case 1: case 14: seg(left, top); break;
            case 2: case 13: seg(top, right); break;
            case 3: case 12: seg(left, right); break;
            case 4: case 11: seg(right, bottom); break;
            case 5: seg(left, top); seg(right, bottom); break;
            case 6: case 9: seg(top, bottom); break;
            case 7: case 8: seg(left, bottom); break;
            case 10: seg(top, right); seg(left, bottom); break;
          }
          ctx.stroke();
        }
      }
    }
  }

  function lerp2(x1, y1, v1, x2, y2, v2, iso) {
    var t = (iso - v1) / (v2 - v1 + 1e-9);
    return [x1 + (x2 - x1) * t, y1 + (y2 - y1) * t];
  }

  function seg(a, b) {
    ctx.moveTo(a[0], a[1]);
    ctx.lineTo(b[0], b[1]);
  }

  function drawFieldLines() {
    if (fieldLines.length === 0) return;

    ctx.lineWidth = 1.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    for (var fi = 0; fi < fieldLines.length; fi++) {
      var line = fieldLines[fi];
      if (line.length < 2) continue;

      ctx.beginPath();
      ctx.moveTo(line[0].x, line[0].y);

      for (var pi = 1; pi < line.length; pi++) {
        ctx.lineTo(line[pi].x, line[pi].y);
      }

      // Gradient along the line: teal near start (positive), purple near end
      var x0 = line[0].x, y0 = line[0].y;
      var xn = line[line.length - 1].x, yn = line[line.length - 1].y;
      var dx = xn - x0, dy = yn - y0;
      var dist = Math.sqrt(dx * dx + dy * dy);

      if (dist > 1) {
        var grad = ctx.createLinearGradient(x0, y0, xn, yn);
        grad.addColorStop(0, 'rgba(0,245,212,0.7)');
        grad.addColorStop(0.4, 'rgba(0,200,220,0.5)');
        grad.addColorStop(0.7, 'rgba(80,100,200,0.4)');
        grad.addColorStop(1, 'rgba(123,44,191,0.6)');
        ctx.strokeStyle = grad;
      } else {
        ctx.strokeStyle = 'rgba(0,245,212,0.5)';
      }

      ctx.stroke();

      // Draw arrowhead at midpoint
      if (line.length > 10) {
        var mi = Math.floor(line.length * 0.4);
        var ax = line[mi].x, ay = line[mi].y;
        var bx = line[mi + 1].x, by = line[mi + 1].y;
        var adx = bx - ax, ady = by - ay;
        var amag = Math.sqrt(adx * adx + ady * ady);
        if (amag > 0.5) {
          var ux = adx / amag, uy = ady / amag;
          var sz = 5;
          ctx.fillStyle = 'rgba(0,220,200,0.5)';
          ctx.beginPath();
          ctx.moveTo(ax + ux * sz, ay + uy * sz);
          ctx.lineTo(ax - ux * sz * 0.4 + uy * sz * 0.5, ay - uy * sz * 0.4 - ux * sz * 0.5);
          ctx.lineTo(ax - ux * sz * 0.4 - uy * sz * 0.5, ay - uy * sz * 0.4 + ux * sz * 0.5);
          ctx.closePath();
          ctx.fill();
        }
      }
    }
  }

  function drawCharges() {
    for (var i = 0; i < charges.length; i++) {
      var c = charges[i];
      var isPos = c.q > 0;
      var color = isPos ? '#00f5d4' : '#7b2cbf';
      var glowColor = isPos ? 'rgba(0,245,212,' : 'rgba(123,44,191,';

      // Glow
      var g = ctx.createRadialGradient(c.x, c.y, 0, c.x, c.y, 50);
      g.addColorStop(0, glowColor + '0.25)');
      g.addColorStop(0.5, glowColor + '0.06)');
      g.addColorStop(1, glowColor + '0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(c.x, c.y, 50, 0, Math.PI * 2);
      ctx.fill();

      // Circle
      ctx.beginPath();
      ctx.arc(c.x, c.y, 12, 0, Math.PI * 2);
      ctx.fillStyle = '#0a0a0f';
      ctx.fill();
      ctx.lineWidth = 2;
      ctx.strokeStyle = color;
      ctx.stroke();

      // Symbol
      ctx.fillStyle = color;
      ctx.font = 'bold 14px "Orbitron", sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(isPos ? '+' : '−', c.x, c.y + 1);
    }
  }

  // --- Interaction ---

  function getPointerPos(e) {
    var rect = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }

  canvas.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return; // handled by contextmenu
    var pos = getPointerPos(e);
    pointerDown = true;
    pointerStartX = pos.x;
    pointerStartY = pos.y;
    pointerMoved = false;
    longPressTriggered = false;

    // Check if clicking on existing charge for drag
    var idx = nearestCharge(pos.x, pos.y, 20);
    if (idx >= 0) {
      dragging = idx;
      dragOffX = charges[idx].x - pos.x;
      dragOffY = charges[idx].y - pos.y;
      canvas.setPointerCapture(e.pointerId);
      return;
    }

    // Long press timer for mobile drag
    longPressTimer = setTimeout(function() {
      var idx2 = nearestCharge(pos.x, pos.y, 40);
      if (idx2 >= 0) {
        dragging = idx2;
        dragOffX = charges[idx2].x - pos.x;
        dragOffY = charges[idx2].y - pos.y;
        longPressTriggered = true;
      }
    }, 400);
  });

  canvas.addEventListener('pointermove', function(e) {
    var pos = getPointerPos(e);
    if (dragging !== null && dragging < charges.length) {
      charges[dragging].x = pos.x + dragOffX;
      charges[dragging].y = pos.y + dragOffY;
      computeFieldLines();
      needsRedraw = true;
      pointerMoved = true;
      if (typeof SFX !== 'undefined') SFX.play('move', 80);
      return;
    }
    if (pointerDown) {
      var dx = pos.x - pointerStartX;
      var dy = pos.y - pointerStartY;
      if (dx * dx + dy * dy > 25) {
        pointerMoved = true;
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
      }
    }
  });

  canvas.addEventListener('pointerup', function(e) {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }

    if (dragging !== null) {
      dragging = null;
      pointerDown = false;
      return;
    }

    if (pointerDown && !pointerMoved && !longPressTriggered) {
      var pos = getPointerPos(e);
      // Left click: place charge in current mode
      addCharge(pos.x, pos.y, placePositive ? 1 : -1);
    }

    pointerDown = false;
    dragging = null;
  });

  canvas.addEventListener('pointercancel', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    pointerDown = false;
    dragging = null;
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var pos = getPointerPos(e);
    // Right-click: place negative charge
    addCharge(pos.x, pos.y, -1);
  });

  // --- Controls ---

  chargeBtn.addEventListener('click', function() {
    placePositive = !placePositive;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  clearBtn.addEventListener('click', function() {
    charges = [];
    fieldLines = [];
    needsRedraw = true;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  densitySlider.addEventListener('input', function() {
    computeFieldLines();
    needsRedraw = true;
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  window.addEventListener('resize', function() {
    resize();
    computeFieldLines();
  });

  // --- Init ---

  function seed() {
    var cx = W / 2;
    var cy = H / 2;
    var sep = Math.min(W, H) * 0.18;
    charges.push({ x: cx - sep, y: cy, q: 1 });
    charges.push({ x: cx + sep, y: cy, q: -1 });
    updateHUD();
    computeFieldLines();
  }

  // --- Loop ---

  function loop() {
    draw();
    requestAnimationFrame(loop);
  }

  resize();
  seed();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
