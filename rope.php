<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rope');

$pageName = 'rope';
$pageTitle = 'ROPE - PULSE';
$pageDesc = 'ロープシミュレーション。Verlet積分と距離拘束による鎖と紐の物理。掴んで、振って、切る。';
$pageCanonical = 'https://pulse.subara3.com/rope.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  ROPES: <span id="hudR" class="v">0</span><br>
  NODES: <span id="hudN" class="v">0</span>
</div>

<?php
$pageInfo = [
  'verlet integration',
  ['accent' => 'distance constraints'],
  'grab · swing · cut',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'grab / add'],
  ['type' => 'drag', 'label' => 'swing'],
  ['type' => 'right', 'label' => 'cut'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">GRAVITY</label>
    <div class="slider-row">
      <input type="range" id="sGrav" min="0" max="100" value="33">
      <span class="slider-value" id="vGrav">0.50</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">WIND</label>
    <div class="slider-row">
      <input type="range" id="sWind" min="0" max="100" value="20">
      <span class="slider-value" id="vWind">1.0</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">STIFFNESS</label>
    <div class="slider-row">
      <input type="range" id="sStiff" min="1" max="15" value="8">
      <span class="slider-value" id="vStiff">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d'), W, H;
  function resize(){ W = C.width = innerWidth; H = C.height = innerHeight; }
  resize();
  addEventListener('resize', resize);

  var grav = 0.5, windAmp = 1.0, iters = 8, segLen = 14, damp = 0.99;
  var ropes = [], sparks = [], grabbedPt = null, mx = 0, my = 0, frame = 0;
  var MAX_ROPES = 50;

  function mkRope(ax, ay, n) {
    var pts = [];
    for (var i = 0; i <= n; i++) {
      pts.push({
        x: ax + Math.sin(i * 0.4) * (2 + i * 0.3),
        y: ay + i * segLen,
        ox: ax, oy: ay + i * segLen,
        pin: i === 0
      });
    }
    return { pts: pts, sl: segLen, hue: 170 + Math.random() * 25 };
  }

  function init() {
    ropes = []; sparks = []; grabbedPt = null;
    var n = Math.max(4, Math.min(9, Math.floor(W / 180)));
    for (var i = 0; i < n; i++) {
      var x = W * (i + 1) / (n + 1);
      ropes.push(mkRope(x, 35 + Math.random() * 25, 12 + Math.floor(Math.random() * 22)));
    }
    hud();
  }

  function updRope(r) {
    var w = windAmp * (Math.sin(frame * 0.013) * 0.6 + Math.sin(frame * 0.031) * 0.4) * 0.08;
    for (var i = 0; i < r.pts.length; i++) {
      var p = r.pts[i];
      if (p.pin || p === grabbedPt) continue;
      var vx = (p.x - p.ox) * damp, vy = (p.y - p.oy) * damp;
      p.ox = p.x; p.oy = p.y;
      p.x += vx + w; p.y += vy + grav;
    }
  }

  function conRope(r) {
    for (var it = 0; it < iters; it++) {
      for (var i = 0; i < r.pts.length - 1; i++) {
        var a = r.pts[i], b = r.pts[i + 1];
        var dx = b.x - a.x, dy = b.y - a.y;
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d < 0.0001) continue;
        var f = (d - r.sl) / d * 0.5;
        var cx = dx * f, cy = dy * f;
        if (!a.pin && a !== grabbedPt) { a.x += cx; a.y += cy; }
        if (!b.pin && b !== grabbedPt) { b.x -= cx; b.y -= cy; }
      }
      for (var i = 0; i < r.pts.length; i++) {
        var p = r.pts[i];
        if (p.pin || p === grabbedPt) continue;
        if (p.y > H - 3) p.y = H - 3;
        if (p.x < 3) p.x = 3;
        if (p.x > W - 3) p.x = W - 3;
      }
    }
  }

  function drawRope(r) {
    if (r.pts.length < 2) return;
    var h = r.hue;
    X.lineCap = 'round'; X.lineJoin = 'round';

    X.beginPath(); X.moveTo(r.pts[0].x, r.pts[0].y);
    for (var i = 1; i < r.pts.length; i++) X.lineTo(r.pts[i].x, r.pts[i].y);
    X.strokeStyle = 'hsla(' + h + ',80%,55%,0.1)'; X.lineWidth = 10; X.stroke();

    X.beginPath(); X.moveTo(r.pts[0].x, r.pts[0].y);
    for (var i = 1; i < r.pts.length; i++) X.lineTo(r.pts[i].x, r.pts[i].y);
    X.strokeStyle = 'hsla(' + h + ',80%,55%,0.7)'; X.lineWidth = 2.5; X.stroke();

    X.beginPath(); X.moveTo(r.pts[0].x, r.pts[0].y);
    for (var i = 1; i < r.pts.length; i++) X.lineTo(r.pts[i].x, r.pts[i].y);
    X.strokeStyle = 'hsla(' + h + ',90%,80%,0.25)'; X.lineWidth = 1; X.stroke();

    for (var i = 0; i < r.pts.length; i++) {
      var p = r.pts[i];
      if (p.pin) {
        X.beginPath(); X.arc(p.x, p.y, 5, 0, 6.283);
        X.fillStyle = '#00f5d4'; X.fill();
        X.beginPath(); X.arc(p.x, p.y, 9, 0, 6.283);
        X.strokeStyle = 'rgba(0,245,212,0.2)'; X.lineWidth = 1; X.stroke();
      } else if (p === grabbedPt) {
        X.beginPath(); X.arc(p.x, p.y, 6, 0, 6.283);
        X.fillStyle = 'rgba(0,245,212,0.8)'; X.fill();
        X.beginPath(); X.arc(p.x, p.y, 12, 0, 6.283);
        X.strokeStyle = 'rgba(0,245,212,0.3)'; X.lineWidth = 1; X.stroke();
      } else {
        X.beginPath(); X.arc(p.x, p.y, 1.3, 0, 6.283);
        X.fillStyle = 'hsla(' + h + ',70%,60%,0.25)'; X.fill();
      }
    }
  }

  function spawnSparks(px, py) {
    for (var i = 0; i < 16; i++) {
      var a = Math.random() * 6.283, s = 1.5 + Math.random() * 4;
      sparks.push({
        x: px, y: py,
        vx: Math.cos(a) * s, vy: Math.sin(a) * s - 2,
        life: 1, r: 1 + Math.random() * 2.5,
        hue: i < 10 ? 170 + Math.random() * 25 : 260 + Math.random() * 25
      });
    }
  }

  function drawSparks() {
    for (var i = sparks.length - 1; i >= 0; i--) {
      var s = sparks[i];
      s.x += s.vx; s.y += s.vy; s.vy += 0.08; s.life -= 0.025;
      if (s.life <= 0) { sparks.splice(i, 1); continue; }
      X.beginPath(); X.arc(s.x, s.y, s.r * s.life, 0, 6.283);
      X.fillStyle = 'hsla(' + s.hue + ',80%,60%,' + s.life + ')'; X.fill();
    }
  }

  function findPt(x, y, md) {
    var best = null, bd = md * md;
    for (var ri = 0; ri < ropes.length; ri++)
      for (var i = 0; i < ropes[ri].pts.length; i++) {
        var p = ropes[ri].pts[i], dx = p.x - x, dy = p.y - y, d = dx * dx + dy * dy;
        if (d < bd) { bd = d; best = p; }
      }
    return best;
  }

  function findSeg(x, y, md) {
    var best = null, bd = md * md;
    for (var ri = 0; ri < ropes.length; ri++) {
      var r = ropes[ri];
      for (var i = 0; i < r.pts.length - 1; i++) {
        var a = r.pts[i], b = r.pts[i + 1];
        var dx = b.x - a.x, dy = b.y - a.y, l2 = dx * dx + dy * dy;
        var t = l2 < 0.001 ? 0 : ((x - a.x) * dx + (y - a.y) * dy) / l2;
        t = Math.max(0, Math.min(1, t));
        var px = a.x + t * dx, py = a.y + t * dy;
        var dd = (x - px) * (x - px) + (y - py) * (y - py);
        if (dd < bd) { bd = dd; best = { ri: ri, i: i + 1, x: px, y: py }; }
      }
    }
    return best;
  }

  function cutAt(x, y) {
    grabbedPt = null;
    var seg = findSeg(x, y, 30);
    if (!seg) return;
    var r = ropes[seg.ri];
    if (seg.i <= 0 || seg.i >= r.pts.length) return;
    var newPts = r.pts.splice(seg.i);
    if (newPts.length > 1) ropes.push({ pts: newPts, sl: r.sl, hue: r.hue });
    if (r.pts.length < 2) ropes.splice(seg.ri, 1);
    spawnSparks(seg.x, seg.y);
    if (typeof SFX !== 'undefined') SFX.play('pop');
    hud();
  }

  function hud() {
    var nn = 0;
    for (var i = 0; i < ropes.length; i++) nn += ropes[i].pts.length;
    document.getElementById('hudR').textContent = ropes.length;
    document.getElementById('hudN').textContent = nn;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) { cutAt(e.clientX, e.clientY); return; }
    mx = e.clientX; my = e.clientY;
    var p = findPt(mx, my, 30);
    if (p) {
      grabbedPt = p;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    } else if (ropes.length < MAX_ROPES) {
      ropes.push(mkRope(mx, my, 12 + Math.floor(Math.random() * 18)));
      if (typeof SFX !== 'undefined') SFX.play('spawn');
      hud();
    }
  });
  C.addEventListener('mousemove', function(e) { mx = e.clientX; my = e.clientY; });
  C.addEventListener('mouseup', function() { grabbedPt = null; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var lpt = null;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length >= 2) { cutAt(e.touches[0].clientX, e.touches[0].clientY); grabbedPt = null; return; }
    var t = e.touches[0]; mx = t.clientX; my = t.clientY;
    lpt = { x: mx, y: my, moved: false };
    var p = findPt(mx, my, 35);
    if (p) { grabbedPt = p; if (typeof SFX !== 'undefined') SFX.play('spawn'); }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0]; if (!t) return;
    mx = t.clientX; my = t.clientY;
    if (lpt && !lpt.moved) {
      var dx = mx - lpt.x, dy = my - lpt.y;
      if (dx * dx + dy * dy > 100) {
        lpt.moved = true;
        if (!grabbedPt && ropes.length < MAX_ROPES) {
          ropes.push(mkRope(lpt.x, lpt.y, 12 + Math.floor(Math.random() * 18)));
          var nr = ropes[ropes.length - 1];
          grabbedPt = nr.pts[nr.pts.length - 1];
          if (typeof SFX !== 'undefined') SFX.play('spawn');
          hud();
        }
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function() {
    if (lpt && !lpt.moved && !grabbedPt && ropes.length < MAX_ROPES) {
      ropes.push(mkRope(lpt.x, lpt.y, 12 + Math.floor(Math.random() * 18)));
      if (typeof SFX !== 'undefined') SFX.play('spawn');
      hud();
    }
    grabbedPt = null; lpt = null;
  });

  document.getElementById('sGrav').addEventListener('input', function() {
    grav = this.value * 0.015;
    document.getElementById('vGrav').textContent = grav.toFixed(2);
  });
  document.getElementById('sWind').addEventListener('input', function() {
    windAmp = this.value / 20;
    document.getElementById('vWind').textContent = windAmp.toFixed(1);
  });
  document.getElementById('sStiff').addEventListener('input', function() {
    iters = parseInt(this.value);
    document.getElementById('vStiff').textContent = iters;
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  function loop() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    if (grabbedPt) {
      grabbedPt.ox = grabbedPt.x; grabbedPt.oy = grabbedPt.y;
      grabbedPt.x = mx; grabbedPt.y = my;
    }

    for (var i = 0; i < ropes.length; i++) { updRope(ropes[i]); conRope(ropes[i]); }
    for (var i = 0; i < ropes.length; i++) drawRope(ropes[i]);
    drawSparks();

    if (frame % 30 === 0) hud();
    frame++;
    requestAnimationFrame(loop);
  }

  init();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
