<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('reach');

$pageName = 'reach';
$pageTitle = 'REACH - PULSE';
$pageDesc = '逆運動学（FABRIK）。鎖状のアームがターゲットに伸びる。前方・後方の反復で解が収束する。';
$pageCanonical = 'https://pulse.subara3.com/reach.php';

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
  ARMS: <span id="hudArms" class="v">0</span><br>
  JOINTS: <span id="hudJoints" class="v">0</span><br>
  REACH: <span id="hudReach" class="v">0</span>%
</div>

<?php
$pageInfo = [
  'FABRIK inverse kinematics',
  ['accent' => 'forward-backward reaching'],
  'iterative constraint solver',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add arm'],
  ['type' => 'drag', 'label' => 'target'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">SEGMENTS</label>
    <div class="slider-row">
      <input type="range" id="sldSeg" min="3" max="20" value="8">
      <span class="slider-value" id="valSeg">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">LENGTH</label>
    <div class="slider-row">
      <input type="range" id="sldLen" min="10" max="60" value="30">
      <span class="slider-value" id="valLen">30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">CONSTRAINT</label>
    <div class="slider-row">
      <input type="range" id="sldAng" min="15" max="180" value="150">
      <span class="slider-value" id="valAng">150°</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESET</label>
    <div class="preset-group" id="presetGrp">
      <button class="preset-btn active" data-p="anemone">ANEMONE</button>
      <button class="preset-btn" data-p="garden">GARDEN</button>
      <button class="preset-btn" data-p="spine">SPINE</button>
      <button class="preset-btn" data-p="scatter">SCATTER</button>
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
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;

  var nSeg = 8, segLen = 30, angLimit = 150 * Math.PI / 180;
  var ITER = 10, MAX = 30;
  var arms = [], aCnt = 0;
  var mx, my, idle = 0, idleT = 0;
  var lt = 0, preset = 'anemone';
  var pDown = false, sPos = null, didDr = false, isR = false;

  var HUES = [175, 182, 190, 197, 268, 275, 283, 178, 186, 272];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function mkArm(bx, by) {
    var joints = [];
    for (var i = 0; i <= nSeg; i++)
      joints.push({ x: bx, y: by + i * segLen });
    return { base: {x: bx, y: by}, joints: joints, hue: HUES[aCnt++ % HUES.length], trail: [] };
  }

  function solve(arm, tx, ty) {
    var j = arm.joints, n = j.length, sl = segLen;
    var bx = arm.base.x, by = arm.base.y;
    for (var it = 0; it < ITER; it++) {
      j[n - 1].x = tx; j[n - 1].y = ty;
      for (var i = n - 2; i >= 0; i--) {
        var dx = j[i].x - j[i + 1].x, dy = j[i].y - j[i + 1].y;
        var d = Math.sqrt(dx * dx + dy * dy) || 1;
        j[i].x = j[i + 1].x + dx / d * sl;
        j[i].y = j[i + 1].y + dy / d * sl;
      }
      j[0].x = bx; j[0].y = by;
      for (var i = 1; i < n; i++) {
        var dx = j[i].x - j[i - 1].x, dy = j[i].y - j[i - 1].y;
        var d = Math.sqrt(dx * dx + dy * dy) || 1;
        j[i].x = j[i - 1].x + dx / d * sl;
        j[i].y = j[i - 1].y + dy / d * sl;
        if (angLimit < Math.PI && i >= 2) {
          var pa = Math.atan2(j[i - 1].y - j[i - 2].y, j[i - 1].x - j[i - 2].x);
          var ca = Math.atan2(j[i].y - j[i - 1].y, j[i].x - j[i - 1].x);
          var df = ca - pa;
          if (df > Math.PI) df -= 6.2832;
          if (df < -Math.PI) df += 6.2832;
          if (df > angLimit) {
            var a = pa + angLimit;
            j[i].x = j[i - 1].x + Math.cos(a) * sl;
            j[i].y = j[i - 1].y + Math.sin(a) * sl;
          } else if (df < -angLimit) {
            var a = pa - angLimit;
            j[i].x = j[i - 1].x + Math.cos(a) * sl;
            j[i].y = j[i - 1].y + Math.sin(a) * sl;
          }
        }
      }
    }
    var tip = j[n - 1];
    arm.trail.push({ x: tip.x, y: tip.y });
    if (arm.trail.length > 35) arm.trail.shift();
  }

  function drawArm(arm) {
    var j = arm.joints, n = j.length, h = arm.hue;

    if (arm.trail.length > 1) {
      for (var i = 1; i < arm.trail.length; i++) {
        var a = i / arm.trail.length * 0.25;
        ctx.beginPath();
        ctx.moveTo(arm.trail[i - 1].x, arm.trail[i - 1].y);
        ctx.lineTo(arm.trail[i].x, arm.trail[i].y);
        ctx.strokeStyle = 'hsla(' + h + ',60%,55%,' + a.toFixed(3) + ')';
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    }

    ctx.lineCap = 'round'; ctx.lineJoin = 'round';

    for (var i = 0; i < n - 1; i++) {
      var t = i / (n - 1);
      ctx.beginPath();
      ctx.moveTo(j[i].x, j[i].y); ctx.lineTo(j[i + 1].x, j[i + 1].y);
      ctx.strokeStyle = 'hsla(' + h + ',60%,55%,0.06)';
      ctx.lineWidth = 10 * (1 - t * 0.5);
      ctx.stroke();
    }

    for (var i = 0; i < n - 1; i++) {
      var t = i / (n - 1);
      ctx.beginPath();
      ctx.moveTo(j[i].x, j[i].y); ctx.lineTo(j[i + 1].x, j[i + 1].y);
      ctx.strokeStyle = 'hsla(' + h + ',65%,55%,' + (0.45 + t * 0.4).toFixed(2) + ')';
      ctx.lineWidth = 3 * (1 - t * 0.6);
      ctx.stroke();
    }

    for (var i = 0; i < n; i++) {
      var r = i === 0 ? 4.5 : (i === n - 1 ? 3.5 : 1.8);
      var a = i === 0 ? 0.6 : (i === n - 1 ? 0.85 : 0.3);
      ctx.beginPath();
      ctx.arc(j[i].x, j[i].y, r, 0, 6.2832);
      ctx.fillStyle = 'hsla(' + h + ',65%,65%,' + a + ')';
      ctx.fill();
    }

    var tip = j[n - 1];
    ctx.beginPath();
    ctx.arc(tip.x, tip.y, 9, 0, 6.2832);
    ctx.fillStyle = 'hsla(' + h + ',60%,55%,0.08)';
    ctx.fill();
  }

  function drawTarget() {
    var t = performance.now() * 0.003;
    var p = 0.5 + 0.5 * Math.sin(t);
    ctx.beginPath();
    ctx.arc(mx, my, 14 + p * 4, 0, 6.2832);
    ctx.fillStyle = 'rgba(123,44,191,0.04)';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(mx, my, 6 + p * 2, 0, 6.2832);
    ctx.strokeStyle = 'rgba(123,44,191,' + (0.2 + p * 0.15).toFixed(2) + ')';
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(mx, my, 2, 0, 6.2832);
    ctx.fillStyle = 'rgba(123,44,191,0.6)';
    ctx.fill();
  }

  function frame(t) {
    var dt = Math.min((t - lt) / 1000, 0.05); lt = t;

    idle += dt;
    if (idle > 2) {
      idleT += dt * 0.5;
      mx = W * 0.5 + Math.cos(idleT * 0.7) * Math.min(W, H) * 0.22;
      my = H * 0.5 + Math.sin(idleT * 1.1) * Math.min(W, H) * 0.18;
    }

    for (var i = 0; i < arms.length; i++) solve(arms[i], mx, my);

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    for (var i = 0; i < arms.length; i++) drawArm(arms[i]);
    drawTarget();

    var nj = 0, rSum = 0;
    for (var i = 0; i < arms.length; i++) {
      var j = arms[i].joints; nj += j.length;
      var tip = j[j.length - 1];
      var dx = tip.x - mx, dy = tip.y - my;
      var d = Math.sqrt(dx * dx + dy * dy);
      var maxD = (j.length - 1) * segLen;
      rSum += maxD > 0 ? Math.max(0, 1 - d / maxD) : 0;
    }
    document.getElementById('hudArms').textContent = arms.length;
    document.getElementById('hudJoints').textContent = nj;
    document.getElementById('hudReach').textContent = arms.length > 0 ? Math.round(rSum / arms.length * 100) : 0;

    requestAnimationFrame(frame);
  }

  function nearest(x, y) {
    var best = -1, bd = 120;
    for (var i = 0; i < arms.length; i++) {
      var b = arms[i].base;
      var d = Math.sqrt((b.x - x) * (b.x - x) + (b.y - y) * (b.y - y));
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function setSld(id, val, sfx) {
    document.getElementById(id).value = val;
    document.getElementById(id.replace('sld', 'val')).textContent = val + (sfx || '');
  }

  function rebuild() {
    for (var i = 0; i < arms.length; i++) {
      var bx = arms[i].base.x, by = arms[i].base.y, jts = [];
      for (var k = 0; k <= nSeg; k++) jts.push({ x: bx, y: by + k * segLen });
      arms[i].joints = jts; arms[i].trail = [];
    }
  }

  function loadP(name) {
    preset = name; arms.length = 0; aCnt = 0;
    var cfg = {
      anemone: [8, 30, 150],
      garden: [10, 40, 120],
      spine: [20, 22, 150],
      scatter: [5, 25, 180]
    };
    var c = cfg[name] || cfg.anemone;
    nSeg = c[0]; segLen = c[1]; angLimit = c[2] * Math.PI / 180;
    setSld('sldSeg', nSeg); setSld('sldLen', segLen); setSld('sldAng', c[2], '°');

    if (name === 'anemone') {
      var cx = W * 0.5, cy = H * 0.5, r = Math.min(W, H) * 0.15;
      for (var i = 0; i < 10; i++) {
        var a = i / 10 * 6.2832;
        arms.push(mkArm(cx + Math.cos(a) * r, cy + Math.sin(a) * r));
      }
    } else if (name === 'garden') {
      for (var i = 0; i < 8; i++)
        arms.push(mkArm(W * (0.1 + 0.8 * i / 7), H * 0.92));
    } else if (name === 'spine') {
      arms.push(mkArm(W * 0.5, H * 0.85));
    } else if (name === 'scatter') {
      for (var i = 0; i < 16; i++)
        arms.push(mkArm(W * (0.1 + Math.random() * 0.8), H * (0.15 + Math.random() * 0.7)));
    }

    mx = W * 0.5; my = H * 0.35; idle = 3; idleT = 0;
    for (var i = 0; i < arms.length; i++) solve(arms[i], mx, my);
  }

  C.addEventListener('pointerdown', function(e) {
    e.preventDefault();
    C.setPointerCapture(e.pointerId);
    pDown = true; didDr = false; isR = e.button === 2;
    sPos = { x: e.clientX, y: e.clientY };
    mx = e.clientX; my = e.clientY; idle = 0;
    if (isR) {
      var idx = nearest(e.clientX, e.clientY);
      if (idx >= 0) { arms.splice(idx, 1); if (typeof SFX !== 'undefined') SFX.play('pop'); }
    }
  });

  C.addEventListener('pointermove', function(e) {
    mx = e.clientX; my = e.clientY; idle = 0;
    if (pDown && sPos) {
      var dx = e.clientX - sPos.x, dy = e.clientY - sPos.y;
      if (dx * dx + dy * dy > 25) didDr = true;
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (!pDown) return; pDown = false;
    if (!isR && !didDr && arms.length < MAX) {
      arms.push(mkArm(e.clientX, e.clientY));
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
    isR = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  document.getElementById('sldSeg').addEventListener('input', function() {
    nSeg = +this.value; document.getElementById('valSeg').textContent = this.value;
    rebuild();
  });
  document.getElementById('sldLen').addEventListener('input', function() {
    segLen = +this.value; document.getElementById('valLen').textContent = this.value;
    rebuild();
  });
  document.getElementById('sldAng').addEventListener('input', function() {
    angLimit = +this.value * Math.PI / 180;
    document.getElementById('valAng').textContent = this.value + '°';
  });

  document.querySelectorAll('#presetGrp .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#presetGrp .preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      loadP(this.dataset.p);
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    loadP(preset);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  window.addEventListener('resize', resize);
  loadP('anemone');
  lt = performance.now();
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
