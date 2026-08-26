<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('burningship');

$pageName = 'burningship';
$pageTitle = 'BURNING SHIP - PULSE';
$pageDesc = 'バーニングシップフラクタル。絶対値操作が対称性を破り、炎上する船のような非対称フラクタルが現れる。';
$pageCanonical = 'https://pulse.subara3.com/burningship.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}
.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
@media(max-width:760px){.hud{top:.75rem;right:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">BURNING SHIP</span><br>ZOOM <b id="h-zoom">1.00</b><br>ITER <b id="h-iter">128</b><br><span class="sub">CENTER</span> <b id="h-center">-0.40, -0.50</b></div>
<div class="mark">BURNING SHIP</div>
<?php $interactionHints=[['type'=>'drag','label'=>'pan'],['type'=>'click','label'=>'focus'],['type'=>'scroll','label'=>'zoom']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" data-palette="ember">EMBER</button><button class="control-btn" data-palette="abyss">ABYSS</button><button class="control-btn" data-palette="frost">FROST</button></div>
  <div class="slider-row"><span class="slider-label">ITER</span><input type="range" id="sl-iter" min="48" max="512" value="128"><span class="slider-value" id="sv-iter">128</span></div>
  <div class="slider-row"><span class="slider-label">DETAIL</span><input type="range" id="sl-step" min="1" max="4" value="2"><span class="slider-value" id="sv-step">2</span></div>
  <div class="btn-row"><button class="control-btn" id="btn-auto">DRIFT</button><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d'),W=0,H=0,D=1;
var cx=-0.4,cy=-0.5,zoom=1,iter=128,step=2,pal='ember',auto=false,drag=null,needs=true,t=0;
var hZ=document.getElementById('h-zoom'),hI=document.getElementById('h-iter'),hC=document.getElementById('h-center');
var sI=document.getElementById('sl-iter'),sS=document.getElementById('sl-step');
var vI=document.getElementById('sv-iter'),vS=document.getElementById('sv-step');

function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';needs=true}

function brandHue(t){t=t<0?0:t>1?1:t;var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);return 172+e*118}

function hsl(h,s,l){s/=100;l/=100;var a=s*Math.min(l,1-l);function f(n){var k=(n+h/30)%12;return l-a*Math.max(-1,Math.min(k-3,9-k,1))}return[f(0)*255|0,f(8)*255|0,f(4)*255|0]}

function color(n,mi,zr2,zi2){
if(n>=mi)return[8,7,14];
var ln=Math.log(zr2+zi2)*0.5,mu=n+1-Math.log(ln)/Math.log(2);
if(mu<0)mu=0;
var q=mu/mi,h,s,l;
if(pal==='abyss'){h=brandHue((q*3)%1);s=60+30*Math.sin(q*14);l=4+35*Math.pow(q,0.8)}
else if(pal==='frost'){h=185+15*Math.sin(q*6);s=55+40*q;l=6+55*Math.pow(q,0.5)}
else{h=brandHue(Math.pow(q,0.65));s=60+35*Math.sin(q*8);l=5+55*Math.sqrt(q)}
return hsl(h,s,l)}

function render(){
if(auto){cx+=Math.sin(t*0.37)*0.0005/zoom;cy+=Math.cos(t*0.29)*0.0004/zoom;zoom*=1.002;needs=true}
if(!needs)return;needs=false;
var img=ctx.createImageData(W,H),d=img.data,sc=3.5/Math.min(W,H)/zoom,hw=W/2,hh=H/2;
for(var py=0;py<H;py+=step){for(var px=0;px<W;px+=step){
var cr=cx+(px-hw)*sc,ci=cy+(py-hh)*sc,zr=0,zi=0,n=0,zr2,zi2;
while(n<iter){zr2=zr*zr;zi2=zi*zi;if(zr2+zi2>256)break;zi=2*Math.abs(zr)*Math.abs(zi)+ci;zr=zr2-zi2+cr;n++}
zr2=zr*zr;zi2=zi*zi;var col=color(n,iter,zr2,zi2);
for(var sy=0;sy<step&&py+sy<H;sy++)for(var sx=0;sx<step&&px+sx<W;sx++){var k=((py+sy)*W+(px+sx))*4;d[k]=col[0];d[k+1]=col[1];d[k+2]=col[2];d[k+3]=255}
}}
ctx.putImageData(img,0,0);hud()}

function hud(){hZ.textContent=zoom.toFixed(2);hI.textContent=iter;vI.textContent=iter;vS.textContent=step;hC.textContent=cx.toFixed(4)+', '+cy.toFixed(4)}

function zoomAt(mx,my,f){var sc=3.5/Math.min(W,H)/zoom,rx=cx+(mx*D-W/2)*sc,ry=cy+(my*D-H/2)*sc;zoom*=f;sc=3.5/Math.min(W,H)/zoom;cx=rx-(mx*D-W/2)*sc;cy=ry-(my*D-H/2)*sc;needs=true}

c.addEventListener('pointerdown',function(e){drag={x:e.clientX,y:e.clientY,cx:cx,cy:cy};c.setPointerCapture(e.pointerId);zoomAt(e.clientX,e.clientY,1.12);if(window.SFX)SFX.play('ripple',100)});
c.addEventListener('pointermove',function(e){if(!drag)return;var sc=3.5/Math.min(W,H)/zoom;cx=drag.cx-(e.clientX-drag.x)*D*sc;cy=drag.cy-(e.clientY-drag.y)*D*sc;needs=true;if(window.SFX)SFX.play('move',140)});
c.addEventListener('pointerup',function(){drag=null});
c.addEventListener('wheel',function(e){e.preventDefault();zoomAt(e.clientX,e.clientY,e.deltaY<0?1.25:0.8);if(window.SFX)SFX.play('tick',110)},{passive:false});

document.querySelectorAll('[data-palette]').forEach(function(b){b.onclick=function(){pal=b.dataset.palette;document.querySelectorAll('[data-palette]').forEach(function(q){q.classList.toggle('active',q===b)});needs=true;if(window.SFX)SFX.play('toggle')}});
sI.oninput=function(){iter=+sI.value;needs=true;hud()};
sS.oninput=function(){step=+sS.value;needs=true;hud()};
document.getElementById('btn-auto').onclick=function(e){auto=!auto;e.currentTarget.classList.toggle('active',auto);if(window.SFX)SFX.play('toggle')};
document.getElementById('btn-reset').onclick=function(){cx=-0.4;cy=-0.5;zoom=1;needs=true;if(window.SFX)SFX.play('success')};
addEventListener('resize',resize);resize();
(function loop(){t+=0.016;render();requestAnimationFrame(loop)})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
