<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('life');

$pageName = 'life';
$pageTitle = "LIFE - PULSE";
$pageDesc = json_decode('"\u30b3\u30f3\u30a6\u30a7\u30a4\u306e\u30e9\u30a4\u30d5\u30b2\u30fc\u30e0\u3092\u3001\u5bc6\u5ea6\u3068\u4e16\u4ee3\u306e\u5834\u3068\u3057\u3066\u89b3\u5bdf\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/life.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">GEN <b id="h-gen">0</b><br>POP <b id="h-pop">0</b><br>DENSITY <b id="h-density">0%</b><br><span class="sub">RULE</span> <b id="h-mode">B3/S23</b></div>
<div class="mark">LIFE</div>
<?php $interactionHints=[['type'=>'click','label'=>'cell'],['type'=>'drag','label'=>'draw']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="classic">CLASSIC</button><button class="control-btn" data-mode="highlife">HIGHLIFE</button><button class="control-btn" data-mode="seeds">SEEDS</button></div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="20" value="8"><span class="slider-value" id="sv-speed">8</span></div><div class="slider-row"><span class="slider-label">CELL</span><input type="range" id="sl-cell" min="6" max="18" value="10"><span class="slider-value" id="sv-cell">10</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-random">RANDOM</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,cell=10,cols=0,rows=0,grid=[],next=[],gen=0,run=true,speed=8,mode='classic',tick=0;
var hg=document.getElementById('h-gen'),hp=document.getElementById('h-pop'),hd=document.getElementById('h-density'),hm=document.getElementById('h-mode'),ss=document.getElementById('sl-speed'),sc=document.getElementById('sl-cell'),vs=document.getElementById('sv-speed'),vc=document.getElementById('sv-cell');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';cols=Math.ceil(W/(cell*D));rows=Math.ceil(H/(cell*D));randomize()}function id(i,j){return ((j+rows)%rows)*cols+((i+cols)%cols)}function randomize(){grid=new Array(cols*rows).fill(0).map(function(){return Math.random()<.22?1:0});gen=0;hud()}function clear(){grid=new Array(cols*rows).fill(0);gen=0;hud()}function neigh(i,j){var n=0;for(var y=-1;y<=1;y++)for(var xx=-1;xx<=1;xx++)if(xx||y)n+=grid[id(i+xx,j+y)];return n}function alive(a,n){if(mode==='seeds')return !a&&n===2;if(mode==='highlife')return a?(n===2||n===3):(n===3||n===6);return a?(n===2||n===3):n===3}function step(){next=new Array(cols*rows);for(var j=0;j<rows;j++)for(var i=0;i<cols;i++){var a=grid[id(i,j)],n=neigh(i,j);next[id(i,j)]=alive(a,n)?1:0}grid=next;gen++}function draw(){tick++;if(run&&tick%(22-speed)===0)step();x.fillStyle='rgba(10,10,15,.28)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var s=cell*D,pop=0;for(var j=0;j<rows;j++)for(var i=0;i<cols;i++){if(grid[id(i,j)]){pop++;x.fillStyle=(i+j+gen)%2?'rgba(123,44,191,.58)':'rgba(0,245,212,.68)';x.fillRect(i*s,j*s,s-1,s-1)}}x.globalCompositeOperation='source-over';hud(pop);requestAnimationFrame(draw)}function hud(pop){if(pop==null)pop=grid.reduce(function(a,b){return a+b},0);hg.textContent=gen;hp.textContent=pop;hd.textContent=(pop/(cols*rows)*100).toFixed(1)+'%';hm.textContent=mode==='classic'?'B3/S23':mode==='highlife'?'B36/S23':'B2/S';vs.textContent=speed;vc.textContent=cell}function paint(e){var i=Math.floor(e.clientX*D/(cell*D)),j=Math.floor(e.clientY*D/(cell*D));grid[id(i,j)]=1;if(window.SFX)SFX.play('tick',130)}c.addEventListener('pointerdown',paint);c.addEventListener('pointermove',function(e){if(e.buttons){paint(e);if(window.SFX)SFX.play('move',150)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});hud();if(window.SFX)SFX.play('toggle')}});ss.oninput=function(){speed=+ss.value;hud()};sc.oninput=function(){cell=+sc.value;resize();hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-random').onclick=function(){randomize();if(window.SFX)SFX.play('success')};document.getElementById('btn-clear').onclick=function(){clear();if(window.SFX)SFX.play('toggle')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
