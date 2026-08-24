<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ferrofluid');

$pageName = 'ferrofluid';
$pageTitle = 'FERROFLUID - PULSE';
$pageDesc = '磁性流体シミュレーション。磁石を置くと、磁力と表面張力の競合でスパイク構造が自己組織化する。';
$pageCanonical = 'https://pulse.subara3.com/ferrofluid.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
  .ctrl-btn.active { background:rgba(0,245,212,0.15); color:#00f5d4; border-color:rgba(0,245,212,0.5); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MAGNETS <span id="hud-m">0</span></div>
  <div>PARTICLES <span id="hud-p">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'magnet'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">STRENGTH</label>
    <input type="range" id="sl-str" min="1" max="20" value="10">
    <span class="slider-value" id="sv-str">10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">PARTICLES</label>
    <input type="range" id="sl-cnt" min="200" max="1500" value="800" step="100">
    <span class="slider-value" id="sv-cnt">800</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-grav">GRAVITY</button>
    <button class="ctrl-btn" id="btn-field">FIELD</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H;
  var pts = [], mags = [];
  var str = 10, gravOn = false, fieldOn = false;
  var dragI = -1;
  var CELL = 28, REST = 10, IR = 26, IR2 = IR * IR;
  var hm = new Map();
  var pSpr, mGlow;

  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
  }

  function makeSprites() {
    var sz = 22, c = document.createElement('canvas');
    c.width = c.height = sz;
    var cx = c.getContext('2d');
    var g = cx.createRadialGradient(sz / 2, sz / 2, 0, sz / 2, sz / 2, sz / 2);
    g.addColorStop(0, 'rgba(0,245,212,0.28)');
    g.addColorStop(0.45, 'rgba(0,210,190,0.1)');
    g.addColorStop(1, 'rgba(0,150,140,0)');
    cx.fillStyle = g;
    cx.fillRect(0, 0, sz, sz);
    pSpr = c;

    var ms = 80, mc = document.createElement('canvas');
    mc.width = mc.height = ms;
    var mx = mc.getContext('2d');
    var mg = mx.createRadialGradient(ms / 2, ms / 2, 0, ms / 2, ms / 2, ms / 2);
    mg.addColorStop(0, 'rgba(123,44,191,0.35)');
    mg.addColorStop(0.35, 'rgba(123,44,191,0.12)');
    mg.addColorStop(1, 'rgba(80,20,140,0)');
    mx.fillStyle = mg;
    mx.fillRect(0, 0, ms, ms);
    mGlow = mc;
  }

  function initPts(n) {
    pts = [];
    for (var i = 0; i < n; i++) {
      pts.push({ x: Math.random() * W, y: Math.random() * H, vx: 0, vy: 0 });
    }
  }

  function initMags() {
    var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.18;
    mags = [
      { x: cx, y: cy - r },
      { x: cx - r * 0.866, y: cy + r * 0.5 },
      { x: cx + r * 0.866, y: cy + r * 0.5 }
    ];
  }

  function hk(x, y) { return ((x / CELL | 0) * 73856093 ^ (y / CELL | 0) * 19349663) | 0; }

  function buildHash() {
    hm.clear();
    for (var i = 0; i < pts.length; i++) {
      var k = hk(pts[i].x, pts[i].y);
      var a = hm.get(k);
      if (!a) { a = []; hm.set(k, a); }
      a.push(i);
    }
  }

  function getNear(x, y) {
    var cx = x / CELL | 0, cy = y / CELL | 0, out = [];
    for (var dx = -1; dx <= 1; dx++)
      for (var dy = -1; dy <= 1; dy++) {
        var a = hm.get(((cx + dx) * 73856093 ^ (cy + dy) * 19349663) | 0);
        if (a) for (var j = 0; j < a.length; j++) out.push(a[j]);
      }
    return out;
  }

  function step() {
    buildHash();
    var fs = str * 0.5;
    var damp = 0.93;
    var gv = gravOn ? 0.35 : 0;

    for (var i = 0; i < pts.length; i++) {
      var p = pts[i], fx = 0, fy = 0;

      for (var mi = 0; mi < mags.length; mi++) {
        var mdx = mags[mi].x - p.x, mdy = mags[mi].y - p.y;
        var mr2 = mdx * mdx + mdy * mdy;
        if (mr2 < 25) mr2 = 25;
        var mr = Math.sqrt(mr2);
        var mf = fs * 5000 / (mr2 * mr);
        fx += mdx / mr * mf;
        fy += mdy / mr * mf;
      }

      var nb = getNear(p.x, p.y);
      for (var ni = 0; ni < nb.length; ni++) {
        var j = nb[ni];
        if (j === i) continue;
        var q = pts[j];
        var dx = q.x - p.x, dy = q.y - p.y;
        var r2 = dx * dx + dy * dy;
        if (r2 > IR2 || r2 < 0.01) continue;
        var r = Math.sqrt(r2);
        var rn = r / REST;
        var f;
        if (rn < 0.6) {
          f = (0.6 - rn) * 4;
        } else if (rn < 1.0) {
          f = (1.0 - rn) * 0.8;
        } else {
          f = -(rn - 1.0) * 0.06 * Math.max(0, 1 - rn / 2.6);
        }
        fx -= dx / r * f;
        fy -= dy / r * f;
      }

      fy += gv;
      p.vx = (p.vx + fx) * damp;
      p.vy = (p.vy + fy) * damp;

      var v2 = p.vx * p.vx + p.vy * p.vy;
      if (v2 > 64) {
        var s = 8 / Math.sqrt(v2);
        p.vx *= s;
        p.vy *= s;
      }

      p.x += p.vx;
      p.y += p.vy;

      if (p.x < 3) { p.x = 3; p.vx *= -0.3; }
      if (p.x > W - 3) { p.x = W - 3; p.vx *= -0.3; }
      if (p.y < 3) { p.y = 3; p.vy *= -0.3; }
      if (p.y > H - 3) { p.y = H - 3; p.vy *= -0.3; }
    }
  }

  function drawField() {
    if (!mags.length) return;
    var sp = 24;
    X.lineWidth = 1.5;
    for (var gx = sp; gx < W; gx += sp) {
      for (var gy = sp; gy < H; gy += sp) {
        var bx = 0, by = 0;
        for (var mi = 0; mi < mags.length; mi++) {
          var dx = gx - mags[mi].x, dy = gy - mags[mi].y;
          var r2 = dx * dx + dy * dy + 200;
          var r3 = r2 * Math.sqrt(r2);
          bx += dx / r3;
          by += dy / r3;
        }
        var mag = Math.sqrt(bx * bx + by * by);
        if (mag < 1e-8) continue;
        var len = Math.min(9, mag * 600000);
        var al = Math.min(0.25, mag * 100000);
        X.strokeStyle = 'rgba(0,245,212,' + al.toFixed(3) + ')';
        X.beginPath();
        X.moveTo(gx - bx / mag * len, gy - by / mag * len);
        X.lineTo(gx + bx / mag * len, gy + by / mag * len);
        X.stroke();
      }
    }
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    if (fieldOn) drawField();

    X.globalCompositeOperation = 'lighter';
    var hsz = pSpr.width / 2;
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      if (mags.length) {
        var inten = 0;
        for (var mi = 0; mi < mags.length; mi++) {
          var dx = mags[mi].x - p.x, dy = mags[mi].y - p.y;
          inten += 1 / (dx * dx + dy * dy + 800);
        }
        X.globalAlpha = Math.min(1, 0.4 + inten * 25000);
      } else {
        X.globalAlpha = 0.55;
      }
      X.drawImage(pSpr, p.x - hsz, p.y - hsz);
    }
    X.globalAlpha = 1;
    X.globalCompositeOperation = 'source-over';

    var mhsz = mGlow.width / 2;
    for (var mi = 0; mi < mags.length; mi++) {
      var m = mags[mi];
      X.drawImage(mGlow, m.x - mhsz, m.y - mhsz);
      X.fillStyle = '#7b2cbf';
      X.beginPath();
      X.arc(m.x, m.y, 5, 0, Math.PI * 2);
      X.fill();
      X.fillStyle = '#9d4edd';
      X.beginPath();
      X.arc(m.x, m.y, 2.5, 0, Math.PI * 2);
      X.fill();
    }

    document.getElementById('hud-m').textContent = mags.length;
    document.getElementById('hud-p').textContent = pts.length;
  }

  function loop() {
    step();
    draw();
    requestAnimationFrame(loop);
  }

  function findMag(x, y, th) {
    var best = -1, bd = th * th;
    for (var i = 0; i < mags.length; i++) {
      var dx = mags[i].x - x, dy = mags[i].y - y;
      var d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  C.addEventListener('mousedown', function(e) {
    var x = e.clientX, y = e.clientY;
    if (e.button === 2) {
      e.preventDefault();
      var idx = findMag(x, y, 60);
      if (idx >= 0) {
        mags.splice(idx, 1);
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    var idx = findMag(x, y, 30);
    if (idx >= 0) {
      dragI = idx;
    } else {
      mags.push({ x: x, y: y });
      dragI = mags.length - 1;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (dragI >= 0 && mags[dragI]) {
      mags[dragI].x = e.clientX;
      mags[dragI].y = e.clientY;
    }
  });

  C.addEventListener('mouseup', function() { dragI = -1; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0], x = t.clientX, y = t.clientY;
    var idx = findMag(x, y, 40);
    if (idx >= 0) {
      dragI = idx;
    } else {
      mags.push({ x: x, y: y });
      dragI = mags.length - 1;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragI >= 0 && e.touches.length && mags[dragI]) {
      mags[dragI].x = e.touches[0].clientX;
      mags[dragI].y = e.touches[0].clientY;
      if (typeof SFX !== 'undefined') SFX.play('move', 100);
    }
  }, { passive: false });

  C.addEventListener('touchend', function() { dragI = -1; });

  var slStr = document.getElementById('sl-str');
  var svStr = document.getElementById('sv-str');
  var slCnt = document.getElementById('sl-cnt');
  var svCnt = document.getElementById('sv-cnt');

  slStr.addEventListener('input', function() {
    str = +this.value;
    svStr.textContent = this.value;
  });

  slCnt.addEventListener('input', function() {
    var n = +this.value;
    svCnt.textContent = n;
    while (pts.length < n)
      pts.push({ x: Math.random() * W, y: Math.random() * H, vx: 0, vy: 0 });
    if (pts.length > n) pts.length = n;
  });

  document.getElementById('btn-grav').addEventListener('click', function() {
    gravOn = !gravOn;
    this.classList.toggle('active', gravOn);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-field').addEventListener('click', function() {
    fieldOn = !fieldOn;
    this.classList.toggle('active', fieldOn);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    mags = [];
    initMags();
    initPts(+slCnt.value);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  makeSprites();
  window.addEventListener('resize', function() { resize(); makeSprites(); });

  var mobile = innerWidth < 768;
  var defN = mobile ? 400 : 800;
  slCnt.value = defN;
  svCnt.textContent = defN;

  initPts(defN);
  initMags();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
