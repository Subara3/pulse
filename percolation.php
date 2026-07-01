<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('percolation');

$pageName = 'percolation';
$pageTitle = "PERCOLATION - PULSE";
$pageDesc = json_decode('"\u683c\u5b50\u3092\u78ba\u7387\u3067\u958b\u304d\u3001\u9023\u7d50\u30af\u30e9\u30b9\u30bf\u3068\u81e8\u754c\u70b9\u3092\u89b3\u5bdf\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/percolation.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">P <b id="h-p">0.59</b><br>OPEN <b id="h-open">0</b><br>CLUSTERS <b id="h-clusters">0</b><br><span class="sub">SPAN</span> <b id="h-span">NO</b></div>
<div class="mark">PERCOLATION</div>
<?php $interactionHints=[['type'=>'click','label'=>'seed'],['type'=>'drag','label'=>'paint']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="site">SITE</button><button class="control-btn" data-mode="flow">FLOW</button><button class="control-btn" data-mode="critical">CRITICAL</button></div><div class="slider-row"><span class="slider-label">PROB</span><input type="range" id="sl-p" min="20" max="80" value="59"><span class="slider-value" id="sv-p">0.59</span></div><div class="slider-row"><span class="slider-label">SIZE</span><input type="range" id="sl-n" min="32" max="96" value="64"><span class="slider-value" id="sv-n">64</span></div><div class="btn-row"><button class="control-btn active" id="btn-auto">AUTO</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,N=64,p=.59,mode='site',auto=true,grid=[],labels=[],span=false,clusters=0,t=0;
var hp=document.getElementById('h-p'),ho=document.getElementById('h-open'),hc=document.getElementById('h-clusters'),hs=document.getElementById('h-span'),sp=document.getElementById('sl-p'),sn=document.getElementById('sl-n'),vp=document.getElementById('sv-p'),vn=document.getElementById('sv-n');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';reset()}function id(i,j){return j*N+i}function reset(){grid=new Array(N*N).fill(0).map(function(){return Math.random()<p?1:0});analyze();hud()}function analyze(){labels=new Array(N*N).fill(0);clusters=0;span=false;for(var j=0;j<N;j++)for(var i=0;i<N;i++){if(!grid[id(i,j)]||labels[id(i,j)])continue;clusters++;var q=[[i,j]],touchT=false,touchB=false;labels[id(i,j)]=clusters;for(var qi=0;qi<q.length;qi++){var a=q[qi][0],b=q[qi][1];if(b===0)touchT=true;if(b===N-1)touchB=true;[[1,0],[-1,0],[0,1],[0,-1]].forEach(function(d){var ni=a+d[0],nj=b+d[1],k=id(ni,nj);if(ni>=0&&nj>=0&&ni<N&&nj<N&&grid[k]&&!labels[k]){labels[k]=clusters;q.push([ni,nj])}})}if(touchT&&touchB)span=true}}function draw(){if(auto){t+=.01;p=.5+.16*Math.sin(t);sp.value=Math.round(p*100);reset();auto=false;setTimeout(function(){auto=true},140)}x.fillStyle='rgba(10,10,15,.25)';x.fillRect(0,0,W,H);var s=Math.min(W,H)*.82/N,ox=(W-s*N)/2,oy=(H-s*N)/2,open=0;for(var j=0;j<N;j++)for(var i=0;i<N;i++){var k=id(i,j);if(grid[k]){open++;var lab=labels[k]||1;var q=(lab*47%100)/100;x.fillStyle=span&&labels[k]?'rgba(0,245,212,.70)':(mode==='flow'?'rgba(123,44,191,.55)':'rgba('+Math.floor(30+80*q)+','+Math.floor(110+130*q)+','+Math.floor(160+70*(1-q))+',.62)');x.fillRect(ox+i*s,oy+j*s,Math.ceil(s),Math.ceil(s))}}hud(open);requestAnimationFrame(draw)}function hud(open){hp.textContent=p.toFixed(2);vp.textContent=p.toFixed(2);vn.textContent=N;ho.textContent=open||grid.reduce(function(a,b){return a+b},0);hc.textContent=clusters;hs.textContent=span?'YES':'NO'}function paint(e){var s=Math.min(W,H)*.82/N,ox=(W-s*N)/2,oy=(H-s*N)/2,i=Math.floor((e.clientX*D-ox)/s),j=Math.floor((e.clientY*D-oy)/s);if(i>=0&&j>=0&&i<N&&j<N){grid[id(i,j)]=1;analyze();if(window.SFX)SFX.play('tick',120)}}c.addEventListener('pointerdown',paint);c.addEventListener('pointermove',function(e){if(e.buttons){paint(e);if(window.SFX)SFX.play('move',150)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(mode==='critical'){p=.593;sp.value=59;reset()}if(window.SFX)SFX.play('toggle')}});sp.oninput=function(){p=+sp.value/100;reset()};sn.oninput=function(){N=+sn.value;reset()};document.getElementById('btn-auto').onclick=function(e){auto=!auto;e.currentTarget.classList.toggle('active',auto);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){reset();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
