<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fractal');

$pageName = 'fractal';
$pageTitle = 'FRACTAL - PULSE';
$pageDesc = json_decode('"\u30de\u30f3\u30c7\u30eb\u30d6\u30ed\u96c6\u5408\u3092\u3001\u30ba\u30fc\u30e0\u3068\u8272\u5c64\u3067\u63a2\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/fractal.php';
$extraStyle = '\nbody{background:#0a0a0f;min-height:100vh;overflow:hidden}\n#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}\n.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}\n.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}\n.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}\n.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}\n@media(max-width:760px){.hud{top:.75rem;right:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}}\n';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">SET <b id="h-set">MANDELBROT</b><br>ZOOM <b id="h-zoom">1.00</b><br>ITER <b id="h-iter">96</b><br><span class="sub">CENTER</span> <b id="h-center">-0.50, 0.00</b></div>
<div class="mark">FRACTAL</div>
<?php $interactionHints=[['type'=>'drag','label'=>'pan'],['type'=>'click','label'=>'focus'],['type'=>'scroll','label'=>'zoom']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" data-palette="pulse">PULSE</button><button class="control-btn" data-palette="veil">VEIL</button><button class="control-btn" data-palette="orbit">ORBIT</button></div>
  <div class="slider-row"><span class="slider-label">ITER</span><input type="range" id="sl-iter" min="48" max="180" value="96"><span class="slider-value" id="sv-iter">96</span></div>
  <div class="slider-row"><span class="slider-label">DETAIL</span><input type="range" id="sl-step" min="1" max="4" value="2"><span class="slider-value" id="sv-step">2</span></div>
  <div class="btn-row"><button class="control-btn" id="btn-auto">DRIFT</button><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W=0,H=0,D=1;
var cx=-.52,cy=0,zoom=1,iter=96,step=2,palette='pulse',auto=false,drag=null,needs=true,t=0;
var hz=document.getElementById('h-zoom'),hi=document.getElementById('h-iter'),hc=document.getElementById('h-center'),si=document.getElementById('sl-iter'),ss=document.getElementById('sl-step'),vi=document.getElementById('sv-iter'),vs=document.getElementById('sv-step');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';needs=true}
function color(n,m){if(n>=m)return [8,7,14,255];var q=n/m,a=0;if(palette==='veil')a=.5+.5*Math.sin(q*10+t);else if(palette==='orbit')a=(q*q*7)%1;else a=q;var k=a,b=.20+.80*Math.sqrt(a);return [Math.floor(6+123*(1-k)*b),Math.floor(8+(44+201*k)*b),Math.floor(12+(191+21*k)*b*.9),255]}
function render(){if(auto){cx+=Math.sin(t*.37)*.0008/zoom;cy+=Math.cos(t*.29)*.0006/zoom;zoom*=1.0015;needs=true}if(!needs)return;needs=false;var img=x.createImageData(W,H),data=img.data,scale=3.15/Math.min(W,H)/zoom,k=0;for(var y=0;y<H;y+=step){for(var xx=0;xx<W;xx+=step){var cr=cx+(xx-W/2)*scale,ci=cy+(y-H/2)*scale,zr=0,zi=0,n=0;while(n<iter&&zr*zr+zi*zi<4){var nz=zr*zr-zi*zi+cr;zi=2*zr*zi+ci;zr=nz;n++}var col=color(n,iter);for(var yy=0;yy<step;yy++)for(var xo=0;xo<step;xo++){var px=xx+xo,py=y+yy;if(px<W&&py<H){k=(py*W+px)*4;data[k]=col[0];data[k+1]=col[1];data[k+2]=col[2];data[k+3]=255}}}}x.putImageData(img,0,0);hud()}
function hud(){hz.textContent=zoom.toFixed(2);hi.textContent=iter;vi.textContent=iter;vs.textContent=step;hc.textContent=cx.toFixed(2)+', '+cy.toFixed(2)}
function zoomAt(mx,my,f){var scale=3.15/Math.min(W,H)/zoom,rx=cx+(mx*D-W/2)*scale,ry=cy+(my*D-H/2)*scale;zoom*=f;scale=3.15/Math.min(W,H)/zoom;cx=rx-(mx*D-W/2)*scale;cy=ry-(my*D-H/2)*scale;needs=true}
c.addEventListener('pointerdown',function(e){drag={x:e.clientX,y:e.clientY,cx:cx,cy:cy};c.setPointerCapture(e.pointerId);zoomAt(e.clientX,e.clientY,1.12);if(window.SFX)SFX.play('ripple',100)});
c.addEventListener('pointermove',function(e){if(!drag)return;var scale=3.15/Math.min(W,H)/zoom;cx=drag.cx-(e.clientX-drag.x)*D*scale;cy=drag.cy-(e.clientY-drag.y)*D*scale;needs=true;if(window.SFX)SFX.play('move',140)});
c.addEventListener('pointerup',function(){drag=null});c.addEventListener('wheel',function(e){e.preventDefault();zoomAt(e.clientX,e.clientY,e.deltaY<0?1.25:.8);if(window.SFX)SFX.play('tick',110)},{passive:false});
document.querySelectorAll('[data-palette]').forEach(function(b){b.onclick=function(){palette=b.dataset.palette;document.querySelectorAll('[data-palette]').forEach(function(q){q.classList.toggle('active',q===b)});needs=true;if(window.SFX)SFX.play('toggle')}});si.oninput=function(){iter=+si.value;needs=true;hud()};ss.oninput=function(){step=+ss.value;needs=true;hud()};document.getElementById('btn-auto').onclick=function(e){auto=!auto;e.currentTarget.classList.toggle('active',auto);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){cx=-.52;cy=0;zoom=1;needs=true;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();(function loop(){t+=.016;render();requestAnimationFrame(loop)})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
