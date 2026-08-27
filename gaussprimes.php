<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gaussprimes');

$pageName = 'gaussprimes';
$pageTitle = 'GAUSSIAN PRIMES - PULSE';
$pageDesc = 'ガウス整数の素数を複素平面に描く。四重対称のパターンが数論の深淵を映し出す。';
$pageCanonical = 'https://pulse.subara3.com/gaussprimes.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(183,140,255,.85)}
.info{position:fixed;bottom:5.5rem;left:1rem;z-index:20;font:11px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.06em;background:rgba(10,10,15,.58);border:1px solid rgba(0,245,212,.12);padding:.55rem .75rem;border-radius:6px;backdrop-filter:blur(12px);max-width:280px;opacity:0;transition:opacity .3s}
.info.show{opacity:1}.info b{color:#00f5d4}.info .sub{color:rgba(183,140,255,.85)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.info{bottom:4.5rem;left:.75rem;font-size:10px}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">GAUSSIAN PRIMES</span><br>SCALE <b id="h-scale">20</b> px/u<br>VISIBLE <b id="h-count">0</b> primes<br>CENTER <b id="h-center">0, 0</b></div>
<div class="info" id="info"></div>
<div class="mark">GAUSSIAN PRIMES</div>
<?php $interactionHints=[['type'=>'click','label'=>'inspect'],['type'=>'drag','label'=>'pan'],['type'=>'scroll','label'=>'zoom']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row"><button class="control-btn active" id="btn-explore">EXPLORE</button><button class="control-btn" id="btn-sieve">SIEVE</button></div>
  <div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="20" value="5"><span class="slider-value" id="sv-speed">5</span></div>
  <div class="btn-row"><button class="control-btn" id="btn-norms">NORMS</button><button class="control-btn" id="btn-grid">GRID</button></div>
  <div class="btn-row"><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d'),W=0,H=0,D=1;
var cx=0,cy=0,scale=20,mode='explore',showNorms=false,showGrid=false;
var drag=null,pinch0=0,selected=null,sieveR=0,sieveActive=false,speed=5;
var hScale=document.getElementById('h-scale'),hCount=document.getElementById('h-count');
var hCenter=document.getElementById('h-center'),info=document.getElementById('info');
var slSpeed=document.getElementById('sl-speed'),svSpeed=document.getElementById('sv-speed');

var MAX_SIEVE=200000;
var sieve=new Uint8Array(MAX_SIEVE+1);
sieve[0]=sieve[1]=1;
for(var i=2;i*i<=MAX_SIEVE;i++){if(!sieve[i])for(var j=i*i;j<=MAX_SIEVE;j+=i)sieve[j]=1;}

function isPrime(n){
  if(n<2)return false;
  if(n<=MAX_SIEVE)return !sieve[n];
  if(n%2===0)return false;
  for(var k=3;k*k<=n;k+=2)if(n%k===0)return false;
  return true;
}

function isGP(a,b){
  if(a===0&&b===0)return false;
  var aa=a<0?-a:a,ab=b<0?-b:b;
  if(ab===0)return aa>1&&isPrime(aa)&&aa%4===3;
  if(aa===0)return ab>1&&isPrime(ab)&&ab%4===3;
  return isPrime(a*a+b*b);
}

function brandHue(t){
  t=t<0?0:t>1?1:t;
  var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);
  return 172+e*118;
}

function resize(){
  D=Math.min(2,devicePixelRatio||1);
  W=c.width=Math.floor(innerWidth*D);
  H=c.height=Math.floor(innerHeight*D);
  c.style.width=innerWidth+'px';
  c.style.height=innerHeight+'px';
}
resize();
window.addEventListener('resize',resize);

function toScreen(a,b){
  return[(a-cx)*scale*D+W/2, H/2-(b-cy)*scale*D];
}
function toWorld(sx,sy){
  return[(sx-W/2)/(scale*D)+cx, (H/2-sy)/(scale*D)+cy];
}

function drawGlow(x,y,r,alpha){
  var g=ctx.createRadialGradient(x,y,0,x,y,r);
  g.addColorStop(0,'rgba(0,245,212,'+alpha+')');
  g.addColorStop(0.4,'rgba(0,245,212,'+(alpha*0.5)+')');
  g.addColorStop(1,'rgba(0,245,212,0)');
  ctx.fillStyle=g;
  ctx.fillRect(x-r,y-r,r*2,r*2);
}

var primeCount=0;
function render(){
  ctx.fillStyle='#0a0a0f';
  ctx.fillRect(0,0,W,H);

  var pixPerUnit=scale*D;
  var halfW=W/2,halfH=H/2;
  var minA=Math.floor(-halfW/pixPerUnit+cx);
  var maxA=Math.ceil(halfW/pixPerUnit+cx);
  var minB=Math.floor(-halfH/pixPerUnit+cy);
  var maxB=Math.ceil(halfH/pixPerUnit+cy);

  var totalPts=(maxA-minA+1)*(maxB-minB+1);
  var drawComposites=totalPts<40000&&showGrid;
  var primeR=Math.max(2.5*D,pixPerUnit*0.18);
  var compR=Math.max(0.8*D,pixPerUnit*0.04);

  if(showNorms&&pixPerUnit>4){
    ctx.strokeStyle='rgba(123,44,191,0.12)';
    ctx.lineWidth=0.5*D;
    var maxNormVis=(maxA-cx)*(maxA-cx)+(maxB-cy)*(maxB-cy);
    var maxNR=Math.ceil(Math.sqrt(maxNormVis));
    var o=toScreen(0,0);
    for(var nr=1;nr<=maxNR&&nr<300;nr++){
      var rr=nr*pixPerUnit;
      if(rr<3)continue;
      if(isPrime(nr)){
        ctx.strokeStyle='rgba(123,44,191,0.22)';
      }else{
        ctx.strokeStyle='rgba(123,44,191,0.07)';
      }
      ctx.beginPath();
      ctx.arc(o[0],o[1],rr,0,Math.PI*2);
      ctx.stroke();
    }
  }

  if(pixPerUnit>2){
    var o2=toScreen(0,0);
    ctx.strokeStyle='rgba(255,255,255,0.06)';
    ctx.lineWidth=0.5*D;
    ctx.beginPath();ctx.moveTo(0,o2[1]);ctx.lineTo(W,o2[1]);ctx.stroke();
    ctx.beginPath();ctx.moveTo(o2[0],0);ctx.lineTo(o2[0],H);ctx.stroke();
  }

  primeCount=0;
  var sieveR2=sieveR*sieveR;

  for(var a=minA;a<=maxA;a++){
    for(var b=minB;b<=maxB;b++){
      var s=toScreen(a,b);
      var sx=s[0],sy=s[1];
      if(sx<-primeR*3||sx>W+primeR*3||sy<-primeR*3||sy>H+primeR*3)continue;

      if(sieveActive){
        var d2=a*a+b*b;
        if(d2>sieveR2)continue;
      }

      if(isGP(a,b)){
        primeCount++;
        var norm=a*a+b*b;
        var t=norm<2?0:Math.min(1,Math.log(norm)/Math.log(5000));
        var hue=brandHue(t);
        var light=55+15*Math.sin(t*4);

        if(pixPerUnit>6){
          drawGlow(sx,sy,primeR*2.5,0.15);
        }
        ctx.fillStyle='hsl('+hue+',70%,'+light+'%)';
        ctx.beginPath();
        ctx.arc(sx,sy,primeR,0,Math.PI*2);
        ctx.fill();

        if(pixPerUnit>12&&primeR>3*D){
          ctx.fillStyle='rgba(255,255,255,0.7)';
          ctx.beginPath();
          ctx.arc(sx-primeR*0.3,sy-primeR*0.3,primeR*0.2,0,Math.PI*2);
          ctx.fill();
        }
      }else if(drawComposites&&!(a===0&&b===0)){
        ctx.fillStyle='rgba(255,255,255,0.08)';
        ctx.beginPath();
        ctx.arc(sx,sy,compR,0,Math.PI*2);
        ctx.fill();
      }
    }
  }

  if(selected){
    var ss=toScreen(selected[0],selected[1]);
    ctx.strokeStyle='#00f5d4';
    ctx.lineWidth=1.5*D;
    ctx.beginPath();
    ctx.arc(ss[0],ss[1],primeR+4*D,0,Math.PI*2);
    ctx.stroke();

    var o3=toScreen(0,0);
    ctx.strokeStyle='rgba(0,245,212,0.2)';
    ctx.lineWidth=0.5*D;
    ctx.setLineDash([4*D,4*D]);
    ctx.beginPath();ctx.moveTo(o3[0],o3[1]);ctx.lineTo(ss[0],ss[1]);ctx.stroke();
    ctx.setLineDash([]);

    var nr2=Math.sqrt(selected[0]*selected[0]+selected[1]*selected[1]);
    ctx.strokeStyle='rgba(0,245,212,0.15)';
    ctx.lineWidth=0.5*D;
    ctx.beginPath();
    ctx.arc(o3[0],o3[1],nr2*pixPerUnit,0,Math.PI*2);
    ctx.stroke();
  }

  hScale.textContent=scale.toFixed(scale<10?1:0);
  hCount.textContent=primeCount;
  hCenter.textContent=cx.toFixed(1)+', '+cy.toFixed(1);

  if(sieveActive){
    sieveR+=speed*0.05;
    if(sieveR>Math.max(maxA-cx,maxB-cy,cx-minA,cy-minB)+10){
      sieveActive=false;
    }
  }

  requestAnimationFrame(render);
}

function showInfo(a,b){
  selected=[a,b];
  var norm=a*a+b*b;
  var gp=isGP(a,b);
  var bSign=b>=0?'+':'';
  var html='<b>'+a+bSign+b+'i</b><br>';
  html+='NORM <b>'+norm+'</b>';
  if(norm>0)html+=' = '+a+'²+'+Math.abs(b)+'²';
  html+='<br>';
  if(a===0&&b===0){
    html+='<span class="sub">ZERO</span>';
  }else if((a===1||a===-1)&&b===0||(b===1||b===-1)&&a===0){
    html+='<span class="sub">UNIT</span>';
  }else if(gp){
    html+='<span class="sub">GAUSSIAN PRIME</span><br>';
    if(a===0||b===0){
      var p=Math.abs(a||b);
      html+=p+' ≡ 3 (mod 4)';
    }else{
      html+=norm+' is prime in ℤ';
    }
  }else{
    html+='<span class="sub">COMPOSITE</span>';
    if(a===0||b===0){
      var p2=Math.abs(a||b);
      if(p2>1&&isPrime(p2)&&p2%4===1){
        html+='<br>'+p2+' ≡ 1 (mod 4) → splits';
      }
    }
  }
  info.innerHTML=html;
  info.classList.add('show');
}

function hideInfo(){
  selected=null;
  info.classList.remove('show');
}

c.addEventListener('pointerdown',function(e){
  if(e.button===2)return;
  drag={x:e.clientX,y:e.clientY,cx:cx,cy:cy,moved:false};
  c.setPointerCapture(e.pointerId);
});
c.addEventListener('pointermove',function(e){
  if(!drag)return;
  var dx=e.clientX-drag.x,dy=e.clientY-drag.y;
  if(Math.abs(dx)+Math.abs(dy)>3)drag.moved=true;
  cx=drag.cx-dx/(scale*D);
  cy=drag.cy+dy/(scale*D);
});
c.addEventListener('pointerup',function(e){
  if(drag&&!drag.moved){
    var w=toWorld(e.clientX*D,e.clientY*D);
    var a=Math.round(w[0]),b=Math.round(w[1]);
    showInfo(a,b);
    SFX.play(isGP(a,b)?'spawn':'tick');
  }
  drag=null;
});

c.addEventListener('wheel',function(e){
  e.preventDefault();
  var f=e.deltaY>0?0.85:1/0.85;
  var w=toWorld(e.clientX*D,e.clientY*D);
  scale*=f;
  scale=Math.max(2,Math.min(200,scale));
  cx=w[0]-(e.clientX*D-W/2)/(scale*D);
  cy=w[1]+(e.clientY*D-H/2)/(scale*D);
},{passive:false});

var lastDist=0;
c.addEventListener('touchstart',function(e){
  if(e.touches.length===2){
    var dx=e.touches[0].clientX-e.touches[1].clientX;
    var dy=e.touches[0].clientY-e.touches[1].clientY;
    lastDist=Math.sqrt(dx*dx+dy*dy);
  }
},{passive:true});
c.addEventListener('touchmove',function(e){
  if(e.touches.length===2){
    var dx=e.touches[0].clientX-e.touches[1].clientX;
    var dy=e.touches[0].clientY-e.touches[1].clientY;
    var dist=Math.sqrt(dx*dx+dy*dy);
    if(lastDist>0){
      var f=dist/lastDist;
      scale*=f;
      scale=Math.max(2,Math.min(200,scale));
    }
    lastDist=dist;
  }
},{passive:true});

document.getElementById('btn-explore').addEventListener('click',function(){
  mode='explore';sieveActive=false;
  this.classList.add('active');
  document.getElementById('btn-sieve').classList.remove('active');
  SFX.play('toggle');
});
document.getElementById('btn-sieve').addEventListener('click',function(){
  mode='sieve';sieveActive=true;sieveR=0;
  this.classList.add('active');
  document.getElementById('btn-explore').classList.remove('active');
  SFX.play('toggle');
});
document.getElementById('btn-norms').addEventListener('click',function(){
  showNorms=!showNorms;
  this.classList.toggle('active',showNorms);
  SFX.play('toggle');
});
document.getElementById('btn-grid').addEventListener('click',function(){
  showGrid=!showGrid;
  this.classList.toggle('active',showGrid);
  SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){
  cx=0;cy=0;scale=20;sieveR=0;sieveActive=mode==='sieve';
  hideInfo();
  SFX.play('click');
});
slSpeed.addEventListener('input',function(){
  speed=+this.value;svSpeed.textContent=speed;
});

render();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
