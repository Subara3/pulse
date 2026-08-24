<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('harmonograph');

$pageName = 'harmonograph';
$pageTitle = 'HARMONOGRAPH - PULSE';
$pageDesc = 'ハーモノグラフ。減衰する振り子が描く繊細な幾何学模様。周波数比と位相が無限の曲線を生む。';
$pageCanonical = 'https://pulse.subara3.com/harmonograph.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  t: <span id="hudT" class="v">0.0</span><br>
  POINTS: <span id="hudP" class="v">0</span><br>
  PRESET: <span id="hudPreset" class="v">ROSE</span>
</div>

<div id="info">
  harmonograph · damped pendulums<br>
  x = <span class="v">&Sigma; A<sub>i</sub> sin(f<sub>i</sub>t+&phi;<sub>i</sub>) e<sup>&minus;d<sub>i</sub>t</sup></span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'random'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">DAMPING</label>
    <div class="slider-row">
      <input type="range" id="sldDamp" min="1" max="100" value="20">
      <span class="slider-value" id="valDamp">0.0020</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="50" max="600" value="300">
      <span class="slider-value" id="valSpeed">300</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">LINE WIDTH</label>
    <div class="slider-row">
      <input type="range" id="sldWidth" min="1" max="30" value="10">
      <span class="slider-value" id="valWidth">1.0</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESETS</label>
    <div class="preset-group" id="presets">
      <button class="preset-btn active" data-p="0">ROSE</button>
      <button class="preset-btn" data-p="1">SPIRAL</button>
      <button class="preset-btn" data-p="2">KNOT</button>
      <button class="preset-btn" data-p="3">DECAY</button>
      <button class="preset-btn" data-p="4">ORBIT</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnRandom">RANDOM</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, cx, cy, sc;

  var A = [1, .5, 1, .5];
  var F = [2, 3, 2, 3];
  var P = [0, 0, 1.5708, 1.5708];
  var D = [.002, .002, .002, .002];

  var t = 0, dt = 0.01, spf = 300;
  var lw = 1.0, tot = 0, active = true;
  var pName = 'ROSE';
  var prev = null;

  var PR = [
    { n: 'ROSE',   a: [1, .5, 1, .5],  f: [2, 3, 2, 3],       p: [0, 0, 1.5708, 1.5708],  d: [.002, .002, .002, .002] },
    { n: 'SPIRAL', a: [1, .8, 1, .8],  f: [2.01, 3, 3, 2.01],  p: [0, .7854, 1.5708, 0],   d: [.004, .004, .004, .004] },
    { n: 'KNOT',   a: [1, .7, 1, .7],  f: [3, 5, 4, 7],        p: [0, 1.0472, 0, .5236],   d: [.0015, .0015, .0015, .0015] },
    { n: 'DECAY',  a: [1, 1, 1, 1],    f: [2, 3, 3, 2],        p: [0, 0, 1.5708, .7854],   d: [.01, .008, .01, .008] },
    { n: 'ORBIT',  a: [1, .3, 1, .3],  f: [1, 4, 1, 4],        p: [0, 0, 1.5708, 1.5708],  d: [.002, .005, .002, .005] }
  ];

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    cx = W / 2; cy = H / 2;
    sc = Math.min(W, H) * 0.28;
    restart();
  }

  function clr() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
  }

  function restart() {
    t = 0; tot = 0; active = true; prev = null;
    clr();
  }

  function norm() {
    var mx = Math.max(A[0] + A[1], A[2] + A[3]);
    if (mx > 1.4) {
      var r = 1.4 / mx;
      for (var i = 0; i < 4; i++) A[i] *= r;
    }
  }

  function setParams(pr) {
    for (var i = 0; i < 4; i++) {
      A[i] = pr.a[i]; F[i] = pr.f[i]; P[i] = pr.p[i]; D[i] = pr.d[i];
    }
    norm();
  }

  function syncSlider() {
    var avg = (D[0] + D[1] + D[2] + D[3]) / 4;
    var sld = document.getElementById('sldDamp');
    sld.value = Math.max(1, Math.min(100, Math.round(avg * 10000)));
    document.getElementById('valDamp').textContent = avg.toFixed(4);
  }

  function clearPresetBtns() {
    var btns = document.querySelectorAll('.preset-btn');
    for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
  }

  function randomize() {
    var bases = [1, 2, 3, 4, 5, 6, 7];
    var bd = parseFloat(document.getElementById('valDamp').textContent) || .003;
    for (var i = 0; i < 4; i++) {
      F[i] = bases[(Math.random() * bases.length) | 0] + (Math.random() - 0.5) * 0.04;
      P[i] = Math.random() * 6.2832;
      A[i] = 0.4 + Math.random() * 0.6;
      D[i] = bd * (0.5 + Math.random());
    }
    norm();
    pName = 'RANDOM';
    document.getElementById('hudPreset').textContent = pName;
    clearPresetBtns();
    restart();
  }

  function applyPreset(idx) {
    setParams(PR[idx]);
    pName = PR[idx].n;
    document.getElementById('hudPreset').textContent = pName;
    syncSlider();
    restart();
  }

  function pt(time) {
    var x = A[0] * Math.sin(F[0] * time + P[0]) * Math.exp(-D[0] * time)
          + A[1] * Math.sin(F[1] * time + P[1]) * Math.exp(-D[1] * time);
    var y = A[2] * Math.sin(F[2] * time + P[2]) * Math.exp(-D[2] * time)
          + A[3] * Math.sin(F[3] * time + P[3]) * Math.exp(-D[3] * time);
    return { x: cx + x * sc, y: cy + y * sc };
  }

  function draw() {
    if (!active) return;

    var mxD = Math.max(D[0], D[1], D[2], D[3]);
    var mxT = Math.min(8 / mxD, 3000);

    var ef = [Math.exp(-D[0] * dt), Math.exp(-D[1] * dt), Math.exp(-D[2] * dt), Math.exp(-D[3] * dt)];
    var ev = [Math.exp(-D[0] * t), Math.exp(-D[1] * t), Math.exp(-D[2] * t), Math.exp(-D[3] * t)];
    var emf = Math.exp(-mxD * dt);
    var emv = Math.exp(-mxD * t);

    if (!prev) prev = pt(t);

    ctx.lineCap = 'round';

    for (var i = 0; i < spf; i++) {
      if (t >= mxT) { active = false; break; }

      t += dt;
      for (var j = 0; j < 4; j++) ev[j] *= ef[j];
      emv *= emf;

      var nx = A[0] * Math.sin(F[0] * t + P[0]) * ev[0]
             + A[1] * Math.sin(F[1] * t + P[1]) * ev[1];
      var ny = A[2] * Math.sin(F[2] * t + P[2]) * ev[2]
             + A[3] * Math.sin(F[3] * t + P[3]) * ev[3];
      var cur = { x: cx + nx * sc, y: cy + ny * sc };

      var alpha = Math.max(0.03, emv * 0.65);
      var hue = 175 + (1 - emv) * 100;
      var w = lw * (0.3 + emv * 0.7);

      if (emv > 0.15) {
        ctx.beginPath();
        ctx.moveTo(prev.x, prev.y);
        ctx.lineTo(cur.x, cur.y);
        ctx.strokeStyle = 'hsla(' + hue + ',80%,70%,' + (alpha * 0.12) + ')';
        ctx.lineWidth = w * 4;
        ctx.stroke();
      }

      ctx.beginPath();
      ctx.moveTo(prev.x, prev.y);
      ctx.lineTo(cur.x, cur.y);
      ctx.strokeStyle = 'hsla(' + hue + ',70%,55%,' + alpha + ')';
      ctx.lineWidth = w;
      ctx.stroke();

      prev = cur;
      tot++;
    }

    document.getElementById('hudT').textContent = t.toFixed(1);
    document.getElementById('hudP').textContent = tot >= 1000 ? (tot / 1000).toFixed(1) + 'K' : tot;
  }

  function loop() { draw(); requestAnimationFrame(loop); }

  var md = false, mx0 = 0, my0 = 0;
  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    md = true; mx0 = e.clientX; my0 = e.clientY;
  });
  C.addEventListener('mouseup', function(e) {
    if (md) {
      var dx = e.clientX - mx0, dy = e.clientY - my0;
      if (dx * dx + dy * dy < 36) {
        randomize();
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }
    md = false;
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    clr(); tot = 0; t = 0; active = false; prev = null;
    document.getElementById('hudT').textContent = '0.0';
    document.getElementById('hudP').textContent = '0';
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  var tS = 0, tM = false;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    tS = Date.now(); tM = false;
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault(); tM = true;
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    var elapsed = Date.now() - tS;
    if (!tM && elapsed < 300) {
      randomize();
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    } else if (!tM && elapsed >= 500) {
      clr(); tot = 0; t = 0; active = false; prev = null;
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  }, { passive: false });

  document.getElementById('sldDamp').addEventListener('input', function() {
    var nv = parseInt(this.value) / 10000;
    var old = parseFloat(document.getElementById('valDamp').textContent) || 0.003;
    if (old > 0) {
      var r = nv / old;
      for (var i = 0; i < 4; i++) D[i] *= r;
    }
    document.getElementById('valDamp').textContent = nv.toFixed(4);
    restart();
  });

  document.getElementById('sldSpeed').addEventListener('input', function() {
    spf = parseInt(this.value);
    document.getElementById('valSpeed').textContent = this.value;
  });

  document.getElementById('sldWidth').addEventListener('input', function() {
    lw = parseInt(this.value) / 10;
    document.getElementById('valWidth').textContent = lw.toFixed(1);
    restart();
  });

  var pBtns = document.querySelectorAll('.preset-btn');
  for (var i = 0; i < pBtns.length; i++) {
    pBtns[i].addEventListener('click', function() {
      var idx = parseInt(this.getAttribute('data-p'));
      applyPreset(idx);
      clearPresetBtns();
      this.classList.add('active');
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  document.getElementById('btnRandom').addEventListener('click', function() {
    randomize();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    clr(); tot = 0; t = 0; active = false; prev = null;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  applyPreset(0);
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
