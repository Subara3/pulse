<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fraction');

$pageName = 'fraction';
$pageTitle = 'FRACTION - PULSE';
$pageDesc = '連分数の幾何学。ユークリッドの互除法が長方形を正方形に分解し、黄金比で完全な螺旋が生まれる。';
$pageCanonical = 'https://pulse.subara3.com/fraction.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>RATIO <span id="hud-ratio">1.618034</span></div>
  <div>CF <span id="hud-cf">[1; 1, 1, 1, ...]</span></div>
  <div>SQUARES <span id="hud-depth">0</span></div>
</div>
<div id="info-label"><span>FRACTION</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'set ratio'],
  ['type' => 'drag',  'label' => 'adjust'],
  ['type' => 'wheel', 'label' => 'depth'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">RATIO</label>
    <input type="range" id="sl-ratio" min="100" max="500" value="162" step="1">
    <span class="slider-value" id="sv-ratio">1.620</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">DEPTH</label>
    <input type="range" id="sl-depth" min="3" max="50" value="20">
    <span class="slider-value" id="sv-depth">20</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="0" max="100" value="30">
    <span class="slider-value" id="sv-speed">30</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPIRAL</label>
    <input type="range" id="sl-spiral" min="0" max="100" value="80">
    <span class="slider-value" id="sv-spiral">80</span>
  </div>
  <div class="btn-row" id="preset-btns">
    <button class="ctrl-btn active" data-val="1.6180">&#x3C6;</button>
    <button class="ctrl-btn" data-val="1.4142">&#x221A;2</button>
    <button class="ctrl-btn" data-val="1.7321">&#x221A;3</button>
    <button class="ctrl-btn" data-val="2.7183">e</button>
    <button class="ctrl-btn" data-val="3.1416">&#x3C0;</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-animate">ANIMATE</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;
var ratio=(1+Math.sqrt(5))/2;
var maxDepth=20;
var animSpeed=30;
var spiralAlpha=0.8;
var animating=true;
var animT=0;
var lastTime=0;

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize',resize);
resize();

function cfTerms(val,n){
  var terms=[],v=Math.abs(val);
  if(v<1e-12)return[0];
  for(var i=0;i<n;i++){
    var a=Math.floor(v);terms.push(a);
    var rem=v-a;if(rem<1e-10)break;
    v=1/rem;if(v>1e12)break;
  }
  return terms;
}

function cfStr(terms){
  if(!terms.length)return'[]';
  var s='['+terms[0]+'; ',r=terms.slice(1);
  return s+(r.length>8?r.slice(0,8).join(', ')+', ...':r.join(', '))+']';
}

function decompose(r,maxSq){
  var tw,th;
  if(r>=1){tw=r;th=1;}else{tw=1;th=1/r;}

  var sqs=[];
  var rx=0,ry=0,rw=tw,rh=th;
  var step=0,total=0;

  while(total<maxSq&&rw>1e-12&&rh>1e-12){
    var horiz=rw>=rh;
    var side=horiz?rh:rw;
    var cnt=Math.floor((horiz?rw:rh)/side);
    if(cnt<1)break;

    for(var j=0;j<cnt&&total<maxSq;j++){
      var sx=horiz?rx+j*side:rx;
      var sy=horiz?ry:ry+j*side;
      sqs.push({x:sx,y:sy,s:side,step:step});
      total++;
    }

    if(horiz){rx+=cnt*side;rw-=cnt*side;}
    else{ry+=cnt*side;rh-=cnt*side;}
    step++;
  }
  return{sqs:sqs,w:tw,h:th};
}

function hsl(h,s,l,a){
  h=((h%360)+360)%360;s/=100;l/=100;
  var c=(1-Math.abs(2*l-1))*s;
  var x2=c*(1-Math.abs((h/60)%2-1));
  var m=l-c/2;var rr,g,b;
  if(h<60){rr=c;g=x2;b=0;}
  else if(h<120){rr=x2;g=c;b=0;}
  else if(h<180){rr=0;g=c;b=x2;}
  else if(h<240){rr=0;g=x2;b=c;}
  else if(h<300){rr=x2;g=0;b=c;}
  else{rr=c;g=0;b=x2;}
  return'rgba('+((rr+m)*255|0)+','+((g+m)*255|0)+','+((b+m)*255|0)+','+a+')';
}

function draw(now){
  requestAnimationFrame(draw);
  var dt=(now-lastTime)/1000;
  if(dt>0.1)dt=0.1;
  lastTime=now;
  if(animating&&animSpeed>0)animT+=dt*animSpeed*0.06;
  var showN=Math.min(animT,maxDepth);

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  var data=decompose(ratio,Math.ceil(showN)+1);
  var sqs=data.sqs;
  if(!sqs.length)return;

  var pad=50;
  var sc=Math.min((W-pad*2)/data.w,(H-pad*2)/data.h);
  var ox=(W-data.w*sc)/2,oy=(H-data.h*sc)/2;

  X.strokeStyle='rgba(0,245,212,0.1)';
  X.lineWidth=1;
  X.strokeRect(ox,oy,data.w*sc,data.h*sc);

  var drawN=Math.min(sqs.length,Math.ceil(showN));
  for(var i=0;i<drawN;i++){
    var sq=sqs[i];
    var px=ox+sq.x*sc,py=oy+sq.y*sc,ps=sq.s*sc;
    if(ps<0.3)continue;
    var fade=i<Math.floor(showN)?1:showN-Math.floor(showN);
    var hu=175+(sq.step*31)%80;
    X.fillStyle=hsl(hu,45,12+sq.step%3*3,0.32*fade);
    X.fillRect(px,py,ps,ps);
    X.strokeStyle=hsl(180+(sq.step*37)%70,50,38,0.25+0.45*fade);
    X.lineWidth=Math.max(0.5,Math.min(2.5,ps/40));
    X.strokeRect(px,py,ps,ps);
    if(ps>20&&fade>0.3){
      X.strokeStyle='rgba(0,245,212,'+(0.04*fade)+')';
      X.lineWidth=0.5;X.beginPath();
      X.moveTo(px,py);X.lineTo(px+ps,py+ps);
      X.moveTo(px+ps,py);X.lineTo(px,py+ps);
      X.stroke();
    }
    if(ps>50&&fade>0.5){
      X.fillStyle='rgba(0,245,212,'+(0.15*fade)+')';
      X.font=Math.max(9,Math.min(13,ps/5))+'px Space Grotesk,monospace';
      X.textAlign='center';X.textBaseline='middle';
      X.fillText(i+1+'',px+ps/2,py+ps/2);
    }
  }

  if(spiralAlpha>0&&drawN>=2)drawSpiral(sqs,drawN,showN,sc,ox,oy);
  drawGlow(sqs,drawN,showN,sc,ox,oy);

  document.getElementById('hud-ratio').textContent=ratio.toFixed(6);
  document.getElementById('hud-cf').textContent=cfStr(cfTerms(ratio,30));
  document.getElementById('hud-depth').textContent=drawN;
}

function getArc(sq,nextSq){
  if(!nextSq)return null;
  var dx=nextSq.x-sq.x,dy=nextSq.y-sq.y;
  var s=sq.s;
  var cx,cy,sa,ea;
  if(Math.abs(dx)>1e-12&&Math.abs(dy)<1e-12){
    if(dx>0){cx=sq.x+s;cy=sq.y+s;sa=Math.PI;ea=Math.PI*1.5;}
    else{cx=sq.x;cy=sq.y;sa=0;ea=Math.PI*0.5;}
  } else if(Math.abs(dy)>1e-12&&Math.abs(dx)<1e-12){
    if(dy>0){cx=sq.x;cy=sq.y+s;sa=Math.PI*1.5;ea=Math.PI*2;}
    else{cx=sq.x+s;cy=sq.y;sa=Math.PI*0.5;ea=Math.PI;}
  } else {
    if(nextSq.x>=sq.x+s*0.5&&nextSq.y>=sq.y+s*0.5){
      cx=sq.x+s;cy=sq.y+s;sa=Math.PI;ea=Math.PI*1.5;
    } else if(nextSq.x<sq.x+s*0.5&&nextSq.y>=sq.y+s*0.5){
      cx=sq.x;cy=sq.y+s;sa=Math.PI*1.5;ea=Math.PI*2;
    } else if(nextSq.x<sq.x+s*0.5&&nextSq.y<sq.y+s*0.5){
      cx=sq.x;cy=sq.y;sa=0;ea=Math.PI*0.5;
    } else {
      cx=sq.x+s;cy=sq.y;sa=Math.PI*0.5;ea=Math.PI;
    }
  }
  return{cx:cx,cy:cy,sa:sa,ea:ea};
}

function drawSpiral(sqs,drawN,showN,sc,ox,oy){
  X.save();
  X.strokeStyle='#00f5d4';
  X.lineWidth=2.5;
  X.globalAlpha=spiralAlpha;
  X.shadowColor='rgba(0,245,212,0.5)';
  X.shadowBlur=8;
  X.beginPath();
  var started=false;
  for(var i=0;i<drawN-1;i++){
    var sq=sqs[i],ns=sqs[i+1];
    var ps=sq.s*sc;
    if(ps<0.5)break;
    var a=getArc(sq,ns);
    if(!a)continue;
    var ccx=ox+a.cx*sc,ccy=oy+a.cy*sc;
    var ea2=a.ea;
    if(i>=Math.floor(showN)-1){
      var f=showN-Math.floor(showN);
      ea2=a.sa+(a.ea-a.sa)*Math.min(f+0.5,1);
    }
    if(!started){
      X.moveTo(ccx+Math.cos(a.sa)*ps,ccy+Math.sin(a.sa)*ps);
      started=true;
    }
    X.arc(ccx,ccy,ps,a.sa,ea2,false);
  }
  X.stroke();
  X.restore();
}

var glowPts=[];
function initGlow(){
  glowPts=[];
  for(var i=0;i<80;i++)
    glowPts.push({t:Math.random()*40,spd:0.12+Math.random()*0.4,a:0.1+Math.random()*0.3});
}
initGlow();

function drawGlow(sqs,drawN,showN,sc,ox,oy){
  if(drawN<3)return;
  var n=drawN-1;
  for(var i=0;i<glowPts.length;i++){
    var p=glowPts[i];
    p.t+=p.spd*0.016;
    var idx=((p.t%n)+n)%n;
    var si=Math.floor(idx),fr=idx-si;
    if(si>=n)si=n-1;
    var sq=sqs[si],ns=sqs[si+1];
    if(!sq||!ns)continue;
    var ps=sq.s*sc;
    if(ps<1)continue;
    var a=getArc(sq,ns);
    if(!a)continue;
    var ccx=ox+a.cx*sc,ccy=oy+a.cy*sc;
    var ang=a.sa+(a.ea-a.sa)*fr;
    var gx=ccx+Math.cos(ang)*ps,gy=ccy+Math.sin(ang)*ps;
    var sz=Math.max(1,Math.min(3,ps/50));
    X.fillStyle='rgba(0,245,212,'+(p.a*spiralAlpha)+')';
    X.beginPath();X.arc(gx,gy,sz,0,Math.PI*2);X.fill();
  }
}

var slRatio=document.getElementById('sl-ratio');
var svRatio=document.getElementById('sv-ratio');
var slDepth=document.getElementById('sl-depth');
var svDepth=document.getElementById('sv-depth');
var slSpeed=document.getElementById('sl-speed');
var svSpeed=document.getElementById('sv-speed');
var slSpiral=document.getElementById('sl-spiral');
var svSpiral=document.getElementById('sv-spiral');

slRatio.addEventListener('input',function(){
  ratio=this.value/100;svRatio.textContent=ratio.toFixed(3);resetAnim();
  if(window.SFX)SFX.play('tick',80);
});
slDepth.addEventListener('input',function(){
  maxDepth=+this.value;svDepth.textContent=maxDepth;
  if(window.SFX)SFX.play('tick',80);
});
slSpeed.addEventListener('input',function(){
  animSpeed=+this.value;svSpeed.textContent=animSpeed;
});
slSpiral.addEventListener('input',function(){
  spiralAlpha=this.value/100;svSpiral.textContent=this.value;
});

document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    ratio=parseFloat(this.dataset.val);
    slRatio.value=Math.round(ratio*100);svRatio.textContent=ratio.toFixed(3);
    resetAnim();if(window.SFX)SFX.play('toggle');
  });
});

document.getElementById('btn-animate').addEventListener('click',function(){
  animating=!animating;
  if(animating&&animT>=maxDepth)animT=0;
  this.classList.toggle('active',animating);
  if(window.SFX)SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  ratio=(1+Math.sqrt(5))/2;maxDepth=20;animSpeed=30;spiralAlpha=0.8;
  slRatio.value=162;svRatio.textContent='1.620';
  slDepth.value=20;svDepth.textContent='20';
  slSpeed.value=30;svSpeed.textContent='30';
  slSpiral.value=80;svSpiral.textContent='80';
  document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
  document.querySelector('#preset-btns .ctrl-btn[data-val="1.6180"]').classList.add('active');
  resetAnim();if(window.SFX)SFX.play('click');
});

function resetAnim(){animT=0;animating=true;document.getElementById('btn-animate').classList.add('active');initGlow();}

C.addEventListener('click',function(e){
  if(e.target!==C)return;
  ratio=1+(e.clientX-C.getBoundingClientRect().left)/W*4;
  slRatio.value=Math.round(ratio*100);svRatio.textContent=ratio.toFixed(3);
  document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
  resetAnim();if(window.SFX)SFX.play('spawn');
});
var drag=false;
C.addEventListener('mousedown',function(e){if(e.button===0&&e.target===C)drag=true;});
C.addEventListener('mousemove',function(e){
  if(!drag)return;ratio=1+e.clientX/W*4;
  slRatio.value=Math.round(ratio*100);svRatio.textContent=ratio.toFixed(3);
  document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
  if(window.SFX)SFX.play('move',100);
});
window.addEventListener('mouseup',function(){drag=false;});
C.addEventListener('wheel',function(e){
  e.preventDefault();
  maxDepth=Math.max(3,Math.min(50,maxDepth+(e.deltaY>0?-1:1)));
  slDepth.value=maxDepth;svDepth.textContent=maxDepth;
  if(window.SFX)SFX.play('tick',60);
},{passive:false});
C.addEventListener('touchstart',function(e){
  if(e.touches.length===1&&e.target===C){
    ratio=1+e.touches[0].clientX/W*4;
    slRatio.value=Math.round(ratio*100);svRatio.textContent=ratio.toFixed(3);
    document.querySelectorAll('#preset-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
    resetAnim();if(window.SFX)SFX.play('spawn');
  }
},{passive:true});
C.addEventListener('touchmove',function(e){
  if(e.touches.length===1){
    ratio=1+e.touches[0].clientX/W*4;
    slRatio.value=Math.round(ratio*100);svRatio.textContent=ratio.toFixed(3);
    if(window.SFX)SFX.play('move',100);
  }
},{passive:true});

resetAnim();
requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
