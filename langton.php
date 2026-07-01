<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('langton');

$pageName = 'langton';
$pageTitle = "LANGTON'S ANT - PULSE";
$pageDesc = json_decode('"\u30e9\u30f3\u30b0\u30c8\u30f3\u306e\u30a2\u30ea\u306e\u30bb\u30eb\u30aa\u30fc\u30c8\u30de\u30c8\u30f3\u3092\u3001\u8907\u6570\u72b6\u614b\u306e\u8ecc\u8de1\u3068\u3057\u3066\u89b3\u5bdf\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/langton.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">RULE <b id="h-rule">LR</b><br>ANTS <b id="h-ants">3</b><br>STEP <b id="h-step">0</b><br><span class="sub">SCALE</span> <b id="h-scale">6</b></div>
<div class="mark">LANGTON'S ANT</div>
<?php $interactionHints=[['type'=>'click','label'=>'ant'],['type'=>'drag','label'=>'paint']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-rule="LR">LR</button><button class="control-btn" data-rule="RLLR">RLLR</button><button class="control-btn" data-rule="LLRR">LLRR</button></div><div class="slider-row"><span class="slider-label">ANTS</span><input type="range" id="sl-ants" min="1" max="8" value="3"><span class="slider-value" id="sv-ants">3</span></div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="20" max="700" value="220"><span class="slider-value" id="sv-speed">220</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,scale=6,cols=0,rows=0,rule='LR',antCount=3,speed=220,run=true,grid=new Map(),ants=[],stepN=0;
var hr=document.getElementById('h-rule'),ha=document.getElementById('h-ants'),hs=document.getElementById('h-step'),hz=document.getElementById('h-scale'),sa=document.getElementById('sl-ants'),sp=document.getElementById('sl-speed'),va=document.getElementById('sv-ants'),vp=document.getElementById('sv-speed');
function key(x,y){return x+','+y}function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';cols=Math.ceil(W/(scale*D));rows=Math.ceil(H/(scale*D));reset(false)}
function reset(clear){if(clear!==false){grid=new Map();stepN=0}ants=[];for(var i=0;i<antCount;i++){ants.push({x:Math.floor(cols/2)+i*3-Math.floor(antCount*1.5),y:Math.floor(rows/2),d:i%4})}paintAll()}
function paintCell(gx,gy,v){var s=scale*D;x.fillStyle=v%2?'rgba(0,245,212,.85)':'rgba(123,44,191,.62)';if(v===0)x.fillStyle='rgba(10,10,15,.25)';x.fillRect(gx*s,gy*s,s+.5,s+.5)}
function paintAll(){x.fillStyle='#0a0a0f';x.fillRect(0,0,W,H);for(var e of grid.entries()){var p=e[0].split(',');paintCell(+p[0],+p[1],e[1])}}
function tick(){for(var n=0;n<speed;n++){for(var i=0;i<ants.length;i++){var a=ants[i],k=key(a.x,a.y),v=grid.get(k)||0,turn=rule[v%rule.length];a.d=(a.d+(turn==='R'?1:3))%4;v=(v+1)%rule.length;if(v)grid.set(k,v);else grid.delete(k);paintCell(a.x,a.y,v);if(a.d===0)a.y--;else if(a.d===1)a.x++;else if(a.d===2)a.y++;else a.x--;if(a.x<0)a.x=cols-1;if(a.x>=cols)a.x=0;if(a.y<0)a.y=rows-1;if(a.y>=rows)a.y=0}stepN++}}
function draw(){if(run)tick();x.globalCompositeOperation='lighter';for(var i=0;i<ants.length;i++){var a=ants[i],s=scale*D;x.fillStyle=i%2?'rgba(123,44,191,.95)':'rgba(0,245,212,.95)';x.beginPath();x.arc((a.x+.5)*s,(a.y+.5)*s,Math.max(2,s*.48),0,Math.PI*2);x.fill()}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}
function hud(){hr.textContent=rule;ha.textContent=antCount;hs.textContent=stepN;hz.textContent=scale;va.textContent=antCount;vp.textContent=speed}
function place(e,paint){var gx=Math.floor(e.clientX/scale),gy=Math.floor(e.clientY/scale);if(paint){grid.set(key(gx,gy),(grid.get(key(gx,gy))||0)+1);paintCell(gx,gy,grid.get(key(gx,gy))%rule.length)}else{ants.push({x:gx,y:gy,d:stepN%4});antCount=ants.length;sa.value=Math.min(8,antCount);if(window.SFX)SFX.play('ripple',100)}}
c.addEventListener('pointerdown',function(e){place(e,e.shiftKey)});c.addEventListener('pointermove',function(e){if(e.buttons){place(e,true);if(window.SFX)SFX.play('move',140)}});document.querySelectorAll('[data-rule]').forEach(function(b){b.onclick=function(){rule=b.dataset.rule;document.querySelectorAll('[data-rule]').forEach(function(q){q.classList.toggle('active',q===b)});reset(true);if(window.SFX)SFX.play('toggle')}});sa.oninput=function(){antCount=+sa.value;reset(false);hud()};sp.oninput=function(){speed=+sp.value;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-clear').onclick=function(){reset(true);if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
