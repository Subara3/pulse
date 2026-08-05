<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('wavemachine');

$pageName = 'wavemachine';
$pageTitle = 'WAVE MACHINE - PULSE';
$pageDesc = 'ねじれ波動マシン。棒の鎖を弾くと波が走り、固定端・自由端・吸収端で反射が変わる。時空図に波の全履歴を描く。';
$pageCanonical = 'https://pulse.subara3.com/wavemachine.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>ELEMENTS <span id="hud-n">0</span></div>
  <div>ENERGY <span id="hud-energy">0.00</span></div>
  <div>LEFT <span id="hud-left">FIXED</span></div>
  <div>RIGHT <span id="hud-right">FIXED</span></div>
</div>
<div id="info-label"><span>WAVE MACHINE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'excite'],
  ['type' => 'drag', 'label' => 'draw'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">LEFT BOUNDARY</label></div>
  <div class="btn-row" id="bc-left-btns">
    <button class="ctrl-btn active" data-val="fixed">FIXED</button>
    <button class="ctrl-btn" data-val="free">FREE</button>
    <button class="ctrl-btn" data-val="absorb">ABSORB</button>
  </div>
  <div class="slider-row"><label class="slider-label">RIGHT BOUNDARY</label></div>
  <div class="btn-row" id="bc-right-btns">
    <button class="ctrl-btn active" data-val="fixed">FIXED</button>
    <button class="ctrl-btn" data-val="free">FREE</button>
    <button class="ctrl-btn" data-val="absorb">ABSORB</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="5" max="50" value="20" step="1">
    <span class="slider-value" id="sv-speed">2.0</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">DAMP</label>
    <input type="range" id="sl-damp" min="0" max="30" value="3" step="1">
    <span class="slider-value" id="sv-damp">3</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">DRIVE FREQ</label>
    <input type="range" id="sl-freq" min="1" max="15" value="3" step="1">
    <span class="slider-value" id="sv-freq">n=3</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-pulse">PULSE</button>
    <button class="ctrl-btn" id="btn-drive">DRIVE</button>
    <button class="ctrl-btn" id="btn-clear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;

  var TEAL = [0, 245, 212];
  var PUR = [123, 44, 191];
  var BG = [10, 10, 15];

  var N, theta, omega;
  var speed = 2.0;
  var damp = 0.009;
  var DT = 0.04;
  var SUB = 12;
  var maxA = 1.2;

  var bcL = 'fixed', bcR = 'fixed';
  var driving = false, driveN = 3, driveT = 0, driveA = 0.7;

  var stC, stX, stH;
  var cY, cX0, cX1, cW, dY, dH, rSp, maxRH;
  var acting = false;

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) { return [a[0] + (b[0] - a[0]) * t | 0, a[1] + (b[1] - a[1]) * t | 0, a[2] + (b[2] - a[2]) * t | 0]; }

  function init() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);

    var avail = W * 0.85;
    var sp = Math.max(3, Math.min(7, avail / 150));
    N = Math.max(30, Math.min(250, Math.floor(avail / sp)));
    theta = new Float64Array(N);
    omega = new Float64Array(N);

    cX0 = W * 0.075;
    rSp = avail / (N - 1);
    cX1 = cX0 + (N - 1) * rSp;
    cW = cX1 - cX0;
    cY = H * 0.27;
    maxRH = H * 0.11;
    dY = cY + maxRH + H * 0.05;
    dH = Math.max(50, H - dY - 10);

    stC = document.createElement('canvas');
    stX = stC.getContext('2d');
    stC.width = N;
    stH = Math.max(50, Math.floor(dH));
    stC.height = stH;
    stX.fillStyle = 'rgb(10,10,15)';
    stX.fillRect(0, 0, N, stH);

    document.getElementById('hud-n').textContent = N;
  }

  function step() {
    var c2 = speed * speed;
    var aLen = Math.max(5, N * 0.1 | 0);

    for (var s = 0; s < SUB; s++) {
      for (var i = 1; i < N - 1; i++) {
        omega[i] += (c2 * (theta[i - 1] - 2 * theta[i] + theta[i + 1]) - damp * omega[i]) * DT;
      }

      if (bcL === 'absorb') {
        for (var i = 0; i < aLen; i++) {
          var f = (aLen - i) / aLen;
          omega[i] -= 20 * f * f * omega[i] * DT;
        }
      }
      if (bcR === 'absorb') {
        for (var i = N - aLen; i < N; i++) {
          var f = (i - (N - aLen)) / aLen;
          omega[i] -= 20 * f * f * omega[i] * DT;
        }
      }

      for (var i = 0; i < N; i++) theta[i] += omega[i] * DT;

      if (driving) {
        driveT += DT;
        var w = driveN * Math.PI * speed / N;
        theta[0] = driveA * Math.sin(w * driveT);
        omega[0] = driveA * w * Math.cos(w * driveT);
      } else {
        if (bcL === 'fixed') { theta[0] = 0; omega[0] = 0; }
        else { theta[0] = theta[1]; omega[0] = omega[1]; }
      }

      if (bcR === 'fixed') { theta[N - 1] = 0; omega[N - 1] = 0; }
      else { theta[N - 1] = theta[N - 2]; omega[N - 1] = omega[N - 2]; }
    }
  }

  function updateST() {
    stX.drawImage(stC, 0, -1);
    var row = stX.createImageData(N, 1);
    var d = row.data;
    for (var i = 0; i < N; i++) {
      var v = Math.max(-1, Math.min(1, theta[i] / maxA));
      var av = Math.abs(v);
      var gv = Math.pow(av, 0.55);
      var c = v >= 0 ? mix(BG, TEAL, gv) : mix(BG, PUR, gv);
      var j = i * 4;
      d[j] = c[0]; d[j + 1] = c[1]; d[j + 2] = c[2]; d[j + 3] = 255;
    }
    stX.putImageData(row, 0, stH - 1);
  }

  function draw() {
    X.fillStyle = 'rgb(10,10,15)';
    X.fillRect(0, 0, W, H);

    var bg = X.createRadialGradient(W / 2, cY, 0, W / 2, cY, W * 0.45);
    bg.addColorStop(0, 'rgba(0,245,212,0.012)');
    bg.addColorStop(1, 'transparent');
    X.fillStyle = bg;
    X.fillRect(0, 0, W, H);

    X.imageSmoothingEnabled = false;
    X.drawImage(stC, cX0, dY, cW, dH);
    X.imageSmoothingEnabled = true;

    X.strokeStyle = rgba(TEAL, 0.07);
    X.lineWidth = 1;
    X.strokeRect(cX0, dY, cW, dH);

    X.save();
    X.translate(cX0 - 14, dY + dH / 2);
    X.rotate(-Math.PI / 2);
    X.fillStyle = 'rgba(255,255,255,0.07)';
    X.font = '9px "Orbitron",monospace';
    X.textAlign = 'center';
    X.fillText('TIME', 0, 0);
    X.restore();

    X.strokeStyle = 'rgba(255,255,255,0.06)';
    X.lineWidth = 1;
    X.beginPath();
    X.moveTo(cX0, cY);
    X.lineTo(cX1, cY);
    X.stroke();

    drawBC(cX0, cY, driving ? 'drive' : bcL, true);
    drawBC(cX1, cY, bcR, false);

    X.beginPath();
    for (var i = 0; i < N; i++) {
      var x = cX0 + i * rSp;
      var v = Math.max(-1, Math.min(1, theta[i] / maxA));
      var y = cY - v * maxRH;
      i === 0 ? X.moveTo(x, y) : X.lineTo(x, y);
    }
    X.strokeStyle = rgba(TEAL, 0.1);
    X.lineWidth = 1;
    X.stroke();

    var rw = Math.min(3, rSp * 0.4);
    for (var i = 0; i < N; i++) {
      var x = cX0 + i * rSp;
      var v = theta[i] / maxA;
      v = Math.max(-1, Math.min(1, v));
      var av = Math.abs(v);
      if (av < 0.008) continue;

      var h = v * maxRH;
      var col = v >= 0 ? mix(BG, TEAL, av) : mix(BG, PUR, av);

      X.strokeStyle = rgba(col, 0.3 + av * 0.7);
      X.lineWidth = rw;
      X.beginPath();
      X.moveTo(x, cY);
      X.lineTo(x, cY - h);
      X.stroke();

      if (av > 0.03) {
        var gr = 3 + av * 8;
        var gd = X.createRadialGradient(x, cY - h, 0, x, cY - h, gr);
        gd.addColorStop(0, rgba(col, 0.5 * av));
        gd.addColorStop(1, 'transparent');
        X.fillStyle = gd;
        X.beginPath();
        X.arc(x, cY - h, gr, 0, Math.PI * 2);
        X.fill();
      }

      X.fillStyle = rgba(col, 0.4 + av * 0.6);
      X.beginPath();
      X.arc(x, cY - h, Math.max(1, rw * 0.5), 0, Math.PI * 2);
      X.fill();
    }

    if (driving) {
      var p = 0.2 + 0.5 * Math.abs(Math.sin(driveT * 4));
      X.fillStyle = rgba(TEAL, p);
      X.beginPath();
      X.arc(cX0 - 10, cY, 4, 0, Math.PI * 2);
      X.fill();
    }

    document.getElementById('hud-left').textContent = driving ? 'DRIVE' : bcL.toUpperCase();
    document.getElementById('hud-right').textContent = bcR.toUpperCase();
    var E = 0;
    for (var i = 0; i < N; i++) {
      E += 0.5 * omega[i] * omega[i];
      if (i < N - 1) { var df = theta[i + 1] - theta[i]; E += 0.5 * speed * speed * df * df; }
    }
    document.getElementById('hud-energy').textContent = E.toFixed(2);

    requestAnimationFrame(draw);
  }

  function drawBC(x, y, type, left) {
    if (type === 'fixed') {
      X.fillStyle = rgba(TEAL, 0.2);
      X.fillRect(x - 3, y - 25, 6, 50);
      X.strokeStyle = rgba(TEAL, 0.12);
      X.lineWidth = 1;
      for (var dy = -22; dy <= 22; dy += 7) {
        X.beginPath();
        X.moveTo(x - 2, y + dy);
        X.lineTo(x + 2, y + dy + 4);
        X.stroke();
      }
    } else if (type === 'free') {
      X.strokeStyle = rgba(TEAL, 0.3);
      X.lineWidth = 1.5;
      X.beginPath();
      X.arc(x, y, 5, 0, Math.PI * 2);
      X.stroke();
    } else if (type === 'absorb') {
      var gd = X.createLinearGradient(x, y, x + (left ? 30 : -30), y);
      gd.addColorStop(0, rgba(PUR, 0.2));
      gd.addColorStop(1, 'transparent');
      X.fillStyle = gd;
      X.fillRect(left ? x - 4 : x - 26, y - 25, 30, 50);
    } else if (type === 'drive') {
      X.strokeStyle = rgba(TEAL, 0.35);
      X.lineWidth = 1.5;
      X.beginPath();
      X.moveTo(x + 3 * Math.sin(-20 * 0.25 + driveT * 5) - 6, y - 20);
      for (var dy = -19; dy <= 20; dy++) {
        X.lineTo(x + 3 * Math.sin(dy * 0.25 + driveT * 5) - 6, y + dy);
      }
      X.stroke();
    }
  }

  function getIdx(px) {
    return Math.max(0, Math.min(N - 1, Math.round((px - cX0) / cW * (N - 1))));
  }

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    e.preventDefault();
    var py = e.clientY, px = e.clientX;
    if (py > cY - maxRH * 1.5 && py < cY + maxRH * 1.5 && px >= cX0 - 20 && px <= cX1 + 20) {
      acting = true;
      poke(px, py);
      SFX.play('spawn');
    }
  });

  C.addEventListener('pointermove', function(e) {
    if (!acting) return;
    e.preventDefault();
    poke(e.clientX, e.clientY);
  });

  C.addEventListener('pointerup', function() {
    if (acting) { acting = false; SFX.play('ripple'); }
  });

  C.addEventListener('pointercancel', function() { acting = false; });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length > 1) e.preventDefault();
  }, { passive: false });

  function poke(px, py) {
    var i = getIdx(px);
    var d = (cY - py) / maxRH * maxA;
    d = Math.max(-maxA * 1.5, Math.min(maxA * 1.5, d));
    theta[i] = d; omega[i] = 0;
    for (var r = 1; r <= 2; r++) {
      var f = 1 - r / 3;
      if (i - r >= 0) { theta[i - r] = theta[i - r] * (1 - f) + d * f; omega[i - r] *= (1 - f); }
      if (i + r < N) { theta[i + r] = theta[i + r] * (1 - f) + d * f; omega[i + r] *= (1 - f); }
    }
  }

  function pulse() {
    var w = Math.max(3, N * 0.03 | 0);
    var c0 = N * 0.12 | 0;
    var amp = maxA * 0.7;
    for (var i = 0; i < N; i++) {
      var x = i - c0;
      var g = amp * Math.exp(-x * x / (2 * w * w));
      theta[i] += g;
      omega[i] += speed * (x / (w * w)) * g;
    }
    SFX.play('spawn');
  }

  function clearAll() {
    for (var i = 0; i < N; i++) { theta[i] = 0; omega[i] = 0; }
    driveT = 0;
    stX.fillStyle = 'rgb(10,10,15)';
    stX.fillRect(0, 0, N, stH);
    SFX.play('click');
  }

  document.getElementById('bc-left-btns').addEventListener('click', function(e) {
    var b = e.target.closest('[data-val]');
    if (!b) return;
    bcL = b.dataset.val;
    this.querySelectorAll('.ctrl-btn').forEach(function(btn) { btn.classList.remove('active'); });
    b.classList.add('active');
    SFX.play('toggle');
  });

  document.getElementById('bc-right-btns').addEventListener('click', function(e) {
    var b = e.target.closest('[data-val]');
    if (!b) return;
    bcR = b.dataset.val;
    this.querySelectorAll('.ctrl-btn').forEach(function(btn) { btn.classList.remove('active'); });
    b.classList.add('active');
    SFX.play('toggle');
  });

  var slS = document.getElementById('sl-speed'), svS = document.getElementById('sv-speed');
  slS.addEventListener('input', function() {
    speed = this.value / 10;
    svS.textContent = speed.toFixed(1);
    SFX.play('move', 80);
  });

  var slD = document.getElementById('sl-damp'), svD = document.getElementById('sv-damp');
  slD.addEventListener('input', function() {
    damp = this.value * 0.003;
    svD.textContent = this.value;
    SFX.play('move', 80);
  });

  var slF = document.getElementById('sl-freq'), svF = document.getElementById('sv-freq');
  slF.addEventListener('input', function() {
    driveN = parseInt(this.value);
    svF.textContent = 'n=' + driveN;
    SFX.play('move', 80);
  });

  document.getElementById('btn-pulse').addEventListener('click', pulse);

  document.getElementById('btn-drive').addEventListener('click', function() {
    driving = !driving;
    this.classList.toggle('active', driving);
    if (!driving) driveT = 0;
    SFX.play('toggle');
  });

  document.getElementById('btn-clear').addEventListener('click', clearAll);

  function tick() {
    step();
    updateST();
    setTimeout(tick, 16);
  }

  init();

  window.addEventListener('resize', function() {
    var ot = theta ? theta.slice() : null;
    var oo = omega ? omega.slice() : null;
    var oN = N;
    acting = false;
    init();
    if (ot && oN > 0 && N > 0) {
      for (var i = 0; i < N; i++) {
        var src = i / (N - 1) * (oN - 1);
        var lo = src | 0, hi = Math.min(oN - 1, lo + 1), t = src - lo;
        theta[i] = ot[lo] * (1 - t) + ot[hi] * t;
        omega[i] = oo[lo] * (1 - t) + oo[hi] * t;
      }
    }
  });

  pulse();
  tick();
  draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
