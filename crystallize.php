<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'SPINODAL - PULSE';
$pageDesc = 'スピノーダル分解。保存量をもつ場が相分離し、迷路状のドメインへ粗大化していく。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:60;color:rgba(255,255,255,.36);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;text-align:right;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .p{color:#9b5de5}
.legend{position:fixed;left:1rem;bottom:1rem;z-index:55;width:min(28vw,220px);height:3px;background:linear-gradient(90deg,rgba(123,44,191,.95),rgba(12,30,42,.8),rgba(0,245,212,.95));opacity:.8;pointer-events:none}
@media(max-width:768px){.hud{top:3rem;right:.7rem;font-size:.52rem}.legend{left:.75rem;bottom:4.5rem;width:38vw}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">FIELD <b id="h-grid">96</b><br>QUENCH <b id="h-quench">1.20</b><br>MIX <b id="h-mix">0.00</b><br><span class="p">COARSEN</span> <b id="h-time">0</b></div>
<div class="legend"></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed phase'],
  ['type' => 'drag', 'label' => 'paint field'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" data-preset="random">RANDOM</button><button class="control-btn" data-preset="droplet">DROPLET</button><button class="control-btn" data-preset="stripe">STRIPE</button></div></div>
  <div><div class="slider-row"><span class="slider-label">QUENCH</span><input type="range" id="sl-quench" min="60" max="190" value="120" step="5"><span class="slider-value" id="sv-quench">1.20</span></div></div>
  <div><div class="slider-row"><span class="slider-label">BRUSH</span><input type="range" id="sl-brush" min="3" max="16" value="8" step="1"><span class="slider-value" id="sv-brush">8</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="8" value="3" step="1"><span class="slider-value" id="sv-speed">3</span></div></div>
  <div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-invert">INVERT</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d',{alpha:false});
var W=0,H=0,DPR=1,N=96,phi=new Float32Array(N*N),next=new Float32Array(N*N),mu=new Float32Array(N*N);
var img=document.createElement('canvas'),ictx=img.getContext('2d'),pixels=ictx.createImageData(N,N);
var running=true,quench=1.2,brush=8,speed=3,preset='random',tick=0,pointer={down:false,phase:1};
function idx(x,y){return ((y+N)%N)*N+((x+N)%N)}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function mix(a,b,t){return a+(b-a)*t}
function resize(){DPR=Math.min(2,window.devicePixelRatio||1);W=C.width=Math.floor(window.innerWidth*DPR);H=C.height=Math.floor(window.innerHeight*DPR);C.style.width=window.innerWidth+'px';C.style.height=window.innerHeight+'px';img.width=N;img.height=N}
function seed(){tick=0;for(var y=0;y<N;y++){for(var x=0;x<N;x++){var i=idx(x,y);if(preset==='droplet'){var dx=x-N*.5,dy=y-N*.5,r=Math.sqrt(dx*dx+dy*dy);phi[i]=r<N*.19?.76:-.44;phi[i]+=(Math.random()-.5)*.08}else if(preset==='stripe'){phi[i]=Math.sin(x*.22)*.2+Math.sin(y*.11)*.08+(Math.random()-.5)*.16}else{phi[i]=(Math.random()-.5)*.34}}}smoothSeed(10);updateHud()}
function lap(a,x,y){var c=a[idx(x,y)];return a[idx(x-1,y)]+a[idx(x+1,y)]+a[idx(x,y-1)]+a[idx(x,y+1)]+.25*(a[idx(x-1,y-1)]+a[idx(x+1,y-1)]+a[idx(x-1,y+1)]+a[idx(x+1,y+1)])-5*c}
function smoothSeed(n){for(var p=0;p<n;p++){for(var y=0;y<N;y++){for(var x=0;x<N;x++){var i=idx(x,y);next[i]=phi[i]*.45+(phi[idx(x-1,y)]+phi[idx(x+1,y)]+phi[idx(x,y-1)]+phi[idx(x,y+1)])*.1375}}var t=phi;phi=next;next=t}}
function step(){var kappa=1.05,dt=.012;for(var y=0;y<N;y++){for(var x=0;x<N;x++){var i=idx(x,y),p=phi[i];mu[i]=-quench*p+p*p*p-kappa*lap(phi,x,y)}}for(var yy=0;yy<N;yy++){for(var xx=0;xx<N;xx++){var j=idx(xx,yy);next[j]=clamp(phi[j]+dt*lap(mu,xx,yy),-1.25,1.25)}}var t=phi;phi=next;next=t;tick++}
function mean(){var s=0;for(var i=0;i<phi.length;i++)s+=phi[i];return s/phi.length}
function drawField(){for(var y=0;y<N;y++){for(var x=0;x<N;x++){var i=idx(x,y),v=clamp((phi[i]+1)*.5,0,1),r,g,b;if(v<.5){var t=v/.5;r=Math.round(mix(123,8,t));g=Math.round(mix(44,25,t));b=Math.round(mix(191,42,t))}else{var u=(v-.5)/.5;r=Math.round(mix(8,0,u));g=Math.round(mix(25,245,u));b=Math.round(mix(42,212,u))}var id=i*4;pixels.data[id]=r;pixels.data[id+1]=g;pixels.data[id+2]=b;pixels.data[id+3]=255}}ictx.putImageData(pixels,0,0)}
function render(){if(running){for(var i=0;i<speed;i++)step()}drawField();ctx.setTransform(DPR,0,0,DPR,0,0);var cw=W/DPR,ch=H/DPR;ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,cw,ch);var size=Math.min(cw,ch)*(cw<760?.82:.78),ox=(cw-size)*.5,oy=(ch-size)*.5;ctx.imageSmoothingEnabled=false;ctx.drawImage(img,ox,oy,size,size);ctx.strokeStyle='rgba(0,245,212,.32)';ctx.lineWidth=1;ctx.strokeRect(ox-.5,oy-.5,size+1,size+1);ctx.strokeStyle='rgba(123,44,191,.22)';ctx.beginPath();for(var k=1;k<4;k++){var p=k/4;ctx.moveTo(ox+size*p,oy);ctx.lineTo(ox+size*p,oy+size);ctx.moveTo(ox,oy+size*p);ctx.lineTo(ox+size,oy+size*p)}ctx.stroke();if(tick%8===0)updateHud();requestAnimationFrame(render)}
function updateHud(){document.getElementById('h-grid').textContent=N;document.getElementById('h-quench').textContent=quench.toFixed(2);document.getElementById('h-mix').textContent=mean().toFixed(2);document.getElementById('h-time').textContent=tick}
function toField(e){var cw=W/DPR,ch=H/DPR,size=Math.min(cw,ch)*(cw<760?.82:.78),ox=(cw-size)*.5,oy=(ch-size)*.5,rect=C.getBoundingClientRect(),px=e.clientX-rect.left,py=e.clientY-rect.top;return{x:Math.floor((px-ox)/size*N),y:Math.floor((py-oy)/size*N)}}
function paint(e,force){var p=toField(e);if(p.x<0||p.x>=N||p.y<0||p.y>=N)return;var ph=force||pointer.phase;for(var yy=-brush;yy<=brush;yy++){for(var xx=-brush;xx<=brush;xx++){var d2=xx*xx+yy*yy;if(d2>brush*brush)continue;var fall=1-Math.sqrt(d2)/brush,id=idx(p.x+xx,p.y+yy);phi[id]=clamp(phi[id]*(1-fall*.5)+ph*fall*.9,-1.15,1.15)}}updateHud()}
C.addEventListener('pointerdown',function(e){pointer.down=true;pointer.phase=e.shiftKey?-1:1;paint(e);C.setPointerCapture(e.pointerId);if(typeof SFX!=='undefined')SFX.play('spawn')});
C.addEventListener('pointermove',function(e){if(!pointer.down)return;paint(e);if(typeof SFX!=='undefined')SFX.play('move',90)});
C.addEventListener('pointerup',function(e){pointer.down=false;try{C.releasePointerCapture(e.pointerId)}catch(err){}});
document.querySelectorAll('[data-preset]').forEach(function(btn){btn.addEventListener('click',function(){preset=this.dataset.preset;document.querySelectorAll('[data-preset]').forEach(function(b){b.classList.remove('active')});this.classList.add('active');seed();if(typeof SFX!=='undefined')SFX.play('toggle')})});
document.getElementById('sl-quench').addEventListener('input',function(){quench=parseInt(this.value,10)/100;document.getElementById('sv-quench').textContent=quench.toFixed(2);updateHud()});
document.getElementById('sl-brush').addEventListener('input',function(){brush=parseInt(this.value,10);document.getElementById('sv-brush').textContent=brush});
document.getElementById('sl-speed').addEventListener('input',function(){speed=parseInt(this.value,10);document.getElementById('sv-speed').textContent=speed});
document.getElementById('btn-run').addEventListener('click',function(){running=!running;this.classList.toggle('active',running);this.textContent=running?'RUN':'PAUSE';if(typeof SFX!=='undefined')SFX.play('toggle')});
document.getElementById('btn-invert').addEventListener('click',function(){for(var i=0;i<phi.length;i++)phi[i]=-phi[i];updateHud();if(typeof SFX!=='undefined')SFX.play('ripple')});
document.getElementById('btn-reset').addEventListener('click',function(){seed();if(typeof SFX!=='undefined')SFX.play('click')});
window.addEventListener('resize',resize);resize();seed();requestAnimationFrame(render);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
