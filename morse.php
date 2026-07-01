<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('morse');

$pageName = 'morse';
$pageTitle = "MORSE - PULSE";
$pageDesc = json_decode('"\u30e2\u30fc\u30eb\u30b9\u4fe1\u53f7\u3092\u3001\u6642\u9593\u6ce2\u5f62\u3068\u30b9\u30da\u30af\u30c8\u30eb\u3068\u3057\u3066\u6253\u3064Canvas\u30c8\u30ec\u30fc\u30ca\u30fc\u3002"');
$pageCanonical = 'https://pulse.subara3.com/morse.php';
$extraStyle = 'body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}.hud{position:fixed;top:1rem;right:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:right;letter-spacing:.08em;background:rgba(10,10,15,.50);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}.hud b{color:#00f5d4}.hud .sub{color:rgba(123,44,191,.9)}.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.22em;color:rgba(255,255,255,.18)}.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}.slider-row{display:grid;grid-template-columns:78px 1fr 54px;gap:.65rem;align-items:center}.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}.slider-value{text-align:right;color:#00f5d4}input[type=range]{width:100%;accent-color:#00f5d4}@media(max-width:760px){.hud{top:.7rem;right:.7rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:64px 1fr 42px}}';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">TARGET <b id="h-target">A</b><br>INPUT <b id="h-input">...</b><br>SCORE <b id="h-score">0</b><br><span class="sub">MODE</span> <b id="h-mode">TRAIN</b></div>
<div class="mark">MORSE</div>
<?php $interactionHints=[['type'=>'click','label'=>'dot'],['type'=>'drag','label'=>'dash'],['type'=>'key','label'=>'space']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls"><div class="btn-row"><button class="control-btn active" data-mode="train">TRAIN</button><button class="control-btn" data-mode="listen">LISTEN</button><button class="control-btn" data-mode="free">FREE</button></div><div class="slider-row"><span class="slider-label">TEMPO</span><input type="range" id="sl-tempo" min="50" max="180" value="100"><span class="slider-value" id="sv-tempo">100</span></div><div class="slider-row"><span class="slider-label">DECAY</span><input type="range" id="sl-decay" min="30" max="95" value="70"><span class="slider-value" id="sv-decay">0.70</span></div><div class="btn-row"><button class="control-btn" id="btn-dash">DASH</button><button class="control-btn" id="btn-clear">CLEAR</button></div></div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var c=document.getElementById('canvas'),x=c.getContext('2d'),W,H,D=1,mode='train',tempo=100,decay=.7,target='A',input='',score=0,t=0,signals=[];
var code={A:'.-',B:'-...',C:'-.-.',D:'-..',E:'.',F:'..-.',G:'--.',H:'....',I:'..',J:'.---',K:'-.-',L:'.-..',M:'--',N:'-.',O:'---',P:'.--.',Q:'--.-',R:'.-.',S:'...',T:'-',U:'..-',V:'...-',W:'.--',X:'-..-',Y:'-.--',Z:'--..'};
var letters=Object.keys(code),ht=document.getElementById('h-target'),hi=document.getElementById('h-input'),hs=document.getElementById('h-score'),hm=document.getElementById('h-mode'),st=document.getElementById('sl-tempo'),sd=document.getElementById('sl-decay'),vt=document.getElementById('sv-tempo'),vd=document.getElementById('sv-decay');
function resize(){D=Math.min(2,devicePixelRatio||1);W=c.width=Math.floor(innerWidth*D);H=c.height=Math.floor(innerHeight*D);c.style.width=innerWidth+'px';c.style.height=innerHeight+'px'}function next(){target=letters[Math.floor(Math.random()*letters.length)];input='';hud()}
function pulse(kind){signals.push({kind:kind,t:0,x:W*.15+signals.length*18*D});input+=kind;if(window.SFX)SFX.play(kind==='.'?'tick':'ripple',110);if(mode==='train'&&input.length>=code[target].length){if(input===code[target]){score++;if(window.SFX)SFX.play('success');next()}else{score=Math.max(0,score-1);input='';if(window.SFX)SFX.play('toggle')}}hud()}
function draw(){t+=.016;x.fillStyle='rgba(10,10,15,.2)';x.fillRect(0,0,W,H);x.globalCompositeOperation='lighter';var base=H*.56,amp=H*.16;x.strokeStyle='rgba(0,245,212,.22)';x.beginPath();for(var i=0;i<W;i+=4*D){var y=base+Math.sin(i*.014+t*3)*12*D;x[i?'lineTo':'moveTo'](i,y)}x.stroke();for(var s=signals.length-1;s>=0;s--){var o=signals[s];o.t+=.018*tempo/100;var life=1-o.t;if(life<=0){signals.splice(s,1);continue}var w=(o.kind==='-'?90:34)*D,h=amp*(o.kind==='-'?1:.42)*life;x.fillStyle=o.kind==='-'?'rgba(123,44,191,'+(.22+life*.45)+')':'rgba(0,245,212,'+(.24+life*.52)+')';x.fillRect(o.x+o.t*W*.62,base-h/2,w,h);x.strokeStyle='rgba(0,245,212,'+(.1+life*.22)+')';x.beginPath();x.arc(o.x+o.t*W*.62,base,life*180*D,0,Math.PI*2);x.stroke()}x.globalCompositeOperation='source-over';requestAnimationFrame(draw)}
function hud(){ht.textContent=mode==='free'?'FREE':target+' '+code[target];hi.textContent=input||'...';hs.textContent=score;hm.textContent=mode.toUpperCase();vt.textContent=tempo;vd.textContent=decay.toFixed(2)}
c.addEventListener('pointerdown',function(e){pulse(e.shiftKey?'-':'.')});c.addEventListener('pointermove',function(e){if(e.buttons&&Math.abs(e.movementX)+Math.abs(e.movementY)>18)pulse('-')});addEventListener('keydown',function(e){if(e.code==='Space'){e.preventDefault();pulse(e.shiftKey?'-':'.')}});document.getElementById('btn-dash').onclick=function(){pulse('-')};document.getElementById('btn-clear').onclick=function(){input='';hud();if(window.SFX)SFX.play('toggle')};document.querySelectorAll('[data-mode]').forEach(function(b){b.onclick=function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(q){q.classList.toggle('active',q===b)});input='';hud();if(window.SFX)SFX.play('toggle')}});st.oninput=function(){tempo=+st.value;hud()};sd.oninput=function(){decay=+sd.value/100;hud()};addEventListener('resize',resize);resize();next();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
