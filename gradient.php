<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gradient');

$pageName = 'gradient';
$pageTitle = 'GRADIENT - PULSE';
$pageDesc = '勾配降下法の可視化。最適化ランドスケープをSGD・Momentum・Adamが降りていく。';
$pageCanonical = 'https://pulse.subara3.com/gradient.php';

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
  PARTICLES: <span id="hudN" class="v">0</span><br>
  OPTIMIZER: <span id="hudOpt" class="v">SGD</span><br>
  STEP: <span id="hudStep" class="v">0</span>
</div>

<div id="info">
  gradient descent<br>
  <span class="v">optimization landscape</span><br>
  ∇f → minimum
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop'],
  ['type' => 'drag', 'label' => 'scatter'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">FUNCTION</label>
    <div class="btn-row" id="funcBtns">
      <button class="control-btn active" data-fn="rastrigin">RASTRIGIN</button>
      <button class="control-btn" data-fn="rosenbrock">ROSENBROCK</button>
      <button class="control-btn" data-fn="himmelblau">HIMMELBLAU</button>
      <button class="control-btn" data-fn="ackley">ACKLEY</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">OPTIMIZER</label>
    <div class="btn-row" id="optBtns">
      <button class="control-btn active" data-opt="sgd">SGD</button>
      <button class="control-btn" data-opt="momentum">MOMENTUM</button>
      <button class="control-btn" data-opt="adam">ADAM</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">LEARNING RATE</label>
    <div class="slider-row">
      <input type="range" id="sldLR" min="1" max="100" value="50">
      <span class="slider-value" id="valLR">5.0e-3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="10" value="3">
      <span class="slider-value" id="valSpeed">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
      <button class="control-btn" id="btnPause">PAUSE</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var PI2 = Math.PI * 2;

  var $n = document.getElementById('hudN');
  var $opt = document.getElementById('hudOpt');
  var $step = document.getElementById('hudStep');

  var funcDefs = {
    rastrigin: {
      fn: function(x, y) {
        return 20 + x*x + y*y - 10*(Math.cos(PI2*x) + Math.cos(PI2*y));
      },
      range: [-5.12, 5.12, -5.12, 5.12],
      lr0: 0.005,
      levels: 30
    },
    rosenbrock: {
      fn: function(x, y) {
        var a = 1-x, b = y-x*x;
        return a*a + 100*b*b;
      },
      range: [-2.5, 2.5, -1.5, 3.5],
      lr0: 0.00001,
      levels: 35
    },
    himmelblau: {
      fn: function(x, y) {
        var a = x*x+y-11, b = x+y*y-7;
        return a*a + b*b;
      },
      range: [-6, 6, -6, 6],
      lr0: 0.002,
      levels: 25
    },
    ackley: {
      fn: function(x, y) {
        return -20*Math.exp(-0.2*Math.sqrt(0.5*(x*x+y*y)))
               - Math.exp(0.5*(Math.cos(PI2*x)+Math.cos(PI2*y)))
               + Math.E + 20;
      },
      range: [-5, 5, -5, 5],
      lr0: 0.03,
      levels: 20
    }
  };

  var curFn = 'rastrigin';
  var optType = 'sgd';
  var lrMul = 1.0;
  var speed = 3;
  var paused = false;
  var stepCount = 0;
  var parts = [];
  var maxTrail = 400;
  var maxParts = 120;
  var eR = [0,0,0,0];
  var lImg;

  function p2m(sx, sy) {
    return {
      x: eR[0] + (sx/W) * (eR[1]-eR[0]),
      y: eR[2] + (sy/H) * (eR[3]-eR[2])
    };
  }
  function m2p(mx, my) {
    return {
      x: (mx-eR[0]) / (eR[1]-eR[0]) * W,
      y: (my-eR[2]) / (eR[3]-eR[2]) * H
    };
  }

  function calcGrad(fn, x, y) {
    var h = 1e-6;
    return {
      x: (fn(x+h,y) - fn(x-h,y)) / (2*h),
      y: (fn(x,y+h) - fn(x,y-h)) / (2*h)
    };
  }

  function makePart(mx, my) {
    return {
      x: mx, y: my,
      trail: [{x:mx,y:my}],
      vx:0, vy:0,
      m1x:0, m1y:0, m2x:0, m2y:0,
      t:0, done:false
    };
  }

  function addPart(mx, my) {
    if (parts.length >= maxParts) parts.shift();
    parts.push(makePart(mx, my));
  }

  function stepPart(p) {
    if (p.done) return;
    var fd = funcDefs[curFn];
    var g = calcGrad(fd.fn, p.x, p.y);
    var elr = fd.lr0 * lrMul;
    p.t++;

    var dx = 0, dy = 0;
    if (optType === 'sgd') {
      dx = -elr * g.x;
      dy = -elr * g.y;
    } else if (optType === 'momentum') {
      p.vx = 0.9*p.vx - elr*g.x;
      p.vy = 0.9*p.vy - elr*g.y;
      dx = p.vx; dy = p.vy;
    } else {
      var b1=0.9, b2=0.999, eps=1e-8;
      p.m1x = b1*p.m1x + (1-b1)*g.x;
      p.m1y = b1*p.m1y + (1-b1)*g.y;
      p.m2x = b2*p.m2x + (1-b2)*g.x*g.x;
      p.m2y = b2*p.m2y + (1-b2)*g.y*g.y;
      var bc1=1-Math.pow(b1,p.t), bc2=1-Math.pow(b2,p.t);
      dx = -elr*(p.m1x/bc1)/(Math.sqrt(p.m2x/bc2)+eps);
      dy = -elr*(p.m1y/bc1)/(Math.sqrt(p.m2y/bc2)+eps);
    }

    var sm = Math.sqrt(dx*dx + dy*dy);
    var mxs = Math.min(eR[1]-eR[0], eR[3]-eR[2]) * 0.04;
    if (sm > mxs) { dx *= mxs/sm; dy *= mxs/sm; }

    p.x += dx; p.y += dy;
    p.x = Math.max(eR[0], Math.min(eR[1], p.x));
    p.y = Math.max(eR[2], Math.min(eR[3], p.y));

    p.trail.push({x:p.x, y:p.y});
    if (p.trail.length > maxTrail) p.trail.shift();

    var gm = Math.sqrt(g.x*g.x + g.y*g.y);
    if (gm < 1e-5 && p.t > 30) p.done = true;
  }

  function compERange() {
    var r = funcDefs[curFn].range;
    var cx=(r[0]+r[1])/2, cy=(r[2]+r[3])/2;
    var dw=r[1]-r[0], dh=r[3]-r[2];
    var sa=W/H, da=dw/dh;
    if (sa > da) {
      var nw=dh*sa;
      eR[0]=cx-nw/2; eR[1]=cx+nw/2; eR[2]=r[2]; eR[3]=r[3];
    } else {
      var nh=dw/sa;
      eR[0]=r[0]; eR[1]=r[1]; eR[2]=cy-nh/2; eR[3]=cy+nh/2;
    }
  }

  function renderLand() {
    compERange();
    var sc = 2;
    var gw = Math.ceil(W/sc), gh = Math.ceil(H/sc);
    lImg = document.createElement('canvas');
    lImg.width = gw; lImg.height = gh;
    var lx = lImg.getContext('2d');
    var id = lx.createImageData(gw, gh);
    var d = id.data;
    var fn = funcDefs[curFn].fn;
    var nL = funcDefs[curFn].levels;

    var vals = new Float32Array(gw*gh);
    var fMin = Infinity, fMax = -Infinity;
    for (var py = 0; py < gh; py++) {
      var my = eR[2] + (py/gh)*(eR[3]-eR[2]);
      for (var px = 0; px < gw; px++) {
        var mx = eR[0] + (px/gw)*(eR[1]-eR[0]);
        var v = fn(mx, my);
        var idx = py*gw+px;
        vals[idx] = v;
        if (v < fMin) fMin = v;
        if (v > fMax) fMax = v;
      }
    }

    var logR = Math.log(1 + fMax - fMin) || 1;

    for (var i = 0, n = gw*gh; i < n; i++) {
      var t = Math.log(1 + vals[i] - fMin) / logR;

      var glow = Math.exp(-8*t);
      var cr = 10 + t*20;
      var cg = 10 + t*2 + glow*40;
      var cb = 15 + t*35 + glow*35;

      var lv = t * nL;
      var fr = lv - Math.floor(lv);
      var dst = Math.min(fr, 1-fr);
      var cl = Math.exp(-dst*60) * (0.3 + 0.7*(1-t));
      cg += cl*160;
      cb += cl*135;

      var p = i << 2;
      d[p]   = Math.min(255, cr|0);
      d[p|1] = Math.min(255, cg|0);
      d[p|2] = Math.min(255, cb|0);
      d[p|3] = 255;
    }

    lx.putImageData(id, 0, 0);
  }

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W*dpr; C.height = H*dpr;
    C.style.width = W+'px'; C.style.height = H+'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    renderLand();
  }

  function draw() {
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(lImg, 0, 0, W, H);

    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      var tr = p.trail;
      if (tr.length < 2) continue;

      var col = p.done ? '123,44,191' : '0,245,212';

      ctx.strokeStyle = 'rgba('+col+',0.12)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      var pt = m2p(tr[0].x, tr[0].y);
      ctx.moveTo(pt.x, pt.y);
      for (var j = 1; j < tr.length; j++) {
        pt = m2p(tr[j].x, tr[j].y);
        ctx.lineTo(pt.x, pt.y);
      }
      ctx.stroke();

      var rLen = Math.min(40, tr.length);
      if (rLen > 1) {
        ctx.strokeStyle = 'rgba('+col+',0.5)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        var si = tr.length - rLen;
        pt = m2p(tr[si].x, tr[si].y);
        ctx.moveTo(pt.x, pt.y);
        for (var j = si+1; j < tr.length; j++) {
          pt = m2p(tr[j].x, tr[j].y);
          ctx.lineTo(pt.x, pt.y);
        }
        ctx.stroke();
      }
    }

    ctx.save();
    ctx.shadowBlur = 10;
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      var pos = m2p(p.x, p.y);
      var c = p.done ? '#7b2cbf' : '#00f5d4';
      ctx.shadowColor = c;
      ctx.fillStyle = c;
      ctx.beginPath();
      ctx.arc(pos.x, pos.y, p.done ? 3 : 4, 0, PI2);
      ctx.fill();
    }
    ctx.restore();

    var tm = Date.now() * 0.001;
    ctx.fillStyle = 'rgba(0,245,212,'+(0.008+0.004*Math.sin(tm*0.5))+')';
    ctx.fillRect(0, 0, W, H);

    $n.textContent = parts.length;
    $step.textContent = stepCount;
  }

  function loop() {
    if (!paused) {
      for (var s = 0; s < speed; s++) {
        for (var i = 0; i < parts.length; i++) stepPart(parts[i]);
        stepCount++;
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  var pDown = false, lastAdd = null;

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) {
      parts = [];
      stepCount = 0;
      SFX.play('pop');
      return;
    }
    pDown = true;
    var m = p2m(e.clientX, e.clientY);
    addPart(m.x, m.y);
    lastAdd = {x:e.clientX, y:e.clientY};
    SFX.play('spawn');
  });

  C.addEventListener('pointermove', function(e) {
    if (!pDown || !lastAdd) return;
    var dx = e.clientX-lastAdd.x, dy = e.clientY-lastAdd.y;
    if (dx*dx+dy*dy > 900) {
      var m = p2m(e.clientX, e.clientY);
      addPart(m.x, m.y);
      lastAdd = {x:e.clientX, y:e.clientY};
      SFX.play('tick', 80);
    }
  });

  C.addEventListener('pointerup', function() { pDown=false; lastAdd=null; });
  C.addEventListener('pointerleave', function() { pDown=false; lastAdd=null; });

  function updateLR() {
    var elr = funcDefs[curFn].lr0 * lrMul;
    document.getElementById('valLR').textContent = elr.toExponential(1);
  }

  var fBtns = document.querySelectorAll('#funcBtns .control-btn');
  for (var i = 0; i < fBtns.length; i++) {
    (function(btn) {
      btn.addEventListener('click', function() {
        curFn = this.getAttribute('data-fn');
        for (var j = 0; j < fBtns.length; j++) fBtns[j].classList.remove('active');
        this.classList.add('active');
        parts = []; stepCount = 0;
        renderLand();
        seedParts();
        updateLR();
        SFX.play('toggle');
      });
    })(fBtns[i]);
  }

  var oBtns = document.querySelectorAll('#optBtns .control-btn');
  for (var i = 0; i < oBtns.length; i++) {
    (function(btn) {
      btn.addEventListener('click', function() {
        optType = this.getAttribute('data-opt');
        for (var j = 0; j < oBtns.length; j++) oBtns[j].classList.remove('active');
        this.classList.add('active');
        $opt.textContent = optType.toUpperCase();
        for (var k = 0; k < parts.length; k++) {
          var pk = parts[k];
          pk.vx=0; pk.vy=0;
          pk.m1x=0; pk.m1y=0; pk.m2x=0; pk.m2y=0;
          pk.t=0; pk.done=false;
        }
        SFX.play('toggle');
      });
    })(oBtns[i]);
  }

  document.getElementById('sldLR').addEventListener('input', function() {
    lrMul = Math.pow(10, (this.value-50)/25);
    updateLR();
  });

  document.getElementById('sldSpeed').addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('valSpeed').textContent = this.value;
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    parts = []; stepCount = 0;
    seedParts();
    SFX.play('click');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    parts = []; stepCount = 0;
    SFX.play('pop');
  });

  document.getElementById('btnPause').addEventListener('click', function() {
    paused = !paused;
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', paused);
    SFX.play('toggle');
  });

  function seedParts() {
    var r = funcDefs[curFn].range;
    var pad = 0.15;
    var dw = r[1]-r[0], dh = r[3]-r[2];
    for (var i = 0; i < 8; i++) {
      var mx = r[0] + pad*dw + Math.random()*dw*(1-2*pad);
      var my = r[2] + pad*dh + Math.random()*dh*(1-2*pad);
      parts.push(makePart(mx, my));
    }
  }

  window.addEventListener('resize', resize);
  resize();
  seedParts();
  updateLR();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>