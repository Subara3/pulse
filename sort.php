<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('sort');

$pageName = 'sort';
$pageTitle = "SORT - PULSE";
$pageDesc = json_decode('"\u30bd\u30fc\u30c8\u30a2\u30eb\u30b4\u30ea\u30ba\u30e0\u306e\u6bd4\u8f03\u3068\u4ea4\u63db\u3092\u3001\u5149\u306e\u68d2\u30b0\u30e9\u30d5\u3067\u89b3\u5bdf\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/sort.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">ALGO <b id="h-algo">BUBBLE</b><br>COMPARE <b id="h-comp">0</b><br>SWAPS <b id="h-swap">0</b><br><span class="sub">SIZE</span> <b id="h-size">96</b></div>
<div class="mark">SORT</div>
<?php $interactionHints=[['type'=>'click','label'=>'shuffle'],['type'=>'drag','label'=>'scrub']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-algo="bubble">BUBBLE</button><button class="control-btn" data-algo="selection">SELECT</button><button class="control-btn" data-algo="insertion">INSERT</button></div><div class="slider-row"><span class="slider-label">SIZE</span><input type="range" id="sl-size" min="32" max="160" value="96"><span class="slider-value" id="sv-size">96</span></div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="60" value="16"><span class="slider-value" id="sv-speed">16</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-shuffle">SHUFFLE</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,N=96,speed=16,algo='bubble',arr=[],i=0,j=0,min=0,run=true,comp=0,swaps=0,mark=[-1,-1];
var ha=document.getElementById('h-algo'),hc=document.getElementById('h-comp'),hs=document.getElementById('h-swap'),hz=document.getElementById('h-size'),sn=document.getElementById('sl-size'),ss=document.getElementById('sl-speed'),vn=document.getElementById('sv-size'),vs=document.getElementById('sv-speed');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}function shuffle(){arr=[];for(var k=0;k<N;k++)arr.push((k+1)/N);for(var n=N-1;n>0;n--){var r=Math.floor(Math.random()*(n+1)),tmp=arr[n];arr[n]=arr[r];arr[r]=tmp}i=0;j=0;min=0;comp=0;swaps=0;hud();if(window.SFX)SFX.play('success')}function swap(a,b){var t=arr[a];arr[a]=arr[b];arr[b]=t;swaps++;if(window.SFX&&swaps%12===0)SFX.play('tick',130)}function step(){for(var s=0;s<speed;s++){if(algo==='bubble'){if(i>=N){i=0;j=0;continue}mark=[j,j+1];if(j<N-i-1){comp++;if(arr[j]>arr[j+1])swap(j,j+1);j++}else{j=0;i++}}else if(algo==='selection'){if(i>=N){i=0;continue}if(j===0){min=i;j=i+1}if(j<N){mark=[min,j];comp++;if(arr[j]<arr[min])min=j;j++}else{if(min!==i)swap(min,i);i++;j=0}}else{if(i<=0)i=1;if(i>=N){i=1;j=0;continue}if(!j)j=i;mark=[j-1,j];if(j>0&&arr[j-1]>arr[j]){comp++;swap(j-1,j);j--}else{i++;j=0}}}}function draw(){if(run)step();x.fillStyle='rgba(10,10,15,.24)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var bw=W/N;for(var k=0;k<N;k++){var h=arr[k]*H*.72,q=arr[k];x.fillStyle=(k===mark[0]||k===mark[1])?'rgba(123,44,191,.86)':'rgba(0,245,212,'+(.25+q*.55)+')';x.fillRect(k*bw,H*.86-h,Math.max(1,bw-1),h)}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}function hud(){ha.textContent=algo.toUpperCase();hc.textContent=comp;hs.textContent=swaps;hz.textContent=N;vn.textContent=N;vs.textContent=speed}c.addEventListener('pointerdown',function(){shuffle()});c.addEventListener('pointermove',function(e){if(e.buttons){speed=Math.max(1,Math.min(60,speed+(e.movementX||0)*.05));ss.value=Math.round(speed);hud();if(window.SFX)SFX.play('move',150)}});document.querySelectorAll('[data-algo]').forEach(function(b){b.onclick=function(){algo=b.dataset.algo;document.querySelectorAll('[data-algo]').forEach(function(q){q.classList.toggle('active',q===b)});shuffle();if(window.SFX)SFX.play('toggle')}});sn.oninput=function(){N=+sn.value;shuffle()};ss.oninput=function(){speed=+ss.value;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-shuffle').onclick=shuffle;addEventListener('resize',resize);resize();shuffle();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
