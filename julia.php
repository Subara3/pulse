<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('julia');

$pageName = 'julia';
$pageTitle = 'JULIA SET - PULSE';
$pageDesc = json_decode('"\u30b8\u30e5\u30ea\u30a2\u96c6\u5408\u306e\u30d1\u30e9\u30e1\u30fc\u30bf\u7a7a\u9593\u3092\u3001\u30c9\u30e9\u30c3\u30b0\u3067\u9023\u7d9a\u7684\u306b\u5909\u5f62\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/julia.php';
$extraStyle = '\nbody{background:#0a0a0f;min-height:100vh;overflow:hidden}\n#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}\n.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}\n.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}\n.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}\n.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}\n@media(max-width:760px){.hud{top:.75rem;right:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}}\n';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">C <b id="h-c">-0.70 + 0.27i</b><br>ZOOM <b id="h-zoom">1.00</b><br>ITER <b id="h-iter">104</b><br><span class="sub">FIELD</span> <b id="h-field">FLOW</b></div>
<div class="mark">JULIA SET</div>
<?php $interactionHints=[['type'=>'drag','label'=>'shape'],['type'=>'click','label'=>'lock'],['type'=>'scroll','label'=>'zoom']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" data-mode="flow">FLOW</button><button class="control-btn" data-mode="thorn">THORN</button><button class="control-btn" data-mode="basin">BASIN</button></div>
  <div class="slider-row"><span class="slider-label">ITER</span><input type="range" id="sl-iter" min="48" max="180" value="104"><span class="slider-value" id="sv-iter">104</span></div>
  <div class="slider-row"><span class="slider-label">MOTION</span><input type="range" id="sl-motion" min="0" max="100" value="35"><span class="slider-value" id="sv-motion">0.35</span></div>
  <div class="btn-row"><button class="control-btn active" id="btn-anim">MORPH</button><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,cr=-.7,ci=.27015,zoom=1,iter=104,mode='flow',motion=.35,anim=true,locked=false,needs=true,t=0;
var hc=document.getElementById('h-c'),hz=document.getElementById('h-zoom'),hi=document.getElementById('h-iter'),hf=document.getElementById('h-field'),si=document.getElementById('sl-iter'),sm=document.getElementById('sl-motion'),vi=document.getElementById('sv-iter'),vm=document.getElementById('sv-motion');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';needs=true}
function col(n){if(n>=iter)return [7,6,13,255];var q=n/iter,a=mode==='thorn'?Math.sqrt(q):mode==='basin'?(Math.sin(q*18+t)*.5+.5):q;return [Math.floor(20+103*a),Math.floor(20+225*Math.pow(a,.65)),Math.floor(72+155*(1-a*.55)),255]}
function render(){if(anim&&!locked){cr=-.72+Math.sin(t*.41)*motion*.42;ci=.18+Math.cos(t*.33)*motion*.34;needs=true}if(!needs)return;needs=false;var img=x.createImageData(W,H),data=img.data,scale=3.1/Math.min(W,H)/zoom,k=0;for(var y=0;y<H;y+=2){for(var px=0;px<W;px+=2){var zr=(px-W/2)*scale,zi=(y-H/2)*scale,n=0;while(n<iter&&zr*zr+zi*zi<4){var nz=zr*zr-zi*zi+cr;zi=2*zr*zi+ci;zr=nz;n++}var cc=col(n);for(var yy=0;yy<2;yy++)for(var xx=0;xx<2;xx++){var X=px+xx,Y=y+yy;if(X<W&&Y<H){k=(Y*W+X)*4;data[k]=cc[0];data[k+1]=cc[1];data[k+2]=cc[2];data[k+3]=255}}}}x.putImageData(img,0,0);hud()}
function hud(){hc.textContent=cr.toFixed(2)+' + '+ci.toFixed(2)+'i';hz.textContent=zoom.toFixed(2);hi.textContent=iter;vi.textContent=iter;hf.textContent=mode.toUpperCase();vm.textContent=motion.toFixed(2)}
function setC(e){cr=(e.clientX/innerWidth-.5)*1.8;ci=(e.clientY/innerHeight-.5)*1.45;needs=true}
c.addEventListener('pointerdown',function(e){locked=true;setC(e);if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){setC(e);if(window.SFX)SFX.play('move',130)}});c.addEventListener('dblclick',function(){locked=false;if(window.SFX)SFX.play('success')});c.addEventListener('wheel',function(e){e.preventDefault();zoom*=e.deltaY<0?1.18:.85;needs=true;if(window.SFX)SFX.play('tick',110)},{passive:false});
document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});needs=true;if(window.SFX)SFX.play('toggle')}});si.oninput=function(){iter=+si.value;needs=true;hud()};sm.oninput=function(){motion=+sm.value/100;needs=true;hud()};document.getElementById('btn-anim').onclick=function(e){anim=!anim;e.currentTarget.classList.toggle('active',anim);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){cr=-.7;ci=.27015;zoom=1;locked=true;needs=true;if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();(function loop(){t+=.016;render();requestAnimationFrame(loop)})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
