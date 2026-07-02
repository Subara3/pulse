<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tomography');

$pageName = 'tomography';
$pageTitle = 'TOMOGRAPHY - PULSE';
$pageDesc = 'CTスキャンのRadon変換。回転する投影からシノグラムを作り、逆投影で内部構造を浮かび上がらせる。';
$pageCanonical = 'https://pulse.subara3.com/tomography.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.38);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(123,44,191,.9)}
.scale{position:fixed;right:1rem;bottom:1rem;z-index:55;width:min(28vw,220px);height:2px;background:linear-gradient(90deg,rgba(0,245,212,.15),rgba(0,245,212,.85),rgba(123,44,191,.85));opacity:.7;pointer-events:none}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.scale{right:.75rem;bottom:4.5rem;width:34vw}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="hud">
  RADON <b id="h-angle">0</b>&deg;<br>
  SAMPLES <b id="h-samples">96</b><br>
  BLOBS <b id="h-blobs">5</b><br>
  <span class="sub">BACKPROJECTION</span> <b id="h-gain">1.20</b>
</div>
<div class="scale"></div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add density'],
  ['type' => 'drag', 'label' => 'move sample'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="scan">SCAN</button>
      <button class="control-btn" data-mode="slice">SLICE</button>
      <button class="control-btn" data-mode="trace">TRACE</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">ANGLES</span>
      <input type="range" id="sl-angles" min="36" max="180" value="96" step="12">
      <span class="slider-value" id="sv-angles">96</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">GAIN</span>
      <input type="range" id="sl-gain" min="60" max="210" value="120" step="5">
      <span class="slider-value" id="sv-gain">1.20</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="10" max="90" value="36" step="2">
      <span class="slider-value" id="sv-speed">36</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
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
var blobs = [];
var samples = 96;
var gain = 1.2;
var speed = 36;
var angle = 0;
var mode = 'scan';
var auto = true;
var dragging = -1;
var pointerDown = false;
var reconstruction = document.createElement('canvas');
var rctx = reconstruction.getContext('2d');
var sinogram = document.createElement('canvas');
var sctx = sinogram.getContext('2d');
var sinogramValues = new Float32Array(0);
var mapsDirty = true;
var grid = 88;
var sinoH = 150;

function resetBlobs(){
  blobs = [
    {x:-0.33,y:-0.18,r:0.19,w:0.92},
    {x:0.22,y:-0.23,r:0.14,w:0.78},
    {x:0.08,y:0.25,r:0.23,w:0.66},
    {x:-0.18,y:0.35,r:0.10,w:0.88},
    {x:0.36,y:0.12,r:0.08,w:0.74}
  ];
  updateHud();
  requestRebuild();
}

function resize(){
  DPR = Math.min(2, window.devicePixelRatio || 1);
  W = canvas.width = Math.floor(window.innerWidth * DPR);
  H = canvas.height = Math.floor(window.innerHeight * DPR);
  canvas.style.width = window.innerWidth + 'px';
  canvas.style.height = window.innerHeight + 'px';
  reconstruction.width = grid;
  reconstruction.height = grid;
  sinogram.width = samples;
  sinogram.height = sinoH;
  requestRebuild();
}

function clamp(v,a,b){ return Math.max(a, Math.min(b, v)); }
function mix(a,b,t){ return a + (b - a) * t; }
function densityAt(x,y){
  var d = 0;
  for (var i=0;i<blobs.length;i++){
    var b = blobs[i];
    var dx = x - b.x, dy = y - b.y;
    var q = (dx*dx + dy*dy) / (b.r*b.r);
    d += b.w * Math.exp(-q * 2.4);
  }
  return clamp(d, 0, 1);
}

function colorFor(v, alpha){
  v = clamp(v, 0, 1);
  var r = Math.round(mix(6, 0, v));
  var g = Math.round(mix(28, 245, v));
  var b = Math.round(mix(38, 212, Math.min(1, v * 1.15)));
  if (v > .68) {
    var t = (v - .68) / .32;
    r = Math.round(mix(r, 123, t));
    g = Math.round(mix(g, 44, t));
    b = Math.round(mix(b, 191, t));
  }
  return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

function projection(theta, s){
  var c = Math.cos(theta), sn = Math.sin(theta);
  var sum = 0;
  var steps = 72;
  for (var i=0;i<steps;i++){
    var t = -1.15 + 2.3 * i / (steps - 1);
    var x = s * c - t * sn;
    var y = s * sn + t * c;
    if (x*x + y*y < 1.1) sum += densityAt(x, y);
  }
  return sum / steps;
}

function requestRebuild(){
  mapsDirty = true;
}

function sampleSinogram(angleIndex, s){
  if (!sinogramValues.length) return 0;
  var y = ((s / 2.1) + .5) * (sinoH - 1);
  y = clamp(y, 0, sinoH - 1);
  var y0 = Math.floor(y);
  var y1 = Math.min(sinoH - 1, y0 + 1);
  var t = y - y0;
  var base0 = y0 * samples + angleIndex;
  var base1 = y1 * samples + angleIndex;
  return sinogramValues[base0] * (1 - t) + sinogramValues[base1] * t;
}

function rebuildMaps(){
  if (!W || !H) return;
  mapsDirty = false;
  sinogram.width = samples;
  sinogram.height = sinoH;
  sinogramValues = new Float32Array(samples * sinoH);
  var img = sctx.createImageData(samples, sinoH);
  for (var a=0;a<samples;a++){
    var theta = Math.PI * a / samples;
    for (var y=0;y<sinoH;y++){
      var s = (y / (sinoH - 1) - .5) * 2.1;
      var p = projection(theta, s);
      sinogramValues[y * samples + a] = p;
      var id = (y * samples + a) * 4;
      var v = clamp(p * 2.8, 0, 1);
      img.data[id] = Math.round(mix(5, 0, v));
      img.data[id+1] = Math.round(mix(20, 245, v));
      img.data[id+2] = Math.round(mix(35, 212, v));
      if (v > .72) {
        var t = (v - .72) / .28;
        img.data[id] = Math.round(mix(img.data[id], 123, t));
        img.data[id+1] = Math.round(mix(img.data[id+1], 44, t));
        img.data[id+2] = Math.round(mix(img.data[id+2], 191, t));
      }
      img.data[id+3] = 255;
    }
  }
  sctx.putImageData(img, 0, 0);

  reconstruction.width = grid;
  reconstruction.height = grid;
  var out = rctx.createImageData(grid, grid);
  for (var yy=0;yy<grid;yy++){
    for (var xx=0;xx<grid;xx++){
      var x = (xx / (grid - 1) - .5) * 2;
      var yv = (yy / (grid - 1) - .5) * 2;
      var acc = 0;
      var used = 0;
      if (x*x + yv*yv <= 1.02) {
        for (var aa=0;aa<samples;aa+=2){
          var th = Math.PI * aa / samples;
          var sproj = x * Math.cos(th) + yv * Math.sin(th);
          acc += sampleSinogram(aa, sproj);
          used++;
        }
        acc = used ? acc / used * gain * 4.2 : 0;
      }
      var idx = (yy * grid + xx) * 4;
      var vv = clamp(acc, 0, 1);
      out.data[idx] = Math.round(mix(4, 0, vv));
      out.data[idx+1] = Math.round(mix(14, 245, vv));
      out.data[idx+2] = Math.round(mix(24, 212, vv));
      if (vv > .7) {
        var tt = (vv - .7) / .3;
        out.data[idx] = Math.round(mix(out.data[idx], 123, tt));
        out.data[idx+1] = Math.round(mix(out.data[idx+1], 44, tt));
        out.data[idx+2] = Math.round(mix(out.data[idx+2], 191, tt));
      }
      out.data[idx+3] = vv > .01 ? 255 : 210;
    }
  }
  rctx.putImageData(out, 0, 0);
}

function layout(){
  var cw = W / DPR, ch = H / DPR;
  var main = Math.min(cw * .42, ch * .72, 520);
  if (cw < 760) main = Math.min(cw * .78, ch * .44);
  var left = cw < 760 ? cw * .5 : cw * .31;
  var top = cw < 760 ? ch * .34 : ch * .52;
  var recon = Math.min(main * .72, cw < 760 ? cw * .35 : 300);
  var rx = cw < 760 ? cw * .75 : cw * .74;
  var ry = cw < 760 ? ch * .34 : ch * .38;
  var sw = cw < 760 ? cw * .78 : Math.min(360, cw * .28);
  var sh = cw < 760 ? 96 : 140;
  var sx = cw < 760 ? cw * .5 : cw * .74;
  var sy = cw < 760 ? ch * .68 : ch * .68;
  return {cx:left, cy:top, r:main*.5, rx:rx, ry:ry, rr:recon*.5, sx:sx, sy:sy, sw:sw, sh:sh};
}

function drawPhantom(L){
  ctx.save();
  ctx.translate(L.cx, L.cy);
  ctx.strokeStyle = 'rgba(0,245,212,.25)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.arc(0, 0, L.r, 0, Math.PI*2);
  ctx.stroke();
  for (var i=0;i<blobs.length;i++){
    var b = blobs[i];
    var x = b.x * L.r, y = b.y * L.r;
    var grd = ctx.createRadialGradient(x, y, 0, x, y, b.r * L.r);
    grd.addColorStop(0, colorFor(b.w, .76));
    grd.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = grd;
    ctx.beginPath();
    ctx.arc(x, y, b.r * L.r * 1.55, 0, Math.PI*2);
    ctx.fill();
    ctx.strokeStyle = i === dragging ? 'rgba(255,255,255,.75)' : 'rgba(0,245,212,.28)';
    ctx.beginPath();
    ctx.arc(x, y, Math.max(7, b.r * L.r * .34), 0, Math.PI*2);
    ctx.stroke();
  }
  var th = angle;
  var c = Math.cos(th), s = Math.sin(th);
  var count = mode === 'trace' ? 19 : 9;
  for (var k=0;k<count;k++){
    var off = (k / (count - 1) - .5) * L.r * 1.7;
    var alpha = k === Math.floor(count/2) ? .9 : .16;
    ctx.strokeStyle = 'rgba(0,245,212,' + alpha + ')';
    ctx.beginPath();
    ctx.moveTo(off*c + L.r*1.1*-s, off*s + L.r*1.1*c);
    ctx.lineTo(off*c - L.r*1.1*-s, off*s - L.r*1.1*c);
    ctx.stroke();
  }
  ctx.restore();
}

function drawReconstruction(L){
  ctx.save();
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(reconstruction, L.rx - L.rr, L.ry - L.rr, L.rr*2, L.rr*2);
  ctx.strokeStyle = 'rgba(123,44,191,.55)';
  ctx.lineWidth = 1.2;
  ctx.strokeRect(L.rx - L.rr, L.ry - L.rr, L.rr*2, L.rr*2);
  ctx.fillStyle = 'rgba(255,255,255,.34)';
  ctx.font = '10px JetBrains Mono, monospace';
  ctx.fillText('RECONSTRUCTION', L.rx - L.rr, L.ry - L.rr - 10);
  ctx.restore();
}

function drawSinogram(L){
  ctx.save();
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(sinogram, L.sx - L.sw/2, L.sy - L.sh/2, L.sw, L.sh);
  ctx.strokeStyle = 'rgba(0,245,212,.42)';
  ctx.strokeRect(L.sx - L.sw/2, L.sy - L.sh/2, L.sw, L.sh);
  var ax = L.sx - L.sw/2 + ((angle % Math.PI) / Math.PI) * L.sw;
  ctx.strokeStyle = 'rgba(255,255,255,.62)';
  ctx.beginPath();
  ctx.moveTo(ax, L.sy - L.sh/2);
  ctx.lineTo(ax, L.sy + L.sh/2);
  ctx.stroke();
  ctx.fillStyle = 'rgba(255,255,255,.34)';
  ctx.font = '10px JetBrains Mono, monospace';
  ctx.fillText('SINOGRAM', L.sx - L.sw/2, L.sy - L.sh/2 - 10);
  ctx.restore();
}

function render(t){
  if (auto) angle += speed * 0.00012 * (mode === 'slice' ? .35 : 1);
  if (mapsDirty) rebuildMaps();
  ctx.setTransform(DPR,0,0,DPR,0,0);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0,0,W/DPR,H/DPR);
  var L = layout();
  drawPhantom(L);
  drawReconstruction(L);
  drawSinogram(L);
  document.getElementById('h-angle').textContent = Math.round((angle % Math.PI) * 180 / Math.PI);
  requestAnimationFrame(render);
}

function pointerToBlob(e){
  var L = layout();
  var rect = canvas.getBoundingClientRect();
  var px = e.clientX - rect.left;
  var py = e.clientY - rect.top;
  return {x:(px - L.cx) / L.r, y:(py - L.cy) / L.r, L:L};
}

function nearestBlob(p){
  var best = -1, bd = .08;
  for (var i=0;i<blobs.length;i++){
    var dx = p.x - blobs[i].x, dy = p.y - blobs[i].y;
    var d = Math.sqrt(dx*dx + dy*dy);
    if (d < bd) { bd = d; best = i; }
  }
  return best;
}

function updateHud(){
  document.getElementById('h-samples').textContent = samples;
  document.getElementById('h-blobs').textContent = blobs.length;
  document.getElementById('h-gain').textContent = gain.toFixed(2);
}

canvas.addEventListener('pointerdown', function(e){
  pointerDown = true;
  var p = pointerToBlob(e);
  dragging = nearestBlob(p);
  if (dragging < 0 && p.x*p.x + p.y*p.y < .92) {
    blobs.push({x:p.x,y:p.y,r:0.09 + Math.random()*0.07,w:0.62 + Math.random()*0.32});
    if (blobs.length > 9) blobs.shift();
    dragging = blobs.length - 1;
    requestRebuild();
    updateHud();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  } else if (dragging >= 0 && typeof SFX !== 'undefined') {
    SFX.play('click');
  }
  canvas.setPointerCapture(e.pointerId);
});

canvas.addEventListener('pointermove', function(e){
  if (!pointerDown || dragging < 0) return;
  var p = pointerToBlob(e);
  var len = Math.sqrt(p.x*p.x + p.y*p.y);
  if (len > .92) { p.x *= .92 / len; p.y *= .92 / len; }
  blobs[dragging].x = p.x;
  blobs[dragging].y = p.y;
  requestRebuild();
  if (typeof SFX !== 'undefined') SFX.play('move', 90);
});

canvas.addEventListener('pointerup', function(e){
  pointerDown = false;
  dragging = -1;
  try { canvas.releasePointerCapture(e.pointerId); } catch(err) {}
});

document.querySelectorAll('[data-mode]').forEach(function(btn){
  btn.addEventListener('click', function(){
    mode = this.dataset.mode;
    document.querySelectorAll('[data-mode]').forEach(function(b){ b.classList.remove('active'); });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.getElementById('sl-angles').addEventListener('input', function(){
  samples = parseInt(this.value, 10);
  document.getElementById('sv-angles').textContent = samples;
  updateHud();
  requestRebuild();
});

document.getElementById('sl-gain').addEventListener('input', function(){
  gain = parseInt(this.value, 10) / 100;
  document.getElementById('sv-gain').textContent = gain.toFixed(2);
  updateHud();
  requestRebuild();
});

document.getElementById('sl-speed').addEventListener('input', function(){
  speed = parseInt(this.value, 10);
  document.getElementById('sv-speed').textContent = speed;
});

document.getElementById('btn-auto').addEventListener('click', function(){
  auto = !auto;
  this.classList.toggle('active', auto);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click', function(){
  resetBlobs();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

window.addEventListener('resize', resize);
resetBlobs();
resize();
requestAnimationFrame(render);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
