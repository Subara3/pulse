<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('brachistochrone');

$pageName = 'brachistochrone';
$pageTitle = 'BRACHISTOCHRONE - PULSE';
$pageDesc = '最速降下曲線。重力下で最も速く降りる経路はサイクロイド。直線・放物線・円弧と競争させて体験する。';
$pageCanonical = 'https://pulse.subara3.com/brachistochrone.php';
$extraStyle = '
#hud {
  position: fixed; top: 16px; left: 16px; z-index: 100;
  font-family: var(--font-mono, "JetBrains Mono", monospace);
  font-size: 0.65rem; color: rgba(255,255,255,0.56);
  pointer-events: none; line-height: 1.6;
}
#hud span { color: #00f5d4; }
@media (max-width: 768px) {
  #hud { top: 60px; font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  MODE <span id="hud-mode">RACE</span><br>
  HEIGHT <span id="hud-h">300</span>px<br>
  WINNER <span id="hud-winner">—</span><br>
  YOU <span id="hud-you">draw a curve</span><br>
  BEST <span id="hud-best">—</span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'start race'],
  ['type' => 'drag', 'label' => 'draw curve'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="mode-btn active" data-mode="race">RACE</button>
      <button class="mode-btn" data-mode="draw">DRAW</button>
      <button class="mode-btn" data-mode="explore">EXPLORE</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="ctrl-label">HEIGHT</label>
      <input type="range" id="sl-height" min="100" max="500" value="300">
      <span class="slider-value" id="sv-height">300</span>
    </div>
    <div class="slider-row">
      <label class="ctrl-label">SPEED</label>
      <input type="range" id="sl-speed" min="1" max="10" value="5">
      <span class="slider-value" id="sv-speed">5</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-go">GO</button>
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W, H, dpr;
  var g = 9.81 * 100;
  var mode = 'race';
  var raceHeight = 300;
  var speedMul = 5;
  var racing = false;
  var raceTime = 0;
  var winner = '';
  var finished = {};

  var teal = [0, 245, 212];
  var purple = [123, 44, 191];
  var white = [255, 255, 255];

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    var k = function(n) { return (n + h / 30) % 12; };
    var a = s * Math.min(l, 1 - l);
    var f = function(n) { return l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)); };
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  var curveColors = {
    cycloid: hslToRgb(175, 80, 55),
    line: hslToRgb(195, 60, 50),
    parabola: hslToRgb(270, 60, 55),
    circle: hslToRgb(285, 55, 50),
    custom: hslToRgb(180, 70, 60)
  };

  var curveNames = {
    cycloid: 'CYCLOID',
    line: 'LINE',
    parabola: 'PARABOLA',
    circle: 'ARC',
    custom: 'CUSTOM'
  };

  var startX, startY, endX, endY;
  var curves = {};
  var balls = {};
  var customPoints = [];
  var customCurve = null;
  var drawingCustom = false;

  function computeLayout() {
    var margin = Math.min(W * 0.1, 80);
    startX = margin + 40;
    startY = H * (W / dpr < 600 ? 0.24 : 0.15);
    endX = W - margin - 40;
    endY = startY + raceHeight;
  }

  // 始点と終点を通るサイクロイド（= 最速降下線）。
  // x/y の比を合わせるために片方だけ伸ばすと最速ではなくなるので、
  // (θ - sinθ)/(1 - cosθ) = dx/dy を満たす終端角 θ を二分法で求める
  function cycloidCurve(n) {
    var dx = endX - startX;
    var dy = endY - startY;
    var ratio = dx / dy;
    var lo = 1e-4, hi = 2 * Math.PI - 1e-4;
    for (var k = 0; k < 60; k++) {
      var mid = (lo + hi) / 2;
      if ((mid - Math.sin(mid)) / (1 - Math.cos(mid)) < ratio) lo = mid; else hi = mid;
    }
    var totalAngle = (lo + hi) / 2;
    var r = dy / (1 - Math.cos(totalAngle));
    var pts = [];
    for (var i = 0; i <= n; i++) {
      var t = (i / n) * totalAngle;
      pts.push({ x: startX + r * (t - Math.sin(t)), y: startY + r * (1 - Math.cos(t)) });
    }
    return pts;
  }

  function lineCurve(n) {
    var pts = [];
    for (var i = 0; i <= n; i++) {
      var t = i / n;
      pts.push({ x: startX + (endX - startX) * t, y: startY + (endY - startY) * t });
    }
    return pts;
  }

  function parabolaCurve(n) {
    var pts = [];
    for (var i = 0; i <= n; i++) {
      var t = i / n;
      pts.push({ x: startX + (endX - startX) * t, y: startY + (endY - startY) * t * t });
    }
    return pts;
  }

  function circleCurve(n) {
    var dx = endX - startX;
    var dy = endY - startY;
    var R = (dx * dx + dy * dy) / (2 * dy);
    var cx = startX;
    var cy = startY + R;
    var startAngle = -Math.PI / 2;
    var endAngle = Math.atan2(endY - cy, endX - cx);
    var pts = [];
    for (var i = 0; i <= n; i++) {
      var t = i / n;
      var angle = startAngle + (endAngle - startAngle) * t;
      pts.push({ x: cx + R * Math.cos(angle), y: cy + R * Math.sin(angle) });
    }
    return pts;
  }

  function buildCurves() {
    var n = 200;
    curves.cycloid = cycloidCurve(n);
    curves.line = lineCurve(n);
    curves.parabola = parabolaCurve(n);
    curves.circle = circleCurve(n);
    if (customCurve && customCurve.length > 2) {
      curves.custom = customCurve;
    }
  }

  function curveLength(pts) {
    var len = 0;
    for (var i = 1; i < pts.length; i++) {
      var dx = pts[i].x - pts[i - 1].x;
      var dy = pts[i].y - pts[i - 1].y;
      len += Math.sqrt(dx * dx + dy * dy);
    }
    return len;
  }

  function initBalls() {
    balls = {};
    finished = {};
    winner = '';
    document.getElementById('hud-winner').textContent = '—';
    var keys = Object.keys(curves);
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      balls[k] = {
        dist: 0,
        speed: 0,
        done: false,
        time: 0,
        pos: { x: curves[k][0].x, y: curves[k][0].y }
      };
    }
  }

  function updateBall(key, dt) {
    var ball = balls[key];
    if (ball.done) return;
    var pts = curves[key];
    var totalLen = curveLength(pts);
    var segLens = [];
    var cumLen = [0];
    for (var i = 1; i < pts.length; i++) {
      var dx = pts[i].x - pts[i - 1].x;
      var dy = pts[i].y - pts[i - 1].y;
      var sl = Math.sqrt(dx * dx + dy * dy);
      segLens.push(sl);
      cumLen.push(cumLen[cumLen.length - 1] + sl);
    }

    var segIdx = 0;
    for (var i = 1; i < cumLen.length; i++) {
      if (cumLen[i] >= ball.dist) { segIdx = i - 1; break; }
      if (i === cumLen.length - 1) segIdx = i - 1;
    }

    var p0 = pts[segIdx];
    var p1 = pts[Math.min(segIdx + 1, pts.length - 1)];
    var dx = p1.x - p0.x;
    var dy = p1.y - p0.y;
    var segLen = Math.sqrt(dx * dx + dy * dy);
    var sinA = segLen > 0.01 ? dy / segLen : 0;

    // 摩擦なしなので速さは落ちた高さだけで決まる（エネルギー保存）。
    // 出発直後の v=0 で止まらないよう、ごく小さな初速を与える
    var drop = ball.pos.y - startY;
    ball.speed = Math.sqrt(2 * g * Math.max(drop, 0)) + (drop > -1 ? 4 : 0);
    ball.dist += ball.speed * dt;
    ball.time += dt;

    // 登り坂で止まった（出発点より高く描いた）ら失格。止まったまま待ち続けない
    if (ball.speed < 1 && ball.time > 0.5) {
      ball.stall = (ball.stall || 0) + dt;
      if (ball.stall > 0.6) {
        ball.done = true;
        ball.dnf = true;
        finished[key] = Infinity;
        if (typeof SFX !== 'undefined') SFX.play('fail');
        return;
      }
    } else {
      ball.stall = 0;
    }

    if (ball.dist >= totalLen) {
      ball.dist = totalLen;
      ball.done = true;
      finished[key] = ball.time;
      if (!winner) {
        winner = key;
        document.getElementById('hud-winner').textContent = curveNames[key];
        if (typeof SFX !== 'undefined') SFX.play('success');
      }
    }

    var frac = ball.dist / totalLen;
    var targetDist = ball.dist;
    for (var i = 1; i < cumLen.length; i++) {
      if (cumLen[i] >= targetDist) {
        var prev = cumLen[i - 1];
        var t = segLens[i - 1] > 0 ? (targetDist - prev) / segLens[i - 1] : 0;
        ball.pos = {
          x: pts[i - 1].x + (pts[i].x - pts[i - 1].x) * t,
          y: pts[i - 1].y + (pts[i].y - pts[i - 1].y) * t
        };
        break;
      }
    }
  }

  function drawCurve(pts, color, lineWidth) {
    ctx.beginPath();
    ctx.moveTo(pts[0].x, pts[0].y);
    for (var i = 1; i < pts.length; i++) {
      ctx.lineTo(pts[i].x, pts[i].y);
    }
    ctx.strokeStyle = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.6)';
    ctx.lineWidth = lineWidth || 2;
    ctx.stroke();
  }

  function drawGlowCurve(pts, color) {
    ctx.save();
    ctx.shadowColor = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.4)';
    ctx.shadowBlur = 8;
    drawCurve(pts, color, 2);
    ctx.restore();
    drawCurve(pts, color, 1.5);
  }

  function drawBall(pos, color, radius) {
    ctx.beginPath();
    ctx.arc(pos.x, pos.y, radius || 8, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.9)';
    ctx.fill();
    ctx.save();
    ctx.shadowColor = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.6)';
    ctx.shadowBlur = 12;
    ctx.beginPath();
    ctx.arc(pos.x, pos.y, radius || 8, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }

  function drawTrail(pts, dist, color) {
    var totalLen = curveLength(pts);
    var frac = dist / totalLen;
    var count = Math.floor(frac * pts.length);
    if (count < 2) return;
    ctx.beginPath();
    ctx.moveTo(pts[0].x, pts[0].y);
    for (var i = 1; i < count && i < pts.length; i++) {
      ctx.lineTo(pts[i].x, pts[i].y);
    }
    ctx.strokeStyle = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.15)';
    ctx.lineWidth = 6;
    ctx.stroke();
  }

  function drawLabel(text, x, y, color) {
    ctx.font = '0.6rem "Space Grotesk", sans-serif';
    ctx.fillStyle = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',0.7)';
    ctx.textAlign = 'left';
    ctx.fillText(text, x, y);
  }

  function drawTimerLabel(key, x, y, color) {
    if (!balls[key]) return;
    var t = balls[key].time;
    var text = (curveNames[key] || key.toUpperCase()) + '  ' + (balls[key].dnf ? 'STALLED' : t.toFixed(2) + 's');
    if (key === winner) text += ' ★';
    ctx.font = '0.55rem "JetBrains Mono", monospace';
    ctx.fillStyle = 'rgba(' + color[0] + ',' + color[1] + ',' + color[2] + ',' + (balls[key].done ? 0.9 : 0.5) + ')';
    ctx.textAlign = 'right';
    ctx.fillText(text, x, y);
  }

  function drawStartEnd() {
    ctx.beginPath();
    ctx.arc(startX, startY, 5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.3)';
    ctx.fill();

    ctx.beginPath();
    ctx.arc(endX, endY, 5, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,245,212,0.3)';
    ctx.fill();

    ctx.setLineDash([4, 4]);
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(startX, startY);
    ctx.lineTo(endX, startY);
    ctx.lineTo(endX, endY);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.font = '0.5rem "Space Grotesk", sans-serif';
    ctx.fillStyle = 'rgba(255,255,255,0.2)';
    ctx.textAlign = 'center';
    ctx.fillText('START', startX, startY - 12);
    ctx.fillText('END', endX, endY + 20);

    var hLabel = raceHeight.toFixed(0) + 'px';
    ctx.save();
    ctx.translate(endX + 16, (startY + endY) / 2);
    ctx.rotate(Math.PI / 2);
    ctx.fillText('h=' + hLabel, 0, 0);
    ctx.restore();
  }

  function drawExploreMode() {
    var n = 100;
    var steps = 60;
    var cellW = (W - 120) / steps;
    var cellH = (H - 160) / n;
    var ox = 60;
    var oy = 80;

    ctx.font = '0.5rem "Space Grotesk", sans-serif';
    ctx.fillStyle = 'rgba(255,255,255,0.3)';
    ctx.textAlign = 'center';
    ctx.fillText('BRACHISTOCHRONE TIME MAP', W / 2, 30);
    ctx.fillText('HEIGHT →', W / 2, oy - 10);

    ctx.save();
    ctx.translate(20, H / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('WIDTH →', 0, 0);
    ctx.restore();

    for (var hi = 0; hi < steps; hi++) {
      for (var wi = 0; wi < n; wi++) {
        var height = 50 + hi * 8;
        var width = 50 + wi * 6;
        var r = height / Math.PI;
        var cycloidTime = Math.sqrt(r / (g / 100)) * Math.PI;
        var lineLen = Math.sqrt(width * width + height * height);
        var lineTime = Math.sqrt(2 * lineLen / (g / 100 * height / lineLen));
        var ratio = cycloidTime / lineTime;
        var t = Math.max(0, Math.min(1, (ratio - 0.5) / 0.5));
        var hue = brandHue(t);
        var rgb = hslToRgb(hue, 70, 35 + t * 20);
        ctx.fillStyle = 'rgb(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ')';
        ctx.fillRect(ox + hi * cellW, oy + wi * cellH, cellW + 0.5, cellH + 0.5);
      }
    }

    ctx.font = '0.45rem "JetBrains Mono", monospace';
    ctx.fillStyle = 'rgba(0,245,212,0.5)';
    ctx.textAlign = 'left';
    ctx.fillText('TEAL = CYCLOID MUCH FASTER', ox, H - 30);
    ctx.fillStyle = 'rgba(123,44,191,0.5)';
    ctx.fillText('PURPLE = SIMILAR TIMES', ox + 200, H - 30);
  }

  var particles = [];
  function spawnParticles(x, y, color) {
    for (var i = 0; i < 6; i++) {
      particles.push({
        x: x, y: y,
        vx: (Math.random() - 0.5) * 3,
        vy: (Math.random() - 0.5) * 3,
        life: 1,
        color: color
      });
    }
  }

  function updateParticles(dt) {
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      p.x += p.vx;
      p.y += p.vy;
      p.life -= dt * 2;
      if (p.life <= 0) particles.splice(i, 1);
    }
  }

  function drawParticles() {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      ctx.beginPath();
      ctx.arc(p.x, p.y, 2 * p.life, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + p.color[0] + ',' + p.color[1] + ',' + p.color[2] + ',' + (p.life * 0.6) + ')';
      ctx.fill();
    }
  }

  function render() {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (mode === 'explore') {
      drawExploreMode();
      requestAnimationFrame(render);
      return;
    }

    drawStartEnd();

    var keys = Object.keys(curves);
    var labelY = startY - 5;
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var col = curveColors[k] || teal;
      drawGlowCurve(curves[k], col);
      if (racing && balls[k]) {
        drawTrail(curves[k], balls[k].dist, col);
      }
      var labelPts = curves[k];
      var midIdx = Math.floor(labelPts.length * (0.35 + 0.1 * i));
      drawLabel(curveNames[k] || k.toUpperCase(), labelPts[midIdx].x, labelPts[midIdx].y - 10, col);
    }

    if (racing) {
      var dt = (1 / 60) * speedMul * 0.3;
      var allDone = true;
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        if (!balls[k].done) allDone = false;
        updateBall(k, dt);
      }

      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var col = curveColors[k] || teal;
        drawBall(balls[k].pos, col);
        if (balls[k].done) {
          spawnParticles(balls[k].pos.x, balls[k].pos.y, col);
        }
      }

      raceTime += dt;

      var timerX = W - 30;
      var timerY0 = startY + 20;
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var col = curveColors[k] || teal;
        drawTimerLabel(k, timerX, timerY0 + i * 16, col);
      }

      if (allDone && !finished._announced) {
        finished._announced = true;
        judgeCustom();
      }
    } else {
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        drawBall(curves[k][0], curveColors[k] || teal);
      }
    }

    if (drawingCustom && customPoints.length > 1) {
      ctx.beginPath();
      ctx.moveTo(customPoints[0].x, customPoints[0].y);
      for (var i = 1; i < customPoints.length; i++) {
        ctx.lineTo(customPoints[i].x, customPoints[i].y);
      }
      ctx.strokeStyle = 'rgba(0,245,212,0.4)';
      ctx.lineWidth = 2;
      ctx.setLineDash([4, 4]);
      ctx.stroke();
      ctx.setLineDash([]);
    }

    updateParticles(1 / 60);
    drawParticles();

    requestAnimationFrame(render);
  }

  // 自分で描いた曲線とサイクロイドの差。0 に近づけるのが遊び
  var bestGap = Infinity;
  function judgeCustom() {
    var hudYou = document.getElementById('hud-you');
    if (!balls.custom) {
      if (typeof SFX !== 'undefined') SFX.play('complete');
      return;
    }
    if (balls.custom.dnf) {
      hudYou.textContent = 'STALLED';
      return;
    }
    var gap = balls.custom.time - balls.cycloid.time;
    hudYou.textContent = (gap >= 0 ? '+' : '') + gap.toFixed(3) + 's vs CYCLOID';
    if (gap < bestGap) {
      bestGap = gap;
      document.getElementById('hud-best').textContent = (gap >= 0 ? '+' : '') + gap.toFixed(3) + 's';
      if (typeof SFX !== 'undefined') SFX.play(gap < 0.01 ? 'correct' : 'success');
    } else if (typeof SFX !== 'undefined') {
      SFX.play('complete');
    }
  }

  function startRace() {
    if (racing && !finished._announced) return;
    buildCurves();
    initBalls();
    racing = true;
    raceTime = 0;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function resetRace() {
    racing = false;
    raceTime = 0;
    winner = '';
    finished = {};
    document.getElementById('hud-winner').textContent = '—';
    buildCurves();
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  function smoothCustom(pts) {
    if (pts.length < 4) return pts;
    var out = [pts[0]];
    for (var i = 1; i < pts.length - 1; i++) {
      out.push({
        x: pts[i - 1].x * 0.25 + pts[i].x * 0.5 + pts[i + 1].x * 0.25,
        y: pts[i - 1].y * 0.25 + pts[i].y * 0.5 + pts[i + 1].y * 0.25
      });
    }
    out.push(pts[pts.length - 1]);
    return out;
  }

  var pointerDown = false;
  var longPressTimer = null;

  var downAt = null;
  function beginDraw() {
    racing = false;
    drawingCustom = true;
    customPoints = [{ x: startX, y: startY }];
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }
  function extendDraw(x, y) {
    var last = customPoints[customPoints.length - 1];
    // 左から右へ描いた点だけ採る（戻り描きで曲線が折り返さないように）
    if (x > last.x + 3 && x < endX) customPoints.push({ x: x, y: y });
  }
  function finishDraw() {
    drawingCustom = false;
    customPoints.push({ x: endX, y: endY });
    customCurve = smoothCustom(smoothCustom(customPoints));
    buildCurves();
    initBalls();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
    if (customPoints.length > 4) startRace();
  }

  canvas.addEventListener('mousedown', function(e) {
    if (e.button === 2) { e.preventDefault(); resetRace(); return; }
    if (mode === 'explore') return;
    var rect = canvas.getBoundingClientRect();
    downAt = { x: (e.clientX - rect.left) * dpr, y: (e.clientY - rect.top) * dpr };
    if (mode === 'draw') beginDraw();
    pointerDown = true;
  });

  canvas.addEventListener('mousemove', function(e) {
    if (!pointerDown) return;
    var rect = canvas.getBoundingClientRect();
    var x = (e.clientX - rect.left) * dpr;
    var y = (e.clientY - rect.top) * dpr;
    if (!drawingCustom && downAt && Math.hypot(x - downAt.x, y - downAt.y) > 12 * dpr) beginDraw();
    if (drawingCustom) extendDraw(x, y);
  });

  canvas.addEventListener('mouseup', function() {
    if (drawingCustom) finishDraw();
    else if (pointerDown && mode !== 'explore') startRace();
    pointerDown = false;
    downAt = null;
  });

  canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var touch = e.touches[0];
    var rect = canvas.getBoundingClientRect();
    var x = (touch.clientX - rect.left) * dpr;
    var y = (touch.clientY - rect.top) * dpr;

    longPressTimer = setTimeout(function() {
      resetRace();
      longPressTimer = null;
    }, 500);

    downAt = { x: x, y: y };
    if (mode === 'draw') beginDraw();
    pointerDown = true;
  }, { passive: false });

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var touch = e.touches[0];
    var rect = canvas.getBoundingClientRect();
    var x = (touch.clientX - rect.left) * dpr;
    var y = (touch.clientY - rect.top) * dpr;
    if (!drawingCustom && downAt && mode !== 'explore' && Math.hypot(x - downAt.x, y - downAt.y) > 12 * dpr) {
      if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
      beginDraw();
    }
    if (drawingCustom) extendDraw(x, y);
  }, { passive: false });

  canvas.addEventListener('touchend', function(e) {
    e.preventDefault();
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
      if (!drawingCustom && mode !== 'explore') startRace();
    }
    if (drawingCustom) finishDraw();
    pointerDown = false;
    downAt = null;
  }, { passive: false });

  document.querySelectorAll('.mode-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.mode-btn').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      mode = btn.getAttribute('data-mode');
      document.getElementById('hud-mode').textContent = mode.toUpperCase();
      resetRace();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  var slHeight = document.getElementById('sl-height');
  var svHeight = document.getElementById('sv-height');
  slHeight.addEventListener('input', function() {
    raceHeight = parseInt(slHeight.value);
    svHeight.textContent = raceHeight;
    document.getElementById('hud-h').textContent = raceHeight;
    computeLayout();
    buildCurves();
    resetRace();
  });

  var slSpeed = document.getElementById('sl-speed');
  var svSpeed = document.getElementById('sv-speed');
  slSpeed.addEventListener('input', function() {
    speedMul = parseInt(slSpeed.value);
    svSpeed.textContent = speedMul;
  });

  document.getElementById('btn-go').addEventListener('click', function() {
    startRace();
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    resetRace();
  });

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth * dpr;
    H = window.innerHeight * dpr;
    canvas.width = W;
    canvas.height = H;
    canvas.style.width = window.innerWidth + 'px';
    canvas.style.height = window.innerHeight + 'px';
    computeLayout();
    buildCurves();
    if (!racing) initBalls();
  }

  window.addEventListener('resize', resize);
  resize();
  render();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
