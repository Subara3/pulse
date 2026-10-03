<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('waveguide');

$pageName = 'waveguide';
$pageTitle = 'WAVEGUIDE - PULSE';
$pageDesc = '光の通り道を描いて、パルスを出口まで届ける。曲がりがきつい所では光が漏れる。届けた割合を競う。';
$pageCanonical = 'https://pulse.subara3.com/waveguide.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #goal-label{position:fixed;top:80px;right:20px;text-align:right;pointer-events:none;z-index:10;font:13px "Space Grotesk",monospace;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>SEGMENTS <span id="hud-seg">0</span></div>
  <div>PULSES <span id="hud-pulse">0</span></div>
  <div>DELIVERY <span id="hud-del">0</span>%</div>
</div>
<div id="goal-label"></div>
<div id="info-label"><span>WAVEGUIDE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'draw path'],
  ['type' => 'drag', 'label' => 'extend'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-mode="free">FREE</button>
    <button class="ctrl-btn" data-mode="goal">GOAL</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">WIDTH</label>
    <input type="range" id="sl-width" class="ctrl-slider" min="8" max="40" value="20">
    <span class="slider-val" id="val-width">20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">PULSE RATE</label>
    <input type="range" id="sl-rate" class="ctrl-slider" min="1" max="10" value="4">
    <span class="slider-val" id="val-rate">4</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
    <button class="ctrl-btn" id="btn-new-goal">NEW GOAL</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;

  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
  }
  resize();
  addEventListener('resize', resize);

  var guideWidth = 20;
  var pulseRate = 4;
  var mode = 'free';

  var paths = [];
  var drawing = false;
  var currentPath = [];

  var source = {x: 0, y: 0};
  var target = {x: 0, y: 0, r: 24};
  var goalActive = false;

  var pulses = [];
  var leaks = [];
  var totalSent = 0;
  var totalDelivered = 0;
  var time = 0;
  var emitTimer = 0;

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function hsl(h, s, l) {
    return 'hsl(' + h + ',' + s + '%,' + l + '%)';
  }

  function placeSourceAndTarget() {
    source.x = 60;
    source.y = H / 2;
    target.x = W - 60;
    target.y = H * (0.25 + Math.random() * 0.5);
  }

  function newGoal() {
    paths = [];
    pulses = [];
    leaks = [];
    totalSent = 0;
    totalDelivered = 0;
    placeSourceAndTarget();
    goalActive = true;
    updateGoalLabel();
    SFX.play('click');
  }

  function updateGoalLabel() {
    var el = document.getElementById('goal-label');
    if (mode === 'goal' && goalActive) {
      var pct = totalSent > 0 ? Math.round(100 * totalDelivered / totalSent) : 0;
      var c = pct >= 90 ? 'rgba(0,245,212,.8)' : 'rgba(123,44,191,.7)';
      el.innerHTML = '<div style="color:' + c + '">' + (pct >= 90 ? 'GOAL ✓' : 'GOAL: 90%') + '</div>';
      if (pct >= 90 && totalSent >= 3) if (window.PULSE) PULSE.clear();
    } else {
      el.innerHTML = '';
    }
  }

  function segmentNearestPoint(px, py, ax, ay, bx, by) {
    var dx = bx - ax, dy = by - ay;
    var len2 = dx * dx + dy * dy;
    if (len2 < 0.001) return {x: ax, y: ay, t: 0};
    var t = ((px - ax) * dx + (py - ay) * dy) / len2;
    t = Math.max(0, Math.min(1, t));
    return {x: ax + t * dx, y: ay + t * dy, t: t};
  }

  function findClosestSegment(px, py) {
    var best = null;
    var bestDist = Infinity;
    for (var i = 0; i < paths.length; i++) {
      var p = paths[i];
      for (var j = 0; j < p.length - 1; j++) {
        var np = segmentNearestPoint(px, py, p[j].x, p[j].y, p[j+1].x, p[j+1].y);
        var d = Math.hypot(np.x - px, np.y - py);
        if (d < bestDist) {
          bestDist = d;
          best = {pathIdx: i, segIdx: j, t: np.t, dist: d, x: np.x, y: np.y};
        }
      }
    }
    return best;
  }

  function pathLength(path) {
    var len = 0;
    for (var i = 0; i < path.length - 1; i++) {
      len += Math.hypot(path[i+1].x - path[i].x, path[i+1].y - path[i].y);
    }
    return len;
  }

  function pathPointAt(path, dist) {
    var acc = 0;
    for (var i = 0; i < path.length - 1; i++) {
      var segLen = Math.hypot(path[i+1].x - path[i].x, path[i+1].y - path[i].y);
      if (acc + segLen >= dist) {
        var t = segLen > 0 ? (dist - acc) / segLen : 0;
        var x = path[i].x + t * (path[i+1].x - path[i].x);
        var y = path[i].y + t * (path[i+1].y - path[i].y);
        var dx = path[i+1].x - path[i].x;
        var dy = path[i+1].y - path[i].y;
        return {x: x, y: y, dx: dx, dy: dy, segIdx: i};
      }
      acc += segLen;
    }
    var last = path[path.length - 1];
    return {x: last.x, y: last.y, dx: 0, dy: 1, segIdx: path.length - 2};
  }

  function curvatureAt(path, idx) {
    if (idx <= 0 || idx >= path.length - 1) return 0;
    var ax = path[idx-1].x, ay = path[idx-1].y;
    var bx = path[idx].x, by = path[idx].y;
    var cx = path[idx+1].x, cy = path[idx+1].y;
    var d1x = bx - ax, d1y = by - ay;
    var d2x = cx - bx, d2y = cy - by;
    var l1 = Math.hypot(d1x, d1y);
    var l2 = Math.hypot(d2x, d2y);
    if (l1 < 0.1 || l2 < 0.1) return 0;
    var cross = (d1x * d2y - d1y * d2x) / (l1 * l2);
    return Math.abs(cross);
  }

  function smoothPath(raw) {
    if (raw.length < 3) return raw;
    var out = [raw[0]];
    for (var i = 1; i < raw.length - 1; i++) {
      out.push({
        x: raw[i-1].x * 0.25 + raw[i].x * 0.5 + raw[i+1].x * 0.25,
        y: raw[i-1].y * 0.25 + raw[i].y * 0.5 + raw[i+1].y * 0.25
      });
    }
    out.push(raw[raw.length - 1]);
    return out;
  }

  function resamplePath(raw, spacing) {
    if (raw.length < 2) return raw;
    var totalLen = pathLength(raw);
    if (totalLen < spacing) return raw;
    var out = [raw[0]];
    var acc = 0;
    var segAcc = 0;
    var si = 0;
    var segLen = Math.hypot(raw[1].x - raw[0].x, raw[1].y - raw[0].y);
    for (var d = spacing; d < totalLen; d += spacing) {
      while (si < raw.length - 1 && acc + segLen < d) {
        acc += segLen;
        si++;
        if (si < raw.length - 1) segLen = Math.hypot(raw[si+1].x - raw[si].x, raw[si+1].y - raw[si].y);
      }
      if (si >= raw.length - 1) break;
      var t = segLen > 0 ? (d - acc) / segLen : 0;
      out.push({
        x: raw[si].x + t * (raw[si+1].x - raw[si].x),
        y: raw[si].y + t * (raw[si+1].y - raw[si].y)
      });
    }
    out.push(raw[raw.length - 1]);
    return out;
  }

  function emitPulse(pathIdx) {
    if (pathIdx < 0 || pathIdx >= paths.length) return;
    var p = paths[pathIdx];
    if (p.length < 2) return;
    var len = pathLength(p);
    pulses.push({
      pathIdx: pathIdx,
      dist: 0,
      totalLen: len,
      speed: 3,
      intensity: 1.0,
      trail: []
    });
    totalSent++;
    SFX.play('spawn');
  }

  function updatePulses(dt) {
    for (var i = pulses.length - 1; i >= 0; i--) {
      var pulse = pulses[i];
      var path = paths[pulse.pathIdx];
      if (!path) { pulses.splice(i, 1); continue; }

      pulse.dist += pulse.speed;
      var pt = pathPointAt(path, pulse.dist);

      var segIdx = pt.segIdx;
      if (segIdx >= 0 && segIdx < path.length) {
        var curv = curvatureAt(path, segIdx + 1);
        var leakRate = curv > 0.15 ? (curv - 0.15) * 2.5 : 0;
        pulse.intensity -= leakRate * 0.02;
        if (leakRate > 0.01) {
          var perpX = -pt.dy, perpY = pt.dx;
          var pLen = Math.hypot(perpX, perpY);
          if (pLen > 0) { perpX /= pLen; perpY /= pLen; }
          var side = (Math.random() > 0.5) ? 1 : -1;
          leaks.push({
            x: pt.x + perpX * side * guideWidth * 0.7,
            y: pt.y + perpY * side * guideWidth * 0.7,
            life: 1.0,
            intensity: leakRate * pulse.intensity * 0.6
          });
        }
      }

      pulse.trail.push({x: pt.x, y: pt.y, age: 0, intensity: pulse.intensity});
      if (pulse.trail.length > 30) pulse.trail.shift();
      for (var j = 0; j < pulse.trail.length; j++) pulse.trail[j].age += 0.04;

      if (pulse.intensity < 0.01) pulse.intensity = 0;

      if (pulse.dist >= pulse.totalLen) {
        var endPt = path[path.length - 1];
        if (mode === 'goal' && goalActive) {
          var dx = endPt.x - target.x, dy = endPt.y - target.y;
          if (Math.hypot(dx, dy) < target.r + guideWidth && pulse.intensity > 0.05) {
            totalDelivered += pulse.intensity;
            SFX.play('success');
          } else if (pulse.intensity > 0.05) {
            SFX.play('pop');
          }
          updateGoalLabel();
        }
        pulses.splice(i, 1);
      }
    }
  }

  function updateLeaks(dt) {
    for (var i = leaks.length - 1; i >= 0; i--) {
      leaks[i].life -= 0.02;
      if (leaks[i].life <= 0) leaks.splice(i, 1);
    }
  }

  function drawBackground() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    ctx.strokeStyle = 'rgba(0,245,212,0.03)';
    ctx.lineWidth = 1;
    var sp = 60;
    for (var x = sp; x < W; x += sp) {
      ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
    }
    for (var y = sp; y < H; y += sp) {
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
    }
  }

  function drawSource() {
    var glow = 0.5 + 0.3 * Math.sin(time * 3);
    ctx.beginPath();
    ctx.arc(source.x, source.y, 14, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,245,212,' + (0.3 + glow * 0.3) + ')';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(source.x, source.y, 8, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,245,212,' + (0.6 + glow * 0.3) + ')';
    ctx.fill();

    ctx.fillStyle = 'rgba(0,245,212,0.5)';
    ctx.font = '10px "Space Grotesk", monospace';
    ctx.textAlign = 'center';
    ctx.fillText('SOURCE', source.x, source.y - 22);
  }

  function drawTarget() {
    if (mode !== 'goal' || !goalActive) return;
    var glow = 0.5 + 0.3 * Math.sin(time * 2);
    ctx.beginPath();
    ctx.arc(target.x, target.y, target.r + 4, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(123,44,191,' + (0.3 + glow * 0.2) + ')';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(target.x, target.y, target.r, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(123,44,191,' + (0.15 + glow * 0.1) + ')';
    ctx.fill();

    ctx.fillStyle = 'rgba(181,140,255,0.6)';
    ctx.font = '10px "Space Grotesk", monospace';
    ctx.textAlign = 'center';
    ctx.fillText('TARGET', target.x, target.y - target.r - 10);
  }

  function drawPaths() {
    for (var i = 0; i < paths.length; i++) {
      var p = paths[i];
      if (p.length < 2) continue;
      ctx.save();
      ctx.lineWidth = guideWidth;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = 'rgba(0,245,212,0.06)';
      ctx.beginPath();
      ctx.moveTo(p[0].x, p[0].y);
      for (var j = 1; j < p.length; j++) ctx.lineTo(p[j].x, p[j].y);
      ctx.stroke();

      ctx.lineWidth = guideWidth + 2;
      ctx.strokeStyle = 'rgba(0,245,212,0.12)';
      ctx.setLineDash([2, 6]);
      ctx.beginPath();
      ctx.moveTo(p[0].x, p[0].y);
      for (var j = 1; j < p.length; j++) ctx.lineTo(p[j].x, p[j].y);
      ctx.stroke();
      ctx.setLineDash([]);

      for (var j = 1; j < p.length - 1; j++) {
        var curv = curvatureAt(p, j);
        if (curv > 0.12) {
          var alpha = Math.min((curv - 0.12) * 4, 0.5);
          ctx.beginPath();
          ctx.arc(p[j].x, p[j].y, guideWidth * 0.5 + 4, 0, Math.PI * 2);
          ctx.fillStyle = 'rgba(123,44,191,' + alpha + ')';
          ctx.fill();
        }
      }
      ctx.restore();
    }

    if (drawing && currentPath.length > 1) {
      ctx.save();
      ctx.lineWidth = guideWidth;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = 'rgba(0,245,212,0.1)';
      ctx.beginPath();
      ctx.moveTo(currentPath[0].x, currentPath[0].y);
      for (var j = 1; j < currentPath.length; j++) ctx.lineTo(currentPath[j].x, currentPath[j].y);
      ctx.stroke();
      ctx.restore();
    }
  }

  function drawPulses() {
    for (var i = 0; i < pulses.length; i++) {
      var pulse = pulses[i];
      var trail = pulse.trail;
      for (var j = 0; j < trail.length; j++) {
        var tp = trail[j];
        var a = (1 - tp.age) * tp.intensity;
        if (a <= 0) continue;
        var r = 4 + (1 - tp.age) * 4;
        var h = brandHue(1 - tp.intensity);
        ctx.beginPath();
        ctx.arc(tp.x, tp.y, r, 0, Math.PI * 2);
        ctx.fillStyle = 'hsla(' + h + ',80%,60%,' + (a * 0.7) + ')';
        ctx.fill();
      }

      if (trail.length > 0) {
        var head = trail[trail.length - 1];
        var ha = pulse.intensity;
        var hh = brandHue(1 - pulse.intensity);
        ctx.beginPath();
        ctx.arc(head.x, head.y, 6, 0, Math.PI * 2);
        ctx.fillStyle = 'hsla(' + hh + ',90%,65%,' + ha + ')';
        ctx.fill();
        ctx.beginPath();
        ctx.arc(head.x, head.y, 10, 0, Math.PI * 2);
        ctx.fillStyle = 'hsla(' + hh + ',90%,65%,' + (ha * 0.3) + ')';
        ctx.fill();
      }
    }
  }

  function drawLeaks() {
    for (var i = 0; i < leaks.length; i++) {
      var lk = leaks[i];
      var a = lk.life * lk.intensity;
      if (a <= 0) continue;
      var r = 6 + (1 - lk.life) * 12;
      ctx.beginPath();
      ctx.arc(lk.x, lk.y, r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(123,44,191,' + (a * 0.5) + ')';
      ctx.fill();
    }
  }

  function drawCurrentPath() {
    if (!drawing || currentPath.length < 2) return;
  }

  function updateHUD() {
    document.getElementById('hud-seg').textContent = paths.length;
    document.getElementById('hud-pulse').textContent = pulses.length;
    var pct = totalSent > 0 ? Math.round(100 * totalDelivered / totalSent) : 0;
    document.getElementById('hud-del').textContent = pct;
  }

  var pointer = {x: 0, y: 0, down: false};
  var longTimer = null;
  var touchId = null;

  function getXY(e) {
    if (e.touches) { return {x: e.touches[0].clientX, y: e.touches[0].clientY}; }
    return {x: e.clientX, y: e.clientY};
  }

  function isNearDrawerOrNav(x, y) {
    var els = document.querySelectorAll('.drawer-controls, .drawer-toggle, .floating-nav, .interaction-hints');
    for (var i = 0; i < els.length; i++) {
      var r = els[i].getBoundingClientRect();
      if (x >= r.left - 10 && x <= r.right + 10 && y >= r.top - 10 && y <= r.bottom + 10) return true;
    }
    return false;
  }

  function startDraw(x, y) {
    if (isNearDrawerOrNav(x, y)) return;
    drawing = true;
    currentPath = [{x: x, y: y}];
    SFX.play('tick');
  }

  function moveDraw(x, y) {
    if (!drawing) return;
    var last = currentPath[currentPath.length - 1];
    if (Math.hypot(x - last.x, y - last.y) > 6) {
      currentPath.push({x: x, y: y});
    }
  }

  function endDraw() {
    if (!drawing) return;
    drawing = false;
    if (currentPath.length > 2) {
      var resampled = resamplePath(currentPath, 8);
      var smoothed = smoothPath(smoothPath(resampled));
      paths.push(smoothed);
      SFX.play('spawn');
    }
    currentPath = [];
  }

  function erasePath(x, y) {
    var closest = findClosestSegment(x, y);
    if (closest && closest.dist < guideWidth * 1.5) {
      paths.splice(closest.pathIdx, 1);
      SFX.play('pop');
    }
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) { erasePath(e.clientX, e.clientY); return; }
    if (e.button !== 0) return;
    startDraw(e.clientX, e.clientY);
  });

  C.addEventListener('mousemove', function(e) {
    pointer.x = e.clientX;
    pointer.y = e.clientY;
    moveDraw(e.clientX, e.clientY);
  });

  C.addEventListener('mouseup', function(e) { endDraw(); });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length > 1) return;
    var t = e.touches[0];
    if (isNearDrawerOrNav(t.clientX, t.clientY)) return;
    e.preventDefault();
    touchId = t.identifier;
    pointer.x = t.clientX;
    pointer.y = t.clientY;
    longTimer = setTimeout(function() {
      erasePath(pointer.x, pointer.y);
      touchId = null;
    }, 500);
    startDraw(t.clientX, t.clientY);
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    var t = null;
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) { t = e.touches[i]; break; }
    }
    if (!t) return;
    e.preventDefault();
    clearTimeout(longTimer);
    pointer.x = t.clientX;
    pointer.y = t.clientY;
    moveDraw(t.clientX, t.clientY);
  }, {passive: false});

  C.addEventListener('touchend', function(e) {
    clearTimeout(longTimer);
    endDraw();
    touchId = null;
  });

  var modeBtns = document.querySelectorAll('[data-mode]');
  modeBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      modeBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      mode = btn.dataset.mode;
      if (mode === 'goal') newGoal();
      else { goalActive = false; updateGoalLabel(); }
      SFX.play('toggle');
    });
  });

  var slWidth = document.getElementById('sl-width');
  var valWidth = document.getElementById('val-width');
  slWidth.addEventListener('input', function() {
    guideWidth = parseInt(slWidth.value);
    valWidth.textContent = guideWidth;
  });

  var slRate = document.getElementById('sl-rate');
  var valRate = document.getElementById('val-rate');
  slRate.addEventListener('input', function() {
    pulseRate = parseInt(slRate.value);
    valRate.textContent = pulseRate;
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    paths = [];
    pulses = [];
    leaks = [];
    totalSent = 0;
    totalDelivered = 0;
    updateGoalLabel();
    SFX.play('click');
  });

  document.getElementById('btn-new-goal').addEventListener('click', function() {
    mode = 'goal';
    modeBtns.forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('[data-mode="goal"]').classList.add('active');
    newGoal();
  });

  function autoEmit() {
    if (paths.length === 0) return;
    emitTimer += 1 / 60;
    var interval = 1.0 / pulseRate;
    if (emitTimer >= interval) {
      emitTimer -= interval;
      var nearSource = -1;
      var nearDist = Infinity;
      for (var i = 0; i < paths.length; i++) {
        if (paths[i].length < 2) continue;
        var d = Math.hypot(paths[i][0].x - source.x, paths[i][0].y - source.y);
        if (d < nearDist) { nearDist = d; nearSource = i; }
      }
      if (nearSource >= 0 && nearDist < guideWidth * 4) {
        emitPulse(nearSource);
      }
    }
  }

  function createInitialPath() {
    placeSourceAndTarget();
    var pts = [];
    var steps = 20;
    for (var i = 0; i <= steps; i++) {
      var t = i / steps;
      var x = source.x + t * (W * 0.5 - source.x);
      var y = source.y + Math.sin(t * Math.PI * 2) * H * 0.12;
      pts.push({x: x, y: y});
    }
    var resampled = resamplePath(pts, 8);
    paths.push(smoothPath(smoothPath(resampled)));
  }

  createInitialPath();

  function loop() {
    time += 1 / 60;
    drawBackground();
    drawTarget();
    drawSource();
    drawPaths();
    drawLeaks();
    drawPulses();
    autoEmit();
    updatePulses(1 / 60);
    updateLeaks(1 / 60);
    updateHUD();
    requestAnimationFrame(loop);
  }

  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
