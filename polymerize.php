<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('polymerize');

$pageName = 'polymerize';
$pageTitle = 'POLYMERIZE - PULSE';
$pageDesc = '重合シミュレーション。モノマーが衝突と結合を繰り返し、鎖状分子からゲルネットワークへ相転移する。';
$pageCanonical = 'https://pulse.subara3.com/polymerize.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MONOMERS <span id="hud-n">0</span></div>
  <div>BONDS <span id="hud-b">0</span></div>
  <div>CHAINS <span id="hud-c">0</span></div>
  <div>LONGEST <span id="hud-mx">0</span></div>
  <div>GEL <span id="hud-gl">0%</span></div>
</div>
<div id="info-label"><span>POLYMERIZE</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'push'],
  ['type' => 'right', 'label' => 'break'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">TEMPERATURE</label>
    <input type="range" id="sl-temp" min="0" max="100" value="50">
    <span class="slider-value" id="v-temp">0.50</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">BOND RATE</label>
    <input type="range" id="sl-rate" min="1" max="100" value="30">
    <span class="slider-value" id="v-rate">0.30</span>
  </div>
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="ctrl-btn active" data-mode="linear">LINEAR</button>
    <button class="ctrl-btn" data-mode="branch">BRANCH</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-auto">AUTO</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr, time = 0;

  var $n = document.getElementById('hud-n');
  var $b = document.getElementById('hud-b');
  var $c = document.getElementById('hud-c');
  var $mx = document.getElementById('hud-mx');
  var $gl = document.getElementById('hud-gl');
  var slT = document.getElementById('sl-temp');
  var slR = document.getElementById('sl-rate');
  var vT = document.getElementById('v-temp');
  var vR = document.getElementById('v-rate');
  var modeBtns = document.querySelectorAll('[data-mode]');
  var btnAuto = document.getElementById('btn-auto');
  var btnReset = document.getElementById('btn-reset');

  var PR = 3, BD = 22, BR = 14, SK = 0.05;
  var RD = 10, RF = 0.3, MX = 400, BN = 0.4, DM = 0.97;

  var ps = [], bs = [], bset = {};
  var temp = 0.5, brt = 0.03, md = 'linear';
  var autoF = true, autoT = 0;
  var mxClust = 0, nChains = 0, gelF = 0, gelDone = false, flash = 0;
  var mX = 0, mY = 0, mDown = false, mRight = false, dragged = false;
  var touchId = null;

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

  function clr(s) {
    var t = s <= 1 ? 0 : Math.min(1, Math.log(s) / 3.4);
    return [0 + 123 * t | 0, 245 - 201 * t | 0, 212 - 21 * t | 0];
  }

  function bkey(a, b) { return a < b ? a * 10000 + b : b * 10000 + a; }

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function addP(x, y) {
    if (ps.length >= MX) return -1;
    var mb = 2;
    if (md === 'branch' && Math.random() < 0.25) mb = 3;
    ps.push({ x: x, y: y, vx: (Math.random() - 0.5) * temp * 3, vy: (Math.random() - 0.5) * temp * 3,
      mb: mb, bc: 0, ci: -1, cs: 1 });
    return ps.length - 1;
  }

  function init() {
    ps = []; bs = []; bset = {};
    mxClust = 0; nChains = 0; gelF = 0; gelDone = false; flash = 0; autoT = 0;
    var n = Math.min(120, Math.floor(W * H / 8000));
    var mg = 60;
    for (var i = 0; i < n; i++) {
      addP(mg + Math.random() * (W - 2 * mg), mg + Math.random() * (H - 2 * mg));
    }
  }

  function computeClusters() {
    var n = ps.length;
    for (var i = 0; i < n; i++) { ps[i].ci = -1; ps[i].cs = 1; }
    var adj = new Array(n);
    for (var i = 0; i < n; i++) adj[i] = [];
    for (var k = 0; k < bs.length; k++) {
      adj[bs[k].a].push(bs[k].b);
      adj[bs[k].b].push(bs[k].a);
    }
    var cid = 0;
    mxClust = 0; nChains = 0;
    for (var i = 0; i < n; i++) {
      if (ps[i].ci >= 0) continue;
      var q = [i], qi = 0, mem = [];
      ps[i].ci = cid;
      while (qi < q.length) {
        var u = q[qi++]; mem.push(u);
        for (var k = 0; k < adj[u].length; k++) {
          var v = adj[u][k];
          if (ps[v].ci < 0) { ps[v].ci = cid; q.push(v); }
        }
      }
      var sz = mem.length;
      for (var k = 0; k < sz; k++) ps[mem[k]].cs = sz;
      if (sz > mxClust) mxClust = sz;
      if (sz > 1) nChains++;
      cid++;
    }
    gelF = n > 0 ? mxClust / n : 0;
  }

  function update() {
    var n = ps.length;

    if (autoF && n < MX) {
      autoT++;
      if (autoT >= 40) {
        autoT = 0;
        var side = Math.random() * 4 | 0;
        var x, y;
        if (side === 0) { x = Math.random() * W; y = -5; }
        else if (side === 1) { x = W + 5; y = Math.random() * H; }
        else if (side === 2) { x = Math.random() * W; y = H + 5; }
        else { x = -5; y = Math.random() * H; }
        var idx = addP(x, y);
        if (idx >= 0) {
          var p = ps[idx];
          if (side === 0) p.vy = 1 + Math.random();
          else if (side === 1) p.vx = -1 - Math.random();
          else if (side === 2) p.vy = -1 - Math.random();
          else p.vx = 1 + Math.random();
        }
      }
    }

    n = ps.length;

    for (var i = 0; i < n; i++) {
      var p = ps[i];
      p.vx += (Math.random() - 0.5) * BN * temp;
      p.vy += (Math.random() - 0.5) * BN * temp;
      p.vx *= DM; p.vy *= DM;
    }

    for (var k = 0; k < bs.length; k++) {
      var bond = bs[k], a = ps[bond.a], b = ps[bond.b];
      var dx = b.x - a.x, dy = b.y - a.y;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < 0.01) continue;
      var f = SK * (d - BR) / d;
      a.vx += f * dx; a.vy += f * dy;
      b.vx -= f * dx; b.vy -= f * dy;
      var dvx = b.vx - a.vx, dvy = b.vy - a.vy;
      a.vx += dvx * 0.008; a.vy += dvy * 0.008;
      b.vx -= dvx * 0.008; b.vy -= dvy * 0.008;
    }

    for (var i = 0; i < n; i++) {
      var a = ps[i], ha = a.bc < a.mb;
      for (var j = i + 1; j < n; j++) {
        var b = ps[j];
        var dx = b.x - a.x, dy = b.y - a.y;
        var d2 = dx * dx + dy * dy;
        if (d2 < RD * RD && d2 > 0.01) {
          var d = Math.sqrt(d2);
          var f = RF * (1 - d / RD) / d;
          a.vx -= f * dx; a.vy -= f * dy;
          b.vx += f * dx; b.vy += f * dy;
        }
        if (ha && b.bc < b.mb && d2 < BD * BD && d2 > 0.01) {
          var k2 = bkey(i, j);
          if (!bset[k2]) {
            var d = Math.sqrt(d2);
            var prob = brt * (1 - d / BD);
            if (Math.random() < prob) {
              bs.push({ a: i, b: j });
              bset[k2] = true;
              a.bc++; b.bc++;
              ha = a.bc < a.mb;
              if (window.SFX) SFX.play('tick', 100);
            }
          }
        }
      }
    }

    if (mDown && !mRight) {
      for (var i = 0; i < n; i++) {
        var p = ps[i];
        var dx = p.x - mX, dy = p.y - mY;
        var d2 = dx * dx + dy * dy;
        var rad = 80;
        if (d2 < rad * rad && d2 > 1) {
          var d = Math.sqrt(d2);
          var f = 0.5 * (1 - d / rad);
          p.vx += f * dx / d * 3; p.vy += f * dy / d * 3;
        }
      }
    }

    if (mRight) {
      for (var k = bs.length - 1; k >= 0; k--) {
        var bond = bs[k], a = ps[bond.a], b = ps[bond.b];
        var cx = (a.x + b.x) / 2, cy = (a.y + b.y) / 2;
        var dx = cx - mX, dy = cy - mY;
        if (dx * dx + dy * dy < 35 * 35) {
          delete bset[bkey(bond.a, bond.b)];
          ps[bond.a].bc--; ps[bond.b].bc--;
          bs.splice(k, 1);
          if (window.SFX) SFX.play('pop', 80);
        }
      }
    }

    for (var i = 0; i < n; i++) {
      var p = ps[i];
      p.x += p.vx; p.y += p.vy;
      if (p.x < PR) { p.x = PR; p.vx = Math.abs(p.vx) * 0.5; }
      if (p.x > W - PR) { p.x = W - PR; p.vx = -Math.abs(p.vx) * 0.5; }
      if (p.y < PR) { p.y = PR; p.vy = Math.abs(p.vy) * 0.5; }
      if (p.y > H - PR) { p.y = H - PR; p.vy = -Math.abs(p.vy) * 0.5; }
    }

    computeClusters();

    if (gelF > 0.5 && !gelDone) {
      gelDone = true; flash = 30;
      if (window.SFX) SFX.play('success');
    }
    if (gelF < 0.3) gelDone = false;
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (flash > 0) {
      ctx.fillStyle = 'rgba(0,245,212,' + (flash / 120) + ')';
      ctx.fillRect(0, 0, W, H);
      flash--;
    }

    var n = ps.length;

    ctx.lineWidth = 1.5;
    for (var k = 0; k < bs.length; k++) {
      var bond = bs[k], a = ps[bond.a], b = ps[bond.b];
      var sz = Math.max(a.cs, b.cs);
      var c = clr(sz);
      ctx.beginPath();
      ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y);
      ctx.strokeStyle = rgba(c, 0.5);
      ctx.stroke();
    }

    for (var i = 0; i < n; i++) {
      var p = ps[i], c = clr(p.cs);
      var r = PR + (p.mb > 2 ? 1.5 : 0);
      var pulse = 0.7 + 0.3 * Math.sin(time * 0.03 + p.ci * 0.7);
      ctx.beginPath();
      ctx.arc(p.x, p.y, r * 3.5, 0, Math.PI * 2);
      ctx.fillStyle = rgba(c, 0.06 * pulse);
      ctx.fill();
    }

    for (var i = 0; i < n; i++) {
      var p = ps[i], c = clr(p.cs);
      var r = PR + (p.mb > 2 ? 1.5 : 0);
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fillStyle = rgba(c, 0.85);
      ctx.fill();
    }

    for (var i = 0; i < n; i++) {
      var p = ps[i];
      if (p.mb <= 2) continue;
      var c = clr(p.cs);
      ctx.beginPath();
      ctx.arc(p.x, p.y, PR + 3, 0, Math.PI * 2);
      ctx.strokeStyle = rgba(c, 0.25);
      ctx.lineWidth = 0.5;
      ctx.stroke();
    }

    $n.textContent = n;
    $b.textContent = bs.length;
    $c.textContent = nChains;
    $mx.textContent = mxClust;
    $gl.textContent = (gelF * 100 | 0) + '%';
  }

  function loop() { update(); render(); time++; requestAnimationFrame(loop); }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) { mRight = true; mX = e.clientX; mY = e.clientY; return; }
    mDown = true; dragged = false;
    mX = e.clientX; mY = e.clientY;
    if (ps.length < MX) {
      for (var i = 0; i < 5; i++) {
        addP(e.clientX + (Math.random() - 0.5) * 30, e.clientY + (Math.random() - 0.5) * 30);
      }
      if (window.SFX) SFX.play('spawn');
    }
  });
  C.addEventListener('mousemove', function(e) {
    mX = e.clientX; mY = e.clientY;
    if (mDown) dragged = true;
  });
  window.addEventListener('mouseup', function() { mDown = false; mRight = false; dragged = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      var t = e.touches[0];
      mX = t.clientX; mY = t.clientY;
      mDown = true; dragged = false; touchId = t.identifier;
      if (ps.length < MX) {
        for (var i = 0; i < 5; i++) {
          addP(t.clientX + (Math.random() - 0.5) * 30, t.clientY + (Math.random() - 0.5) * 30);
        }
        if (window.SFX) SFX.play('spawn');
      }
    }
    e.preventDefault();
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        mX = e.touches[i].clientX; mY = e.touches[i].clientY;
        dragged = true;
      }
    }
    e.preventDefault();
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    mDown = false; dragged = false; touchId = null;
  });

  slT.addEventListener('input', function() {
    temp = this.value / 100; vT.textContent = temp.toFixed(2);
  });
  slR.addEventListener('input', function() {
    brt = this.value / 1000; vR.textContent = (this.value / 100).toFixed(2);
  });
  modeBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      modeBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      md = this.dataset.mode;
      if (window.SFX) SFX.play('toggle');
    });
  });
  btnAuto.addEventListener('click', function() {
    autoF = !autoF; this.classList.toggle('active', autoF);
    if (window.SFX) SFX.play('toggle');
  });
  btnReset.addEventListener('click', function() {
    init();
    if (window.SFX) SFX.play('click');
  });

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
