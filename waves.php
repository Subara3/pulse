<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('waves');

$pageName = 'waves';
$pageTitle = 'WAVES - PULSE';
$pageDesc = '複数の波源が干渉する波動場を、Canvas上で触れて操作する可視化。';
$pageCanonical = 'https://pulse.subara3.com/waves.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.wave-key{position:fixed;right:1rem;bottom:1rem;z-index:55;width:min(28vw,220px);height:2px;background:linear-gradient(90deg,rgba(123,44,191,.1),rgba(0,245,212,.9),rgba(123,44,191,.8));box-shadow:0 0 16px rgba(0,245,212,.24);pointer-events:none}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.wave-key{right:.75rem;bottom:4.45rem;width:38vw}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  MODE <b id="h-mode">INTERFERE</b><br>
  SOURCES <b id="h-sources">4</b><br>
  DAMPING <b id="h-damping">0.985</b><br>
  <span class="sub">ENERGY</span> <b id="h-energy">0.00</b>
</div>
<div class="wave-key"></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'emit'],
  ['type' => 'drag', 'label' => 'draw wave'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row"><button class="control-btn active" data-mode="interfere">INTERFERE</button><button class="control-btn" data-mode="membrane">MEMBRANE</button><button class="control-btn" data-mode="rays">RAYS</button></div></div>
  <div><div class="slider-row"><span class="slider-label">SOURCES</span><input type="range" id="sl-sources" min="1" max="8" value="4"><span class="slider-value" id="sv-sources">4</span></div></div>
  <div><div class="slider-row"><span class="slider-label">DAMP</span><input type="range" id="sl-damp" min="960" max="998" value="985"><span class="slider-value" id="sv-damp">0.985</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="40" max="160" value="90"><span class="slider-value" id="sv-speed">0.90</span></div></div>
  <div><div class="btn-row"><button class="control-btn active" id="btn-auto">AUTO</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var canvas=document.getElementById('canvas'),ctx=canvas.getContext('2d');
var W=0,H=0,DPR=1,NX=150,NY=86,prev,curr,next,img,buffer,bufferCtx,mode='interfere',sources=4,damping=.985,speed=.9,auto=true,t=0,drag=false;
var hMode=document.getElementById('h-mode'),hSources=document.getElementById('h-sources'),hDamping=document.getElementById('h-damping'),hEnergy=document.getElementById('h-energy');
var slSources=document.getElementById('sl-sources'),slDamp=document.getElementById('sl-damp'),slSpeed=document.getElementById('sl-speed'),svSources=document.getElementById('sv-sources'),svDamp=document.getElementById('sv-damp'),svSpeed=document.getElementById('sv-speed'),btnAuto=document.getElementById('btn-auto'),btnClear=document.getElementById('btn-clear');
function resize(){DPR=Math.min(2,window.devicePixelRatio||1);W=canvas.width=Math.floor(innerWidth*DPR);H=canvas.height=Math.floor(innerHeight*DPR);canvas.style.width=innerWidth+'px';canvas.style.height=innerHeight+'px';NX=innerWidth<760?96:154;NY=Math.max(58,Math.round(NX*H/W));alloc();}
function alloc(){prev=new Float32Array(NX*NY);curr=new Float32Array(NX*NY);next=new Float32Array(NX*NY);img=ctx.createImageData(NX,NY);buffer=document.createElement('canvas');buffer.width=NX;buffer.height=NY;bufferCtx=buffer.getContext('2d');for(var i=0;i<sources;i++){emit((i+1)/(sources+1),.5,.9);}}
function id(x,y){return y*NX+x;}function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
function emit(nx,ny,pow){var cx=Math.floor(clamp(nx,0,1)*(NX-1)),cy=Math.floor(clamp(ny,0,1)*(NY-1));for(var y=-4;y<=4;y++)for(var x=-4;x<=4;x++){var xx=cx+x,yy=cy+y;if(xx<1||yy<1||xx>=NX-1||yy>=NY-1)continue;var d=Math.sqrt(x*x+y*y);curr[id(xx,yy)]+=Math.cos(d*Math.PI/4)*pow*Math.max(0,1-d/5);}}
function sourceStep(){if(!auto)return;for(var i=0;i<sources;i++){var a=t*(.8+i*.11)+i*Math.PI*2/sources;var nx=.5+Math.cos(a)*(.25+.04*Math.sin(t+i));var ny=.5+Math.sin(a*1.31)*.28;emit(nx,ny,.06+.04*Math.sin(t*2+i));}}
function solve(){var steps=Math.max(1,Math.round(speed*2));for(var s=0;s<steps;s++){sourceStep();for(var y=1;y<NY-1;y++){for(var x=1;x<NX-1;x++){var k=id(x,y);var lap=curr[k-1]+curr[k+1]+curr[k-NX]+curr[k+NX]-4*curr[k];var c=mode==='membrane'?.54:mode==='rays'?.42:.49;next[k]=(2*curr[k]-prev[k]+lap*c)*damping;}}var tmp=prev;prev=curr;curr=next;next=tmp;next.fill(0);t+=.016;}}
function draw(){solve();var data=img.data,energy=0;for(var y=0;y<NY;y++){for(var x=0;x<NX;x++){var k=id(x,y),v=curr[k],m=Math.min(1,Math.abs(v)*1.8);energy+=Math.abs(v);var o=k*4;if(v>=0){data[o]=0;data[o+1]=Math.floor(38+207*m);data[o+2]=Math.floor(54+158*m);}else{data[o]=Math.floor(24+99*m);data[o+1]=Math.floor(12+32*m);data[o+2]=Math.floor(56+135*m);}data[o+3]=Math.floor(48+190*m);}}
ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);bufferCtx.putImageData(img,0,0);ctx.imageSmoothingEnabled=true;ctx.drawImage(buffer,0,0,W,H);ctx.globalCompositeOperation='lighter';ctx.strokeStyle='rgba(0,245,212,.08)';ctx.lineWidth=1*DPR;for(var gx=0;gx<10;gx++){ctx.beginPath();ctx.moveTo(gx*W/10,0);ctx.lineTo(gx*W/10,H);ctx.stroke();}for(var gy=0;gy<7;gy++){ctx.beginPath();ctx.moveTo(0,gy*H/7);ctx.lineTo(W,gy*H/7);ctx.stroke();}ctx.globalCompositeOperation='source-over';hEnergy.textContent=(energy/(NX*NY)).toFixed(2);requestAnimationFrame(draw);}
function updateHud(){hMode.textContent=mode.toUpperCase();hSources.textContent=sources;hDamping.textContent=damping.toFixed(3);svSources.textContent=sources;svDamp.textContent=damping.toFixed(3);svSpeed.textContent=speed.toFixed(2);}
function poke(e,p){emit(e.clientX/innerWidth,e.clientY/innerHeight,p);}
canvas.addEventListener('pointerdown',function(e){drag=true;poke(e,1.2);if(typeof SFX!=='undefined')SFX.play('ripple',100);});
canvas.addEventListener('pointermove',function(e){if(drag||e.buttons){poke(e,.34);}});window.addEventListener('pointerup',function(){drag=false;});
document.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('active',x===b);});updateHud();if(typeof SFX!=='undefined')SFX.play('toggle');});});
slSources.addEventListener('input',function(){sources=parseInt(slSources.value,10);updateHud();if(typeof SFX!=='undefined')SFX.play('tick',90);});
slDamp.addEventListener('input',function(){damping=parseInt(slDamp.value,10)/1000;updateHud();});
slSpeed.addEventListener('input',function(){speed=parseInt(slSpeed.value,10)/100;updateHud();});
btnAuto.addEventListener('click',function(){auto=!auto;btnAuto.classList.toggle('active',auto);if(typeof SFX!=='undefined')SFX.play('toggle');});
btnClear.addEventListener('click',function(){prev.fill(0);curr.fill(0);next.fill(0);if(typeof SFX!=='undefined')SFX.play('pop');});
window.addEventListener('resize',resize);resize();updateHud();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
