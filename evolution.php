<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('evolution');

$pageName = 'evolution';
$pageTitle = "EVOLUTION - PULSE";
$pageDesc = json_decode('"\u907a\u4f1d\u7684\u30a2\u30eb\u30b4\u30ea\u30ba\u30e0\u306e\u4e16\u4ee3\u4ea4\u4ee3\u3092\u3001\u8ecc\u8de1\u3068\u9069\u5fdc\u5ea6\u3067\u89b3\u5bdf\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/evolution.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">GEN <b id="h-gen">1</b><br>POP <b id="h-pop">80</b><br>FITNESS <b id="h-fit">0.00</b><br><span class="sub">MUTATION</span> <b id="h-mut">0.18</b></div>
<div class="mark">EVOLUTION</div>
<?php $interactionHints=[['type'=>'click','label'=>'target'],['type'=>'drag','label'=>'pressure']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="seek">SEEK</button><button class="control-btn" data-mode="orbit">ORBIT</button><button class="control-btn" data-mode="swarm">SWARM</button></div><div class="slider-row"><span class="slider-label">POP</span><input type="range" id="sl-pop" min="30" max="150" value="80"><span class="slider-value" id="sv-pop">80</span></div><div class="slider-row"><span class="slider-label">MUTATE</span><input type="range" id="sl-mut" min="2" max="45" value="18"><span class="slider-value" id="sv-mut">0.18</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-reset">RESET</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='seek',pop=80,mut=.18,gen=1,run=true,target={x:0,y:0},agents=[],life=0,maxLife=180,best=0;
var hg=document.getElementById('h-gen'),hp=document.getElementById('h-pop'),hf=document.getElementById('h-fit'),hm=document.getElementById('h-mut'),sp=document.getElementById('sl-pop'),sm=document.getElementById('sl-mut'),vp=document.getElementById('sv-pop'),vm=document.getElementById('sv-mut');
function rnd(a){return Math.sin(a*128.91+gen*17.3)*.5+.5}function gene(i,j){return (rnd(i*31+j*7)-.5)*2}
function make(){agents=[];for(var i=0;i<pop;i++){var dna=[];for(var j=0;j<maxLife;j++)dna.push({x:gene(i,j),y:gene(i+99,j)});agents.push({x:W*.5,y:H*.82,vx:0,vy:0,dna:dna,trail:[],fit:0})}life=0;best=0}
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';target={x:W*.5,y:H*.22};make()}
function evolve(){agents.sort(function(a,b){return b.fit-a.fit});best=agents[0].fit;var elite=agents.slice(0,Math.max(4,Math.floor(pop*.14)));var next=[];for(var i=0;i<pop;i++){var p=elite[i%elite.length],dna=[];for(var j=0;j<maxLife;j++){var g=p.dna[j],m=(Math.random()<mut);dna.push({x:g.x+(m?(Math.random()-.5)*1.4:0),y:g.y+(m?(Math.random()-.5)*1.4:0)})}next.push({x:W*.5,y:H*.82,vx:0,vy:0,dna:dna,trail:[],fit:0})}agents=next;life=0;gen++}
function step(){if(!run)return;if(life>=maxLife){evolve();return}for(var i=0;i<agents.length;i++){var a=agents[i],g=a.dna[life],tx=target.x-a.x,ty=target.y-a.y,dd=Math.hypot(tx,ty)||1;if(mode==='seek'){a.vx+=g.x*.18+tx/dd*.08;a.vy+=g.y*.18+ty/dd*.08}else if(mode==='orbit'){a.vx+=g.x*.13-ty/dd*.10;a.vy+=g.y*.13+tx/dd*.10}else{a.vx+=g.x*.22+(rnd(i+life)-.5)*.06;a.vy+=g.y*.22+(rnd(i-life)-.5)*.06}a.vx*=.985;a.vy*=.985;a.x+=a.vx*D;a.y+=a.vy*D;a.trail.push([a.x,a.y]);if(a.trail.length>36)a.trail.shift();var dist=Math.hypot(target.x-a.x,target.y-a.y);a.fit=1/(1+dist*.01)+Math.max(0,1-life/maxLife)*.05}life++}
function draw(){step();x.fillStyle='rgba(10,10,15,.22)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';x.strokeStyle='rgba(123,44,191,.42)';x.beginPath();x.arc(target.x,target.y,28*D+Math.sin(life*.08)*6*D,0,Math.PI*2);x.stroke();for(var i=0;i<agents.length;i++){var a=agents[i],q=Math.min(1,a.fit);x.strokeStyle=i%2?'rgba(123,44,191,.22)':'rgba(0,245,212,.25)';x.beginPath();for(var j=0;j<a.trail.length;j++){var p=a.trail[j];if(j)x.lineTo(p[0],p[1]);else x.moveTo(p[0],p[1])}x.stroke();x.fillStyle=i%2?'rgba(123,44,191,'+(.22+q*.35)+')':'rgba(0,245,212,'+(.22+q*.38)+')';x.beginPath();x.arc(a.x,a.y,(2+q*3)*D,0,Math.PI*2);x.fill()}x.globalCompositeOperation='source-over';hud();requestAnimationFrame(draw)}
function hud(){hg.textContent=gen;hp.textContent=pop;hf.textContent=best.toFixed(2);hm.textContent=mut.toFixed(2);vp.textContent=pop;vm.textContent=mut.toFixed(2)}
c.addEventListener('pointerdown',function(e){target={x:e.clientX*D,y:e.clientY*D};if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(e.buttons){target={x:e.clientX*D,y:e.clientY*D};if(window.SFX)SFX.play('move',130)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});sp.oninput=function(){pop=+sp.value;make();hud()};sm.oninput=function(){mut=+sm.value/100;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){gen=1;make();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
