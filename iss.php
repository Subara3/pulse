<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('iss');

$pageName = 'iss';
$pageTitle = 'ISS RACE - PULSE';
$pageDesc = '低軌道の地上軌跡と周回速度を、競走のように眺める軌道シミュレーション。';
$pageCanonical = 'https://pulse.subara3.com/iss.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(123,44,191,.9)}
.orbit-key{position:fixed;right:1rem;bottom:1rem;z-index:55;display:flex;gap:.35rem;align-items:center;pointer-events:none;color:rgba(255,255,255,.36);font-family:"JetBrains Mono",monospace;font-size:.58rem}
.orbit-key::before{content:"";width:76px;height:2px;background:linear-gradient(90deg,#00f5d4,#7b2cbf);box-shadow:0 0 16px rgba(0,245,212,.32)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.orbit-key{right:.75rem;bottom:4.45rem;font-size:.5rem}.orbit-key::before{width:52px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="hud">
  ORBIT <b id="h-orbit">92.7</b>m<br>
  INCL <b id="h-incl">51.6</b>&deg;<br>
  LAP <b id="h-lap">0</b><br>
  <span class="sub">LEAD</span> <b id="h-lead">0</b>km
</div>
<div class="orbit-key">ground track</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop beacon'],
  ['type' => 'drag', 'label' => 'rotate view'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-view="map">MAP</button>
      <button class="control-btn" data-view="orbit">ORBIT</button>
      <button class="control-btn" data-view="split">SPLIT</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">INCL</span>
      <input type="range" id="sl-incl" min="28" max="74" value="52" step="1">
      <span class="slider-value" id="sv-incl">52</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="40" max="220" value="100" step="5">
      <span class="slider-value" id="sv-speed">1.00</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-trails">TRAILS</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var W = 0, H = 0, DPR = 1;
var inclination = 51.6;
var speed = 1;
var view = 'map';
var trails = true;
var simTime = 0;
var last = performance.now();
var yaw = .35, pitch = .18;
var dragging = false, lastX = 0, lastY = 0;
var beacons = [];
var hOrbit = document.getElementById('h-orbit');
var hIncl = document.getElementById('h-incl');
var hLap = document.getElementById('h-lap');
var hLead = document.getElementById('h-lead');
var slIncl = document.getElementById('sl-incl');
var slSpeed = document.getElementById('sl-speed');
var svIncl = document.getElementById('sv-incl');
var svSpeed = document.getElementById('sv-speed');
var btnTrails = document.getElementById('btn-trails');
var btnReset = document.getElementById('btn-reset');
var orbitPeriod = 92.7 * 60;
var earthTurn = Math.PI * 2 / 86164;
var radiusKm = 6790;

function resize(){
  DPR = Math.min(2, window.devicePixelRatio || 1);
  W = canvas.width = Math.floor(window.innerWidth * DPR);
  H = canvas.height = Math.floor(window.innerHeight * DPR);
  canvas.style.width = window.innerWidth + 'px';
  canvas.style.height = window.innerHeight + 'px';
}
function clamp(v,a,b){ return Math.max(a, Math.min(b, v)); }
function orbitAt(t, phase){
  var nu = (t / orbitPeriod * Math.PI * 2 + (phase || 0)) % (Math.PI * 2);
  var inc = inclination * Math.PI / 180;
  var x = Math.cos(nu);
  var y = Math.sin(nu) * Math.cos(inc);
  var z = Math.sin(nu) * Math.sin(inc);
  var lon = Math.atan2(y, x) - earthTurn * t;
  var lat = Math.asin(z);
  lon = ((lon + Math.PI) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2) - Math.PI;
  return {x:x,y:y,z:z,lat:lat,lon:lon,nu:nu};
}
function projectMap(p, rect){
  return {x:rect.x + (p.lon / Math.PI + 1) * .5 * rect.w, y:rect.y + (.5 - p.lat / Math.PI) * rect.h};
}
function rotate3(p){
  var cy = Math.cos(yaw), sy = Math.sin(yaw);
  var cp = Math.cos(pitch), sp = Math.sin(pitch);
  var x = p.x * cy - p.y * sy;
  var y = p.x * sy + p.y * cy;
  var z = p.z;
  var yy = y * cp - z * sp;
  var zz = y * sp + z * cp;
  return {x:x,y:yy,z:zz};
}
function projectOrbit(p, rect){
  var r = rotate3(p);
  var s = Math.min(rect.w, rect.h) * .39;
  return {x:rect.x + rect.w/2 + r.x * s, y:rect.y + rect.h/2 + r.y * s, z:r.z};
}
function drawStars(){
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0,0,W,H);
  ctx.fillStyle = 'rgba(0,245,212,.18)';
  for(var i=0;i<80;i++){
    var x = ((i * 97.31) % (W / DPR)) * DPR;
    var y = ((i * 53.77) % (H / DPR)) * DPR;
    var tw = .35 + .65 * Math.sin(simTime * .002 + i);
    ctx.globalAlpha = .08 + tw * .22;
    ctx.fillRect(x,y,DPR,DPR);
  }
  ctx.globalAlpha = 1;
}
function drawMap(rect){
  ctx.save();
  ctx.strokeStyle = 'rgba(0,245,212,.07)';
  ctx.lineWidth = 1 * DPR;
  for(var i=0;i<=12;i++){
    var x = rect.x + rect.w * i / 12;
    ctx.beginPath(); ctx.moveTo(x, rect.y); ctx.lineTo(x, rect.y + rect.h); ctx.stroke();
  }
  for(var j=0;j<=6;j++){
    var y = rect.y + rect.h * j / 6;
    ctx.beginPath(); ctx.moveTo(rect.x, y); ctx.lineTo(rect.x + rect.w, y); ctx.stroke();
  }
  ctx.strokeStyle = 'rgba(123,44,191,.2)';
  ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);
  if(trails){
    ctx.lineWidth = 1.5 * DPR;
    ctx.beginPath();
    for(var k=-260;k<=40;k++){
      var p = orbitAt(simTime + k * 28, 0);
      var m = projectMap(p, rect);
      if(k === -260) ctx.moveTo(m.x,m.y); else ctx.lineTo(m.x,m.y);
    }
    ctx.strokeStyle = 'rgba(0,245,212,.55)';
    ctx.stroke();
    ctx.beginPath();
    for(var q=-260;q<=40;q++){
      var p2 = orbitAt(simTime + q * 28, Math.PI * .74);
      var n = projectMap(p2, rect);
      if(q === -260) ctx.moveTo(n.x,n.y); else ctx.lineTo(n.x,n.y);
    }
    ctx.strokeStyle = 'rgba(123,44,191,.48)';
    ctx.stroke();
  }
  drawCraft(projectMap(orbitAt(simTime,0),rect), '#00f5d4');
  drawCraft(projectMap(orbitAt(simTime,Math.PI*.74),rect), '#7b2cbf');
  ctx.restore();
}
function drawOrbit(rect){
  ctx.save();
  var cx = rect.x + rect.w/2, cy = rect.y + rect.h/2;
  var er = Math.min(rect.w,rect.h) * .26;
  var g = ctx.createRadialGradient(cx,cy,0,cx,cy,er*1.8);
  g.addColorStop(0,'rgba(0,245,212,.22)');
  g.addColorStop(.72,'rgba(123,44,191,.10)');
  g.addColorStop(1,'rgba(0,245,212,0)');
  ctx.fillStyle = g;
  ctx.beginPath(); ctx.arc(cx,cy,er*1.8,0,Math.PI*2); ctx.fill();
  ctx.strokeStyle = 'rgba(0,245,212,.18)';
  ctx.beginPath(); ctx.arc(cx,cy,er,0,Math.PI*2); ctx.stroke();
  ctx.lineWidth = 1.4 * DPR;
  ctx.beginPath();
  for(var i=0;i<=240;i++){
    var p = orbitAt(i / 240 * orbitPeriod, 0);
    var s = projectOrbit(p, rect);
    if(i===0) ctx.moveTo(s.x,s.y); else ctx.lineTo(s.x,s.y);
  }
  ctx.strokeStyle = 'rgba(0,245,212,.55)'; ctx.stroke();
  ctx.beginPath();
  for(var j=0;j<=240;j++){
    var p2 = orbitAt(j / 240 * orbitPeriod, Math.PI*.74);
    var s2 = projectOrbit(p2, rect);
    if(j===0) ctx.moveTo(s2.x,s2.y); else ctx.lineTo(s2.x,s2.y);
  }
  ctx.strokeStyle = 'rgba(123,44,191,.5)'; ctx.stroke();
  drawCraft(projectOrbit(orbitAt(simTime,0), rect), '#00f5d4');
  drawCraft(projectOrbit(orbitAt(simTime,Math.PI*.74), rect), '#7b2cbf');
  ctx.restore();
}
function drawCraft(p, color){
  ctx.save();
  ctx.globalCompositeOperation = 'lighter';
  ctx.fillStyle = color;
  ctx.shadowColor = color;
  ctx.shadowBlur = 18 * DPR;
  ctx.beginPath(); ctx.arc(p.x,p.y,4.5*DPR,0,Math.PI*2); ctx.fill();
  ctx.strokeStyle = color;
  ctx.lineWidth = 1 * DPR;
  ctx.beginPath(); ctx.arc(p.x,p.y,14*DPR,0,Math.PI*2); ctx.stroke();
  ctx.restore();
}
function drawBeacons(rect){
  for(var i=beacons.length-1;i>=0;i--){
    var b = beacons[i];
    b.life += .012;
    ctx.strokeStyle = 'rgba(0,245,212,' + Math.max(0,.72-b.life) + ')';
    ctx.lineWidth = (2.2 - b.life) * DPR;
    ctx.beginPath(); ctx.arc(b.x*DPR,b.y*DPR,b.life*160*DPR,0,Math.PI*2); ctx.stroke();
    if(b.life >= 1) beacons.splice(i,1);
  }
}
function updateHud(){
  var a = orbitAt(simTime,0), b = orbitAt(simTime,Math.PI*.74);
  var dLon = Math.abs(a.lon - b.lon);
  var lead = Math.round(Math.min(dLon, Math.PI*2-dLon) / (Math.PI*2) * 40075);
  hOrbit.textContent = '92.7';
  hIncl.textContent = inclination.toFixed(1);
  hLap.textContent = String(Math.floor(simTime / orbitPeriod));
  hLead.textContent = String(lead);
  svIncl.textContent = Math.round(inclination);
  svSpeed.textContent = speed.toFixed(2);
}
function draw(now){
  var dt = Math.min(80, now - last) / 1000;
  last = now;
  simTime += dt * 420 * speed;
  drawStars();
  var pad = 42 * DPR;
  var full = {x:pad,y:pad,w:W-pad*2,h:H-pad*2};
  if(view === 'map') drawMap(full);
  else if(view === 'orbit') drawOrbit(full);
  else {
    drawMap({x:pad,y:pad,w:W-pad*2,h:(H-pad*2)*.48});
    drawOrbit({x:pad,y:H*.49,w:W-pad*2,h:H*.45-pad});
  }
  drawBeacons(full);
  updateHud();
  requestAnimationFrame(draw);
}
function setView(next){
  view = next;
  document.querySelectorAll('[data-view]').forEach(function(btn){ btn.classList.toggle('active', btn.dataset.view === view); });
}
canvas.addEventListener('pointerdown', function(e){
  dragging = true; lastX = e.clientX; lastY = e.clientY;
  beacons.push({x:e.clientX,y:e.clientY,life:0});
  if (typeof SFX !== 'undefined') SFX.play('ripple', 100);
});
window.addEventListener('pointermove', function(e){
  if(!dragging) return;
  yaw += (e.clientX - lastX) * .005;
  pitch = clamp(pitch + (e.clientY - lastY) * .004, -.85, .85);
  lastX = e.clientX; lastY = e.clientY;
});
window.addEventListener('pointerup', function(){ dragging = false; });
canvas.addEventListener('touchstart', function(e){
  var t = e.touches && e.touches[0];
  if(!t) return;
  beacons.push({x:t.clientX,y:t.clientY,life:0});
  if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
}, {passive:true});
document.querySelectorAll('[data-view]').forEach(function(btn){
  btn.addEventListener('click', function(){ setView(btn.dataset.view); if (typeof SFX !== 'undefined') SFX.play('toggle'); });
});
slIncl.addEventListener('input', function(){ inclination = parseFloat(slIncl.value); if (typeof SFX !== 'undefined') SFX.play('move', 100); });
slSpeed.addEventListener('input', function(){ speed = parseInt(slSpeed.value,10) / 100; if (typeof SFX !== 'undefined') SFX.play('tick', 100); });
btnTrails.addEventListener('click', function(){ trails = !trails; btnTrails.classList.toggle('active', trails); if (typeof SFX !== 'undefined') SFX.play('toggle'); });
btnReset.addEventListener('click', function(){ simTime = 0; yaw = .35; pitch = .18; if (typeof SFX !== 'undefined') SFX.play('success'); });
window.addEventListener('resize', resize);
resize();
requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
