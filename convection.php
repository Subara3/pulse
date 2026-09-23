<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('convection');

$pageName = 'convection';
$pageTitle = 'CONVECTION - PULSE';
$pageDesc = 'レイリー・ベナール対流。下から加熱された流体が自発的に対流セルを形成し、レイリー数の増加とともにカオスへ遷移する。';
$pageCanonical = 'https://pulse.subara3.com/convection.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>Ra <span id="hud-ra">29K</span> / Ra<sub>c</sub> 1708</div>
  <div>STATE <span id="hud-state">CONDUCTION</span></div>
  <div>PLUMES <span id="hud-cells">0</span></div>
  <div>T<sub>max</sub> <span id="hud-tmax">1.00</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'heat'],
  ['type' => 'drag', 'label' => 'perturb'],
  ['type' => 'right', 'label' => 'cool'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">RAYLEIGH</label>
    <input type="range" id="sl-ra" min="0" max="100" value="59" step="1">
    <span class="slider-value" id="sv-ra">29K</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">PARTICLES</label>
    <input type="range" id="sl-part" min="0" max="100" value="60" step="1">
    <span class="slider-value" id="sv-part">1200</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-view">THERMAL</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;

  var NX = 80, NY = 48;
  var aspect = NX / NY;
  var dx = 1.0 / NY, dy = 1.0 / NY;
  var dx2 = dx * dx, dy2 = dy * dy;
  var n = NX * NY;

  var T = new Float32Array(n);
  var Tn = new Float32Array(n);
  var w = new Float32Array(n);
  var wn = new Float32Array(n);
  var psi = new Float32Array(n);
  var uf = new Float32Array(n);
  var vf = new Float32Array(n);

  var Ra = 10000, Pr = 1.0;
  var dt = 1.2e-4;
  var substeps = 14, poissonIts = 20;

  var viewMode = 0;
  var numPart = 1200;
  var partX, partY, partAge, partMax;

  var off = document.createElement('canvas');
  var O = off.getContext('2d');
  var img, pix;
  var SCALE;

  var hudRa = document.getElementById('hud-ra');
  var hudCells = document.getElementById('hud-cells');
  var hudState = document.getElementById('hud-state');
  var lastState = '';
  var hudTmax = document.getElementById('hud-tmax');

  var lut = new Uint8Array(256 * 3);
  (function buildLUT() {
    for (var i = 0; i < 256; i++) {
      var t = i / 255;
      var h, s, l;
      if (t < 0.5) {
        var tt = t / 0.5;
        h = 280 - tt * 60;
        s = 50 + tt * 20;
        l = 6 + tt * 18;
      } else {
        var tt = (t - 0.5) / 0.5;
        h = 220 - tt * 45;
        s = 70 + tt * 15;
        l = 24 + tt * 30;
      }
      var c = (1 - Math.abs(2 * l / 100 - 1)) * s / 100;
      var x = c * (1 - Math.abs((h / 60) % 2 - 1));
      var m = l / 100 - c / 2;
      var r1, g1, b1;
      if (h < 60) { r1=c; g1=x; b1=0; }
      else if (h < 120) { r1=x; g1=c; b1=0; }
      else if (h < 180) { r1=0; g1=c; b1=x; }
      else if (h < 240) { r1=0; g1=x; b1=c; }
      else if (h < 300) { r1=x; g1=0; b1=c; }
      else { r1=c; g1=0; b1=x; }
      lut[i*3]   = Math.round((r1+m)*255);
      lut[i*3+1] = Math.round((g1+m)*255);
      lut[i*3+2] = Math.round((b1+m)*255);
    }
  })();

  var wLut = new Uint8Array(256 * 3);
  (function buildWLUT() {
    for (var i = 0; i < 256; i++) {
      var t = i / 255;
      var val = t * 2 - 1;
      var h, s, l;
      if (val < 0) {
        h = 275;
        s = 70;
        l = -val * 45;
      } else {
        h = 175;
        s = 80;
        l = val * 50;
      }
      var c = (1 - Math.abs(2 * l / 100 - 1)) * s / 100;
      var x = c * (1 - Math.abs((h / 60) % 2 - 1));
      var m = l / 100 - c / 2;
      var r1, g1, b1;
      if (h < 60) { r1=c; g1=x; b1=0; }
      else if (h < 120) { r1=x; g1=c; b1=0; }
      else if (h < 180) { r1=0; g1=c; b1=x; }
      else if (h < 240) { r1=0; g1=x; b1=c; }
      else if (h < 300) { r1=x; g1=0; b1=c; }
      else { r1=c; g1=0; b1=x; }
      wLut[i*3]   = Math.round((r1+m)*255);
      wLut[i*3+1] = Math.round((g1+m)*255);
      wLut[i*3+2] = Math.round((b1+m)*255);
    }
  })();

  function idx(i, j) { return j * NX + i; }
  function iw(i) { return ((i % NX) + NX) % NX; }

  function initFields() {
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = idx(i, j);
        T[k] = 1.0 - j / (NY - 1);
        T[k] += (0.1 * (Math.random() - 0.5) + 0.05 * Math.cos(6 * Math.PI * i / NX)) * Math.sin(Math.PI * j / (NY - 1));
        w[k] = 0;
        psi[k] = 0;
        uf[k] = 0;
        vf[k] = 0;
      }
    }
  }

  function initParticles() {
    partX = new Float32Array(numPart);
    partY = new Float32Array(numPart);
    partAge = new Float32Array(numPart);
    partMax = new Float32Array(numPart);
    for (var p = 0; p < numPart; p++) spawnP(p);
  }

  function spawnP(p) {
    partX[p] = Math.random() * (NX - 2) + 1;
    partY[p] = Math.random() * (NY - 2) + 1;
    partAge[p] = 0;
    partMax[p] = 120 + Math.random() * 180;
  }

  function poissonSOR() {
    var omega = 1.55;
    var invF = 1.0 / (2.0 / dx2 + 2.0 / dy2);
    for (var it = 0; it < poissonIts; it++) {
      for (var j = 1; j < NY - 1; j++) {
        for (var i = 0; i < NX; i++) {
          var ip = iw(i + 1), im = iw(i - 1);
          var k = idx(i, j);
          var newP = ((psi[idx(ip,j)] + psi[idx(im,j)]) / dx2 +
                      (psi[idx(i,j+1)] + psi[idx(i,j-1)]) / dy2 +
                      w[k]) * invF;
          psi[k] += omega * (newP - psi[k]);
        }
      }
      for (var i = 0; i < NX; i++) {
        psi[idx(i, 0)] = 0;
        psi[idx(i, NY - 1)] = 0;
      }
    }
  }

  function computeVel() {
    for (var j = 1; j < NY - 1; j++) {
      for (var i = 0; i < NX; i++) {
        var ip = iw(i + 1), im = iw(i - 1);
        var k = idx(i, j);
        uf[k] = (psi[idx(i, j + 1)] - psi[idx(i, j - 1)]) / (2.0 * dy);
        vf[k] = -(psi[idx(ip, j)] - psi[idx(im, j)]) / (2.0 * dx);
      }
    }
  }

  function advectDiffuse() {
    var kT = 1.0, kW = Pr;
    for (var j = 1; j < NY - 1; j++) {
      for (var i = 0; i < NX; i++) {
        var ip = iw(i + 1), im = iw(i - 1);
        var k = idx(i, j);
        var u0 = uf[k], v0 = vf[k];

        var dTdx = u0 > 0 ? (T[k] - T[idx(im, j)]) / dx : (T[idx(ip, j)] - T[k]) / dx;
        var dTdy = v0 > 0 ? (T[k] - T[idx(i, j - 1)]) / dy : (T[idx(i, j + 1)] - T[k]) / dy;
        var lapT = (T[idx(ip, j)] + T[idx(im, j)] - 2 * T[k]) / dx2 +
                   (T[idx(i, j + 1)] + T[idx(i, j - 1)] - 2 * T[k]) / dy2;
        Tn[k] = T[k] + dt * (-u0 * dTdx - v0 * dTdy + kT * lapT);

        var dwdx = u0 > 0 ? (w[k] - w[idx(im, j)]) / dx : (w[idx(ip, j)] - w[k]) / dx;
        var dwdy = v0 > 0 ? (w[k] - w[idx(i, j - 1)]) / dy : (w[idx(i, j + 1)] - w[k]) / dy;
        var lapW = (w[idx(ip, j)] + w[idx(im, j)] - 2 * w[k]) / dx2 +
                   (w[idx(i, j + 1)] + w[idx(i, j - 1)] - 2 * w[k]) / dy2;
        var buoy = Ra * Pr * (T[idx(ip, j)] - T[idx(im, j)]) / (2.0 * dx);
        wn[k] = w[k] + dt * (-u0 * dwdx - v0 * dwdy + kW * lapW + buoy);
      }
    }

    for (var j = 1; j < NY - 1; j++) {
      for (var i = 0; i < NX; i++) {
        var k = idx(i, j);
        T[k] = Tn[k];
        if (T[k] < 0) T[k] = 0;
        if (T[k] > 1.5) T[k] = 1.5;
        w[k] = wn[k];
      }
    }

    for (var i = 0; i < NX; i++) {
      T[idx(i, 0)] = 1.0;
      T[idx(i, NY - 1)] = 0.0;
      w[idx(i, 0)] = -2.0 * psi[idx(i, 1)] / dy2;
      w[idx(i, NY - 1)] = -2.0 * psi[idx(i, NY - 2)] / dy2;
    }
  }

  var dtDiff = dt;
  // 中心差分どうしの結合で生じる格子スケールの市松模様（odd-even モード）を
  // 弱いフィルタで潰す。物理スケールのロールにはほぼ影響しない。
  function smoothOddEven(f) {
    for (var j = 1; j < NY - 1; j++) {
      for (var i = 0; i < NX; i++) {
        var k = idx(i, j);
        var avg = (f[idx(iw(i + 1), j)] + f[idx(iw(i - 1), j)] + f[idx(i, j + 1)] + f[idx(i, j - 1)]) * 0.25;
        wn[k] = f[k] * 0.85 + avg * 0.15;
      }
    }
    for (var j = 1; j < NY - 1; j++) for (var i = 0; i < NX; i++) f[idx(i, j)] = wn[idx(i, j)];
  }
  var frameT = 0;
  function stepSim() {
    frameT = 0;
    for (var s = 0; s < substeps; s++) {
      poissonSOR();
      computeVel();
      // 移流の CFL 条件で dt を絞る（高 Ra で速度が上がると陽解法が発散する）
      var umax = 1e-6;
      for (var k = 0; k < n; k++) {
        var a = Math.abs(uf[k]) + Math.abs(vf[k]);
        if (a > umax) umax = a;
      }
      dt = Math.min(dtDiff, 0.35 * dx / umax);
      advectDiffuse();
      frameT += dt;
      smoothOddEven(w);
      smoothOddEven(T);
    }
  }

  function updateParticles() {
    for (var p = 0; p < numPart; p++) {
      var px = partX[p], py = partY[p];
      var gi = Math.floor(px), gj = Math.floor(py);
      if (gi < 0 || gi >= NX - 1 || gj < 0 || gj >= NY - 1) { spawnP(p); continue; }
      var fx = px - gi, fy = py - gj;
      var k00 = idx(gi, gj), k10 = idx(gi+1, gj);
      var k01 = idx(gi, gj+1), k11 = idx(gi+1, gj+1);
      var pu = uf[k00]*(1-fx)*(1-fy) + uf[k10]*fx*(1-fy) + uf[k01]*(1-fx)*fy + uf[k11]*fx*fy;
      var pv = vf[k00]*(1-fx)*(1-fy) + vf[k10]*fx*(1-fy) + vf[k01]*(1-fx)*fy + vf[k11]*fx*fy;
      var speed = frameT / dx;
      partX[p] += pu * speed;
      partY[p] += pv * speed;
      partAge[p]++;
      if (partAge[p] > partMax[p]) spawnP(p);
      if (partX[p] < 0) partX[p] += NX;
      if (partX[p] >= NX) partX[p] -= NX;
    }
  }

  function sampleT(px, py) {
    var gi = Math.floor(px), gj = Math.floor(py);
    if (gi < 0) gi = 0; if (gi >= NX - 1) gi = NX - 2;
    if (gj < 0) gj = 0; if (gj >= NY - 1) gj = NY - 2;
    var fx = px - gi, fy = py - gj;
    return T[idx(gi,gj)]*(1-fx)*(1-fy) + T[idx(gi+1,gj)]*fx*(1-fy) +
           T[idx(gi,gj+1)]*(1-fx)*fy + T[idx(gi+1,gj+1)]*fx*fy;
  }

  function render() {
    X.setTransform(1, 0, 0, 1, 0, 0);
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, C.width, C.height);

    var rW = off.width, rH = off.height;
    var curLut = viewMode === 0 ? lut : wLut;
    var field = viewMode === 0 ? T : w;

    for (var py = 0; py < rH; py++) {
      var gj = (rH - 1 - py) * (NY - 1) / rH;
      var j0 = Math.floor(gj), fy = gj - j0;
      if (j0 >= NY - 1) { j0 = NY - 2; fy = 1; }
      for (var px = 0; px < rW; px++) {
        var gi = px * (NX - 1) / rW;
        var i0 = Math.floor(gi), fx = gi - i0;
        if (i0 >= NX - 1) { i0 = NX - 2; fx = 1; }
        var val = field[idx(i0,j0)]*(1-fx)*(1-fy) + field[idx(i0+1,j0)]*fx*(1-fy) +
                  field[idx(i0,j0+1)]*(1-fx)*fy + field[idx(i0+1,j0+1)]*fx*fy;

        var ci;
        if (viewMode === 0) {
          ci = Math.round(val * 255);
        } else {
          var wMax = Ra * 0.003;
          ci = Math.round(((val / wMax) * 0.5 + 0.5) * 255);
        }
        if (ci < 0) ci = 0; if (ci > 255) ci = 255;

        var off4 = (py * rW + px) * 4;
        pix[off4]   = curLut[ci * 3];
        pix[off4+1] = curLut[ci * 3 + 1];
        pix[off4+2] = curLut[ci * 3 + 2];
        pix[off4+3] = 255;
      }
    }
    O.putImageData(img, 0, 0);
    X.imageSmoothingEnabled = true;
    X.drawImage(off, 0, 0, C.width, C.height);

    var scX = C.width / NX, scY = C.height / NY;
    for (var p = 0; p < numPart; p++) {
      var age = partAge[p] / partMax[p];
      var alpha = age < 0.1 ? age / 0.1 : (age > 0.8 ? (1 - age) / 0.2 : 1.0);
      alpha *= 0.6;
      var tv = sampleT(partX[p], partY[p]);
      var bright = 0.5 + tv * 0.5;
      var r = Math.round(0 + tv * 0), g = Math.round(150 + tv * 95), b = Math.round(170 + tv * 42);
      X.fillStyle = 'rgba(' + r + ',' + g + ',' + b + ',' + alpha.toFixed(2) + ')';
      X.fillRect(partX[p] * scX, (NY - partY[p]) * scY, 2, 2);
    }

    var maxT = 0;
    for (var k = 0; k < n; k++) if (T[k] > maxT) maxT = T[k];
    hudTmax.textContent = maxT.toFixed(2);

    // 中段の行で上昇流の区間を数える（= 上がっていくプルームの本数）
    var jm = NY >> 1, cells = 0, umax = 0;
    for (var i = 0; i < NX; i++) {
      var v0 = vf[idx(i, jm)], v1 = vf[idx(iw(i + 1), jm)];
      if (v0 <= 0 && v1 > 0) cells++;
      var a = Math.abs(v0); if (a > umax) umax = a;
    }
    var st = umax < 1 ? 'CONDUCTION' : 'CONVECTING';
    hudCells.textContent = umax < 1 ? 0 : cells;
    if (st !== lastState) {
      hudState.textContent = st;
      if (lastState && typeof SFX !== 'undefined') SFX.play(st === 'CONDUCTION' ? 'pop' : 'grow');
      lastState = st;
    }
  }

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    SCALE = W > 1200 ? 3 : (W > 600 ? 4 : 5);
    var rW = Math.ceil(W * dpr / SCALE);
    var rH = Math.ceil(H * dpr / SCALE);
    off.width = rW; off.height = rH;
    img = O.createImageData(rW, rH);
    pix = img.data;
  }

  function setRa(val) {
    Ra = val;
    dtDiff = dt = 0.2 * dx2 / Math.max(1, Pr);
    hudRa.textContent = Ra >= 1000 ? (Ra / 1000).toFixed(Ra >= 10000 ? 0 : 1) + 'K' : Ra;
    document.getElementById('sv-ra').textContent = hudRa.textContent;
  }

  var slRa = document.getElementById('sl-ra');
  slRa.addEventListener('input', function() {
    var v = +this.value;
    var ra = Math.round(500 * Math.pow(10, v / 33.33));
    if (ra > 100000) ra = 100000;
    setRa(ra);
  });

  var slPart = document.getElementById('sl-part');
  slPart.addEventListener('input', function() {
    var v = +this.value;
    numPart = Math.round(v * 30);
    document.getElementById('sv-part').textContent = numPart;
    initParticles();
  });

  var btnView = document.getElementById('btn-view');
  var viewNames = ['THERMAL', 'VORTICITY'];
  btnView.addEventListener('click', function() {
    viewMode = (viewMode + 1) % 2;
    btnView.textContent = viewNames[viewMode];
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  var btnReset = document.getElementById('btn-reset');
  btnReset.addEventListener('click', function() {
    initFields();
    initParticles();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var pDown = false, rightDown = false;
  function canvasToGrid(cx, cy) {
    return { i: cx / (W * dpr) * NX, j: (1 - cy / (H * dpr)) * NY };
  }
  function applyHeat(cx, cy, hot) {
    var g = canvasToGrid(cx, cy);
    var gi = Math.round(g.i), gj = Math.round(g.j);
    var r = 3;
    for (var dj = -r; dj <= r; dj++) {
      for (var di = -r; di <= r; di++) {
        var ii = iw(gi + di), jj = gj + dj;
        if (jj < 1 || jj >= NY - 1) continue;
        var d2 = di * di + dj * dj;
        if (d2 > r * r) continue;
        var f = 1 - Math.sqrt(d2) / r;
        var k = idx(ii, jj);
        if (hot) {
          T[k] = Math.min(1.5, T[k] + 0.3 * f);
        } else {
          T[k] = Math.max(0, T[k] - 0.3 * f);
        }
      }
    }
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    if (e.button === 2) {
      rightDown = true;
      applyHeat(e.clientX * dpr, e.clientY * dpr, false);
      if (typeof SFX !== 'undefined') SFX.play('pop');
    } else {
      pDown = true;
      applyHeat(e.clientX * dpr, e.clientY * dpr, true);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (pDown) applyHeat(e.clientX * dpr, e.clientY * dpr, true);
    if (rightDown) applyHeat(e.clientX * dpr, e.clientY * dpr, false);
  });
  window.addEventListener('mouseup', function() { pDown = false; rightDown = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    pDown = true;
    applyHeat(t.clientX * dpr, t.clientY * dpr, true);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (pDown) {
      var t = e.touches[0];
      applyHeat(t.clientX * dpr, t.clientY * dpr, true);
    }
  }, { passive: false });
  C.addEventListener('touchend', function() { pDown = false; });

  function loop() {
    stepSim();
    updateParticles();
    render();
    requestAnimationFrame(loop);
  }

  resize();
  window.addEventListener('resize', resize);
  initFields();
  initParticles();
  setRa(Math.round(500 * Math.pow(10, 59 / 33.33)));
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
