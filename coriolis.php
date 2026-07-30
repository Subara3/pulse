<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('coriolis');

$pageName = 'coriolis';
$pageTitle = 'CORIOLIS - PULSE';
$pageDesc = 'コリオリ効果。回転する円盤の上で直線運動が曲がって見える。慣性系と回転系を切り替えて見かけの力を体験する。';
$pageCanonical = 'https://pulse.subara3.com/coriolis.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #frame-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #frame-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>ω <span id="hud-omega">1.50</span></div>
  <div>N <span id="hud-n">0</span></div>
</div>
<div id="frame-label"><span>ROTATING FRAME</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'launch'],
  ['type' => 'drag', 'label' => 'aim'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">OMEGA</label>
    <input type="range" id="sl-omega" min="0" max="100" value="30" step="1">
    <span class="slider-value" id="sv-omega">1.50</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="0" max="100" value="50" step="1">
    <span class="slider-value" id="sv-speed">1.0×</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-frame">ROTATING</button>
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-vec">VECTORS</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr, cx, cy, DR;

  var omega = 1.5, theta = 0;
  var fmode = 1, showV = false, autoOn = true, lmul = 1;
  var parts = [], MP = 50, TL = 280;
  var lastT = 0, aTmr = 0;
  var isDown = false, moved = false, dStart = null, dEnd = null;

  var hO = document.getElementById('hud-omega');
  var hN = document.getElementById('hud-n');
  var fL = document.getElementById('frame-label').firstElementChild;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2; cy = H / 2;
    DR = Math.min(W, H) * 0.36;
  }

  function rot(x, y, a) {
    var c = Math.cos(a), s = Math.sin(a);
    return [x * c - y * s, x * s + y * c];
  }
  function toDisp(x, y, a) { return fmode ? rot(x, y, -a) : [x, y]; }
  function toInert(x, y, a) { return fmode ? rot(x, y, a) : [x, y]; }

  function spawnI(ix, iy, vx, vy) {
    if (parts.length >= MP) parts.shift();
    parts.push({ x: ix, y: iy, vx: vx, vy: vy, trail: [], age: 0 });
    if (typeof SFX !== 'undefined') SFX.play('spawn', 80);
  }

  function spawnD(dx, dy, dvx, dvy) {
    var p = toInert(dx, dy, theta), vx, vy;
    if (!fmode) { vx = dvx; vy = dvy; }
    else {
      var w = rot(dvx - omega * dy, dvy + omega * dx, theta);
      vx = w[0]; vy = w[1];
    }
    spawnI(p[0], p[1], vx, vy);
  }

  function autoOne() {
    var a = Math.random() * Math.PI * 2, r = DR * 0.78;
    var dx = Math.cos(a) * r, dy = Math.sin(a) * r;
    var sp = DR * 0.45 * lmul;
    spawnD(dx, dy, -Math.cos(a) * sp, -Math.sin(a) * sp);
  }

  function update(dt) {
    theta += omega * dt;
    if (autoOn) { aTmr += dt; if (aTmr > 0.75) { aTmr = 0; autoOne(); } }
    for (var i = parts.length - 1; i >= 0; i--) {
      var p = parts[i];
      p.x += p.vx * dt; p.y += p.vy * dt; p.age += dt;
      p.trail.push({ x: p.x, y: p.y, a: theta });
      if (p.trail.length > TL) p.trail.shift();
      if (p.x * p.x + p.y * p.y > DR * DR * 7 || p.age > 50) parts.splice(i, 1);
    }
  }

  function drawDisk() {
    X.save(); X.translate(cx, cy);
    X.rotate(fmode ? 0 : theta);

    var og = X.createRadialGradient(0, 0, DR * 0.92, 0, 0, DR * 1.05);
    og.addColorStop(0, 'rgba(0,245,212,.04)'); og.addColorStop(1, 'rgba(0,245,212,0)');
    X.beginPath(); X.arc(0, 0, DR * 1.05, 0, Math.PI * 2); X.fillStyle = og; X.fill();

    var bg = X.createRadialGradient(0, 0, 0, 0, 0, DR);
    bg.addColorStop(0, 'rgba(12,14,22,.94)');
    bg.addColorStop(0.8, 'rgba(10,13,20,.9)');
    bg.addColorStop(1, 'rgba(0,50,42,.22)');
    X.beginPath(); X.arc(0, 0, DR, 0, Math.PI * 2);
    X.fillStyle = bg; X.fill();
    X.strokeStyle = 'rgba(0,245,212,.28)'; X.lineWidth = 1.5; X.stroke();

    X.strokeStyle = 'rgba(0,245,212,.06)'; X.lineWidth = 0.7;
    for (var i = 0; i < 12; i++) {
      var a = i * Math.PI / 6;
      X.beginPath(); X.moveTo(0, 0);
      X.lineTo(Math.cos(a) * DR, Math.sin(a) * DR); X.stroke();
    }

    X.strokeStyle = 'rgba(0,245,212,.04)';
    for (var i = 1; i <= 5; i++) {
      X.beginPath(); X.arc(0, 0, DR * i / 6, 0, Math.PI * 2); X.stroke();
    }

    var st = DR / 5;
    X.fillStyle = 'rgba(0,245,212,.055)';
    for (var gx = -4; gx <= 4; gx++) {
      for (var gy = -4; gy <= 4; gy++) {
        var px = gx * st, py = gy * st;
        if (px * px + py * py < DR * DR * 0.82) {
          X.beginPath(); X.arc(px, py, 1.3, 0, Math.PI * 2); X.fill();
        }
      }
    }

    var mk = [[0, -1, 5, 0.85], [1, 0, 3.5, 0.5], [0, 1, 3.5, 0.5], [-1, 0, 3.5, 0.5]];
    for (var i = 0; i < mk.length; i++) {
      var m = mk[i];
      X.beginPath(); X.arc(m[0] * DR * 0.91, m[1] * DR * 0.91, m[2], 0, Math.PI * 2);
      X.fillStyle = 'rgba(123,44,191,' + m[3] + ')'; X.fill();
    }

    X.restore();
  }

  function drawArrow(x1, y1, x2, y2, col) {
    var dx = x2 - x1, dy = y2 - y1, l = Math.sqrt(dx * dx + dy * dy);
    if (l < 2) return;
    var nx = dx / l, ny = dy / l;
    X.beginPath(); X.moveTo(x1, y1); X.lineTo(x2, y2);
    X.strokeStyle = col; X.lineWidth = 1.6; X.stroke();
    X.beginPath(); X.moveTo(x2, y2);
    X.lineTo(x2 - 7 * nx + 3 * ny, y2 - 7 * ny - 3 * nx);
    X.lineTo(x2 - 7 * nx - 3 * ny, y2 - 7 * ny + 3 * nx);
    X.closePath(); X.fillStyle = col; X.fill();
  }

  function drawParts() {
    X.save(); X.translate(cx, cy);
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i], tr = p.trail;

      if (tr.length > 2) {
        var segs = 5, sl = Math.floor(tr.length / segs);
        for (var s = 0; s < segs; s++) {
          var sa = s * sl, sb = Math.min(sa + sl + 1, tr.length);
          if (sb - sa < 2) continue;
          var al = ((s + 0.5) / segs) * 0.38;
          X.beginPath();
          var q = toDisp(tr[sa].x, tr[sa].y, tr[sa].a);
          X.moveTo(q[0], q[1]);
          for (var j = sa + 1; j < sb; j++) {
            q = toDisp(tr[j].x, tr[j].y, tr[j].a);
            X.lineTo(q[0], q[1]);
          }
          X.strokeStyle = 'rgba(0,245,212,' + al.toFixed(3) + ')';
          X.lineWidth = 0.7 + al * 3;
          X.stroke();
        }
      }

      var dp = toDisp(p.x, p.y, theta), px = dp[0], py = dp[1];

      var gl = X.createRadialGradient(px, py, 1, px, py, 12);
      gl.addColorStop(0, 'rgba(0,245,212,.5)'); gl.addColorStop(1, 'rgba(0,245,212,0)');
      X.beginPath(); X.arc(px, py, 12, 0, Math.PI * 2); X.fillStyle = gl; X.fill();

      X.beginPath(); X.arc(px, py, 3.5, 0, Math.PI * 2);
      X.fillStyle = '#00f5d4'; X.fill();

      if (showV && fmode) {
        var rp = rot(p.x, p.y, -theta), rx = rp[0], ry = rp[1];
        var vi = rot(p.vx, p.vy, -theta);
        var vrx = vi[0] + omega * ry, vry = vi[1] - omega * rx;

        var cax = 2 * omega * vry, cay = -2 * omega * vrx;
        var cfx = omega * omega * rx, cfy = omega * omega * ry;

        var sc = 0.08;
        var ax = cax * sc, ay = cay * sc, al = Math.sqrt(ax * ax + ay * ay);
        if (al > 45) { var r = 45 / al; ax *= r; ay *= r; }
        drawArrow(px, py, px + ax, py + ay, 'rgba(0,200,180,.6)');

        ax = cfx * sc; ay = cfy * sc; al = Math.sqrt(ax * ax + ay * ay);
        if (al > 45) { var r = 45 / al; ax *= r; ay *= r; }
        drawArrow(px, py, px + ax, py + ay, 'rgba(140,60,210,.6)');
      }
    }
    X.restore();
  }

  function draw() {
    X.fillStyle = '#0a0a0f'; X.fillRect(0, 0, W, H);
    drawDisk(); drawParts();
    if (isDown && dStart && dEnd) {
      X.save(); X.translate(cx, cy);
      drawArrow(dStart[0], dStart[1], dEnd[0], dEnd[1], 'rgba(123,44,191,.5)');
      X.restore();
    }
    hN.textContent = parts.length;
  }

  function loop(t) {
    if (!lastT) lastT = t;
    var dt = Math.min((t - lastT) / 1000, 0.05);
    lastT = t;
    if (dt > 0) update(dt);
    draw();
    requestAnimationFrame(loop);
  }

  function gxy(e) {
    var r = C.getBoundingClientRect(), tx, ty;
    if (e.touches && e.touches.length) {
      tx = e.touches[0].clientX - r.left; ty = e.touches[0].clientY - r.top;
    } else {
      tx = e.clientX - r.left; ty = e.clientY - r.top;
    }
    return [tx - cx, ty - cy];
  }

  function onDisk(xy) { return xy[0] * xy[0] + xy[1] * xy[1] <= DR * DR * 1.15; }

  function fire() {
    if (!isDown) return;
    isDown = false;
    if (dStart) {
      var dx = dStart[0], dy = dStart[1], d = Math.sqrt(dx * dx + dy * dy) || 1;
      if (moved && dEnd) {
        var vx = dEnd[0] - dx, vy = dEnd[1] - dy, vl = Math.sqrt(vx * vx + vy * vy);
        if (vl > 8) spawnD(dx, dy, vx * 0.8 * lmul, vy * 0.8 * lmul);
        else spawnD(dx, dy, -dx / d * DR * 0.45 * lmul, -dy / d * DR * 0.45 * lmul);
      } else {
        spawnD(dx, dy, -dx / d * DR * 0.45 * lmul, -dy / d * DR * 0.45 * lmul);
      }
    }
    dStart = null; dEnd = null;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var xy = gxy(e); if (!onDisk(xy)) return;
    isDown = true; moved = false; dStart = xy; dEnd = null;
  });
  C.addEventListener('mousemove', function(e) {
    if (!isDown) return; moved = true; dEnd = gxy(e);
  });
  document.addEventListener('mouseup', fire);
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var xy = gxy(e); if (!onDisk(xy)) return;
    isDown = true; moved = false; dStart = xy; dEnd = null;
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault(); if (!isDown) return; moved = true; dEnd = gxy(e);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault(); fire();
  }, { passive: false });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault(); parts.length = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var slO = document.getElementById('sl-omega'), svO = document.getElementById('sv-omega');
  var slS = document.getElementById('sl-speed'), svS = document.getElementById('sv-speed');
  slO.addEventListener('input', function() {
    omega = this.value * 0.05;
    svO.textContent = omega.toFixed(2); hO.textContent = omega.toFixed(2);
  });
  slS.addEventListener('input', function() {
    lmul = this.value / 50; svS.textContent = lmul.toFixed(1) + '×';
  });
  document.getElementById('btn-frame').addEventListener('click', function() {
    fmode = 1 - fmode;
    this.textContent = fmode ? 'ROTATING' : 'INERTIAL';
    this.classList.toggle('active', !!fmode);
    fL.textContent = fmode ? 'ROTATING FRAME' : 'INERTIAL FRAME';
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-auto').addEventListener('click', function() {
    autoOn = !autoOn; this.classList.toggle('active', autoOn);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-vec').addEventListener('click', function() {
    showV = !showV; this.classList.toggle('active', showV);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    parts.length = 0; theta = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  window.addEventListener('resize', resize);

  for (var i = 0; i < 5; i++) {
    var a = i * Math.PI * 2 / 5, r = DR * 0.75;
    var dx = Math.cos(a) * r, dy = Math.sin(a) * r;
    spawnD(dx, dy, -Math.cos(a) * DR * 0.4, -Math.sin(a) * DR * 0.4);
  }
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
