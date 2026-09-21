<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('grin');

$pageName = 'grin';
$pageTitle = 'GRIN - PULSE';
$pageDesc = '屈折率勾配光学。連続的に変化する屈折率場の中を光線が曲がりながら進む。レンズを配置して光の経路を描く。';
$pageCanonical = 'https://pulse.subara3.com/grin.php';
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
  <div>LENSES <span id="hud-n">1</span></div>
  <div>RAYS <span id="hud-r">40</span></div>
</div>
<div id="info-label"><span>GRIN</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place lens'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">PRESET</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-preset="focus">FOCUS</button>
    <button class="ctrl-btn" data-preset="diverge">DIVERGE</button>
    <button class="ctrl-btn" data-preset="mirage">MIRAGE</button>
    <button class="ctrl-btn" data-preset="guide">GUIDE</button>
  </div>
  <div class="slider-row"><label class="slider-label">TYPE</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-convex">CONVEX</button>
    <button class="ctrl-btn" id="btn-concave">CONCAVE</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">RAYS</label>
    <input type="range" id="sl-rays" class="ctrl-slider" min="10" max="80" value="40">
    <span class="slider-val" id="val-rays">40</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  var dirty = true;

  function resize() { W = C.width = innerWidth; H = C.height = innerHeight; dirty = true; }
  resize();
  addEventListener('resize', resize);

  var lenses = [];
  var bgMode = 'none';
  var lensType = 1;
  var rayCount = 40;
  var rays = [];
  var particles = [];
  var time = 0;

  var dragIdx = -1, dragOff = {x: 0, y: 0};
  var hoverIdx = -1;
  var longTimer = null, touchMoved = false, touchId = null;

  var LENS_R = 70;
  var LENS_DN = 0.6;

  function nAt(x, y) {
    var n = 1.0;
    if (bgMode === 'mirage') {
      n += 0.35 * (1 - y / H);
    } else if (bgMode === 'guide') {
      var dy = Math.abs(y - H / 2);
      var hw = H * 0.12;
      if (dy < hw) n += 0.5 * (1 - dy * dy / (hw * hw));
    }
    for (var i = 0; i < lenses.length; i++) {
      var l = lenses[i];
      var dx = x - l.cx, ddy = y - l.cy;
      var rr = l.r * l.r;
      n += l.dn * Math.exp(-(dx * dx + ddy * ddy) / (2 * rr));
    }
    return Math.max(n, 0.2);
  }

  function gradN(x, y) {
    var gx = 0, gy = 0;
    if (bgMode === 'mirage') {
      gy = -0.35 / H;
    } else if (bgMode === 'guide') {
      var dy = y - H / 2;
      var hw = H * 0.12;
      if (Math.abs(dy) < hw) gy = -0.5 * 2 * dy / (hw * hw);
    }
    for (var i = 0; i < lenses.length; i++) {
      var l = lenses[i];
      var dx = x - l.cx, ddy = y - l.cy;
      var rr = l.r * l.r;
      var e = l.dn * Math.exp(-(dx * dx + ddy * ddy) / (2 * rr));
      gx += (-dx / rr) * e;
      gy += (-ddy / rr) * e;
    }
    return {x: gx, y: gy};
  }

  function traceRay(x0, y0, th0) {
    var path = [{x: x0, y: y0}];
    var x = x0, y = y0, th = th0;
    var ds = 2;
    for (var s = 0; s < 800; s++) {
      var n = nAt(x, y);
      var g = gradN(x, y);
      var ct = Math.cos(th), st = Math.sin(th);
      var dth = (-st * g.x + ct * g.y) / n;
      th += dth * ds;
      x += Math.cos(th) * ds;
      y += Math.sin(th) * ds;
      path.push({x: x, y: y});
      if (x < -20 || x > W + 20 || y < -20 || y > H + 20) break;
    }
    return path;
  }

  function computeRays() {
    rays = [];
    for (var i = 0; i < rayCount; i++) {
      var ry = H * (i + 0.5) / rayCount;
      rays.push(traceRay(0, ry, 0));
    }
    initParticles();
    dirty = false;
  }

  function initParticles() {
    particles = [];
    for (var i = 0; i < rays.length; i++) {
      for (var j = 0; j < 2; j++) {
        particles.push({
          ray: i,
          pos: Math.random() * Math.max(1, rays[i].length - 1),
          speed: 0.7 + Math.random() * 0.5
        });
      }
    }
  }

  function drawField() {
    if (bgMode === 'mirage') {
      var g = ctx.createLinearGradient(0, 0, 0, H);
      g.addColorStop(0, 'rgba(123,44,191,0.07)');
      g.addColorStop(1, 'rgba(123,44,191,0.01)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, W, H);
    } else if (bgMode === 'guide') {
      var cy = H / 2, hw = H * 0.12;
      var g = ctx.createLinearGradient(0, cy - hw, 0, cy + hw);
      g.addColorStop(0, 'rgba(123,44,191,0)');
      g.addColorStop(0.5, 'rgba(123,44,191,0.07)');
      g.addColorStop(1, 'rgba(123,44,191,0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, cy - hw, W, hw * 2);
      ctx.strokeStyle = 'rgba(123,44,191,0.12)';
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.beginPath();
      ctx.moveTo(0, cy - hw); ctx.lineTo(W, cy - hw);
      ctx.moveTo(0, cy + hw); ctx.lineTo(W, cy + hw);
      ctx.stroke();
      ctx.setLineDash([]);
    }
    for (var i = 0; i < lenses.length; i++) {
      var l = lenses[i];
      var rd = l.r * 2.8;
      var rg = ctx.createRadialGradient(l.cx, l.cy, 0, l.cx, l.cy, rd);
      if (l.dn > 0) {
        rg.addColorStop(0, 'rgba(123,44,191,0.1)');
        rg.addColorStop(0.4, 'rgba(123,44,191,0.04)');
        rg.addColorStop(1, 'rgba(123,44,191,0)');
      } else {
        rg.addColorStop(0, 'rgba(0,245,212,0.05)');
        rg.addColorStop(0.4, 'rgba(0,245,212,0.02)');
        rg.addColorStop(1, 'rgba(0,245,212,0)');
      }
      ctx.beginPath();
      ctx.arc(l.cx, l.cy, rd, 0, Math.PI * 2);
      ctx.fillStyle = rg;
      ctx.fill();
    }
  }

  function drawLenses() {
    for (var i = 0; i < lenses.length; i++) {
      var l = lenses[i];
      var a = i === hoverIdx ? 0.45 : 0.25;
      var isPos = l.dn > 0;
      ctx.beginPath();
      ctx.arc(l.cx, l.cy, l.r * 0.35, 0, Math.PI * 2);
      ctx.strokeStyle = isPos ? 'rgba(123,44,191,' + a + ')' : 'rgba(0,245,212,' + a * 0.7 + ')';
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(l.cx, l.cy, 2.5, 0, Math.PI * 2);
      ctx.fillStyle = isPos ? 'rgba(181,140,255,0.5)' : 'rgba(0,245,212,0.5)';
      ctx.fill();
      ctx.fillStyle = 'rgba(181,140,255,0.4)';
      ctx.font = '10px "Space Grotesk",monospace';
      ctx.textAlign = 'center';
      ctx.fillText(isPos ? 'n+' : 'n−', l.cx, l.cy - l.r * 0.35 - 6);
    }
    ctx.textAlign = 'left';
  }

  function drawRays() {
    ctx.lineWidth = 4;
    ctx.strokeStyle = 'rgba(0,245,212,0.04)';
    for (var i = 0; i < rays.length; i++) {
      var p = rays[i];
      if (p.length < 2) continue;
      ctx.beginPath();
      ctx.moveTo(p[0].x, p[0].y);
      for (var j = 1; j < p.length; j++) ctx.lineTo(p[j].x, p[j].y);
      ctx.stroke();
    }
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(0,245,212,0.3)';
    for (var i = 0; i < rays.length; i++) {
      var p = rays[i];
      if (p.length < 2) continue;
      ctx.beginPath();
      ctx.moveTo(p[0].x, p[0].y);
      for (var j = 1; j < p.length; j++) ctx.lineTo(p[j].x, p[j].y);
      ctx.stroke();
    }
  }

  function drawParticles() {
    ctx.fillStyle = 'rgba(0,245,212,0.8)';
    for (var i = 0; i < particles.length; i++) {
      var pt = particles[i];
      pt.pos += pt.speed;
      var path = rays[pt.ray];
      if (!path || path.length < 2) continue;
      if (pt.pos >= path.length - 1) pt.pos -= path.length - 1;
      var idx = Math.floor(pt.pos);
      if (idx < 0) idx = 0;
      if (idx >= path.length - 1) { pt.pos = 0; idx = 0; }
      var frac = pt.pos - idx;
      var x = path[idx].x + (path[idx + 1].x - path[idx].x) * frac;
      var y = path[idx].y + (path[idx + 1].y - path[idx].y) * frac;
      ctx.beginPath();
      ctx.arc(x, y, 1.8, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function drawSource() {
    ctx.strokeStyle = 'rgba(0,245,212,0.08)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.lineTo(0, H);
    ctx.stroke();
  }

  var hudN = document.getElementById('hud-n');
  var hudR = document.getElementById('hud-r');
  function updHud() {
    hudN.textContent = lenses.length;
    hudR.textContent = rayCount;
  }

  function nearLens(mx, my) {
    var best = -1, bd = 50;
    for (var i = 0; i < lenses.length; i++) {
      var dx = mx - lenses[i].cx, dy = my - lenses[i].cy;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function addLens(x, y) {
    lenses.push({cx: x, cy: y, r: LENS_R, dn: lensType * LENS_DN});
    dirty = true;
    updHud();
    SFX.play('spawn');
  }

  function rmLens(i) {
    lenses.splice(i, 1);
    dirty = true;
    updHud();
    SFX.play('pop');
  }

  var pBtns = document.querySelectorAll('[data-preset]');
  function setPreset(name) {
    lenses = [];
    bgMode = 'none';
    if (name === 'focus') {
      lenses.push({cx: W * 0.4, cy: H * 0.5, r: LENS_R * 1.3, dn: 0.7});
    } else if (name === 'diverge') {
      lenses.push({cx: W * 0.4, cy: H * 0.5, r: LENS_R * 1.3, dn: -0.5});
    } else if (name === 'mirage') {
      bgMode = 'mirage';
    } else if (name === 'guide') {
      bgMode = 'guide';
    }
    dirty = true;
    updHud();
    SFX.play('toggle');
    for (var i = 0; i < pBtns.length; i++)
      pBtns[i].classList.toggle('active', pBtns[i].getAttribute('data-preset') === name);
  }

  for (var i = 0; i < pBtns.length; i++)
    pBtns[i].addEventListener('click', function() { setPreset(this.getAttribute('data-preset')); });

  var btnConvex = document.getElementById('btn-convex');
  var btnConcave = document.getElementById('btn-concave');
  btnConvex.addEventListener('click', function() {
    lensType = 1;
    btnConvex.classList.add('active');
    btnConcave.classList.remove('active');
    SFX.play('click');
  });
  btnConcave.addEventListener('click', function() {
    lensType = -1;
    btnConcave.classList.add('active');
    btnConvex.classList.remove('active');
    SFX.play('click');
  });

  var slRays = document.getElementById('sl-rays');
  var valRays = document.getElementById('val-rays');
  slRays.addEventListener('input', function() {
    rayCount = +this.value;
    valRays.textContent = rayCount;
    dirty = true;
    updHud();
  });

  document.getElementById('btn-clear').addEventListener('click', function() {
    lenses = []; bgMode = 'none'; dirty = true; updHud();
    SFX.play('click');
    for (var i = 0; i < pBtns.length; i++) pBtns[i].classList.remove('active');
  });

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var mx = e.clientX, my = e.clientY;
    if (e.button === 2) { var idx = nearLens(mx, my); if (idx >= 0) rmLens(idx); return; }
    var idx = nearLens(mx, my);
    if (idx >= 0) {
      dragIdx = idx;
      dragOff.x = mx - lenses[idx].cx;
      dragOff.y = my - lenses[idx].cy;
    } else {
      addLens(mx, my);
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (dragIdx >= 0) {
      lenses[dragIdx].cx = e.clientX - dragOff.x;
      lenses[dragIdx].cy = e.clientY - dragOff.y;
      dirty = true;
      SFX.play('move', 80);
    } else {
      hoverIdx = nearLens(e.clientX, e.clientY);
    }
  });
  C.addEventListener('mouseup', function() { dragIdx = -1; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length !== 1) return;
    var t = e.touches[0];
    touchId = t.identifier; touchMoved = false;
    var mx = t.clientX, my = t.clientY;
    var idx = nearLens(mx, my);
    if (idx >= 0) {
      dragIdx = idx;
      dragOff.x = mx - lenses[idx].cx;
      dragOff.y = my - lenses[idx].cy;
      longTimer = setTimeout(function() {
        if (!touchMoved && dragIdx >= 0) { rmLens(dragIdx); dragIdx = -1; }
      }, 500);
    } else {
      addLens(mx, my);
    }
  }, {passive: false});
  C.addEventListener('touchmove', function(e) {
    e.preventDefault(); touchMoved = true;
    if (longTimer) { clearTimeout(longTimer); longTimer = null; }
    for (var i = 0; i < e.changedTouches.length; i++) {
      var t = e.changedTouches[i];
      if (t.identifier === touchId && dragIdx >= 0) {
        lenses[dragIdx].cx = t.clientX - dragOff.x;
        lenses[dragIdx].cy = t.clientY - dragOff.y;
        dirty = true;
        SFX.play('move', 80);
      }
    }
  }, {passive: false});
  C.addEventListener('touchend', function() {
    if (longTimer) { clearTimeout(longTimer); longTimer = null; }
    dragIdx = -1; touchId = null;
  });

  function loop() {
    time += 1 / 60;
    if (dirty) computeRays();
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    drawSource();
    drawField();
    drawRays();
    drawParticles();
    drawLenses();
    requestAnimationFrame(loop);
  }

  setPreset('focus');
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
