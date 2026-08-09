<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('wallpaper');

$pageName = 'wallpaper';
$pageTitle = 'WALLPAPER - PULSE';
$pageDesc = '壁紙群。平面を埋め尽くす対称性パターン。粒子の軌跡が回転・鏡映・並進で無限に繰り返され、結晶学的秩序が創発する。';
$pageCanonical = 'https://pulse.subara3.com/wallpaper.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>GROUP <span id="hud-grp">p4m</span></div>
  <div>COPIES <span id="hud-ops">8</span></div>
  <div>SOURCES <span id="hud-cnt">5</span></div>
</div>
<div id="info-label"><span>WALLPAPER</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'source'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">GROUP</label></div>
  <div class="btn-row" id="grp-btns">
    <button class="ctrl-btn" data-val="p1">p1</button>
    <button class="ctrl-btn" data-val="pm">pm</button>
    <button class="ctrl-btn" data-val="cmm">cmm</button>
    <button class="ctrl-btn active" data-val="p4m">p4m</button>
    <button class="ctrl-btn" data-val="p3">p3</button>
    <button class="ctrl-btn" data-val="p6m">p6m</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">SCALE</label>
    <input type="range" id="sl-sc" min="50" max="180" value="100">
    <span class="slider-value" id="sv-sc">100</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-sp" min="5" max="100" value="40">
    <span class="slider-value" id="sv-sp">40</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">FADE</label>
    <input type="range" id="sl-fd" min="1" max="40" value="8">
    <span class="slider-value" id="sv-fd">8</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-grid">GRID</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;
var TC=document.createElement('canvas'),TX=TC.getContext('2d');
var S3=Math.sqrt(3)*0.5;

var GR={};
(function(){
  GR.p1={o:[[1,0,0,1,0]],l:'sq'};
  GR.pm={o:[[1,0,0,1,0],[-1,0,0,1,1]],l:'sq'};
  GR.cmm={o:[[1,0,0,1,0],[-1,0,0,-1,0],[1,0,0,-1,1],[-1,0,0,1,1]],l:'sq'};
  GR.p4m={o:[],l:'sq'};
  for(var k=0;k<4;k++){var a=k*Math.PI/2,c=Math.cos(a),s=Math.sin(a);
    GR.p4m.o.push([c,-s,s,c,0],[c,s,s,-c,1]);}
  GR.p3={o:[],l:'hex'};
  for(var k=0;k<3;k++){var a=k*2*Math.PI/3,c=Math.cos(a),s=Math.sin(a);
    GR.p3.o.push([c,-s,s,c,0]);}
  GR.p6m={o:[],l:'hex'};
  for(var k=0;k<6;k++){var a=k*Math.PI/3,c=Math.cos(a),s=Math.sin(a);
    GR.p6m.o.push([c,-s,s,c,0],[c,s,s,-c,1]);}
})();

var gk='p4m',L=100,sp=40,fd=8,showG=false;
var pts=[],tm=0;

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  TC.width=W*dpr;TC.height=H*dpr;
  TX.setTransform(dpr,0,0,dpr,0,0);
  TX.fillStyle='#0a0a0f';TX.fillRect(0,0,W,H);
}

function mkP(){
  var amp=L*0.26*(0.3+Math.random()*0.7);
  var fx=1+Math.floor(Math.random()*4),fy=1+Math.floor(Math.random()*4);
  if(fx===fy)fy=fx%4+1;
  return{ax:amp,ay:amp*(0.5+Math.random()*0.5),
    fx:fx*(0.82+Math.random()*0.36),fy:fy*(0.82+Math.random()*0.36),
    px:Math.random()*Math.PI*2,py:Math.random()*Math.PI*2,x:0,y:0};
}

function initP(n){pts=[];for(var i=0;i<n;i++)pts.push(mkP());}

function upP(){
  var s=sp*0.0004;
  for(var i=0;i<pts.length;i++){var p=pts[i];
    p.x=p.ax*Math.sin(p.fx*tm+p.px);
    p.y=p.ay*Math.sin(p.fy*tm+p.py);}
  tm+=s;
}

function red(x,y){
  var g=GR[gk];
  if(g.l==='sq'){return[((x%L)+L)%L,((y%L)+L)%L];}
  var u=x/L-y/(L*S3*2),v=y/(L*S3);
  u=((u%1)+1)%1;v=((v%1)+1)%1;
  return[u*L+v*L*0.5,v*L*S3];
}

function calc(){
  var g=GR[gk],ops=g.o,no=ops.length;
  var a1x=L,a2x,a2y;
  if(g.l==='sq'){a2x=0;a2y=L;}
  else{a2x=L*0.5;a2y=L*S3;}
  var nj=Math.ceil(H/a2y)+4;
  var niB=Math.ceil(W/L)+4;
  var ciP=g.l==='hex'?Math.ceil(nj*0.5)+2:2;
  var tl=[],pr=[];
  for(var pi=0;pi<pts.length;pi++){
    var p=pts[pi];
    for(var oi=0;oi<no;oi++){
      var op=ops[oi];
      var ix=op[0]*p.x+op[1]*p.y,iy=op[2]*p.x+op[3]*p.y;
      var r=red(ix,iy),cx=r[0],cy=r[1];
      var ar=op[4]?pr:tl;
      for(var cj=-2;cj<=nj;cj++){
        var by=cj*a2y+cy;
        if(by<-20||by>H+20)continue;
        for(var ci=-ciP;ci<=niB+ciP;ci++){
          var bx=ci*a1x+cj*a2x+cx;
          if(bx<-20||bx>W+20)continue;
          ar.push(bx,by);
        }
      }
    }
  }
  return{t:tl,p:pr};
}

function bR(ctx,a,col,r){
  if(!a.length)return;
  ctx.fillStyle=col;ctx.beginPath();
  for(var i=0;i<a.length;i+=2)ctx.rect(a[i]-r,a[i+1]-r,r*2,r*2);
  ctx.fill();
}

function frame(){
  upP();
  var d=calc();
  TX.fillStyle='rgba(10,10,15,'+(fd*0.002)+')';
  TX.fillRect(0,0,W,H);
  bR(TX,d.t,P.rgba(P.accent,0.1),1);
  bR(TX,d.p,P.rgba(P.sub,0.12),1);
  X.clearRect(0,0,W,H);
  X.drawImage(TC,0,0,W*dpr,H*dpr,0,0,W,H);
  bR(X,d.t,P.rgba(P.accent,0.06),4);
  bR(X,d.p,P.rgba(P.sub,0.08),4);
  bR(X,d.t,P.rgba(P.accent,0.5),1.5);
  bR(X,d.p,P.rgba(P.sub,0.55),1.5);
  if(showG){
    var g=GR[gk],a2x,a2y;
    if(g.l==='sq'){a2x=0;a2y=L;}else{a2x=L*0.5;a2y=L*S3;}
    var nj2=Math.ceil(H/a2y)+4,ni2=Math.ceil(W/L)+4;
    var cp=g.l==='hex'?Math.ceil(nj2*0.5)+2:2;
    X.strokeStyle='rgba(255,255,255,0.04)';X.lineWidth=0.5;X.beginPath();
    for(var cj=-2;cj<=nj2;cj++){
      for(var ci=-cp;ci<=ni2+cp;ci++){
        var ox=ci*L+cj*a2x,oy=cj*a2y;
        if(ox>W+L||ox<-L-L||oy>H+L||oy<-L)continue;
        if(g.l==='sq'){X.rect(ox,oy,L,L);}
        else{X.moveTo(ox,oy);X.lineTo(ox+L,oy);X.lineTo(ox+L+a2x,oy+a2y);X.lineTo(ox+a2x,oy+a2y);X.closePath();}
      }
    }
    X.stroke();
  }
  requestAnimationFrame(frame);
}

C.addEventListener('click',function(e){
  if(e.button!==0)return;
  pts.push(mkP());
  if(pts.length>20)pts.shift();
  hud();
  if(window.SFX)SFX.play('spawn');
});
C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  TX.fillStyle='#0a0a0f';TX.fillRect(0,0,W,H);
  if(window.SFX)SFX.play('click');
});
C.addEventListener('touchstart',function(e){
  if(e.touches.length===1){
    pts.push(mkP());
    if(pts.length>20)pts.shift();
    hud();
    if(window.SFX)SFX.play('spawn');
  }
},{passive:true});

document.getElementById('grp-btns').addEventListener('click',function(e){
  var b=e.target.closest('[data-val]');if(!b)return;
  gk=b.dataset.val;
  var bs=this.querySelectorAll('[data-val]');
  for(var i=0;i<bs.length;i++)bs[i].classList.remove('active');
  b.classList.add('active');
  TX.fillStyle='#0a0a0f';TX.fillRect(0,0,W,H);
  initP(pts.length||5);tm=0;
  for(var i=0;i<60;i++){upP();var dd=calc();bR(TX,dd.t,P.rgba(P.accent,0.05),1);bR(TX,dd.p,P.rgba(P.sub,0.06),1);}
  hud();
  if(window.SFX)SFX.play('toggle');
});

var eSc=document.getElementById('sl-sc'),vSc=document.getElementById('sv-sc');
eSc.addEventListener('input',function(){L=+this.value;vSc.textContent=L;TX.fillStyle='#0a0a0f';TX.fillRect(0,0,W,H);initP(pts.length||5);tm=0;});
var eSp=document.getElementById('sl-sp'),vSp=document.getElementById('sv-sp');
eSp.addEventListener('input',function(){sp=+this.value;vSp.textContent=sp;});
var eFd=document.getElementById('sl-fd'),vFd=document.getElementById('sv-fd');
eFd.addEventListener('input',function(){fd=+this.value;vFd.textContent=fd;});
document.getElementById('btn-grid').addEventListener('click',function(){
  showG=!showG;this.classList.toggle('active',showG);
  if(window.SFX)SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){
  TX.fillStyle='#0a0a0f';TX.fillRect(0,0,W,H);
  tm=0;initP(5);hud();
  if(window.SFX)SFX.play('click');
});

function hud(){
  document.getElementById('hud-grp').textContent=gk;
  document.getElementById('hud-ops').textContent=GR[gk].o.length;
  document.getElementById('hud-cnt').textContent=pts.length;
}

window.addEventListener('resize',resize);
resize();
initP(5);
for(var i=0;i<80;i++){upP();var dd=calc();bR(TX,dd.t,P.rgba(P.accent,0.05),1);bR(TX,dd.p,P.rgba(P.sub,0.06),1);}
hud();
frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
