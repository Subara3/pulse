<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tongues');

$pageName = 'tongues';
$pageTitle = 'ARNOLD TONGUES - PULSE';
$pageDesc = 'アーノルドの舌。円写像のパラメータ空間にモード同期の舌状領域が広がり、悪魔の階段が回転数を刻む。';
$pageCanonical = 'https://pulse.subara3.com/tongues.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>ρ = <span id="hud-rho">—</span></div>
  <div>Ω = <span id="hud-omega">—</span></div>
  <div>K = <span id="hud-k">—</span></div>
</div>
<div id="info-label"><span>ARNOLD TONGUES</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'inspect'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">ITERATIONS</label>
    <input type="range" id="sl-iter" min="50" max="500" value="200">
    <span class="slider-value" id="v-iter">200</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">RESOLUTION</label>
    <input type="range" id="sl-res" min="1" max="4" value="2">
    <span class="slider-value" id="v-res">2</span>
  </div>
  <div class="slider-row"><label class="slider-label">VIEW</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-v="tongues">TONGUES</button>
    <button class="ctrl-btn" data-v="staircase">STAIRCASE</button>
    <button class="ctrl-btn" data-v="orbit">ORBIT</button>
  </div>
  <div class="slider-row"><label class="slider-label">STAIRCASE K</label></div>
  <div class="slider-row">
    <input type="range" id="sl-sk" min="0" max="300" value="100">
    <span class="slider-value" id="v-sk">1.00</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-reset">RESET VIEW</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;
  var dpr = window.devicePixelRatio || 1;

  var hudRho = document.getElementById('hud-rho');
  var hudOmega = document.getElementById('hud-omega');
  var hudK = document.getElementById('hud-k');

  var slIter = document.getElementById('sl-iter');
  var slRes = document.getElementById('sl-res');
  var slSK = document.getElementById('sl-sk');
  var vIter = document.getElementById('v-iter');
  var vRes = document.getElementById('v-res');
  var vSK = document.getElementById('v-sk');

  var viewBtns = document.querySelectorAll('[data-v]');
  var btnReset = document.getElementById('btn-reset');

  var viewOmegaMin = 0, viewOmegaMax = 1;
  var viewKMin = 0, viewKMax = 3;
  var iterations = 200;
  var pixelStep = 2;
  var currentView = 'tongues';
  var staircaseK = 1.0;

  var tongueImg = null;
  var needsRedraw = true;
  var renderPass = 0;
  var rendering = false;

  var inspectOmega = 0.5, inspectK = 1.0;
  var orbitData = null;

  var isDragging = false;
  var dragStartX, dragStartY;
  var dragOmega0, dragOmega1, dragK0, dragK1;

  function resize() {
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    tongueImg = null;
    needsRedraw = true;
  }

  function circleMapWinding(omega, k, n) {
    var theta = 0;
    var twoPi = 2 * Math.PI;
    for (var i = 0; i < n; i++) {
      theta += omega - (k / twoPi) * Math.sin(twoPi * theta);
    }
    return theta / n;
  }

  function circleMapOrbit(omega, k, n) {
    var pts = [];
    var theta = 0;
    var twoPi = 2 * Math.PI;
    for (var i = 0; i < n; i++) {
      var thetaMod = theta - Math.floor(theta);
      pts.push(thetaMod);
      theta += omega - (k / twoPi) * Math.sin(twoPi * theta);
    }
    return pts;
  }

  function windingToColor(rho) {
    var r = rho - Math.floor(rho);
    if (r < 0) r += 1;

    var bestP = 0, bestQ = 1, bestDist = 1;
    for (var q = 1; q <= 12; q++) {
      for (var p = 0; p <= q; p++) {
        var rat = p / q;
        var d = Math.abs(r - rat);
        if (d < bestDist) {
          bestDist = d;
          bestP = p;
          bestQ = q;
        }
      }
    }

    var threshold = 0.5 / (bestQ * bestQ);
    if (bestDist > threshold) {
      return [10, 10, 15, 255];
    }

    var closeness = 1 - bestDist / threshold;
    var hue = 170 + (bestP / bestQ) * 120;
    var sat = 0.5 + 0.4 * closeness;
    var lum = 0.15 + 0.45 * closeness * (1 - 0.06 * bestQ);

    var rgb = hslToRgb(hue / 360, sat, lum);
    return [rgb[0], rgb[1], rgb[2], 255];
  }

  function hslToRgb(h, s, l) {
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h * 6) % 2 - 1));
    var m = l - c / 2;
    var r, g, b;
    var hh = h * 6;
    if (hh < 1) { r = c; g = x; b = 0; }
    else if (hh < 2) { r = x; g = c; b = 0; }
    else if (hh < 3) { r = 0; g = c; b = x; }
    else if (hh < 4) { r = 0; g = x; b = c; }
    else if (hh < 5) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return [
      Math.round((r + m) * 255),
      Math.round((g + m) * 255),
      Math.round((b + m) * 255)
    ];
  }

  function renderTongues() {
    if (rendering) return;
    rendering = true;

    var step = pixelStep;
    var imgData = ctx.createImageData(W, H);
    var data = imgData.data;
    var n = iterations;

    var cols = Math.ceil(W / step);
    var rows = Math.ceil(H / step);
    var col = 0, row = 0;
    var batchSize = 800;

    function batch() {
      var count = 0;
      while (count < batchSize && row < rows) {
        var py = row * step;
        var px = col * step;

        var omega = viewOmegaMin + (px / W) * (viewOmegaMax - viewOmegaMin);
        var k = viewKMax - (py / H) * (viewKMax - viewKMin);

        var rho = circleMapWinding(omega, k, n);
        var color = windingToColor(rho);

        for (var dy = 0; dy < step && py + dy < H; dy++) {
          for (var dx = 0; dx < step && px + dx < W; dx++) {
            var idx = ((py + dy) * W + (px + dx)) * 4;
            data[idx] = color[0];
            data[idx + 1] = color[1];
            data[idx + 2] = color[2];
            data[idx + 3] = 255;
          }
        }

        col++;
        if (col >= cols) { col = 0; row++; }
        count++;
      }

      if (row < rows) {
        requestAnimationFrame(batch);
      } else {
        ctx.putImageData(imgData, 0, 0);
        tongueImg = ctx.getImageData(0, 0, W * dpr, H * dpr);
        rendering = false;
        drawOverlays();
      }
    }
    batch();
  }

  function renderStaircase() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var k = staircaseK;
    var marginL = 60, marginR = 30, marginT = 40, marginB = 50;
    var pw = W - marginL - marginR;
    var ph = H - marginT - marginB;

    ctx.strokeStyle = 'rgba(0,245,212,0.15)';
    ctx.lineWidth = 1;
    for (var i = 0; i <= 10; i++) {
      var x = marginL + (i / 10) * pw;
      var y = marginT + (i / 10) * ph;
      ctx.beginPath(); ctx.moveTo(x, marginT); ctx.lineTo(x, marginT + ph); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(marginL, y); ctx.lineTo(marginL + pw, y); ctx.stroke();
    }

    ctx.strokeStyle = 'rgba(255,255,255,0.1)';
    ctx.beginPath();
    ctx.moveTo(marginL, marginT + ph);
    ctx.lineTo(marginL + pw, marginT);
    ctx.stroke();

    var steps = Math.min(pw, 600);
    ctx.beginPath();
    ctx.strokeStyle = '#00f5d4';
    ctx.lineWidth = 2;
    var first = true;
    for (var i = 0; i <= steps; i++) {
      var omega = i / steps;
      var rho = circleMapWinding(omega, k, iterations);
      rho = rho - Math.floor(rho);
      if (rho < 0) rho += 1;

      var x = marginL + omega * pw;
      var y = marginT + (1 - rho) * ph;
      if (first) { ctx.moveTo(x, y); first = false; }
      else ctx.lineTo(x, y);
    }
    ctx.stroke();

    var rats = [[0,1],[1,3],[1,2],[2,3],[1,1]];
    ctx.fillStyle = 'rgba(0,245,212,0.5)';
    ctx.font = '10px Orbitron, monospace';
    ctx.textAlign = 'center';
    for (var i = 0; i < rats.length; i++) {
      var p = rats[i][0], q = rats[i][1];
      var val = p / q;
      var y = marginT + (1 - val) * ph;
      ctx.fillText(p + '/' + q, marginL - 20, y + 4);
    }

    ctx.fillStyle = 'rgba(0,245,212,0.4)';
    ctx.font = '11px Orbitron, monospace';
    ctx.textAlign = 'center';
    ctx.fillText('Ω', marginL + pw / 2, marginT + ph + 35);
    ctx.save();
    ctx.translate(15, marginT + ph / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('ρ (winding number)', 0, 0);
    ctx.restore();

    ctx.fillStyle = 'rgba(0,245,212,0.3)';
    ctx.font = '10px Orbitron, monospace';
    ctx.textAlign = 'right';
    ctx.fillText('K = ' + k.toFixed(2), W - 20, 30);
  }

  function renderOrbit() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var omega = inspectOmega;
    var k = inspectK;
    var orbit = circleMapOrbit(omega, k, iterations);

    var cx = W / 2, cy = H / 2;
    var rad = Math.min(W, H) * 0.32;

    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(cx, cy, rad, 0, Math.PI * 2);
    ctx.stroke();

    for (var i = 0; i < 12; i++) {
      var a = (i / 12) * Math.PI * 2 - Math.PI / 2;
      ctx.beginPath();
      ctx.moveTo(cx + rad * 0.95 * Math.cos(a), cy + rad * 0.95 * Math.sin(a));
      ctx.lineTo(cx + rad * 1.05 * Math.cos(a), cy + rad * 1.05 * Math.sin(a));
      ctx.stroke();
    }

    var maxPts = Math.min(orbit.length, 500);
    for (var i = 0; i < maxPts; i++) {
      var angle = orbit[i] * Math.PI * 2 - Math.PI / 2;
      var x = cx + rad * Math.cos(angle);
      var y = cy + rad * Math.sin(angle);

      var alpha = 0.15 + 0.85 * (i / maxPts);
      var hue = 170 + (orbit[i]) * 120;
      var rgb = hslToRgb(hue / 360, 0.7, 0.5);

      ctx.fillStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + alpha + ')';
      ctx.beginPath();
      ctx.arc(x, y, 2.5, 0, Math.PI * 2);
      ctx.fill();
    }

    if (maxPts > 1) {
      ctx.strokeStyle = 'rgba(123,44,191,0.25)';
      ctx.lineWidth = 0.5;
      ctx.beginPath();
      for (var i = 0; i < maxPts; i++) {
        var angle = orbit[i] * Math.PI * 2 - Math.PI / 2;
        var x = cx + rad * Math.cos(angle);
        var y = cy + rad * Math.sin(angle);
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      }
      ctx.stroke();
    }

    var rho = circleMapWinding(omega, k, iterations);
    rho = rho - Math.floor(rho);
    if (rho < 0) rho += 1;

    ctx.fillStyle = 'rgba(0,245,212,0.4)';
    ctx.font = '11px Orbitron, monospace';
    ctx.textAlign = 'left';
    ctx.fillText('Ω = ' + omega.toFixed(4), 20, H - 60);
    ctx.fillText('K = ' + k.toFixed(4), 20, H - 42);
    ctx.fillText('ρ = ' + rho.toFixed(6), 20, H - 24);
  }

  function drawOverlays() {
    if (currentView !== 'tongues') return;
    if (!tongueImg) return;

    ctx.putImageData(tongueImg, 0, 0);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    var px = ((inspectOmega - viewOmegaMin) / (viewOmegaMax - viewOmegaMin)) * W;
    var py = ((viewKMax - inspectK) / (viewKMax - viewKMin)) * H;

    ctx.strokeStyle = 'rgba(0,245,212,0.5)';
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 4]);
    ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, H); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, py); ctx.lineTo(W, py); ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = '#00f5d4';
    ctx.beginPath();
    ctx.arc(px, py, 4, 0, Math.PI * 2);
    ctx.fill();

    var labels = [[0,1,'0'],[1,4,'1/4'],[1,3,'1/3'],[1,2,'1/2'],[2,3,'2/3'],[3,4,'3/4'],[1,1,'1']];
    ctx.fillStyle = 'rgba(0,245,212,0.35)';
    ctx.font = '9px Orbitron, monospace';
    ctx.textAlign = 'center';
    for (var i = 0; i < labels.length; i++) {
      var val = labels[i][0] / labels[i][1];
      var x = ((val - viewOmegaMin) / (viewOmegaMax - viewOmegaMin)) * W;
      if (x > 10 && x < W - 10) {
        ctx.fillText(labels[i][2], x, H - 8);
      }
    }

    ctx.fillStyle = 'rgba(0,245,212,0.3)';
    ctx.font = '10px Orbitron, monospace';
    ctx.textAlign = 'center';
    ctx.fillText('Ω', W / 2, H - 20);
    ctx.textAlign = 'left';
    ctx.fillText('K', 6, H / 2);
  }

  function updateHUD() {
    var rho = circleMapWinding(inspectOmega, inspectK, iterations);
    rho = rho - Math.floor(rho);
    if (rho < 0) rho += 1;
    hudRho.textContent = rho.toFixed(6);
    hudOmega.textContent = inspectOmega.toFixed(4);
    hudK.textContent = inspectK.toFixed(4);
  }

  function draw() {
    if (currentView === 'tongues') {
      if (needsRedraw) {
        needsRedraw = false;
        renderTongues();
      } else if (tongueImg) {
        drawOverlays();
      }
    } else if (currentView === 'staircase') {
      renderStaircase();
    } else if (currentView === 'orbit') {
      renderOrbit();
    }
    updateHUD();
  }

  function pixToParam(px, py) {
    var omega = viewOmegaMin + (px / W) * (viewOmegaMax - viewOmegaMin);
    var k = viewKMax - (py / H) * (viewKMax - viewKMin);
    return { omega: Math.max(0, Math.min(1, omega)), k: Math.max(0, k) };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    if (currentView === 'tongues') {
      isDragging = true;
      dragStartX = e.clientX;
      dragStartY = e.clientY;
      dragOmega0 = viewOmegaMin;
      dragOmega1 = viewOmegaMax;
      dragK0 = viewKMin;
      dragK1 = viewKMax;
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (currentView === 'tongues') {
      if (isDragging) {
        var dx = e.clientX - dragStartX;
        var dy = e.clientY - dragStartY;
        var omegaRange = dragOmega1 - dragOmega0;
        var kRange = dragK1 - dragK0;
        viewOmegaMin = dragOmega0 - (dx / W) * omegaRange;
        viewOmegaMax = dragOmega1 - (dx / W) * omegaRange;
        viewKMin = dragK0 + (dy / H) * kRange;
        viewKMax = dragK1 + (dy / H) * kRange;
        tongueImg = null;
        needsRedraw = true;
      } else {
        var p = pixToParam(e.clientX, e.clientY);
        inspectOmega = p.omega;
        inspectK = p.k;
        if (tongueImg) drawOverlays();
        updateHUD();
      }
    }
  });

  C.addEventListener('mouseup', function(e) {
    if (isDragging) {
      isDragging = false;
      if (Math.abs(e.clientX - dragStartX) < 3 && Math.abs(e.clientY - dragStartY) < 3) {
        var p = pixToParam(e.clientX, e.clientY);
        inspectOmega = p.omega;
        inspectK = p.k;
        if (window.SFX) SFX.play('tick');
        updateHUD();
        drawOverlays();
      } else {
        if (window.SFX) SFX.play('move', 100);
      }
    }
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    if (currentView !== 'tongues') return;

    var zoomFactor = e.deltaY > 0 ? 1.15 : 0.87;
    var mouseOmega = viewOmegaMin + (e.clientX / W) * (viewOmegaMax - viewOmegaMin);
    var mouseK = viewKMax - (e.clientY / H) * (viewKMax - viewKMin);

    var newOmegaRange = (viewOmegaMax - viewOmegaMin) * zoomFactor;
    var newKRange = (viewKMax - viewKMin) * zoomFactor;

    viewOmegaMin = mouseOmega - (e.clientX / W) * newOmegaRange;
    viewOmegaMax = mouseOmega + (1 - e.clientX / W) * newOmegaRange;
    viewKMin = mouseK - (1 - e.clientY / H) * newKRange;
    viewKMax = mouseK + (e.clientY / H) * newKRange;

    tongueImg = null;
    needsRedraw = true;
    if (window.SFX) SFX.play('click');
  }, { passive: false });

  var touchDist0 = 0;
  var touches0 = null;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      isDragging = true;
      dragStartX = e.touches[0].clientX;
      dragStartY = e.touches[0].clientY;
      dragOmega0 = viewOmegaMin;
      dragOmega1 = viewOmegaMax;
      dragK0 = viewKMin;
      dragK1 = viewKMax;
    } else if (e.touches.length === 2) {
      isDragging = false;
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      touchDist0 = Math.sqrt(dx * dx + dy * dy);
      touches0 = {
        oMin: viewOmegaMin, oMax: viewOmegaMax,
        kMin: viewKMin, kMax: viewKMax
      };
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 1 && isDragging && currentView === 'tongues') {
      var dx = e.touches[0].clientX - dragStartX;
      var dy = e.touches[0].clientY - dragStartY;
      var omegaRange = dragOmega1 - dragOmega0;
      var kRange = dragK1 - dragK0;
      viewOmegaMin = dragOmega0 - (dx / W) * omegaRange;
      viewOmegaMax = dragOmega1 - (dx / W) * omegaRange;
      viewKMin = dragK0 + (dy / H) * kRange;
      viewKMax = dragK1 + (dy / H) * kRange;
      tongueImg = null;
      needsRedraw = true;
    } else if (e.touches.length === 2 && touches0 && currentView === 'tongues') {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var dist = Math.sqrt(dx * dx + dy * dy);
      var scale = touchDist0 / dist;

      var cx = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      var cy = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      var centerOmega = touches0.oMin + (cx / W) * (touches0.oMax - touches0.oMin);
      var centerK = touches0.kMax - (cy / H) * (touches0.kMax - touches0.kMin);

      var newOmegaRange = (touches0.oMax - touches0.oMin) * scale;
      var newKRange = (touches0.kMax - touches0.kMin) * scale;

      viewOmegaMin = centerOmega - (cx / W) * newOmegaRange;
      viewOmegaMax = centerOmega + (1 - cx / W) * newOmegaRange;
      viewKMin = centerK - (1 - cy / H) * newKRange;
      viewKMax = centerK + (cy / H) * newKRange;

      tongueImg = null;
      needsRedraw = true;
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (isDragging) {
        isDragging = false;
      }
      touches0 = null;

      if (e.changedTouches.length === 1 && currentView === 'tongues') {
        var t = e.changedTouches[0];
        var p = pixToParam(t.clientX, t.clientY);
        inspectOmega = p.omega;
        inspectK = p.k;
        if (window.SFX) SFX.play('tick');
        updateHUD();
      }
    }
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    viewOmegaMin = 0; viewOmegaMax = 1;
    viewKMin = 0; viewKMax = 3;
    tongueImg = null;
    needsRedraw = true;
    if (window.SFX) SFX.play('click');
  });

  viewBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      viewBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      currentView = btn.dataset.v;
      tongueImg = null;
      needsRedraw = true;
      if (window.SFX) SFX.play('toggle');
      draw();
    });
  });

  slIter.addEventListener('input', function() {
    iterations = parseInt(this.value);
    vIter.textContent = iterations;
    tongueImg = null;
    needsRedraw = true;
  });

  slRes.addEventListener('input', function() {
    pixelStep = parseInt(this.value);
    vRes.textContent = pixelStep;
    tongueImg = null;
    needsRedraw = true;
  });

  slSK.addEventListener('input', function() {
    staircaseK = parseInt(this.value) / 100;
    vSK.textContent = staircaseK.toFixed(2);
    if (currentView === 'staircase') draw();
  });

  btnReset.addEventListener('click', function() {
    viewOmegaMin = 0; viewOmegaMax = 1;
    viewKMin = 0; viewKMax = 3;
    tongueImg = null;
    needsRedraw = true;
    if (window.SFX) SFX.play('click');
    draw();
  });

  window.addEventListener('resize', resize);
  resize();

  function loop() {
    if (needsRedraw && !rendering) {
      draw();
    }
    requestAnimationFrame(loop);
  }
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
