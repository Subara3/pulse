<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('breathe');

$pageName = 'breathe';
$pageTitle = 'BREATHE - PULSE';
$pageDesc = '呼吸の周期を位相波として描く、静かなCanvas 2Dビジュアライゼーション。';
$pageCanonical = 'https://pulse.subara3.com/breathe.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(123,44,191,.9)}
.phase-ring{position:fixed;right:1rem;bottom:1rem;z-index:55;width:64px;height:64px;border:1px solid rgba(0,245,212,.28);border-radius:50%;box-shadow:0 0 24px rgba(0,245,212,.12);pointer-events:none}
.phase-ring::after{content:"";position:absolute;left:50%;top:50%;width:8px;height:8px;border-radius:50%;background:#00f5d4;box-shadow:0 0 18px rgba(0,245,212,.8);transform:translate(-50%,-50%) scale(var(--phase-scale,1))}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.phase-ring{right:.75rem;bottom:4.5rem;width:48px;height:48px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="hud">
  PHASE <b id="h-phase">INHALE</b><br>
  PERIOD <b id="h-period">16</b>s<br>
  RINGS <b id="h-rings">9</b><br>
  <span class="sub">SYNC</span> <b id="h-sync">0.62</b>
</div>
<div class="phase-ring" id="phaseRing"></div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'sync wave'],
  ['type' => 'drag', 'label' => 'guide phase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-pattern="box">BOX</button>
      <button class="control-btn" data-pattern="wave">WAVE</button>
      <button class="control-btn" data-pattern="quiet">QUIET</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">PERIOD</span>
      <input type="range" id="sl-period" min="8" max="28" value="16" step="1">
      <span class="slider-value" id="sv-period">16</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">RINGS</span>
      <input type="range" id="sl-rings" min="4" max="18" value="9" step="1">
      <span class="slider-value" id="sv-rings">9</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SYNC</span>
      <input type="range" id="sl-sync" min="20" max="95" value="62" step="1">
      <span class="slider-value" id="sv-sync">0.62</span>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var W = 0, H = 0, DPR = 1;
var period = 16;
var rings = 9;
var sync = .62;
var pattern = 'box';
var t0 = performance.now();
var ripples = [];
var pointer = {x:.5,y:.5,active:false};
var hPhase = document.getElementById('h-phase');
var hPeriod = document.getElementById('h-period');
var hRings = document.getElementById('h-rings');
var hSync = document.getElementById('h-sync');
var phaseRing = document.getElementById('phaseRing');
var slPeriod = document.getElementById('sl-period');
var slRings = document.getElementById('sl-rings');
var slSync = document.getElementById('sl-sync');
var svPeriod = document.getElementById('sv-period');
var svRings = document.getElementById('sv-rings');
var svSync = document.getElementById('sv-sync');

function resize(){
  DPR = Math.min(2, window.devicePixelRatio || 1);
  W = canvas.width = Math.floor(window.innerWidth * DPR);
  H = canvas.height = Math.floor(window.innerHeight * DPR);
  canvas.style.width = window.innerWidth + 'px';
  canvas.style.height = window.innerHeight + 'px';
}
function clamp(v,a,b){ return Math.max(a, Math.min(b, v)); }
function mix(a,b,t){ return a + (b - a) * t; }
function updateHud(phase, amp){
  var names = pattern === 'box' ? ['INHALE','HOLD','EXHALE','REST'] : ['RISE','FLOAT','FALL','STILL'];
  hPhase.textContent = names[phase];
  hPeriod.textContent = period;
  hRings.textContent = rings;
  hSync.textContent = sync.toFixed(2);
  svPeriod.textContent = period;
  svRings.textContent = rings;
  svSync.textContent = sync.toFixed(2);
  phaseRing.style.setProperty('--phase-scale', (0.7 + amp * 2.8).toFixed(2));
}
function breathingState(time){
  var u = (time % period) / period;
  if(pattern === 'quiet'){
    var q = .5 - .5 * Math.cos(u * Math.PI * 2);
    return {amp:q, phase:u < .5 ? 0 : 2};
  }
  if(pattern === 'wave'){
    var w = .5 + .5 * Math.sin(u * Math.PI * 2 - Math.PI / 2);
    return {amp:w, phase:Math.floor(u * 4)};
  }
  var p = Math.floor(u * 4);
  var v = (u * 4) % 1;
  var a = p === 0 ? v : p === 1 ? 1 : p === 2 ? 1 - v : 0;
  return {amp:a, phase:p};
}
function colorTeal(alpha){ return 'rgba(0,245,212,' + alpha + ')'; }
function colorPurple(alpha){ return 'rgba(123,44,191,' + alpha + ')'; }

function draw(now){
  var time = (now - t0) / 1000;
  var state = breathingState(time);
  updateHud(state.phase, state.amp);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0,0,W,H);
  var cx = W * pointer.x;
  var cy = H * pointer.y;
  var span = Math.min(W,H) * .42;
  ctx.globalCompositeOperation = 'lighter';

  for(var i=rings;i>=1;i--){
    var k = i / rings;
    var local = state.amp * sync + (1 - sync) * (.5 + .5 * Math.sin(time * .8 - k * 6.0));
    var r = span * (k * .18 + local * (.16 + k * .7));
    var a = .04 + (1 - k) * .06 + local * .09;
    ctx.strokeStyle = i % 2 ? colorTeal(a) : colorPurple(a * 1.3);
    ctx.lineWidth = Math.max(1, DPR * (1 + local * 2.2));
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();
  }

  var cols = Math.max(8, Math.floor(W / (70 * DPR)));
  var rows = Math.max(6, Math.floor(H / (70 * DPR)));
  for(var y=0;y<=rows;y++){
    for(var x=0;x<=cols;x++){
      var px = x / cols * W;
      var py = y / rows * H;
      var dx = (px - cx) / W;
      var dy = (py - cy) / H;
      var d = Math.sqrt(dx*dx + dy*dy);
      var wave = .5 + .5 * Math.sin(time * 2.2 - d * 18 + state.amp * 4);
      var len = (8 + wave * 28) * DPR;
      var angle = Math.atan2(py - cy, px - cx) + Math.PI / 2;
      ctx.strokeStyle = d < .32 ? colorTeal(.09 + wave * .16) : colorPurple(.05 + wave * .11);
      ctx.lineWidth = 1 * DPR;
      ctx.beginPath();
      ctx.moveTo(px - Math.cos(angle) * len, py - Math.sin(angle) * len);
      ctx.lineTo(px + Math.cos(angle) * len, py + Math.sin(angle) * len);
      ctx.stroke();
    }
  }

  for(var j=ripples.length-1;j>=0;j--){
    var u = ripples[j];
    u.life += .018;
    ctx.strokeStyle = colorTeal(Math.max(0, .55 - u.life));
    ctx.lineWidth = (2.2 - u.life) * DPR;
    ctx.beginPath();
    ctx.arc(u.x * DPR, u.y * DPR, u.life * 260 * DPR, 0, Math.PI * 2);
    ctx.stroke();
    if(u.life >= 1) ripples.splice(j,1);
  }

  var core = 10 * DPR + state.amp * 36 * DPR;
  var grd = ctx.createRadialGradient(cx,cy,0,cx,cy,core * 5);
  grd.addColorStop(0, 'rgba(0,245,212,.45)');
  grd.addColorStop(.42, 'rgba(123,44,191,.16)');
  grd.addColorStop(1, 'rgba(0,245,212,0)');
  ctx.fillStyle = grd;
  ctx.beginPath();
  ctx.arc(cx, cy, core * 5, 0, Math.PI * 2);
  ctx.fill();
  ctx.globalCompositeOperation = 'source-over';
  requestAnimationFrame(draw);
}
function setPointer(x,y){
  pointer.x = clamp(x / window.innerWidth, .08, .92);
  pointer.y = clamp(y / window.innerHeight, .12, .88);
}
function pulse(x,y){
  ripples.push({x:x,y:y,life:0});
  t0 = performance.now() - ((performance.now() - t0) % (period * 1000));
  if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
}
canvas.addEventListener('pointerdown', function(e){
  pointer.active = true;
  setPointer(e.clientX, e.clientY);
  pulse(e.clientX, e.clientY);
});
window.addEventListener('pointermove', function(e){
  if(!pointer.active) return;
  setPointer(e.clientX, e.clientY);
});
window.addEventListener('pointerup', function(){ pointer.active = false; });
canvas.addEventListener('touchstart', function(e){
  var t = e.touches && e.touches[0];
  if(!t) return;
  setPointer(t.clientX, t.clientY);
  pulse(t.clientX, t.clientY);
}, {passive:true});
document.querySelectorAll('[data-pattern]').forEach(function(btn){
  btn.addEventListener('click', function(){
    pattern = btn.dataset.pattern;
    document.querySelectorAll('[data-pattern]').forEach(function(b){ b.classList.toggle('active', b === btn); });
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});
slPeriod.addEventListener('input', function(){ period = parseInt(slPeriod.value,10); if (typeof SFX !== 'undefined') SFX.play('move',90); });
slRings.addEventListener('input', function(){ rings = parseInt(slRings.value,10); if (typeof SFX !== 'undefined') SFX.play('tick',90); });
slSync.addEventListener('input', function(){ sync = parseInt(slSync.value,10)/100; if (typeof SFX !== 'undefined') SFX.play('move',90); });
window.addEventListener('resize', resize);
resize();
requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
