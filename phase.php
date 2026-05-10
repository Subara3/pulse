<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('phase');

$pageName = 'phase';
$pageTitle = 'PHASE - PULSE';
$pageDesc = '位相面。2次元力学系の軌道構造を粒子の流れとして可視化する。';
$pageCanonical = 'https://pulse.subara3.com/phase.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  SYSTEM: <span id="hudSys" class="v">PENDULUM</span><br>
  TRACERS: <span id="hudN" class="v">0</span><br>
  ORBITS: <span id="hudOrb" class="v">0</span>
</div>

<div id="info">
  phase portrait &middot; ODE flow<br>
  dx/dt = <span class="v" id="infoX">y</span><br>
  dy/dt = <span class="v" id="infoY">&minus;sin(x)</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'trace orbit'],
  ['type' => 'right', 'label' => 'clear orbits'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">SYSTEM</label>
    <div class="preset-group" id="presets">
      <button class="preset-btn active" data-p="0">PENDULUM</button>
      <button class="preset-btn" data-p="1">VAN DER POL</button>
      <button class="preset-btn" data-p="2">DUFFING</button>
      <button class="preset-btn" data-p="3">HOPF</button>
      <button class="preset-btn" data-p="4">DAMPED</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">DENSITY</label>
    <div class="slider-row">
      <input type="range" id="sldDen" min="100" max="2000" value="800">
      <span class="slider-value" id="valDen">800</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpd" min="1" max="50" value="15">
      <span class="slider-value" id="valSpd">15</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnNull">NULLCLINES: OFF</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnClear">CLEAR ORBITS</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;

  var SY = [
    {
      n:'PENDULUM', ex:'y', ey:'−sin(x)',
      dx:function(x,y){return y},
      dy:function(x,y){return -Math.sin(x)},
      xr:[-10,10], yr:[-4,4],
      fp:[
        {x:0,y:0,t:'c'},{x:Math.PI,y:0,t:'s'},{x:-Math.PI,y:0,t:'s'},
        {x:2*Math.PI,y:0,t:'c'},{x:-2*Math.PI,y:0,t:'c'},
        {x:3*Math.PI,y:0,t:'s'},{x:-3*Math.PI,y:0,t:'s'}
      ],
      seed:[[0.1,1.5],[0.1,2.8],[Math.PI+0.001,0.001]]
    },
    {
      n:'VAN DER POL', ex:'y', ey:'μ(1−x²)y − x',
      dx:function(x,y){return y},
      dy:function(x,y){return 2*(1-x*x)*y - x},
      xr:[-5,5], yr:[-8,8],
      fp:[{x:0,y:0,t:'u'}],
      seed:[[0.1,0.1],[4,0],[-3,5]]
    },
    {
      n:'DUFFING', ex:'y', ey:'x − x³',
      dx:function(x,y){return y},
      dy:function(x,y){return x - x*x*x},
      xr:[-2.5,2.5], yr:[-2,2],
      fp:[{x:0,y:0,t:'s'},{x:1,y:0,t:'c'},{x:-1,y:0,t:'c'}],
      seed:[[0.5,0.3],[-0.5,-0.3],[0.001,1.4]]
    },
    {
      n:'HOPF', ex:'−y + x(1−r²)', ey:'x + y(1−r²)',
      dx:function(x,y){var r2=x*x+y*y;return -y+x*(1-r2)},
      dy:function(x,y){var r2=x*x+y*y;return x+y*(1-r2)},
      xr:[-3,3], yr:[-3,3],
      fp:[{x:0,y:0,t:'u'}],
      seed:[[0.1,0],[2,0],[0,0.5]]
    },
    {
      n:'DAMPED', ex:'y', ey:'−0.3y − sin(x)',
      dx:function(x,y){return y},
      dy:function(x,y){return -0.3*y - Math.sin(x)},
      xr:[-10,10], yr:[-4,4],
      fp:[
        {x:0,y:0,t:'a'},{x:Math.PI,y:0,t:'s'},{x:-Math.PI,y:0,t:'s'},
        {x:2*Math.PI,y:0,t:'a'},{x:-2*Math.PI,y:0,t:'a'},
        {x:3*Math.PI,y:0,t:'s'},{x:-3*Math.PI,y:0,t:'s'}
      ],
      seed:[[0.3,2],[Math.PI+0.1,0.5],[0.1,3.5]]
    }
  ];

  var ci = 0;
  var den = 800, spd = 15;
  var showNL = false;

  var vx, vy, sc;
  var P = [];
  var TRAIL = 10;
  var orbs = [];

  function sy() { return SY[ci]; }

  function setView() {
    var s = sy();
    var xw = s.xr[1] - s.xr[0];
    var yw = s.yr[1] - s.yr[0];
    sc = Math.min(W / xw, H / yw) * 0.88;
    vx = W / 2 - (s.xr[0] + s.xr[1]) / 2 * sc;
    vy = H / 2 + (s.yr[0] + s.yr[1]) / 2 * sc;
  }

  function toS(x, y) { return [vx + x * sc, vy - y * sc]; }
  function toW(sx, sy) { return [(sx - vx) / sc, -(sy - vy) / sc]; }

  function mkPt() {
    var s = sy();
    return {
      x: s.xr[0] + Math.random() * (s.xr[1] - s.xr[0]),
      y: s.yr[0] + Math.random() * (s.yr[1] - s.yr[0]),
      tr: [], age: 0, ml: 80 + Math.random() * 160
    };
  }

  function initField() {
    P = [];
    for (var i = 0; i < den; i++) P.push(mkPt());
  }

  function rk4(x, y, dt, dxf, dyf) {
    var k1x = dxf(x, y), k1y = dyf(x, y);
    var k2x = dxf(x + .5 * dt * k1x, y + .5 * dt * k1y);
    var k2y = dyf(x + .5 * dt * k1x, y + .5 * dt * k1y);
    var k3x = dxf(x + .5 * dt * k2x, y + .5 * dt * k2y);
    var k3y = dyf(x + .5 * dt * k2x, y + .5 * dt * k2y);
    var k4x = dxf(x + dt * k3x, y + dt * k3y);
    var k4y = dyf(x + dt * k3x, y + dt * k3y);
    return [
      x + dt / 6 * (k1x + 2 * k2x + 2 * k3x + k4x),
      y + dt / 6 * (k1y + 2 * k2y + 2 * k3y + k4y)
    ];
  }

  function traceOrbit(x0, y0) {
    var s = sy();
    var dt = 0.005;
    var n = 6000;
    var fwd = [{x: x0, y: y0}];
    var bwd = [];
    var x, y, r, dx, dy, v;
    var bx = 2, by = 2;
    var xMin = s.xr[0] * bx, xMax = s.xr[1] * bx;
    var yMin = s.yr[0] * by, yMax = s.yr[1] * by;

    x = x0; y = y0;
    for (var i = 0; i < n; i++) {
      r = rk4(x, y, dt, s.dx, s.dy);
      x = r[0]; y = r[1];
      fwd.push({x: x, y: y});
      if (x < xMin || x > xMax || y < yMin || y > yMax) break;
      dx = s.dx(x, y); dy = s.dy(x, y);
      v = dx * dx + dy * dy;
      if (v < 1e-12) break;
    }

    x = x0; y = y0;
    for (var i = 0; i < n; i++) {
      r = rk4(x, y, -dt, s.dx, s.dy);
      x = r[0]; y = r[1];
      bwd.unshift({x: x, y: y});
      if (x < xMin || x > xMax || y < yMin || y > yMax) break;
      dx = s.dx(x, y); dy = s.dy(x, y);
      v = dx * dx + dy * dy;
      if (v < 1e-12) break;
    }

    return bwd.concat(fwd);
  }

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    setView();
  }

  function updateField() {
    var s = sy();
    var dt = 0.02 * spd / 15;

    for (var i = 0; i < P.length; i++) {
      var p = P[i];
      p.tr.push({x: p.x, y: p.y});
      if (p.tr.length > TRAIL) p.tr.shift();

      var r = rk4(p.x, p.y, dt, s.dx, s.dy);
      p.x = r[0]; p.y = r[1];
      p.age++;

      if (p.x < s.xr[0] || p.x > s.xr[1] ||
          p.y < s.yr[0] || p.y > s.yr[1] || p.age > p.ml) {
        P[i] = mkPt();
      }
    }
  }

  function drawNullclines() {
    var s = sy();
    var res = 150;
    var xw = s.xr[1] - s.xr[0];
    var yw = s.yr[1] - s.yr[0];
    var dxs = xw / res, dys = yw / res;

    for (var i = 0; i < res; i++) {
      for (var j = 0; j < res; j++) {
        var x = s.xr[0] + dxs * i;
        var y = s.yr[0] + dys * j;

        var v00 = s.dx(x, y);
        var v10 = s.dx(x + dxs, y);
        var v01 = s.dx(x, y + dys);
        if ((v00 > 0) !== (v10 > 0) || (v00 > 0) !== (v01 > 0)) {
          var p = toS(x + dxs * 0.5, y + dys * 0.5);
          ctx.fillStyle = 'rgba(0,245,212,0.25)';
          ctx.fillRect(p[0] - 1, p[1] - 1, 2, 2);
        }

        v00 = s.dy(x, y);
        v10 = s.dy(x + dxs, y);
        v01 = s.dy(x, y + dys);
        if ((v00 > 0) !== (v10 > 0) || (v00 > 0) !== (v01 > 0)) {
          var p = toS(x + dxs * 0.5, y + dys * 0.5);
          ctx.fillStyle = 'rgba(123,44,191,0.35)';
          ctx.fillRect(p[0] - 1, p[1] - 1, 2, 2);
        }
      }
    }
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var o = toS(0, 0);
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, o[1]); ctx.lineTo(W, o[1]);
    ctx.moveTo(o[0], 0); ctx.lineTo(o[0], H);
    ctx.stroke();

    if (showNL) drawNullclines();

    var s = sy();
    for (var i = 0; i < orbs.length; i++) {
      var orb = orbs[i];
      if (orb.length < 2) continue;

      ctx.beginPath();
      var pp = toS(orb[0].x, orb[0].y);
      ctx.moveTo(pp[0], pp[1]);
      for (var j = 1; j < orb.length; j++) {
        pp = toS(orb[j].x, orb[j].y);
        ctx.lineTo(pp[0], pp[1]);
      }
      ctx.strokeStyle = 'rgba(0,245,212,0.12)';
      ctx.lineWidth = 3;
      ctx.stroke();
      ctx.strokeStyle = 'rgba(0,245,212,0.55)';
      ctx.lineWidth = 1.2;
      ctx.stroke();
    }

    for (var i = 0; i < P.length; i++) {
      var p = P[i];
      var tr = p.tr;
      if (tr.length < 2) continue;

      var dx = s.dx(p.x, p.y), dy = s.dy(p.x, p.y);
      var vel = Math.sqrt(dx * dx + dy * dy);
      var a = Math.min(vel * 0.25, 0.3);
      if (a < 0.01) continue;

      ctx.beginPath();
      var sp = toS(tr[0].x, tr[0].y);
      ctx.moveTo(sp[0], sp[1]);
      for (var j = 1; j < tr.length; j++) {
        sp = toS(tr[j].x, tr[j].y);
        ctx.lineTo(sp[0], sp[1]);
      }
      sp = toS(p.x, p.y);
      ctx.lineTo(sp[0], sp[1]);
      ctx.strokeStyle = 'rgba(0,245,212,' + a.toFixed(3) + ')';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    var fps = s.fp;
    for (var i = 0; i < fps.length; i++) {
      var fp = fps[i];
      var pp = toS(fp.x, fp.y);
      if (pp[0] < -20 || pp[0] > W + 20 || pp[1] < -20 || pp[1] > H + 20) continue;

      ctx.beginPath();
      ctx.arc(pp[0], pp[1], 10, 0, Math.PI * 2);
      var gc;
      if (fp.t === 'a') gc = 'rgba(0,245,212,0.12)';
      else if (fp.t === 'c') gc = 'rgba(123,44,191,0.12)';
      else if (fp.t === 's') gc = 'rgba(255,255,255,0.08)';
      else gc = 'rgba(123,44,191,0.12)';
      ctx.fillStyle = gc;
      ctx.fill();

      ctx.beginPath();
      ctx.arc(pp[0], pp[1], 3.5, 0, Math.PI * 2);
      if (fp.t === 'a') ctx.fillStyle = '#00f5d4';
      else if (fp.t === 'c') ctx.fillStyle = '#7b2cbf';
      else if (fp.t === 's') ctx.fillStyle = 'rgba(255,255,255,0.5)';
      else ctx.fillStyle = '#7b2cbf';
      ctx.fill();

      if (fp.t === 's') {
        ctx.beginPath();
        ctx.moveTo(pp[0] - 5, pp[1] - 5);
        ctx.lineTo(pp[0] + 5, pp[1] + 5);
        ctx.moveTo(pp[0] + 5, pp[1] - 5);
        ctx.lineTo(pp[0] - 5, pp[1] + 5);
        ctx.strokeStyle = 'rgba(255,255,255,0.2)';
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    }

    document.getElementById('hudSys').textContent = s.n;
    document.getElementById('hudN').textContent = P.length;
    document.getElementById('hudOrb').textContent = orbs.length;
    document.getElementById('infoX').textContent = s.ex;
    document.getElementById('infoY').textContent = s.ey;
  }

  function loop() {
    updateField();
    render();
    requestAnimationFrame(loop);
  }

  function switchSys(idx) {
    ci = idx;
    orbs = [];
    setView();
    initField();
    seedOrbits();
  }

  function seedOrbits() {
    var s = sy();
    if (s.seed) {
      for (var i = 0; i < s.seed.length; i++) {
        orbs.push(traceOrbit(s.seed[i][0], s.seed[i][1]));
      }
    }
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var w = toW(e.clientX, e.clientY);
    orbs.push(traceOrbit(w[0], w[1]));
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    orbs = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var tc = e.touches[0];
    var w = toW(tc.clientX, tc.clientY);
    orbs.push(traceOrbit(w[0], w[1]));
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, {passive: false});

  document.getElementById('sldDen').addEventListener('input', function() {
    den = parseInt(this.value);
    document.getElementById('valDen').textContent = den;
    initField();
  });

  document.getElementById('sldSpd').addEventListener('input', function() {
    spd = parseInt(this.value);
    document.getElementById('valSpd').textContent = spd;
  });

  document.getElementById('btnNull').addEventListener('click', function() {
    showNL = !showNL;
    this.textContent = 'NULLCLINES: ' + (showNL ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    orbs = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var pBtns = document.querySelectorAll('#presets .preset-btn');
  for (var j = 0; j < pBtns.length; j++) {
    pBtns[j].addEventListener('click', function() {
      for (var k = 0; k < pBtns.length; k++) pBtns[k].classList.remove('active');
      this.classList.add('active');
      switchSys(parseInt(this.dataset.p));
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  window.addEventListener('resize', resize);
  resize();
  initField();
  seedOrbits();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
