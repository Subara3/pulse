<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('roulette');

$pageName = 'roulette';
$pageTitle = 'ROULETTE - PULSE';
$pageDesc = '多角形が転がると、つけた印が曲線を描く。三角形は激しく跳ね、八角形はなめらかに波打ち、円に近づくとサイクロイドになる。';
$pageCanonical = 'https://pulse.subara3.com/roulette.php';
$extraStyle = '
  #modes{position:fixed;top:56px;left:50%;transform:translateX(-50%);display:flex;gap:3px;z-index:10}
  .mbtn{background:rgba(123,44,191,.15);border:1px solid rgba(123,44,191,.3);color:rgba(255,255,255,.6);font:600 13px "Space Grotesk",sans-serif;padding:5px 14px;border-radius:4px;cursor:pointer;transition:all .2s;min-width:28px;text-align:center}
  .mbtn:hover{background:rgba(123,44,191,.3)}
  .mbtn.on{background:rgba(0,245,212,.12);border-color:rgba(0,245,212,.45);color:#00f5d4}
  .ctrl-btn.on{background:rgba(0,245,212,.15);border-color:rgba(0,245,212,.4);color:#00f5d4}
  @media(max-width:480px){#modes{gap:2px}.mbtn{padding:4px 10px;font-size:12px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="modes">
  <button class="mbtn" data-n="3">3</button>
  <button class="mbtn on" data-n="4">4</button>
  <button class="mbtn" data-n="5">5</button>
  <button class="mbtn" data-n="6">6</button>
  <button class="mbtn" data-n="8">8</button>
  <button class="mbtn" data-n="360">&#9675;</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-spd" class="ctrl-slider" min="1" max="30" value="10">
    <span class="slider-val" id="val-spd">1.0</span>
  </div>
  <div class="slider-row"><label class="slider-label">MARKER</label></div>
  <div class="btn-row">
    <button class="ctrl-btn on" id="btn-vtx">VERTEX</button>
    <button class="ctrl-btn" id="btn-ctr">CENTER</button>
    <button class="ctrl-btn" id="btn-out">OUTER</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-lock">LOCK</button>
    <button class="ctrl-btn" id="btn-clr">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, R, gY;
var n = 4, phi = 0, spd = 1, savedSpd = 1;
var mBx = 0, mBy = 0, camX = 0;
var trace = [], locked = [], MAX = 6000;
var dragging = false, prevStep = -1, flash = 0, lt = 0;
var HU = {3:172, 4:182, 5:195, 6:262, 8:278, 360:288};

function resize(){
  var pR = R || 0;
  W = C.width = innerWidth; H = C.height = innerHeight;
  R = Math.max(24, Math.min(W, H) * 0.058);
  gY = H * 0.68;
  if(pR > 0 && pR !== R){ var s = R / pR; mBx *= s; mBy *= s; }
}

function vAng(i){ return -Math.PI/2 - Math.PI/n + 2*Math.PI*(i % n)/n; }

function setMarker(type){
  var a = vAng(0);
  if(type === 'v'){ mBx = R*Math.cos(a); mBy = R*Math.sin(a); }
  else if(type === 'c'){ mBx = 0; mBy = 0; }
  else { mBx = 1.5*R*Math.cos(a); mBy = 1.5*R*Math.sin(a); }
  trace = []; phi = 0; camX = 0; prevStep = -1;
}

function setN(v){
  n = v; setMarker('v'); flash = 0;
  document.querySelectorAll('.mbtn').forEach(function(b){
    b.classList.toggle('on', +b.dataset.n === n);
  });
  updMB('btn-vtx');
  SFX.play('toggle');
}

function updMB(id){
  ['btn-vtx','btn-ctr','btn-out'].forEach(function(x){
    document.getElementById(x).classList.toggle('on', x === id);
  });
}

function getSt(){
  var c = Math.cos(phi), s = Math.sin(phi);
  if(n >= 100) return {cx: R*phi, cy: R, c: c, s: s};
  var al = 2*Math.PI/n, sl = 2*R*Math.sin(Math.PI/n);
  var k = Math.floor(phi/al), j = (k+1) % n;
  var th = vAng(j);
  var pvx = R*Math.cos(th), pvy = R*Math.sin(th);
  var px = sl/2 + k*sl;
  return {
    cx: px - (pvx*c + pvy*s),
    cy: pvx*s - pvy*c,
    c: c, s: s, sl: sl, k: k
  };
}

function b2w(o, bx, by){
  return [o.cx + bx*o.c + by*o.s, o.cy - bx*o.s + by*o.c];
}
function w2s(x, y){ return [x - camX + W*0.35, gY - y]; }

function tPath(p){
  ctx.beginPath();
  var on = false;
  for(var i = 0; i < p.length; i++){
    var s = w2s(p[i][0], p[i][1]);
    if(s[0] < -80 || s[0] > W + 80){ on = false; continue; }
    if(!on){ ctx.moveTo(s[0], s[1]); on = true; }
    else ctx.lineTo(s[0], s[1]);
  }
  ctx.stroke();
}

function drawTrace(pts, hue, a){
  if(pts.length < 2) return;
  ctx.lineWidth = 4;
  ctx.strokeStyle = 'hsla('+hue+',80%,50%,'+(a*0.22)+')';
  tPath(pts);
  ctx.lineWidth = 1.5;
  ctx.strokeStyle = 'hsla('+hue+',85%,58%,'+a+')';
  tPath(pts);
}

function draw(o){
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  ctx.strokeStyle = 'rgba(0,245,212,0.1)';
  ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(0, gY); ctx.lineTo(W, gY); ctx.stroke();

  for(var i = 0; i < locked.length; i++)
    drawTrace(locked[i].p, locked[i].h, 0.25);

  drawTrace(trace, HU[n] || 200, 0.85);

  var hu = HU[n] || 200;
  ctx.strokeStyle = 'hsla('+hu+',65%,42%,0.5)';
  ctx.lineWidth = 1.5;
  ctx.beginPath();
  if(n >= 100){
    var cs = w2s(o.cx, o.cy);
    ctx.arc(cs[0], cs[1], R, 0, Math.PI*2);
  } else {
    for(var i = 0; i <= n; i++){
      var a = vAng(i);
      var wp = b2w(o, R*Math.cos(a), R*Math.sin(a));
      var sp = w2s(wp[0], wp[1]);
      if(!i) ctx.moveTo(sp[0], sp[1]); else ctx.lineTo(sp[0], sp[1]);
    }
  }
  ctx.stroke();

  var cs2 = w2s(o.cx, o.cy);
  var mw = b2w(o, mBx, mBy), ms = w2s(mw[0], mw[1]);

  ctx.strokeStyle = 'rgba(0,245,212,0.15)';
  ctx.lineWidth = 1;
  ctx.setLineDash([3, 3]);
  ctx.beginPath();
  ctx.moveTo(cs2[0], cs2[1]); ctx.lineTo(ms[0], ms[1]);
  ctx.stroke();
  ctx.setLineDash([]);

  ctx.fillStyle = 'rgba(255,255,255,0.2)';
  ctx.beginPath();
  ctx.arc(cs2[0], cs2[1], 2, 0, Math.PI*2);
  ctx.fill();

  if(flash > 0.03 && n < 100){
    var sl2 = 2*R*Math.sin(Math.PI/n);
    var kk = Math.floor(phi/(2*Math.PI/n));
    var cp = w2s(sl2/2 + kk*sl2, 0);
    ctx.fillStyle = 'rgba(0,245,212,'+(flash*0.45)+')';
    ctx.beginPath();
    ctx.arc(cp[0], cp[1], 6*flash + 2, 0, Math.PI*2);
    ctx.fill();
  }

  ctx.shadowBlur = 12;
  ctx.shadowColor = '#00f5d4';
  ctx.fillStyle = '#00f5d4';
  ctx.beginPath();
  ctx.arc(ms[0], ms[1], 4, 0, Math.PI*2);
  ctx.fill();
  ctx.shadowBlur = 0;

  ctx.fillStyle = 'rgba(255,255,255,0.45)';
  ctx.font = '11px "Space Grotesk",sans-serif';
  ctx.textAlign = 'left';
  var lb = n >= 100 ? 'CIRCLE' : n + '-GON';
  var md = (Math.sqrt(mBx*mBx + mBy*mBy) / R).toFixed(2);
  ctx.fillText(lb + '  ·  MARKER ' + md + 'R', 14, 76);
  if(locked.length)
    ctx.fillText('LOCKED ' + locked.length + '/5', 14, 90);
}

function loop(t){
  var dt = lt ? Math.min((t - lt)/1000, 0.05) : 0.016;
  lt = t;
  if(spd > 0) phi += spd * dt * 1.2;

  var o = getSt();
  var mw = b2w(o, mBx, mBy);
  trace.push([mw[0], mw[1]]);
  if(trace.length > MAX) trace = trace.slice(-MAX + 1000);
  camX += (o.cx - camX) * 0.06;

  if(n < 100){
    var step = Math.floor(phi / (2*Math.PI/n));
    if(step !== prevStep){ prevStep = step; flash = 1; SFX.play('tick', 80); }
  }
  flash *= 0.92;

  draw(o);
  requestAnimationFrame(loop);
}

function clickAt(sx, sy){
  var wx = sx + camX - W*0.35, wy = gY - sy;
  var o = getSt();
  var dx = wx - o.cx, dy = wy - o.cy;
  var d = Math.sqrt(dx*dx + dy*dy);
  if(d < R*3.5 && d > 1){
    mBx = o.c*dx - o.s*dy;
    mBy = o.s*dx + o.c*dy;
    trace = [];
    updMB('');
    SFX.play('spawn');
  }
}

function updateMarker(sx, sy){
  var wx = sx + camX - W*0.35, wy = gY - sy;
  var o = getSt();
  var dx = wx - o.cx, dy = wy - o.cy;
  mBx = o.c*dx - o.s*dy;
  mBy = o.s*dx + o.c*dy;
}

C.addEventListener('mousedown', function(e){
  if(e.button === 2) return;
  var r = C.getBoundingClientRect();
  var sx = e.clientX - r.left, sy = e.clientY - r.top;
  var o = getSt(), mw = b2w(o, mBx, mBy), ms = w2s(mw[0], mw[1]);
  if(Math.hypot(sx - ms[0], sy - ms[1]) < 25){
    dragging = true; savedSpd = spd; spd = 0;
    return;
  }
  clickAt(sx, sy);
});
C.addEventListener('mousemove', function(e){
  if(!dragging) return;
  var r = C.getBoundingClientRect();
  updateMarker(e.clientX - r.left, e.clientY - r.top);
});
C.addEventListener('mouseup', function(){
  if(dragging){
    dragging = false; spd = savedSpd;
    trace = []; updMB('');
    SFX.play('spawn');
  }
});
C.addEventListener('contextmenu', function(e){
  e.preventDefault(); setMarker('v'); updMB('btn-vtx'); SFX.play('click');
});

C.addEventListener('touchstart', function(e){
  e.preventDefault();
  var t = e.touches[0], r = C.getBoundingClientRect();
  var sx = t.clientX - r.left, sy = t.clientY - r.top;
  var o = getSt(), mw = b2w(o, mBx, mBy), ms = w2s(mw[0], mw[1]);
  if(Math.hypot(sx - ms[0], sy - ms[1]) < 40){
    dragging = true; savedSpd = spd; spd = 0;
    return;
  }
  clickAt(sx, sy);
}, {passive: false});
C.addEventListener('touchmove', function(e){
  if(!dragging) return;
  e.preventDefault();
  var t = e.touches[0], r = C.getBoundingClientRect();
  updateMarker(t.clientX - r.left, t.clientY - r.top);
}, {passive: false});
C.addEventListener('touchend', function(){
  if(dragging){
    dragging = false; spd = savedSpd;
    trace = []; updMB('');
    SFX.play('spawn');
  }
});

document.querySelectorAll('.mbtn').forEach(function(b){
  b.addEventListener('click', function(e){ e.stopPropagation(); setN(+this.dataset.n); });
});

document.getElementById('btn-vtx').addEventListener('click', function(){
  setMarker('v'); updMB('btn-vtx'); SFX.play('click');
});
document.getElementById('btn-ctr').addEventListener('click', function(){
  setMarker('c'); updMB('btn-ctr'); SFX.play('click');
});
document.getElementById('btn-out').addEventListener('click', function(){
  setMarker('o'); updMB('btn-out'); SFX.play('click');
});
document.getElementById('btn-lock').addEventListener('click', function(){
  if(trace.length > 1){
    locked.push({p: trace.slice(), h: HU[n] || 200});
    if(locked.length > 5) locked.shift();
    trace = [];
    SFX.play('click');
  }
});
document.getElementById('btn-clr').addEventListener('click', function(){
  trace = []; locked = []; phi = 0; camX = 0; prevStep = -1;
  SFX.play('click');
});

var spdEl = document.getElementById('sl-spd');
var spdVal = document.getElementById('val-spd');
spdEl.addEventListener('input', function(){
  spd = +this.value / 10;
  spdVal.textContent = spd.toFixed(1);
});

addEventListener('keydown', function(e){
  var sh = [3,4,5,6,8,360];
  if(e.key >= '1' && e.key <= '6'){ setN(sh[+e.key - 1]); return; }
  if(e.key === ' '){ e.preventDefault(); spd = spd > 0 ? 0 : (+spdEl.value/10); }
  if(e.key === 'c'){
    trace = []; locked = []; phi = 0; camX = 0; prevStep = -1;
    SFX.play('click');
  }
  if(e.key === 'l' && trace.length > 1){
    locked.push({p: trace.slice(), h: HU[n] || 200});
    if(locked.length > 5) locked.shift();
    trace = [];
    SFX.play('click');
  }
});

addEventListener('resize', resize);
resize();
setMarker('v');

for(var i = 0; i < 250; i++){
  phi += 0.018;
  var o = getSt(), mw = b2w(o, mBx, mBy);
  trace.push([mw[0], mw[1]]);
}
camX = getSt().cx;
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
