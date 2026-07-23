<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('drumhead');

$pageName = 'drumhead';
$pageTitle = 'DRUMHEAD - PULSE';
$pageDesc = '円形膜の振動モード。ベッセル関数の固有振動が描く同心円と放射線の節線パターン。';
$pageCanonical = 'https://pulse.subara3.com/drumhead.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Orbitron",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MODE (<span id="hud-m">0</span>,<span id="hud-n">1</span>)</div>
  <div>FREQ <span id="hud-f">1.00</span></div>
  <div>ENERGY <span id="hud-e">0.00</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'excite'],
  ['type' => 'drag', 'label' => 'strike'],
  ['type' => 'wheel', 'label' => 'damping'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">M (angular)</label>
    <input type="range" id="sl-m" min="0" max="6" value="0">
    <span class="slider-value" id="sv-m">0</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">N (radial)</label>
    <input type="range" id="sl-n" min="1" max="6" value="1">
    <span class="slider-value" id="sv-n">1</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">DAMPING</label>
    <input type="range" id="sl-damp" min="0" max="100" value="15">
    <span class="slider-value" id="sv-damp">15</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="30" value="10">
    <span class="slider-value" id="sv-speed">10</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-mix">MIX</button>
    <button class="ctrl-btn active" id="btn-3d">3D</button>
    <button class="ctrl-btn" id="btn-nodal">NODAL</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, cx, cy, memR;

  var hudM = document.getElementById('hud-m');
  var hudN = document.getElementById('hud-n');
  var hudF = document.getElementById('hud-f');
  var hudE = document.getElementById('hud-e');

  // Bessel function zeros (J_m has zeros at alpha_{m,n})
  // Precomputed for m=0..6, n=1..6
  var ZEROS = [
    [2.4048, 5.5201, 8.6537, 11.7915, 14.9309, 18.0711], // m=0
    [3.8317, 7.0156, 10.1735, 13.3237, 16.4706, 19.6159], // m=1
    [5.1356, 8.4172, 11.6198, 14.7960, 17.9598, 21.1170], // m=2
    [6.3802, 9.7610, 13.0152, 16.2235, 19.4094, 22.5828], // m=3
    [7.5883, 11.0647, 14.3725, 17.6160, 20.8269, 24.0190], // m=4
    [8.7715, 12.3386, 15.7002, 18.9801, 22.2178, 25.4303], // m=5
    [9.9361, 13.5893, 17.0038, 20.3208, 23.5861, 26.8202]  // m=6
  ];

  function besselJ(m, x) {
    if (x === 0) return m === 0 ? 1 : 0;
    var sum = 0, term = 1;
    var halfX = x * 0.5;
    for (var k = 0; k < m; k++) term *= halfX;
    var factK = 1;
    for (var k = 0; k <= 20; k++) {
      if (k > 0) {
        factK *= k;
        term *= -halfX * halfX / (k * (m + k));
      } else {
        var factM = 1;
        for (var j = 1; j <= m; j++) factM *= j;
        term /= factM;
      }
      sum += term;
      if (Math.abs(term) < 1e-12) break;
    }
    return sum;
  }

  // Better Bessel implementation using series expansion
  function besselJ2(m, x) {
    if (Math.abs(x) < 1e-15) return m === 0 ? 1 : 0;
    var hx = x / 2;
    var sum = 0;
    var t = 1;
    for (var i = 1; i <= m; i++) t *= hx / i;
    for (var k = 0; k < 30; k++) {
      sum += t;
      t *= -(hx * hx) / ((k + 1) * (k + 1 + m));
      if (Math.abs(t) < 1e-14 * Math.abs(sum + 1e-30)) break;
    }
    return sum;
  }

  // Active modes
  var modes = [];
  var mSel = 0, nSel = 1;
  var damping = 0.015;
  var speed = 10;
  var show3D = true;
  var showNodal = false;
  var time = 0;

  function addMode(m, n, amp, phase) {
    var alpha = ZEROS[m][n - 1];
    var freq = alpha / ZEROS[0][0];
    modes.push({
      m: m, n: n,
      alpha: alpha,
      freq: freq,
      amp: amp,
      phase: phase || 0,
      maxAmp: Math.abs(amp)
    });
  }

  function exciteMode(m, n, amp) {
    for (var i = 0; i < modes.length; i++) {
      if (modes[i].m === m && modes[i].n === n) {
        modes[i].amp = Math.min(1.5, modes[i].amp + amp);
        modes[i].maxAmp = Math.max(modes[i].maxAmp, Math.abs(modes[i].amp));
        return;
      }
    }
    addMode(m, n, amp, Math.random() * Math.PI * 2);
  }

  function strikeAt(px, py) {
    var dx = px - cx, dy = py - cy;
    var r = Math.sqrt(dx * dx + dy * dy) / memR;
    var theta = Math.atan2(dy, dx);
    if (r > 1) return;

    for (var m = 0; m <= 4; m++) {
      for (var n = 1; n <= 3; n++) {
        var alpha = ZEROS[m][n - 1];
        var jVal = besselJ2(m, alpha * r);
        var angVal = m === 0 ? 1 : Math.cos(m * theta);
        var coupling = jVal * angVal;
        if (Math.abs(coupling) > 0.05) {
          exciteMode(m, n, coupling * 0.6);
        }
      }
    }
  }

  // Resolution for membrane grid
  var GRID = 80;
  var gridData;

  function computeGrid() {
    if (!gridData || gridData.length !== GRID + 1) {
      gridData = [];
      for (var i = 0; i <= GRID; i++) {
        gridData[i] = new Float32Array(GRID + 1);
      }
    }

    var energy = 0;
    for (var ir = 0; ir <= GRID; ir++) {
      var r = ir / GRID;
      for (var it = 0; it <= GRID; it++) {
        var theta = (it / GRID) * Math.PI * 2;
        var z = 0;
        for (var k = 0; k < modes.length; k++) {
          var md = modes[k];
          if (Math.abs(md.amp) < 1e-6) continue;
          var jVal = besselJ2(md.m, md.alpha * r);
          var angPart = md.m === 0 ? 1 : Math.cos(md.m * theta);
          z += md.amp * jVal * angPart * Math.cos(md.freq * time + md.phase);
        }
        gridData[ir][it] = z;
        energy += z * z;
      }
    }
    return energy / ((GRID + 1) * (GRID + 1));
  }

  function resize() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2;
    cy = H / 2;
    memR = Math.min(W, H) * 0.38;
  }

  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2;
    var r, g, b;
    if (h < 60) { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return [(r + m) * 255 | 0, (g + m) * 255 | 0, (b + m) * 255 | 0];
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var energy = computeGrid();

    if (showNodal) {
      drawNodalLines();
    } else if (show3D) {
      draw3DMembrane();
    } else {
      drawTopDown();
    }

    drawBoundary();

    // Update HUD
    var domM = 0, domN = 1, maxA = 0;
    for (var k = 0; k < modes.length; k++) {
      if (Math.abs(modes[k].amp) > maxA) {
        maxA = Math.abs(modes[k].amp);
        domM = modes[k].m;
        domN = modes[k].n;
      }
    }
    hudM.textContent = domM;
    hudN.textContent = domN;
    hudF.textContent = (maxA > 0.01 ? (ZEROS[domM][domN - 1] / ZEROS[0][0]).toFixed(2) : '---');
    hudE.textContent = energy.toFixed(3);

    // Decay
    for (var k = modes.length - 1; k >= 0; k--) {
      modes[k].amp *= (1 - damping * 0.1);
      if (Math.abs(modes[k].amp) < 1e-5) {
        modes.splice(k, 1);
      }
    }

    time += speed * 0.01;
  }

  function drawBoundary() {
    X.beginPath();
    X.arc(cx, cy, memR + 2, 0, Math.PI * 2);
    X.strokeStyle = 'rgba(0,245,212,0.3)';
    X.lineWidth = 2;
    X.stroke();

    X.beginPath();
    X.arc(cx, cy, memR + 6, 0, Math.PI * 2);
    X.strokeStyle = 'rgba(123,44,191,0.2)';
    X.lineWidth = 1;
    X.stroke();
  }

  function draw3DMembrane() {
    var tilt = 0.35;
    var cosT = Math.cos(tilt);
    var step = 2;

    for (var ir = 0; ir <= GRID; ir += step) {
      var r = ir / GRID;
      X.beginPath();
      var first = true;
      for (var it = 0; it <= GRID; it++) {
        var theta = (it / GRID) * Math.PI * 2;
        var z = gridData[ir][it];
        var px = r * Math.cos(theta);
        var py = r * Math.sin(theta) * cosT - z * 0.3;
        var sx = cx + px * memR;
        var sy = cy + py * memR;
        if (first) { X.moveTo(sx, sy); first = false; }
        else X.lineTo(sx, sy);
      }
      var a = Math.max(0.08, Math.min(0.6, 0.1 + r * 0.4));
      X.strokeStyle = 'rgba(0,245,212,' + a + ')';
      X.lineWidth = 0.8;
      X.stroke();
    }

    for (var it = 0; it < GRID; it += step * 2) {
      var theta = (it / GRID) * Math.PI * 2;
      X.beginPath();
      var first = true;
      for (var ir = 0; ir <= GRID; ir++) {
        var r = ir / GRID;
        var z = gridData[ir][it];
        var px = r * Math.cos(theta);
        var py = r * Math.sin(theta) * cosT - z * 0.3;
        var sx = cx + px * memR;
        var sy = cy + py * memR;
        if (first) { X.moveTo(sx, sy); first = false; }
        else X.lineTo(sx, sy);
      }
      X.strokeStyle = 'rgba(123,44,191,0.2)';
      X.lineWidth = 0.5;
      X.stroke();
    }
  }

  function drawTopDown() {
    var imgSize = Math.ceil(memR * 2);
    var imgData = X.createImageData(imgSize, imgSize);
    var data = imgData.data;
    var rr = memR;

    for (var py = 0; py < imgSize; py++) {
      for (var px = 0; px < imgSize; px++) {
        var dx = (px - rr) / rr;
        var dy = (py - rr) / rr;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > 1) continue;

        var theta = Math.atan2(dy, dx);
        var z = 0;
        for (var k = 0; k < modes.length; k++) {
          var md = modes[k];
          if (Math.abs(md.amp) < 1e-6) continue;
          var jVal = besselJ2(md.m, md.alpha * dist);
          var angPart = md.m === 0 ? 1 : Math.cos(md.m * theta);
          z += md.amp * jVal * angPart * Math.cos(md.freq * time + md.phase);
        }

        var idx = (py * imgSize + px) * 4;
        if (z > 0) {
          var v = Math.min(1, z * 2);
          data[idx] = v * 0 | 0;
          data[idx + 1] = v * 245 | 0;
          data[idx + 2] = v * 212 | 0;
          data[idx + 3] = (0.15 + v * 0.85) * 255 | 0;
        } else {
          var v = Math.min(1, -z * 2);
          data[idx] = v * 123 | 0;
          data[idx + 1] = v * 44 | 0;
          data[idx + 2] = v * 191 | 0;
          data[idx + 3] = (0.15 + v * 0.85) * 255 | 0;
        }
      }
    }
    X.putImageData(imgData, cx - rr, cy - rr);
  }

  function drawNodalLines() {
    var step = 3;
    var rr = memR;

    for (var py = -rr; py <= rr; py += step) {
      for (var px = -rr; px <= rr; px += step) {
        var dx = px / rr, dy = py / rr;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > 1) continue;

        var theta = Math.atan2(dy, dx);
        var z = 0;
        for (var k = 0; k < modes.length; k++) {
          var md = modes[k];
          if (Math.abs(md.amp) < 1e-6) continue;
          var jVal = besselJ2(md.m, md.alpha * dist);
          var angPart = md.m === 0 ? 1 : Math.cos(md.m * theta);
          z += jVal * angPart;
        }

        var nearZero = Math.abs(z);
        if (nearZero < 0.08) {
          var brightness = 1 - nearZero / 0.08;
          X.fillStyle = 'rgba(0,245,212,' + (brightness * 0.9).toFixed(2) + ')';
          X.fillRect(cx + px, cy + py, step - 0.5, step - 0.5);
        } else {
          var a = Math.max(0, 0.06 - nearZero * 0.02);
          if (a > 0.005) {
            X.fillStyle = z > 0
              ? 'rgba(0,245,212,' + a.toFixed(3) + ')'
              : 'rgba(123,44,191,' + a.toFixed(3) + ')';
            X.fillRect(cx + px, cy + py, step - 0.5, step - 0.5);
          }
        }
      }
    }

    X.beginPath();
    X.arc(cx, cy, memR, 0, Math.PI * 2);
    X.strokeStyle = 'rgba(0,245,212,0.5)';
    X.lineWidth = 2;
    X.stroke();
  }

  function frame_loop() {
    draw();
    requestAnimationFrame(frame_loop);
  }

  // Controls
  var slM = document.getElementById('sl-m');
  var slN = document.getElementById('sl-n');
  var slDamp = document.getElementById('sl-damp');
  var slSpeed = document.getElementById('sl-speed');
  var svM = document.getElementById('sv-m');
  var svN = document.getElementById('sv-n');
  var svDamp = document.getElementById('sv-damp');
  var svSpeed = document.getElementById('sv-speed');

  slM.addEventListener('input', function() {
    mSel = +this.value; svM.textContent = mSel;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });
  slN.addEventListener('input', function() {
    nSel = +this.value; svN.textContent = nSel;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });
  slDamp.addEventListener('input', function() {
    damping = +this.value / 1000; svDamp.textContent = this.value;
  });
  slSpeed.addEventListener('input', function() {
    speed = +this.value; svSpeed.textContent = this.value;
  });

  document.getElementById('btn-mix').addEventListener('click', function() {
    modes = [];
    for (var m = 0; m <= 3; m++) {
      for (var n = 1; n <= 2; n++) {
        addMode(m, n, (Math.random() * 0.4 + 0.1) * (Math.random() < 0.5 ? 1 : -1), Math.random() * Math.PI * 2);
      }
    }
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  });

  var btn3D = document.getElementById('btn-3d');
  var btnNodal = document.getElementById('btn-nodal');

  btn3D.addEventListener('click', function() {
    show3D = !show3D;
    if (show3D) showNodal = false;
    btn3D.classList.toggle('active', show3D);
    btnNodal.classList.toggle('active', showNodal);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  btnNodal.addEventListener('click', function() {
    showNodal = !showNodal;
    if (showNodal) show3D = false;
    btnNodal.classList.toggle('active', showNodal);
    btn3D.classList.toggle('active', show3D);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    modes = [];
    time = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Interaction
  var dragging = false;
  var lastStrike = 0;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    dragging = true;
    var rect = C.getBoundingClientRect();
    var mx = e.clientX - rect.left;
    var my = e.clientY - rect.top;
    var dx = mx - cx, dy = my - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist <= memR) {
      exciteMode(mSel, nSel, 0.8);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var now = Date.now();
    if (now - lastStrike < 60) return;
    lastStrike = now;
    var rect = C.getBoundingClientRect();
    var mx = e.clientX - rect.left;
    var my = e.clientY - rect.top;
    strikeAt(mx, my);
    if (typeof SFX !== 'undefined') SFX.play('ripple', 80);
  });

  C.addEventListener('mouseup', function() { dragging = false; });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var d = e.deltaY > 0 ? 5 : -5;
    var v = Math.max(0, Math.min(100, +slDamp.value + d));
    slDamp.value = v;
    damping = v / 1000;
    svDamp.textContent = v;
  }, { passive: false });

  // Touch support
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var touch = e.touches[0];
    var rect = C.getBoundingClientRect();
    var mx = touch.clientX - rect.left;
    var my = touch.clientY - rect.top;
    var dx = mx - cx, dy = my - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist <= memR) {
      exciteMode(mSel, nSel, 0.8);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
    dragging = true;
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!dragging) return;
    var now = Date.now();
    if (now - lastStrike < 80) return;
    lastStrike = now;
    var touch = e.touches[0];
    var rect = C.getBoundingClientRect();
    var mx = touch.clientX - rect.left;
    var my = touch.clientY - rect.top;
    strikeAt(mx, my);
    if (typeof SFX !== 'undefined') SFX.play('ripple', 100);
  }, { passive: false });

  C.addEventListener('touchend', function() { dragging = false; });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // Init
  resize();
  window.addEventListener('resize', resize);

  addMode(0, 1, 0.5, 0);
  addMode(2, 1, 0.3, Math.PI / 3);

  frame_loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
