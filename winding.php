<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('winding');

$pageName = 'winding';
$pageTitle = 'WINDING - PULSE';
$pageDesc = '巻き数。閉曲線が平面をトポロジカルに分割する——各領域の巻き数が色になる。';
$pageCanonical = 'https://pulse.subara3.com/winding.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  PRESET <span class="v" id="hud-mode">TREFOIL</span><br>
  WINDING <span class="v" id="hud-w">&mdash;</span><br>
  SEGMENTS <span class="v" id="hud-seg">150</span>
</div>

<div id="info">
  <span class="v">Winding Number</span><br>
  w(p) = (1/2&pi;) &#8750; d&theta;<br>
  <span style="color:rgba(0,245,212,.5)">&#9632;</span> w &gt; 0 &middot; <span style="color:rgba(123,44,191,.5)">&#9632;</span> w &lt; 0
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row" id="presets">
      <button class="preset-btn" data-m="loop">LOOP</button>
      <button class="preset-btn" data-m="eight">EIGHT</button>
      <button class="preset-btn" data-m="spiral">SPIRAL</button>
      <button class="preset-btn active" data-m="trefoil">TREFOIL</button>
      <button class="preset-btn" data-m="star">STAR</button>
      <button class="preset-btn" data-m="custom">CUSTOM</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="0" max="200" value="50">
      <span class="slider-value" id="sv-speed">1.0</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
      <button class="control-btn active" id="btn-trace">TRACERS</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d'),W,H;
var mode='trefoil',crv=[],cpts=[];
var ph=0,spd=1,autoOn=true,showTr=true;
var gW,gH,gD;
var oC=document.createElement('canvas'),oX=oC.getContext('2d');
var trs=[],NT=20;
var mx=-1,my=-1;
var dIdx=-1,drg=false;
var cx,cy,R;
var PI2=Math.PI*2;

function resize(){
  var d=devicePixelRatio||1;W=innerWidth;H=innerHeight;
  C.width=W*d;C.height=H*d;C.style.width=W+'px';C.style.height=H+'px';
  ctx.setTransform(d,0,0,d,0,0);
  cx=W/2;cy=H/2;R=Math.min(W,H)*.28;
  build();grid();initTr();hud();
}

function build(){
  crv=[];var N=150,i,t,r,s,a;
  switch(mode){
  case'loop':
    for(i=0;i<N;i++){t=i/N*PI2;r=R*(1+.08*Math.sin(3*(t+ph)));
      crv.push({x:cx+r*Math.cos(t+ph*.3),y:cy+r*Math.sin(t+ph*.3)});}break;
  case'eight':
    a=.9+.1*Math.sin(ph*.7);
    for(i=0;i<N;i++){t=i/N*PI2;
      crv.push({x:cx+R*a*Math.sin(t+ph),y:cy+R*.45*Math.sin(2*(t+ph))});}break;
  case'spiral':
    a=.35+.15*Math.sin(ph*.5);
    for(i=0;i<N;i++){t=i/N*PI2;r=(a+Math.cos(t+ph))*R*.5;
      crv.push({x:cx+r*Math.cos(t+ph),y:cy+r*Math.sin(t+ph)});}break;
  case'trefoil':
    a=1+.15*Math.sin(ph*.3);
    for(i=0;i<N;i++){t=i/N*PI2;s=t+ph;
      crv.push({x:cx+(Math.sin(s)+2*a*Math.sin(2*s))*R*.24,
                y:cy+(Math.cos(s)-2*a*Math.cos(2*s))*R*.24});}break;
  case'star':
    var ord=[0,2,4,1,3],vs=[];
    for(i=0;i<5;i++){t=ord[i]/5*PI2-Math.PI/2+ph;
      vs.push({x:cx+R*Math.cos(t),y:cy+R*Math.sin(t)});}
    for(i=0;i<5;i++){var p1=vs[i],p2=vs[(i+1)%5];
      for(var j=0;j<30;j++){var f=j/30;
        crv.push({x:p1.x+(p2.x-p1.x)*f,y:p1.y+(p2.y-p1.y)*f});}}break;
  case'custom':
    if(cpts.length<3){crv=cpts.map(function(p){return{x:p.x,y:p.y};});break;}
    var n=cpts.length,sg=Math.max(8,N/n|0);
    for(i=0;i<n;i++){
      var p0=cpts[(i-1+n)%n],cp1=cpts[i],p2=cpts[(i+1)%n],p3=cpts[(i+2)%n];
      for(var j=0;j<sg;j++){t=j/sg;var t2=t*t,t3=t2*t;
        crv.push({
          x:.5*((2*cp1.x)+(-p0.x+p2.x)*t+(2*p0.x-5*cp1.x+4*p2.x-p3.x)*t2+(-p0.x+3*cp1.x-3*p2.x+p3.x)*t3),
          y:.5*((2*cp1.y)+(-p0.y+p2.y)*t+(2*p0.y-5*cp1.y+4*p2.y-p3.y)*t2+(-p0.y+3*cp1.y-3*p2.y+p3.y)*t3)
        });}}break;
  }
}

function wn(px,py){
  var w=0,n=crv.length;
  for(var i=0;i<n;i++){
    var ax=crv[i].x,ay=crv[i].y,j=(i+1)%n,bx=crv[j].x,by=crv[j].y;
    if(ay<=py){if(by>py){if((bx-ax)*(py-ay)-(px-ax)*(by-ay)>0)w++;}}
    else{if(by<=py){if((bx-ax)*(py-ay)-(px-ax)*(by-ay)<0)w--;}}
  }
  return w;
}

function grid(){
  gW=Math.min(120,Math.max(50,W/12|0));
  gH=Math.min(90,Math.max(38,H/12|0));
  gD=new Int8Array(gW*gH);
  if(crv.length<3)return;
  var cw=W/gW,ch=H/gH;
  for(var y=0;y<gH;y++)for(var x=0;x<gW;x++)
    gD[y*gW+x]=wn((x+.5)*cw,(y+.5)*ch);
}

function initTr(){
  trs=[];for(var i=0;i<NT;i++)trs.push({t:i/NT,s:.0008+.0015*Math.random()});
}

function drGrid(){
  if(crv.length<3)return;
  oC.width=gW;oC.height=gH;
  var im=oX.createImageData(gW,gH),d=im.data;
  for(var i=0;i<gW*gH;i++){
    var w=gD[i],k=i*4;
    if(w===0){d[k]=10;d[k+1]=10;d[k+2]=15;}
    else if(w>0){var f=Math.min(1,w*.4);d[k]=(10*(1-f))|0;d[k+1]=(10+235*f)|0;d[k+2]=(15+197*f)|0;}
    else{var f=Math.min(1,(-w)*.4);d[k]=(10+113*f)|0;d[k+1]=(10+34*f)|0;d[k+2]=(15+176*f)|0;}
    d[k+3]=255;
  }
  oX.putImageData(im,0,0);
  ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';
  ctx.drawImage(oC,0,0,W,H);
}

function drCrv(){
  if(crv.length<2)return;
  var n=crv.length;
  var layers=[[12,'rgba(0,245,212,.06)'],[6,'rgba(0,245,212,.12)'],[2.5,'rgba(0,245,212,.7)'],[1,'rgba(255,255,255,.25)']];
  for(var l=0;l<layers.length;l++){
    ctx.beginPath();ctx.moveTo(crv[0].x,crv[0].y);
    for(var i=1;i<n;i++)ctx.lineTo(crv[i].x,crv[i].y);
    ctx.closePath();ctx.strokeStyle=layers[l][1];ctx.lineWidth=layers[l][0];
    ctx.lineJoin='round';ctx.lineCap='round';ctx.stroke();
  }
}

function drTr(){
  if(!showTr||crv.length<2)return;
  var n=crv.length;
  for(var i=0;i<trs.length;i++){
    var tr=trs[i];tr.t=(tr.t+tr.s*spd)%1;
    var fi=tr.t*n,i0=fi|0,i1=(i0+1)%n,f=fi-i0;
    var px=crv[i0].x+(crv[i1].x-crv[i0].x)*f;
    var py=crv[i0].y+(crv[i1].y-crv[i0].y)*f;
    ctx.beginPath();ctx.arc(px,py,3,0,PI2);
    ctx.fillStyle='rgba(0,245,212,.9)';ctx.fill();
    var g=ctx.createRadialGradient(px,py,0,px,py,10);
    g.addColorStop(0,'rgba(0,245,212,.25)');g.addColorStop(1,'rgba(0,245,212,0)');
    ctx.fillStyle=g;ctx.fillRect(px-10,py-10,20,20);
  }
}

function drPts(){
  if(mode!=='custom')return;
  for(var i=0;i<cpts.length;i++){
    var p=cpts[i];
    ctx.beginPath();ctx.arc(p.x,p.y,7,0,PI2);
    ctx.fillStyle=i===dIdx?'#00f5d4':'rgba(0,245,212,.5)';ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,.3)';ctx.lineWidth=1;ctx.stroke();
  }
}

function hud(){
  document.getElementById('hud-mode').textContent=mode.toUpperCase();
  document.getElementById('hud-seg').textContent=crv.length;
  var pb=document.querySelectorAll('#presets .preset-btn');
  for(var i=0;i<pb.length;i++)pb[i].classList.toggle('active',pb[i].dataset.m===mode);
}

function frame(){
  if(autoOn&&mode!=='custom'){ph+=.005*spd;build();grid();}
  ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);
  drGrid();drCrv();drTr();drPts();
  if(mx>=0&&my>=0&&crv.length>=3)
    document.getElementById('hud-w').textContent=wn(mx,my);
  requestAnimationFrame(frame);
}

function findP(x,y){
  var b=-1,bd=900;
  for(var i=0;i<cpts.length;i++){var dx=cpts[i].x-x,dy=cpts[i].y-y,dd=dx*dx+dy*dy;
    if(dd<bd){bd=dd;b=i;}}
  return b;
}

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;mx=e.clientX;my=e.clientY;
  if(mode==='custom'){var idx=findP(mx,my);
    if(idx>=0){dIdx=idx;drg=true;}
    else{cpts.push({x:mx,y:my});SFX.play('spawn');build();grid();hud();}}
});
C.addEventListener('mousemove',function(e){
  mx=e.clientX;my=e.clientY;
  if(drg&&dIdx>=0){cpts[dIdx].x=mx;cpts[dIdx].y=my;build();grid();SFX.play('move',100);}
});
C.addEventListener('mouseup',function(){drg=false;dIdx=-1;});
C.addEventListener('mouseleave',function(){mx=-1;my=-1;document.getElementById('hud-w').textContent='—';});
C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  if(mode==='custom'){var idx=findP(e.clientX,e.clientY);
    if(idx>=0){cpts.splice(idx,1);SFX.play('pop');build();grid();hud();}}
});

C.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];mx=t.clientX;my=t.clientY;
  if(mode==='custom'){var idx=findP(mx,my);
    if(idx>=0){dIdx=idx;drg=true;}
    else{cpts.push({x:mx,y:my});SFX.play('spawn');build();grid();hud();}}
},{passive:false});
C.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];mx=t.clientX;my=t.clientY;
  if(drg&&dIdx>=0){cpts[dIdx].x=mx;cpts[dIdx].y=my;build();grid();}
},{passive:false});
C.addEventListener('touchend',function(){drg=false;dIdx=-1;});

var pbs=document.querySelectorAll('#presets .preset-btn');
for(var i=0;i<pbs.length;i++)pbs[i].addEventListener('click',function(){
  mode=this.dataset.m;ph=0;
  if(mode==='custom'&&cpts.length===0){
    cpts=[];for(var k=0;k<8;k++){var a=k/8*PI2-Math.PI/2;
      cpts.push({x:cx+R*.7*Math.cos(a),y:cy+R*.7*Math.sin(a)});}}
  build();grid();initTr();hud();SFX.play('click');
});

document.getElementById('sl-speed').addEventListener('input',function(){
  spd=+this.value/50;document.getElementById('sv-speed').textContent=spd.toFixed(1);
});
document.getElementById('btn-auto').addEventListener('click',function(){
  autoOn=!autoOn;this.classList.toggle('active',autoOn);SFX.play('toggle');
});
document.getElementById('btn-trace').addEventListener('click',function(){
  showTr=!showTr;this.classList.toggle('active',showTr);SFX.play('toggle');
});
document.getElementById('btn-reset').addEventListener('click',function(){
  ph=0;cpts=[];
  if(mode==='custom'){for(var k=0;k<8;k++){var a=k/8*PI2-Math.PI/2;
    cpts.push({x:cx+R*.7*Math.cos(a),y:cy+R*.7*Math.sin(a)});}}
  build();grid();initTr();hud();SFX.play('click');
});

resize();addEventListener('resize',resize);frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
