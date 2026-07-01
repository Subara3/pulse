<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pendulum');

$pageName = 'pendulum';
$pageTitle = 'PENDULUM - PULSE';
$pageDesc = json_decode('"\u5358\u632f\u308a\u5b50\u3001\u4e8c\u91cd\u632f\u308a\u5b50\u3001\u4f4d\u76f8\u306e\u8ecc\u8de1\u3092\u64cd\u4f5c\u3059\u308bCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/pendulum.php';
$extraStyle = '\nbody{background:#0a0a0f;min-height:100vh;overflow:hidden}\n#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}\n.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}\n.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}\n.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}\n.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}\n@media(max-width:760px){.hud{top:.75rem;right:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}}\n';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">MODE <b id="h-mode">CHAIN</b><br>GRAVITY <b id="h-g">0.80</b><br>DAMP <b id="h-d">0.998</b><br><span class="sub">ENERGY</span> <b id="h-e">0.00</b></div>
<div class="mark">PENDULUM</div>
<?php $interactionHints=[['type'=>'drag','label'=>'pull'],['type'=>'click','label'=>'kick']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" data-mode="chain">CHAIN</button><button class="control-btn" data-mode="double">DOUBLE</button><button class="control-btn" data-mode="phase">PHASE</button></div>
  <div class="slider-row"><span class="slider-label">GRAVITY</span><input type="range" id="sl-g" min="20" max="160" value="80"><span class="slider-value" id="sv-g">0.80</span></div>
  <div class="slider-row"><span class="slider-label">DAMP</span><input type="range" id="sl-d" min="990" max="1000" value="998"><span class="slider-value" id="sv-d">0.998</span></div>
  <div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='chain',g=.8,damp=.998,run=true,drag=null,trail=[];
var hm=document.getElementById('h-mode'),hg=document.getElementById('h-g'),hd=document.getElementById('h-d'),he=document.getElementById('h-e'),sg=document.getElementById('sl-g'),sd=document.getElementById('sl-d'),vg=document.getElementById('sv-g'),vd=document.getElementById('sv-d');
var ps=[];function reset(){ps=[];for(var i=0;i<7;i++)ps.push({a:-.75+i*.25,v:0,l:Math.min(W,H)*(.16+i*.012),m:1+i*.08});trail=[];hud()}
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';reset()}
function step(){if(!run)return;for(var i=0;i<ps.length;i++){var p=ps[i];p.v+=-(g/60)*Math.sin(p.a)/p.m;p.v*=damp;p.a+=p.v}if(mode==='double'){var p0=ps[0],p1=ps[1];p1.a+=Math.sin(p0.a-p1.a)*.002;p0.v-=Math.sin(p0.a-p1.a)*.0015}}
function drawPend(originX,originY,p,col){var bx=originX+Math.sin(p.a)*p.l,by=originY+Math.cos(p.a)*p.l;x.strokeStyle=col;x.lineWidth=1.3*D;x.beginPath();x.moveTo(originX,originY);x.lineTo(bx,by);x.stroke();x.fillStyle=col;x.beginPath();x.arc(bx,by,7*D*p.m,0,Math.PI*2);x.fill();return [bx,by]}
function draw(){step();x.fillStyle='rgba(10,10,15,.22)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var energy=0;if(mode==='phase'){for(var i=0;i<ps.length;i++){var p=ps[i],px=W/2+p.a*W*.18,py=H/2+p.v*H*5;trail.push([px,py,i]);energy+=Math.abs(p.v)+Math.abs(1-Math.cos(p.a));}while(trail.length>900)trail.shift();for(var j=1;j<trail.length;j++){var a=trail[j-1],b=trail[j];if(a[2]!==b[2])continue;x.strokeStyle=b[2]%2?'rgba(123,44,191,.32)':'rgba(0,245,212,.35)';x.beginPath();x.moveTo(a[0],a[1]);x.lineTo(b[0],b[1]);x.stroke()}}else if(mode==='double'){var o=[W/2,H*.22],p0=ps[0],p1=ps[1],b=drawPend(o[0],o[1],p0,'rgba(0,245,212,.72)');p1.l=Math.min(W,H)*.24;var b2=drawPend(b[0],b[1],p1,'rgba(123,44,191,.72)');trail.push(b2);while(trail.length>420)trail.shift();x.strokeStyle='rgba(0,245,212,.18)';x.beginPath();for(var k=0;k<trail.length;k++){if(k)x.lineTo(trail[k][0],trail[k][1]);else x.moveTo(trail[k][0],trail[k][1])}x.stroke();energy=Math.abs(p0.v)+Math.abs(p1.v)+Math.abs(2-Math.cos(p0.a)-Math.cos(p1.a))}else{for(var n=0;n<ps.length;n++){var ox=W*(.2+n*.1),oy=H*.25;drawPend(ox,oy,ps[n],n%2?'rgba(123,44,191,.68)':'rgba(0,245,212,.72)');energy+=Math.abs(ps[n].v)+Math.abs(1-Math.cos(ps[n].a))}}x.globalCompositeOperation='source-over';he.textContent=energy.toFixed(2);requestAnimationFrame(draw)}
function hud(){hm.textContent=mode.toUpperCase();hg.textContent=g.toFixed(2);hd.textContent=damp.toFixed(3);vg.textContent=g.toFixed(2);vd.textContent=damp.toFixed(3)}
c.addEventListener('pointerdown',function(e){drag={x:e.clientX,y:e.clientY};for(var i=0;i<ps.length;i++)ps[i].v+=(Math.random()-.5)*.08;if(window.SFX)SFX.play('ripple',100)});c.addEventListener('pointermove',function(e){if(!drag||!e.buttons)return;var dx=(e.clientX-drag.x)/innerWidth,dy=(e.clientY-drag.y)/innerHeight;for(var i=0;i<ps.length;i++){ps[i].a+=dx*(1+i*.08);ps[i].v+=dy*.08}drag={x:e.clientX,y:e.clientY};trail=[];if(window.SFX)SFX.play('move',130)});c.addEventListener('pointerup',function(){drag=null});
document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});trail=[];hud();if(window.SFX)SFX.play('toggle')}});sg.oninput=function(){g=+sg.value/100;hud()};sd.oninput=function(){damp=+sd.value/1000;hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-reset').onclick=function(){reset();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
