<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('knot');

$pageName = 'knot';
$pageTitle = 'KNOT - PULSE';
$pageDesc = 'トーラス結び目。パラメータp,qで経度・緯度の巻数を変え、3次元の結び目を回転させながら眺める。';
$pageCanonical = 'https://pulse.subara3.com/knot.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  T(<span class="v" id="hud-pq">2,3</span>)<br>
  <span id="hud-type">KNOT</span><br>
  CROSSINGS <span class="v" id="hud-cross">3</span>
</div>

<div id="info">
  <span class="v">Torus Knot T(p,q)</span><br>
  x = (R + r&middot;cos qt) cos pt<br>
  y = (R + r&middot;cos qt) sin pt<br>
  z = r&middot;sin qt
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'rotate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-wrap">
      <div class="slider-row">
        <span class="slider-label" style="min-width:1.2rem">p</span>
        <button class="control-btn" id="p-dn">&minus;</button>
        <span class="slider-value" id="p-val" style="min-width:1.5rem;text-align:center">2</span>
        <button class="control-btn" id="p-up">+</button>
      </div>
      <div class="slider-row">
        <span class="slider-label" style="min-width:1.2rem">q</span>
        <button class="control-btn" id="q-dn">&minus;</button>
        <span class="slider-value" id="q-val" style="min-width:1.5rem;text-align:center">3</span>
        <button class="control-btn" id="q-up">+</button>
      </div>
    </div>
  </div>
  <div>
    <div class="btn-row" id="presets">
      <button class="preset-btn active" data-p="2" data-q="3">TREFOIL</button>
      <button class="preset-btn" data-p="2" data-q="5">CINQUE</button>
      <button class="preset-btn" data-p="3" data-q="4">T(3,4)</button>
      <button class="preset-btn" data-p="2" data-q="2">HOPF</button>
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
      <button class="control-btn active" id="btn-part">PARTICLES</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C=document.getElementById('canvas'),ctx=C.getContext('2d'),W,H;
var BG='#0a0a0f';
var kp=2,kq=3,R=1.0,rv=0.38;
var rx=0.4,ry=0,autoR=true,rSpd=0.008;
var showPt=true,parts=[],NP=24;
var isDrag=false,lx=0,ly=0;

function resize(){
  var d=devicePixelRatio||1;W=innerWidth;H=innerHeight;
  C.width=W*d;C.height=H*d;
  C.style.width=W+'px';C.style.height=H+'px';
  ctx.setTransform(d,0,0,d,0,0);
}

function gcd(a,b){a=Math.abs(a);b=Math.abs(b);while(b){var t=b;b=a%b;a=t;}return a;}

function kPt(p,q,t,ph){
  var cq=Math.cos(q*t+ph),sq=Math.sin(q*t+ph);
  var cp=Math.cos(p*t),sp=Math.sin(p*t);
  return[(R+rv*cq)*cp,(R+rv*cq)*sp,-rv*sq];
}

function rot3(pt){
  var x=pt[0],y=pt[1],z=pt[2];
  var cx=Math.cos(rx),sx=Math.sin(rx);
  var y1=y*cx-z*sx,z1=y*sx+z*cx;
  var cy=Math.cos(ry),sy=Math.sin(ry);
  return[x*cy+z1*sy,y1,-x*sy+z1*cy];
}

function proj(pt){
  var sc=Math.min(W,H)*0.3;
  return[W/2+pt[0]*sc,H/2+pt[1]*sc,pt[2]];
}

function hsl2rgb(h,s,l){
  s/=100;l/=100;
  var a=s*Math.min(l,1-l);
  function f(n){var k=(n+h/30)%12;return l-a*Math.max(-1,Math.min(k-3,9-k,1));}
  return[f(0)*255|0,f(8)*255|0,f(4)*255|0];
}

function initParts(){
  parts=[];
  var d=gcd(kp,kq),nc=Math.max(3,Math.floor(NP/d));
  var bs=0.015/Math.sqrt(Math.max(kp,2)*Math.max(kq,2));
  for(var c=0;c<d;c++)for(var i=0;i<nc;i++)
    parts.push({t:i/nc*2*Math.PI,comp:c,spd:bs*(0.7+0.6*Math.random())});
}

function updHUD(){
  document.getElementById('hud-pq').textContent=kp+','+kq;
  var d=gcd(kp,kq),te=document.getElementById('hud-type'),ce=document.getElementById('hud-cross');
  if(d===1){
    if(kp<=1||kq<=1){te.textContent='UNKNOT';ce.textContent='0';}
    else{te.textContent='KNOT';ce.textContent=Math.min(kp*(kq-1),kq*(kp-1));}
  }else{te.textContent=d+'-LINK';ce.textContent='—';}
  document.getElementById('p-val').textContent=kp;
  document.getElementById('q-val').textContent=kq;
  var pb=document.querySelectorAll('#presets .preset-btn');
  for(var i=0;i<pb.length;i++)
    pb[i].classList.toggle('active',+pb[i].dataset.p===kp&&+pb[i].dataset.q===kq);
}

function frame(){
  ctx.fillStyle=BG;ctx.fillRect(0,0,W,H);
  var d=gcd(kp,kq),pp=kp/d,qq=kq/d;
  var sn=Math.max(80,Math.floor(800/d)),st=2*Math.PI/sn;
  var segs=[];

  for(var c=0;c<d;c++){
    var ph=d>1?2*Math.PI*c/d:0;
    var pts=[];
    for(var i=0;i<=sn;i++){
      var t=(i%sn)*st;
      pts.push(proj(rot3(d>1?kPt(pp,qq,t,ph):kPt(kp,kq,t,0))));
    }
    for(var i=0;i<sn;i++){
      var a=pts[i],b=pts[i+1];
      segs.push({x1:a[0],y1:a[1],x2:b[0],y2:b[1],z:(a[2]+b[2])/2,ci:c});
    }
  }

  var mnz=1e9,mxz=-1e9;
  for(var i=0;i<segs.length;i++){if(segs[i].z<mnz)mnz=segs[i].z;if(segs[i].z>mxz)mxz=segs[i].z;}
  var zr=mxz-mnz||1;

  segs.sort(function(a,b){return a.z-b.z;});

  var tw=Math.max(3,Math.min(8,Math.min(W,H)/130));
  ctx.lineCap='round';

  for(var i=0;i<segs.length;i++){
    var s=segs[i],zn=(s.z-mnz)/zr;
    var cr,cg,cb;
    if(d===1){
      cr=40*(1-zn)|0;cg=(15+230*zn)|0;cb=(60+152*zn)|0;
    }else{
      var rgb=hsl2rgb(275-105*zn+s.ci/d*20,55+25*zn,20+35*zn);
      cr=rgb[0];cg=rgb[1];cb=rgb[2];
    }
    var lw=tw*(0.55+0.45*zn);

    ctx.beginPath();ctx.moveTo(s.x1,s.y1);ctx.lineTo(s.x2,s.y2);
    ctx.strokeStyle=BG;ctx.lineWidth=lw+5;ctx.stroke();

    ctx.beginPath();ctx.moveTo(s.x1,s.y1);ctx.lineTo(s.x2,s.y2);
    ctx.strokeStyle='rgba('+cr+','+cg+','+cb+',0.12)';ctx.lineWidth=lw+3;ctx.stroke();

    ctx.beginPath();ctx.moveTo(s.x1,s.y1);ctx.lineTo(s.x2,s.y2);
    ctx.strokeStyle='rgb('+cr+','+cg+','+cb+')';ctx.lineWidth=lw;ctx.stroke();

    ctx.beginPath();ctx.moveTo(s.x1,s.y1);ctx.lineTo(s.x2,s.y2);
    ctx.strokeStyle='rgba(255,255,255,'+(0.04+0.14*zn)+')';ctx.lineWidth=lw*0.3;ctx.stroke();
  }

  if(showPt){
    for(var j=0;j<parts.length;j++){
      var pa=parts[j];pa.t=(pa.t+pa.spd)%(2*Math.PI);
      var ph=d>1?2*Math.PI*pa.comp/d:0;
      var pt=d>1?kPt(pp,qq,pa.t,ph):kPt(kp,kq,pa.t,0);
      var sp=proj(rot3(pt));
      var pz=(sp[2]-mnz)/zr,pr=2+4*pz;

      ctx.beginPath();ctx.arc(sp[0],sp[1],pr,0,6.2832);
      ctx.fillStyle='rgba(0,245,212,'+(0.4+0.6*pz)+')';ctx.fill();

      var g=ctx.createRadialGradient(sp[0],sp[1],0,sp[0],sp[1],pr*3);
      g.addColorStop(0,'rgba(0,245,212,'+(0.15+0.25*pz)+')');
      g.addColorStop(1,'rgba(0,245,212,0)');
      ctx.fillStyle=g;ctx.fillRect(sp[0]-pr*3,sp[1]-pr*3,pr*6,pr*6);
    }
  }

  if(autoR&&!isDrag)ry+=rSpd;
  requestAnimationFrame(frame);
}

function onD(x,y){isDrag=true;lx=x;ly=y;}
function onM(x,y){if(!isDrag)return;ry+=(x-lx)*0.007;rx+=(y-ly)*0.007;rx=Math.max(-1.5,Math.min(1.5,rx));lx=x;ly=y;}
function onU(){isDrag=false;}

C.addEventListener('mousedown',function(e){if(!e.button)onD(e.clientX,e.clientY);});
C.addEventListener('mousemove',function(e){onM(e.clientX,e.clientY);});
C.addEventListener('mouseup',onU);
C.addEventListener('mouseleave',onU);
C.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];onD(t.clientX,t.clientY);},{passive:false});
C.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];onM(t.clientX,t.clientY);},{passive:false});
C.addEventListener('touchend',onU);
C.addEventListener('contextmenu',function(e){e.preventDefault();});

function setK(p,q){kp=p;kq=q;initParts();updHUD();}

document.getElementById('p-up').onclick=function(){setK(Math.min(9,kp+1),kq);SFX.play('toggle');};
document.getElementById('p-dn').onclick=function(){setK(Math.max(1,kp-1),kq);SFX.play('toggle');};
document.getElementById('q-up').onclick=function(){setK(kp,Math.min(9,kq+1));SFX.play('toggle');};
document.getElementById('q-dn').onclick=function(){setK(kp,Math.max(1,kq-1));SFX.play('toggle');};

var pbs=document.querySelectorAll('#presets .preset-btn');
for(var i=0;i<pbs.length;i++)pbs[i].addEventListener('click',function(){
  setK(+this.dataset.p,+this.dataset.q);SFX.play('click');
});

document.getElementById('sl-speed').addEventListener('input',function(){
  rSpd=+this.value/6000;
  document.getElementById('sv-speed').textContent=(+this.value/50).toFixed(1);
});

document.getElementById('btn-auto').addEventListener('click',function(){
  autoR=!autoR;this.classList.toggle('active',autoR);SFX.play('toggle');
});

document.getElementById('btn-part').addEventListener('click',function(){
  showPt=!showPt;this.classList.toggle('active',showPt);SFX.play('toggle');
});

resize();addEventListener('resize',resize);initParts();updHUD();frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
