<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hough');

$pageName = 'hough';
$pageTitle = 'HOUGH - PULSE';
$pageDesc = '点群を線のパラメータ空間へ投影し、交差と蓄積から直線を検出すHough変換のCanvas可視化。';
$pageCanonical = 'https://pulse.subara3.com/hough.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.readout{position:fixed;right:1rem;bottom:1.2rem;z-index:55;color:rgba(255,255,255,.32);font-family:"JetBrains Mono",monospace;font-size:.58rem;line-height:1.7;text-align:right;pointer-events:none}.readout b{color:#7b2cbf}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.readout{right:.75rem;bottom:4.4rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  FIELD <b id="h-mode">DUAL</b><br>
  POINTS <b id="h-points">0</b><br>
  PEAKS <b id="h-peaks">0</b><br>
  <span class="sub">BINS</span> <b id="h-bins">180</b>
</div>
<div class="readout">
  THETA <b id="r-theta">0</b><br>
  RHO <b id="r-rho">0</b><br>
  VOTE <b id="r-vote">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag', 'label' => 'move point'],
  ['type' => 'right', 'label' => 'erase point'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" data-mode="dual">DUAL</button><button class="control-btn" data-mode="lines">LINES</button><button class="control-btn" data-mode="waves">WAVES</button></div></div>
  <div><div class="slider-row"><span class="slider-label">BINS</span><input type="range" id="sl-bins" min="72" max="240" value="180" step="12"><span class="slider-value" id="sv-bins">180</span></div></div>
  <div><div class="slider-row"><span class="slider-label">THRESH</span><input type="range" id="sl-thresh" min="2" max="12" value="5"><span class="slider-value" id="sv-thresh">5</span></div></div>
  <div><div class="btn-row"><button class="control-btn active" id="btn-drift">DRIFT</button><button class="control-btn" id="btn-seed">SEED</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W=0,H=0,D=1,t=0,mode='dual',bins=180,threshold=5,drift=true,drag=-1;
var pts=[],acc=[],peaks=[],rhoBins=150,maxVote=1,activePeak=null;
var hMode=document.getElementById('h-mode'),hPts=document.getElementById('h-points'),hPeaks=document.getElementById('h-peaks'),hBins=document.getElementById('h-bins');
var rTheta=document.getElementById('r-theta'),rRho=document.getElementById('r-rho'),rVote=document.getElementById('r-vote');
var slBins=document.getElementById('sl-bins'),slThresh=document.getElementById('sl-thresh'),svBins=document.getElementById('sv-bins'),svThresh=document.getElementById('sv-thresh');
function resize(){D=Math.min(2,devicePixelRatio||1);W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);C.style.width=innerWidth+'px';C.style.height=innerHeight+'px'}
function seed(){pts=[];var a=-.55,b=.12;for(var i=0;i<9;i++){var x=-.82+i*.2,y=a*x+b+(Math.random()-.5)*.08;pts.push({x:x,y:y,vx:(Math.random()-.5)*.001,vy:(Math.random()-.5)*.001})}for(var j=0;j<8;j++){var x2=-.7+j*.19,y2=.55*x2-.28+(Math.random()-.5)*.08;pts.push({x:x2,y:y2,vx:(Math.random()-.5)*.001,vy:(Math.random()-.5)*.001})}for(var k=0;k<8;k++)pts.push({x:(Math.random()*2-1)*.82,y:(Math.random()*2-1)*.72,vx:(Math.random()-.5)*.001,vy:(Math.random()-.5)*.001});compute()}
function leftRect(){var gap=28*D,w=W<760*D?W:H?Math.min(W*.54,H*.9):W*.5;return{x:gap,y:H*.13,w:Math.min(W*.52,w),h:H*.74}}
function rightRect(){var l=leftRect(),gap=30*D;return{x:l.x+l.w+gap,y:l.y,w:Math.max(80*D,W-(l.x+l.w+gap)-gap),h:l.h}}
function toScreen(p){var r=leftRect();return{x:r.x+(p.x*.5+.5)*r.w,y:r.y+(p.y*.5+.5)*r.h}}
function fromScreen(x,y){var r=leftRect();return{x:((x-r.x)/r.w-.5)*2,y:((y-r.y)/r.h-.5)*2}}
function compute(){rhoBins=Math.max(80,Math.round(bins*.84));acc=new Uint16Array(bins*rhoBins);maxVote=1;var rmax=Math.SQRT2;for(var p of pts){for(var ti=0;ti<bins;ti++){var th=ti/bins*Math.PI;var rho=p.x*Math.cos(th)+p.y*Math.sin(th);var ri=Math.round((rho/rmax*.5+.5)*(rhoBins-1));if(ri>=0&&ri<rhoBins){var v=++acc[ri*bins+ti];if(v>maxVote)maxVote=v}}}findPeaks()}
function findPeaks(){peaks=[];for(var ri=1;ri<rhoBins-1;ri++){for(var ti=0;ti<bins;ti++){var v=acc[ri*bins+ti];if(v<threshold)continue;var l=acc[ri*bins+((ti+bins-1)%bins)],r=acc[ri*bins+((ti+1)%bins)],u=acc[(ri-1)*bins+ti],d=acc[(ri+1)*bins+ti];if(v>=l&&v>=r&&v>=u&&v>=d)peaks.push({ti:ti,ri:ri,v:v})}}peaks.sort(function(a,b){return b.v-a.v});peaks=peaks.slice(0,7);activePeak=peaks[0]||null}
function drawFrame(r,label){X.strokeStyle='rgba(0,245,212,.18)';X.lineWidth=1*D;X.strokeRect(r.x,r.y,r.w,r.h);X.fillStyle='rgba(0,245,212,.26)';X.font=(10*D)+'px JetBrains Mono, monospace';X.fillText(label,r.x+8*D,r.y+16*D)}
function drawPlane(){var r=leftRect();drawFrame(r,'POINT SPACE');X.save();X.beginPath();X.rect(r.x,r.y,r.w,r.h);X.clip();X.fillStyle='rgba(0,245,212,.035)';for(var gx=0;gx<=8;gx++){X.fillRect(r.x+gx*r.w/8,r.y,1*D,r.h)}for(var gy=0;gy<=8;gy++){X.fillRect(r.x,r.y+gy*r.h/8,r.w,1*D)}X.globalCompositeOperation='lighter';for(var n=0;n<Math.min(4,peaks.length);n++)drawLine(peaks[n],n===0?.65:.28);for(var i=0;i<pts.length;i++){var s=toScreen(pts[i]);X.fillStyle=i===drag?'rgba(255,255,255,.92)':'rgba(0,245,212,.82)';X.beginPath();X.arc(s.x,s.y,(i===drag?5:3.4)*D,0,Math.PI*2);X.fill();X.strokeStyle='rgba(123,44,191,.32)';X.lineWidth=1*D;X.stroke()}X.restore()}
function drawLine(pk,alpha){var th=pk.ti/bins*Math.PI,rmax=Math.SQRT2,rho=(pk.ri/(rhoBins-1)-.5)*2*rmax;var c=Math.cos(th),s=Math.sin(th),pts2=[];for(var x=-1;x<=1;x+=2){if(Math.abs(s)>1e-4){var y=(rho-x*c)/s;if(y>=-1.2&&y<=1.2)pts2.push({x:x,y:y})}}for(var y=-1;y<=1;y+=2){if(Math.abs(c)>1e-4){var x=(rho-y*s)/c;if(x>=-1.2&&x<=1.2)pts2.push({x:x,y:y})}}if(pts2.length<2)return;var a=toScreen(pts2[0]),b=toScreen(pts2[1]);X.strokeStyle='rgba(123,44,191,'+alpha+')';X.lineWidth=(2+pk.v*.18)*D;X.beginPath();X.moveTo(a.x,a.y);X.lineTo(b.x,b.y);X.stroke()}
function drawAccum(){var r=rightRect();drawFrame(r,'HOUGH SPACE');if(r.w<40*D)return;var cw=r.w/bins,ch=r.h/rhoBins;X.save();X.beginPath();X.rect(r.x,r.y,r.w,r.h);X.clip();for(var ri=0;ri<rhoBins;ri++){for(var ti=0;ti<bins;ti++){var v=acc[ri*bins+ti];if(!v)continue;var q=v/maxVote;var a=.08+q*.76;X.fillStyle=q>.72?'rgba(123,44,191,'+a+')':'rgba(0,245,212,'+a+')';X.fillRect(r.x+ti*cw,r.y+ri*ch,Math.max(1,cw+1),Math.max(1,ch+1))}}if(mode==='waves'||mode==='dual')drawSinusoids(r);for(var p of peaks){X.strokeStyle='rgba(255,255,255,.7)';X.lineWidth=1.4*D;X.strokeRect(r.x+p.ti*cw-3*D,r.y+p.ri*ch-3*D,6*D,6*D)}X.restore()}
function drawSinusoids(r){var rmax=Math.SQRT2;X.globalCompositeOperation='lighter';for(var pi=0;pi<pts.length;pi++){var p=pts[pi];X.beginPath();for(var ti=0;ti<bins;ti++){var th=ti/bins*Math.PI,rho=p.x*Math.cos(th)+p.y*Math.sin(th),ri=(rho/rmax*.5+.5)*(rhoBins-1),x=r.x+ti/bins*r.w,y=r.y+ri/(rhoBins-1)*r.h;if(ti===0)X.moveTo(x,y);else X.lineTo(x,y)}X.strokeStyle=pi===drag?'rgba(255,255,255,.65)':'rgba(0,245,212,.10)';X.lineWidth=(pi===drag?1.8:1)*D;X.stroke()}X.globalCompositeOperation='source-over'}
function update(){if(drift&&drag<0){for(var p of pts){p.x+=p.vx;p.y+=p.vy;if(Math.abs(p.x)>.95)p.vx*=-1;if(Math.abs(p.y)>.86)p.vy*=-1}}compute();hMode.textContent=mode.toUpperCase();hPts.textContent=pts.length;hPeaks.textContent=peaks.length;hBins.textContent=bins;svBins.textContent=bins;svThresh.textContent=threshold;if(activePeak){rTheta.textContent=(activePeak.ti/bins*180).toFixed(1);rRho.textContent=activePeak.ri; rVote.textContent=activePeak.v}else{rTheta.textContent='0';rRho.textContent='0';rVote.textContent='0'}}
function draw(){t+=.016;X.fillStyle='rgba(10,10,15,.34)';X.fillRect(0,0,W,H);update();drawPlane();drawAccum();requestAnimationFrame(draw)}
function hit(x,y){var best=-1,bd=16*D*D;for(var i=0;i<pts.length;i++){var s=toScreen(pts[i]),dx=s.x-x,dy=s.y-y,d2=dx*dx+dy*dy;if(d2<bd){bd=d2;best=i}}return best}
C.addEventListener('pointerdown',function(e){var x=e.clientX*D,y=e.clientY*D;if(e.button===2){var h=hit(x,y);if(h>=0){pts.splice(h,1);if(typeof SFX!=='undefined')SFX.play('pop')}return}var l=leftRect();if(x>=l.x&&x<=l.x+l.w&&y>=l.y&&y<=l.y+l.h){drag=hit(x,y);if(drag<0){var p=fromScreen(x,y);pts.push({x:p.x,y:p.y,vx:(Math.random()-.5)*.001,vy:(Math.random()-.5)*.001});drag=pts.length-1;if(typeof SFX!=='undefined')SFX.play('spawn')}else if(typeof SFX!=='undefined')SFX.play('click')}});
C.addEventListener('pointermove',function(e){if(drag<0)return;var p=fromScreen(e.clientX*D,e.clientY*D);pts[drag].x=Math.max(-1,Math.min(1,p.x));pts[drag].y=Math.max(-1,Math.min(1,p.y));pts[drag].vx=0;pts[drag].vy=0;if(typeof SFX!=='undefined'&&Math.random()<.08)SFX.play('move',80)});
addEventListener('pointerup',function(){drag=-1});C.addEventListener('contextmenu',function(e){e.preventDefault()});
document.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('active',x===b)});if(typeof SFX!=='undefined')SFX.play('toggle')})});
slBins.addEventListener('input',function(){bins=+this.value;if(typeof SFX!=='undefined')SFX.play('tick',80)});slThresh.addEventListener('input',function(){threshold=+this.value;if(typeof SFX!=='undefined')SFX.play('tick',80)});
document.getElementById('btn-drift').addEventListener('click',function(){drift=!drift;this.classList.toggle('active',drift);if(typeof SFX!=='undefined')SFX.play('toggle')});document.getElementById('btn-seed').addEventListener('click',function(){seed();if(typeof SFX!=='undefined')SFX.play('success')});
addEventListener('resize',resize);resize();seed();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
