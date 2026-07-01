<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('primes');

$pageName = 'primes';
$pageTitle = 'PRIMES - PULSE';
$pageDesc = 'Prime gap rings: an interactive Canvas view of spacing, records, and residue classes between primes.';
$pageCanonical = 'https://pulse.subara3.com/primes.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.readout{position:fixed;right:1rem;bottom:1.2rem;z-index:55;color:rgba(255,255,255,.32);font-family:"JetBrains Mono",monospace;font-size:.58rem;line-height:1.7;text-align:right;pointer-events:none}.readout b{color:#7b2cbf}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}.readout{right:.75rem;bottom:4.4rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  FIELD <b id="h-mode">GAPS</b><br>
  LIMIT <b id="h-limit">60000</b><br>
  PRIME <b id="h-prime">2</b><br>
  <span class="sub">GAP</span> <b id="h-gap">1</b>
</div>
<div class="readout">
  INDEX <b id="r-index">0</b><br>
  RECORD <b id="r-record">0</b><br>
  CLASS <b id="r-class">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'mark gap'],
  ['type' => 'drag', 'label' => 'scrub index'],
  ['type' => 'wheel', 'label' => 'zoom field'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="gaps">GAPS</button>
      <button class="control-btn" data-mode="records">RECORDS</button>
      <button class="control-btn" data-mode="wheel">WHEEL</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">LIMIT</span>
      <input type="range" id="sl-limit" min="12000" max="180000" value="60000" step="6000">
      <span class="slider-value" id="sv-limit">60000</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">PACE</span>
      <input type="range" id="sl-pace" min="0" max="100" value="45">
      <span class="slider-value" id="sv-pace">45</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-run">RUN</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W=0,H=0,D=1,limit=60000,mode='gaps',pace=.45,run=true,zoom=1,t=0,focus=40;
var primes=[],gaps=[],records=[],pulses=[];
var hMode=document.getElementById('h-mode'),hLimit=document.getElementById('h-limit'),hPrime=document.getElementById('h-prime'),hGap=document.getElementById('h-gap');
var rIndex=document.getElementById('r-index'),rRecord=document.getElementById('r-record'),rClass=document.getElementById('r-class');
var slLimit=document.getElementById('sl-limit'),slPace=document.getElementById('sl-pace'),svLimit=document.getElementById('sv-limit'),svPace=document.getElementById('sv-pace');
function resize(){D=Math.min(2,devicePixelRatio||1);W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);C.style.width=innerWidth+'px';C.style.height=innerHeight+'px'}
function sieve(){var a=new Uint8Array(limit+1);a[0]=1;a[1]=1;for(var i=2;i*i<=limit;i++)if(!a[i])for(var j=i*i;j<=limit;j+=i)a[j]=1;primes=[];for(var n=2;n<=limit;n++)if(!a[n])primes.push(n);gaps=[];records=[];var rec=0;for(var k=1;k<primes.length;k++){var g=primes[k]-primes[k-1];if(g>rec){rec=g;records.push(k)}gaps.push({p:primes[k],prev:primes[k-1],g:g,rec:rec,record:g===rec,cls:primes[k]%30})}focus=Math.min(focus,gaps.length-1);updateHud()}
function updateHud(){var q=gaps[Math.max(0,Math.min(gaps.length-1,Math.floor(focus)))]||{p:2,g:1,rec:0,cls:2};hMode.textContent=mode.toUpperCase();hLimit.textContent=limit;hPrime.textContent=q.p;hGap.textContent=q.g;rIndex.textContent=Math.floor(focus);rRecord.textContent=q.rec;rClass.textContent=q.cls;svLimit.textContent=limit;svPace.textContent=Math.round(pace*100)}
function colorFor(q,a){if(mode==='records'&&q.record)return 'rgba(255,255,255,'+a+')';if(mode==='wheel'){var m=q.cls%5;return m===1?'rgba(0,245,212,'+a+')':m===2?'rgba(123,44,191,'+a+')':m===3?'rgba(122,220,255,'+a+')':'rgba(180,140,255,'+a+')'}return q.g>q.rec*.72?'rgba(123,44,191,'+a+')':'rgba(0,245,212,'+a+')'}
function drawRings(cx,cy,R){var maxGap=Math.max(6,gaps[gaps.length-1]?gaps[gaps.length-1].rec:6);var span=Math.min(gaps.length,1600);var start=Math.max(0,Math.floor(focus-span*.42));var end=Math.min(gaps.length,start+span);X.globalCompositeOperation='lighter';for(var i=start;i<end;i++){var q=gaps[i],u=(i-start)/Math.max(1,end-start),ang=u*Math.PI*2+t*.06;var band=R*(.18+.76*u);var amp=(q.g/maxGap)*R*.18*zoom;var rr=band+amp*Math.sin(ang*3+q.g*.13);var x=cx+Math.cos(ang)*rr,y=cy+Math.sin(ang)*rr;var near=1-Math.min(1,Math.abs(i-focus)/220);var rad=(1.15+near*3.2+q.g/maxGap*2.4)*D;X.fillStyle=colorFor(q,.16+near*.55+q.g/maxGap*.25);X.beginPath();X.arc(x,y,rad,0,Math.PI*2);X.fill();if(mode==='records'&&q.record){X.strokeStyle='rgba(255,255,255,.38)';X.lineWidth=1*D;X.beginPath();X.arc(cx,cy,rr,0,Math.PI*2);X.stroke()}}
}
function drawTrace(cx,cy,R){X.globalCompositeOperation='source-over';X.strokeStyle='rgba(0,245,212,.08)';X.lineWidth=1*D;for(var r=0;r<5;r++){X.beginPath();X.arc(cx,cy,R*(.2+r*.16),0,Math.PI*2);X.stroke()}X.globalCompositeOperation='lighter';X.lineWidth=1.2*D;X.beginPath();var start=Math.max(0,Math.floor(focus-420));var end=Math.min(gaps.length,start+840);for(var i=start;i<end;i++){var q=gaps[i],u=(i-start)/Math.max(1,end-start);var x=cx-R+u*R*2;var y=cy+R*.38-(q.g/Math.max(8,q.rec))*R*.76;if(i===start)X.moveTo(x,y);else X.lineTo(x,y)}X.strokeStyle='rgba(0,245,212,.55)';X.stroke();X.beginPath();for(var j=0;j<records.length;j++){var ri=records[j];if(ri<start||ri>=end)continue;var qq=gaps[ri],uu=(ri-start)/Math.max(1,end-start);var xx=cx-R+uu*R*2;var yy=cy+R*.38-(qq.g/Math.max(8,qq.rec))*R*.76;X.moveTo(xx,cy+R*.42);X.lineTo(xx,yy)}X.strokeStyle='rgba(123,44,191,.56)';X.stroke()}
function drawPulses(cx,cy){for(var i=pulses.length-1;i>=0;i--){var p=pulses[i];p.a+=.025;X.strokeStyle='rgba(0,245,212,'+(1-p.a)+')';X.lineWidth=(2.5-p.a)*D;X.beginPath();X.arc(p.x,p.y,p.a*160*D,0,Math.PI*2);X.stroke();if(p.a>=1)pulses.splice(i,1)}}
function draw(){if(run){focus=(focus+1+pace*9)%Math.max(1,gaps.length);t+=.016+.04*pace}else t+=.004;X.fillStyle='rgba(10,10,15,.42)';X.fillRect(0,0,W,H);var cx=W/2,cy=H/2,R=Math.min(W,H)*.42;X.save();drawTrace(cx,cy,R);drawRings(cx,cy,R);drawPulses(cx,cy);X.restore();updateHud();requestAnimationFrame(draw)}
function scrub(clientX){focus=Math.max(0,Math.min(gaps.length-1,(clientX/Math.max(1,innerWidth))*gaps.length));updateHud()}
var down=false;C.addEventListener('pointerdown',function(e){down=true;scrub(e.clientX);pulses.push({x:e.clientX*D,y:e.clientY*D,a:0});if(typeof SFX!=='undefined')SFX.play('click')});window.addEventListener('pointermove',function(e){if(down){scrub(e.clientX);if(typeof SFX!=='undefined'&&Math.random()<.08)SFX.play('move',80)}});window.addEventListener('pointerup',function(){down=false});C.addEventListener('wheel',function(e){e.preventDefault();zoom*=e.deltaY<0?1.08:.92;zoom=Math.max(.45,Math.min(2.8,zoom));if(typeof SFX!=='undefined')SFX.play('tick',90)},{passive:false});
document.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;document.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('active',x===b)});if(typeof SFX!=='undefined')SFX.play('toggle');updateHud()})});
slLimit.addEventListener('input',function(){limit=parseInt(slLimit.value,10);sieve();if(typeof SFX!=='undefined')SFX.play('tick',80)});slPace.addEventListener('input',function(){pace=parseInt(slPace.value,10)/100;updateHud();if(typeof SFX!=='undefined')SFX.play('move',80)});
document.getElementById('btn-run').addEventListener('click',function(){run=!run;this.classList.toggle('active',run);if(typeof SFX!=='undefined')SFX.play('toggle')});document.getElementById('btn-reset').addEventListener('click',function(){focus=0;t=0;zoom=1;pulses.length=0;if(typeof SFX!=='undefined')SFX.play('success')});
addEventListener('resize',resize);resize();sieve();draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
