<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystal');

$pageName = 'crystal';
$pageTitle = 'CRYSTAL - PULSE';
$pageDesc = '六角格子上の結晶成長シミュレーション。拡散と凝結で六対称の結晶が育つ。';
$pageCanonical = 'https://pulse.subara3.com/crystal.php';

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
  CELLS: <span id="hudCells" class="v">1</span><br>
  STEP: <span id="hudStep" class="v">0</span><br>
  MORPH: <span id="hudMorph" class="v">DENDRITE</span>
</div>

<div id="info">
  hex crystal growth &middot; Reiter model<br>
  &alpha; = <span class="v" id="infoA">0.50</span> &middot;
  &beta; = <span class="v" id="infoB">1.20</span> &middot;
  &gamma; = <span class="v" id="infoG">0.40</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">PRESET</label>
    <div class="preset-group" id="presets">
      <button class="preset-btn active" data-p="0">DENDRITE</button>
      <button class="preset-btn" data-p="1">PLATE</button>
      <button class="preset-btn" data-p="2">FERN</button>
      <button class="preset-btn" data-p="3">NEEDLE</button>
      <button class="preset-btn" data-p="4">STAR</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">DIFFUSION (&alpha;)</label>
    <div class="slider-row">
      <input type="range" id="sldA" min="5" max="95" value="50">
      <span class="slider-value" id="valA">0.50</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">THRESHOLD (&beta;)</label>
    <div class="slider-row">
      <input type="range" id="sldB" min="20" max="300" value="120">
      <span class="slider-value" id="valB">1.20</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">VAPOR (&gamma;)</label>
    <div class="slider-row">
      <input type="range" id="sldG" min="10" max="90" value="40">
      <span class="slider-value" id="valG">0.40</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpd" min="1" max="50" value="10">
      <span class="slider-value" id="valSpd">10</span>
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
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, cellSz, cenX, cenY;
  var SQ3 = Math.sqrt(3);

  var N = Math.min(80, Math.max(25,
    Math.floor(Math.min(
      window.innerWidth * 0.82 / (SQ3 * 2 * 3.5),
      window.innerHeight * 0.82 / (3 * 3.5)
    ))
  ));
  var S = 2 * N + 1, TOT = S * S;
  var DIRS = [[1,0],[-1,0],[0,1],[0,-1],[1,-1],[-1,1]];

  var frz, d0, d1, bm;
  var step, fcnt, done;

  var al = 0.50, bt = 1.20, gm = 0.40, spd = 10;
  var presets = [
    {a:0.50, b:1.20, g:0.40, n:'DENDRITE'},
    {a:0.85, b:0.45, g:0.65, n:'PLATE'},
    {a:0.35, b:1.80, g:0.38, n:'FERN'},
    {a:0.20, b:2.40, g:0.32, n:'NEEDLE'},
    {a:0.60, b:0.80, g:0.50, n:'STAR'}
  ];

  var glowC = [], coreC = [];
  function buildLUT() {
    glowC.length = 0; coreC.length = 0;
    for (var i = 0; i <= N; i++) {
      var t = Math.min(i / (N * 0.85), 1);
      var r = Math.round(123 * t);
      var g = Math.round(245 - 201 * t);
      var b = Math.round(212 - 21 * t);
      glowC.push('rgba(' + r + ',' + g + ',' + b + ',0.12)');
      coreC.push('rgb(' + r + ',' + g + ',' + b + ')');
    }
  }

  function ix(q, r) { return (q + N) + (r + N) * S; }
  function ok(q, r) { return Math.abs(q) <= N && Math.abs(r) <= N && Math.abs(q + r) <= N; }

  function init() {
    frz = new Uint8Array(TOT);
    d0 = new Float32Array(TOT);
    d1 = new Float32Array(TOT);
    bm = new Float32Array(TOT);
    step = 0; fcnt = 0; done = false;

    for (var r = -N; r <= N; r++)
      for (var q = -N; q <= N; q++)
        if (ok(q, r)) d0[ix(q, r)] = gm;

    doFreeze(0, 0);
    for (var i = 0; i < 600 && fcnt < 80; i++) simStep();
  }

  function doFreeze(q, r) {
    if (!ok(q, r)) return;
    var i = ix(q, r);
    if (frz[i]) return;
    frz[i] = 1; d0[i] = 0; d1[i] = 0; bm[i] = 0; fcnt++;
  }

  function addSeed(q, r) {
    var pts = [[q,r],[-r,q+r],[-(q+r),q],[-q,-r],[r,-(q+r)],[q+r,-q]];
    for (var i = 0; i < 6; i++) doFreeze(pts[i][0], pts[i][1]);
  }

  function simStep() {
    if (done) return;

    for (var r = -N; r <= N; r++) {
      for (var q = -N; q <= N; q++) {
        if (!ok(q, r)) continue;
        var i = ix(q, r);
        if (frz[i]) { d1[i] = 0; continue; }
        var sum = 0;
        for (var k = 0; k < 6; k++) {
          var nq = q + DIRS[k][0], nr = r + DIRS[k][1];
          if (ok(nq, nr) && !frz[ix(nq, nr)]) sum += d0[ix(nq, nr)];
        }
        d1[i] = d0[i] + al * (sum / 6 - d0[i]);
      }
    }

    var triggered = false;
    for (var r = -N; r <= N; r++) {
      for (var q = -N; q <= N; q++) {
        if (!ok(q, r)) continue;
        var i = ix(q, r);
        if (frz[i]) continue;
        var adj = false;
        for (var k = 0; k < 6; k++) {
          var nq = q + DIRS[k][0], nr = r + DIRS[k][1];
          if (ok(nq, nr) && frz[ix(nq, nr)]) { adj = true; break; }
        }
        if (adj) {
          bm[i] += d1[i];
          d1[i] = 0;
          if (bm[i] >= bt) {
            doFreeze(q, r);
            var dist = Math.max(Math.abs(q), Math.abs(r), Math.abs(q + r));
            if (dist >= N - 2 && !triggered) {
              triggered = true;
              done = true;
              if (typeof SFX !== 'undefined') SFX.play('complete');
            }
          }
        }
      }
    }

    for (var r = -N; r <= N; r++) {
      for (var q = -N; q <= N; q++) {
        if (!ok(q, r)) continue;
        var dist = Math.max(Math.abs(q), Math.abs(r), Math.abs(q + r));
        if (dist >= N - 1 && !frz[ix(q, r)]) d1[ix(q, r)] = gm;
      }
    }

    var tmp = d0; d0 = d1; d1 = tmp;
    step++;
  }

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    var gw = SQ3 * N * 2, gh = 1.5 * N * 2;
    cellSz = Math.min((W * 0.82) / gw, (H * 0.82) / gh);
    cenX = W / 2; cenY = H / 2;
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    var cs = cellSz, s = cs * 0.55;

    for (var r = -N; r <= N; r++) {
      for (var q = -N; q <= N; q++) {
        if (!ok(q, r)) continue;
        var i = ix(q, r);
        var px = cenX + cs * (SQ3 * q + SQ3 * 0.5 * r);
        var py = cenY + cs * 1.5 * r;

        if (frz[i]) {
          var dist = Math.max(Math.abs(q), Math.abs(r), Math.abs(q + r));
          var di = Math.min(dist, N);
          ctx.fillStyle = glowC[di];
          ctx.fillRect(px - s * 1.5, py - s * 1.5, s * 3, s * 3);
          ctx.fillStyle = coreC[di];
          ctx.fillRect(px - s, py - s, s * 2, s * 2);
        } else if (bm[i] > 0.02) {
          var a = Math.min(bm[i] / bt * 0.3, 0.25);
          ctx.fillStyle = 'rgba(0,245,212,' + a.toFixed(3) + ')';
          ctx.fillRect(px - s * 0.8, py - s * 0.8, s * 1.6, s * 1.6);
        }
      }
    }

    document.getElementById('hudCells').textContent = fcnt;
    document.getElementById('hudStep').textContent = step;
    document.getElementById('infoA').textContent = al.toFixed(2);
    document.getElementById('infoB').textContent = bt.toFixed(2);
    document.getElementById('infoG').textContent = gm.toFixed(2);
  }

  function loop() {
    for (var i = 0; i < spd; i++) simStep();
    render();
    requestAnimationFrame(loop);
  }

  function pxToHex(mx, my) {
    var x = (mx - cenX) / cellSz;
    var y = (my - cenY) / cellSz;
    var q = SQ3 / 3 * x - y / 3;
    var r = 2 / 3 * y;
    var s = -q - r;
    var rq = Math.round(q), rr = Math.round(r), rs = Math.round(s);
    var dq = Math.abs(rq - q), dr = Math.abs(rr - r), ds = Math.abs(rs - s);
    if (dq > dr && dq > ds) rq = -rr - rs;
    else if (dr > ds) rr = -rq - rs;
    return [rq, rr];
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var h = pxToHex(e.clientX, e.clientY);
    if (ok(h[0], h[1])) {
      addSeed(h[0], h[1]);
      done = false;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var tc = e.touches[0];
    var h = pxToHex(tc.clientX, tc.clientY);
    if (ok(h[0], h[1])) {
      addSeed(h[0], h[1]);
      done = false;
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }, {passive: false});

  document.getElementById('sldA').addEventListener('input', function() {
    al = parseInt(this.value) / 100;
    document.getElementById('valA').textContent = al.toFixed(2);
  });
  document.getElementById('sldB').addEventListener('input', function() {
    bt = parseInt(this.value) / 100;
    document.getElementById('valB').textContent = bt.toFixed(2);
  });
  document.getElementById('sldG').addEventListener('input', function() {
    gm = parseInt(this.value) / 100;
    document.getElementById('valG').textContent = gm.toFixed(2);
  });
  document.getElementById('sldSpd').addEventListener('input', function() {
    spd = parseInt(this.value);
    document.getElementById('valSpd').textContent = spd;
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var pBtns = document.querySelectorAll('#presets .preset-btn');
  for (var j = 0; j < pBtns.length; j++) {
    pBtns[j].addEventListener('click', function() {
      for (var k = 0; k < pBtns.length; k++) pBtns[k].classList.remove('active');
      this.classList.add('active');
      var p = presets[parseInt(this.dataset.p)];
      al = p.a; bt = p.b; gm = p.g;
      document.getElementById('sldA').value = Math.round(al * 100);
      document.getElementById('sldB').value = Math.round(bt * 100);
      document.getElementById('sldG').value = Math.round(gm * 100);
      document.getElementById('valA').textContent = al.toFixed(2);
      document.getElementById('valB').textContent = bt.toFixed(2);
      document.getElementById('valG').textContent = gm.toFixed(2);
      document.getElementById('hudMorph').textContent = p.n;
      init();
      if (typeof SFX !== 'undefined') SFX.play('click');
    });
  }

  buildLUT();
  window.addEventListener('resize', resize);
  resize();
  init();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
