<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('sandpile');

$pageName = 'sandpile';
$pageTitle = "SANDPILE - PULSE";
$pageDesc = json_decode('"\u30a2\u30fc\u30d9\u30eb\u7802\u5c71\u30e2\u30c7\u30eb\u306e\u5d29\u58ca\u3068\u81ea\u5df1\u7d44\u7e54\u5316\u81e8\u754c\u3092\u63cf\u304fCanvas\u53ef\u8996\u5316\u3002"');
$pageCanonical = 'https://pulse.subara3.com/sandpile.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">GRAINS <b id="h-grains">0</b><br>TOPPLES <b id="h-topples">0</b><br>RATE <b id="h-rate">60</b><br><span class="sub">VIEW</span> <b id="h-mode">DENSITY</b></div>
<div class="mark">SANDPILE</div>
<?php $interactionHints=[['type'=>'click','label'=>'grain'],['type'=>'drag','label'=>'pour']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="density">DENSITY</button><button class="control-btn" data-mode="avalanche">AVALANCHE</button><button class="control-btn" data-mode="rings">RINGS</button></div><div class="slider-row"><span class="slider-label">RATE</span><input type="range" id="sl-rate" min="1" max="220" value="60"><span class="slider-value" id="sv-rate">60</span></div><div class="slider-row"><span class="slider-label">SIZE</span><input type="range" id="sl-size" min="55" max="115" value="85"><span class="slider-value" id="sv-size">85</span></div><div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,N=85,grid=[],next=[],mode='density',rate=60,run=true,grains=0,topples=0,last=[];
var hg=document.getElementById('h-grains'),ht=document.getElementById('h-topples'),hr=document.getElementById('h-rate'),hm=document.getElementById('h-mode'),sr=document.getElementById('sl-rate'),ss=document.getElementById('sl-size'),vr=document.getElementById('sv-rate'),vs=document.getElementById('sv-size');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}function init(){grid=new Array(N*N).fill(0);next=new Array(N*N).fill(0);grains=0;topples=0;last=[]}function id(i,j){return j*N+i}function add(i,j,n){if(i<1||j<1||i>=N-1||j>=N-1)return;grid[id(i,j)]+=n;grains+=n}
function step(){for(var r=0;r<rate;r++)add(Math.floor(N/2),Math.floor(N/2),1);last=[];var changed=true,guard=0;while(changed&&guard++<60){changed=false;next=grid.slice();for(var j=1;j<N-1;j++)for(var i=1;i<N-1;i++){var k=id(i,j),v=grid[k];if(v>=4){var q=Math.floor(v/4);next[k]-=q*4;next[id(i+1,j)]+=q;next[id(i-1,j)]+=q;next[id(i,j+1)]+=q;next[id(i,j-1)]+=q;topples+=q;changed=true;last.push([i,j,q])}}grid=next}}
function color(v,i,j){if(mode==='avalanche'){return last.some(function(p){return p[0]===i&&p[1]===j})?'rgba(0,245,212,.95)':v?'rgba(123,44,191,.20)':'rgba(10,10,15,.05)'}if(mode==='rings'){var d=Math.hypot(i-N/2,j-N/2),a=(Math.sin(d*.55+v)+1)/2;return v?'rgba('+Math.floor(35+88*a)+','+Math.floor(65+180*a)+','+Math.floor(125+80*(1-a))+',.82)':'rgba(10,10,15,.08)'}return ['rgba(10,10,15,.08)','rgba(0,245,212,.22)','rgba(0,245,212,.52)','rgba(123,44,191,.74)'][Math.min(3,v)]}
function draw(){if(run)step();x.fillStyle='rgba(10,10,15,.32)';x.fillRect(0,0,W,H);var s=Math.min(W,H)*.82/N,ox=(W-s*N)/2,oy=(H-s*N)/2;for(var j=0;j<N;j++)for(var i=0;i<N;i++){var v=grid[id(i,j)];if(v||mode==='rings'){x.fillStyle=color(v,i,j);x.fillRect(ox+i*s,oy+j*s,Math.ceil(s),Math.ceil(s))}}hud();requestAnimationFrame(draw)}function hud(){hg.textContent=grains;ht.textContent=topples;hr.textContent=rate;hm.textContent=mode.toUpperCase();vr.textContent=rate;vs.textContent=N}function place(e,n){var s=Math.min(W,H)*.82/N,ox=(W-s*N)/2,oy=(H-s*N)/2;add(Math.floor((e.clientX*D-ox)/s),Math.floor((e.clientY*D-oy)/s),n);if(window.SFX)SFX.play(n>20?'ripple':'tick',100)}c.addEventListener('pointerdown',function(e){place(e,40)});c.addEventListener('pointermove',function(e){if(e.buttons){place(e,8);if(window.SFX)SFX.play('move',140)}});document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});if(window.SFX)SFX.play('toggle')}});sr.oninput=function(){rate=+sr.value;hud()};ss.oninput=function(){N=+ss.value;init();hud()};document.getElementById('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};document.getElementById('btn-clear').onclick=function(){init();if(window.SFX)SFX.play('success')};addEventListener('resize',resize);resize();init();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
