<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('firefly');

$pageName = 'firefly';
$pageTitle = 'FIREFLY - PULSE';
$pageDesc = '暗がりで点がばらばらに光っている。隣の発光を見ると自分の時計を少し進めるだけで、やがて全体が一斉に脈打つ。（パルス結合振動子）';
$pageCanonical = 'https://pulse.subara3.com/firefly.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4}
.hud .sub{color:rgba(123,44,191,.9)}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
#mode-bar{position:fixed;top:3.2rem;left:50%;transform:translateX(-50%);z-index:60;display:flex;gap:6px}
#mode-bar button{padding:4px 12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.5);font-family:"Space Grotesk",sans-serif;font-size:.6rem;letter-spacing:.08em;cursor:pointer;border-radius:3px;transition:all .2s}
#mode-bar button:hover{border-color:rgba(0,245,212,0.4);color:rgba(0,245,212,0.7)}
#mode-bar button.active{background:rgba(0,245,212,0.12);border-color:#00f5d4;color:#00f5d4}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}#info{bottom:1rem;left:1rem;font-size:.5rem}#mode-bar{top:2.8rem}#mode-bar button{font-size:.52rem;padding:3px 8px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  FIREFLIES <b id="h-count">0</b><br>
  <span id="hud-sync-line">ORDER <b id="h-order">0.00</b><br></span>
  <span id="hud-vs-lines" style="display:none">LOCAL <b id="h-local">0.00</b><br><span class="sub">GLOBAL</span> <b id="h-global">0.00</b><br></span>
  <span class="sub">COUPLING</span> <b id="h-coupling">0.10</b>
</div>

<div id="mode-bar">
  <button class="active" data-mode="sync">SYNC</button>
  <button data-mode="vs">VS</button>
</div>

<div id="info">
  pulse-coupled oscillators<br>
  <span class="v">synchronization without a conductor</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'attract'],
  ['type' => 'right', 'label' => 'disturb'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">COUPLING</span>
      <input type="range" id="sldCoupling" min="0" max="100" value="30">
      <span class="slider-value" id="valCoupling">0.10</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">COUNT</span>
      <input type="range" id="sldCount" min="20" max="500" value="150">
      <span class="slider-value" id="valCount">150</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">RANGE</span>
      <input type="range" id="sldRange" min="20" max="200" value="80">
      <span class="slider-value" id="valRange">80</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;
  var TAU = Math.PI * 2;

  var mode = 'sync';
  var coupling = 0.10;
  var cRange = 80;
  var targetN = 150;

  var MAX = 600;
  var N = 0;
  var px = new Float32Array(MAX);
  var py = new Float32Array(MAX);
  var phase = new Float32Array(MAX);
  var freq = new Float32Array(MAX);
  var bright = new Float32Array(MAX);
  var side = new Uint8Array(MAX);
  var firedFlag = new Uint8Array(MAX);

  var rings = [];
  var MAXRINGS = 60;

  var mx = -9999, my = -9999, mdown = false;
  var lastT = 0, syncDone = false, lastCsfx = 0;

  var hCount = null, hOrder = null, hCoupling = null;
  var hudSL = null, hudVL = null, hLocal = null, hGlobal = null;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth * dpr;
    H = window.innerHeight * dpr;
    C.width = W; C.height = H;
    C.style.width = window.innerWidth + 'px';
    C.style.height = window.innerHeight + 'px';
  }

  function spawn(count) {
    N = 0; syncDone = false; rings = [];
    var w = W / dpr, h = H / dpr, pad = 50;
    var half = Math.floor(count / 2);
    for (var i = 0; i < count && N < MAX; i++) {
      if (mode === 'vs') {
        if (i < half) {
          px[N] = pad + Math.random() * (w / 2 - pad * 1.5);
          side[N] = 0;
        } else {
          px[N] = w / 2 + pad * 0.5 + Math.random() * (w / 2 - pad * 1.5);
          side[N] = 1;
        }
      } else {
        px[N] = pad + Math.random() * (w - pad * 2);
        side[N] = 0;
      }
      py[N] = pad + Math.random() * (h - pad * 2);
      phase[N] = Math.random();
      freq[N] = 0.85 + Math.random() * 0.3;
      bright[N] = 0;
      N++;
    }
  }

  function addOne(x, y) {
    if (N >= MAX) return;
    px[N] = x; py[N] = y;
    phase[N] = Math.random();
    freq[N] = 0.85 + Math.random() * 0.3;
    bright[N] = 0;
    side[N] = (mode === 'vs') ? (x < (W / dpr) / 2 ? 0 : 1) : 0;
    N++;
    SFX.play('spawn');
  }

  function disturb(cx, cy) {
    var r2 = 100 * 100;
    for (var i = 0; i < N; i++) {
      var dx = px[i] - cx, dy = py[i] - cy;
      if (dx * dx + dy * dy < r2) {
        phase[i] = Math.random();
        bright[i] = 0;
      }
    }
    SFX.play('ripple');
  }

  function processFires(now) {
    var queue = [], head = 0;
    for (var i = 0; i < N; i++) {
      firedFlag[i] = 0;
      if (phase[i] >= 1.0) {
        phase[i] = 0; bright[i] = 1.0; firedFlag[i] = 1;
        queue.push(i);
      }
    }
    while (head < queue.length) {
      var fi = queue[head++];
      if (rings.length < MAXRINGS) {
        var isGlobal = (mode === 'vs' && side[fi] === 1);
        if (!isGlobal) {
          rings.push({ x: px[fi], y: py[fi], age: 0, life: 0.45, maxR: cRange });
        }
      }
      for (var j = 0; j < N; j++) {
        if (firedFlag[j]) continue;
        if (mode === 'vs' && side[j] !== side[fi]) continue;
        var inR;
        if (mode === 'vs' && side[fi] === 1) {
          inR = true;
        } else {
          var dx = px[j] - px[fi], dy = py[j] - py[fi];
          inR = (dx * dx + dy * dy < cRange * cRange);
        }
        if (inR) {
          phase[j] += coupling;
          if (phase[j] >= 1.0) {
            phase[j] = 0; bright[j] = 1.0; firedFlag[j] = 1;
            queue.push(j);
          }
        }
      }
    }
    if (queue.length > 5 && now - lastCsfx > 300) {
      SFX.play('collide', 200);
      lastCsfx = now;
    }
    return queue.length;
  }

  function orderFor(s) {
    var cs = 0, sn = 0, cnt = 0;
    for (var i = 0; i < N; i++) {
      if (mode === 'vs' && s !== undefined && side[i] !== s) continue;
      cs += Math.cos(TAU * phase[i]);
      sn += Math.sin(TAU * phase[i]);
      cnt++;
    }
    return cnt ? Math.sqrt(cs * cs + sn * sn) / cnt : 0;
  }

  function update(dt, now) {
    if (dt > 0.05) dt = 0.05;
    for (var i = 0; i < N; i++) phase[i] += freq[i] * dt;
    processFires(now);
    var decay = Math.pow(0.015, dt);
    for (var i = 0; i < N; i++) {
      bright[i] *= decay;
      if (bright[i] < 0.004) bright[i] = 0;
    }
    for (var r = rings.length - 1; r >= 0; r--) {
      rings[r].age += dt;
      if (rings[r].age >= rings[r].life) rings.splice(r, 1);
    }
    if (mdown) {
      for (var i = 0; i < N; i++) {
        var dx = mx - px[i], dy = my - py[i];
        var d = Math.sqrt(dx * dx + dy * dy);
        if (d < 140 && d > 3) {
          px[i] += (dx / d) * 70 * dt;
          py[i] += (dy / d) * 70 * dt;
        }
      }
    }
    var ord;
    if (mode === 'vs') {
      var oL = orderFor(0), oG = orderFor(1);
      hLocal.textContent = oL.toFixed(2);
      hGlobal.textContent = oG.toFixed(2);
      ord = Math.max(oL, oG);
    } else {
      ord = orderFor();
      hOrder.textContent = ord.toFixed(2);
    }
    if (ord > 0.92 && !syncDone && N > 10) {
      syncDone = true; SFX.play('complete');
    } else if (ord < 0.5) {
      syncDone = false;
    }
    hCount.textContent = N;
    hCoupling.textContent = coupling.toFixed(2);
  }

  function render() {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var w = W / dpr, h = H / dpr;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, w, h);

    for (var i = 0; i < N; i++) {
      var pb = Math.pow(phase[i], 5) * 0.1;
      var tb = Math.max(bright[i], pb);
      if (tb < 0.12) {
        ctx.fillStyle = 'rgba(123,44,191,' + (0.12 + pb * 2) + ')';
        ctx.beginPath();
        ctx.arc(px[i], py[i], 1.5, 0, TAU);
        ctx.fill();
      }
    }

    ctx.globalCompositeOperation = 'lighter';

    for (var r = 0; r < rings.length; r++) {
      var rg = rings[r], t = rg.age / rg.life;
      var rad = rg.maxR * t;
      var a = (1 - t) * 0.1;
      ctx.strokeStyle = 'rgba(0,245,212,' + a + ')';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.arc(rg.x, rg.y, rad, 0, TAU);
      ctx.stroke();
    }

    for (var i = 0; i < N; i++) {
      var pb = Math.pow(phase[i], 5) * 0.1;
      var tb = Math.max(bright[i], pb);
      if (tb < 0.06) continue;
      var gr = 3 + tb * 18;
      var gd = ctx.createRadialGradient(px[i], py[i], 0, px[i], py[i], gr);
      gd.addColorStop(0, 'rgba(0,245,212,' + (tb * 0.85) + ')');
      gd.addColorStop(0.45, 'rgba(0,245,212,' + (tb * 0.25) + ')');
      gd.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = gd;
      ctx.beginPath();
      ctx.arc(px[i], py[i], gr, 0, TAU);
      ctx.fill();
      ctx.fillStyle = 'rgba(0,245,212,' + (0.2 + tb * 0.8) + ')';
      ctx.beginPath();
      ctx.arc(px[i], py[i], 1.5 + tb * 1.5, 0, TAU);
      ctx.fill();
    }

    ctx.globalCompositeOperation = 'source-over';

    if (mode === 'vs') {
      ctx.save();
      ctx.strokeStyle = 'rgba(123,44,191,0.3)';
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 6]);
      ctx.beginPath();
      ctx.moveTo(w / 2, 40);
      ctx.lineTo(w / 2, h - 20);
      ctx.stroke();
      ctx.restore();
      ctx.font = '600 11px "Space Grotesk",sans-serif';
      ctx.textAlign = 'center';
      ctx.fillStyle = 'rgba(123,44,191,0.55)';
      ctx.fillText('LOCAL', w * 0.25, 55);
      ctx.fillText('GLOBAL', w * 0.75, 55);
    }
  }

  function frame(t) {
    var dt = lastT ? (t - lastT) / 1000 : 0.016;
    lastT = t;
    update(dt, t);
    render();
    requestAnimationFrame(frame);
  }

  function canvasPos(e) {
    var r = C.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    mdown = true;
    var p = canvasPos(e);
    mx = p.x; my = p.y;
    addOne(p.x, p.y);
  });
  C.addEventListener('mousemove', function(e) {
    var p = canvasPos(e);
    mx = p.x; my = p.y;
  });
  C.addEventListener('mouseup', function(e) {
    if (e.button === 2) return;
    mdown = false;
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var p = canvasPos(e);
    disturb(p.x, p.y);
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var tc = e.touches[0];
    var r = C.getBoundingClientRect();
    var x = tc.clientX - r.left, y = tc.clientY - r.top;
    mx = x; my = y;
    if (e.touches.length >= 2) {
      disturb(x, y);
    } else {
      mdown = true;
      addOne(x, y);
    }
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var tc = e.touches[0];
    var r = C.getBoundingClientRect();
    mx = tc.clientX - r.left;
    my = tc.clientY - r.top;
  }, { passive: false });
  C.addEventListener('touchend', function() { mdown = false; });

  document.querySelectorAll('#mode-bar button').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#mode-bar button').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      mode = btn.getAttribute('data-mode');
      hudSL.style.display = mode === 'sync' ? '' : 'none';
      hudVL.style.display = mode === 'vs' ? '' : 'none';
      spawn(targetN);
      SFX.play('toggle');
    });
  });

  var sldC = document.getElementById('sldCoupling');
  var sldN = document.getElementById('sldCount');
  var sldR = document.getElementById('sldRange');
  sldC.addEventListener('input', function() {
    coupling = parseFloat(sldC.value) / 300;
    document.getElementById('valCoupling').textContent = coupling.toFixed(2);
  });
  sldN.addEventListener('input', function() {
    targetN = parseInt(sldN.value);
    document.getElementById('valCount').textContent = targetN;
  });
  sldN.addEventListener('change', function() { spawn(targetN); SFX.play('click'); });
  sldR.addEventListener('input', function() {
    cRange = parseInt(sldR.value);
    document.getElementById('valRange').textContent = cRange;
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    spawn(targetN); SFX.play('click');
  });

  hCount = document.getElementById('h-count');
  hOrder = document.getElementById('h-order');
  hCoupling = document.getElementById('h-coupling');
  hudSL = document.getElementById('hud-sync-line');
  hudVL = document.getElementById('hud-vs-lines');
  hLocal = document.getElementById('h-local');
  hGlobal = document.getElementById('h-global');

  resize();
  spawn(targetN);
  window.addEventListener('resize', function() { resize(); spawn(targetN); });
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
