<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spring');

$pageName = 'spring';
$pageTitle = 'SPRING - PULSE';
$pageDesc = 'バネ振り子。弾性ロッドに吊るされた質点が描くカオス的軌跡。自動パラメトリック共鳴で振動と揺動が結合する。';
$pageCanonical = 'https://pulse.subara3.com/spring.php';

$extraStyle = '
body{background:#0a0a0f;margin:0;overflow:hidden}
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
  COUNT: <span class="v" id="hud-n">0</span><br>
  ENERGY: <span class="v" id="hud-e">0.0</span>
</div>

<div id="info">
  <span class="v">spring pendulum</span><br>
  autoparametric resonance
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'pull'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">STIFFNESS</span>
      <input type="range" id="k-slider" min="3" max="50" value="18" step="1">
      <span class="slider-value" id="k-val">18</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">DAMPING</span>
      <input type="range" id="b-slider" min="0" max="100" value="20" step="5">
      <span class="slider-value" id="b-val">0.020</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">TRAIL</span>
      <input type="range" id="tr-slider" min="0" max="2000" value="800" step="100">
      <span class="slider-value" id="tr-val">800</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-clear">CLEAR TRAIL</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),ctx=C.getContext('2d');
  var W,H,pivX,pivY,SC;

  var g=9.81,K=18,M=1,B=0.02,L0=1.8;
  var trailMax=800,DT=0.002,SUBS=8;

  var COLS=[
    [0,245,212],[0,210,235],[80,180,245],[148,80,225],
    [170,55,240],[0,230,190],[50,195,240],[120,120,235]
  ];
  var nextCol=0;
  var pends=[],dragIdx=-1;

  function Pend(r,th,rd,td){
    this.r=r;this.th=th;this.rd=rd;this.td=td;
    this.col=COLS[nextCol++%COLS.length];
    this.trail=[];
  }

  function deriv(r,th,rd,td){
    if(r<0.01)r=0.01;
    return[
      rd,td,
      r*td*td+g*Math.cos(th)-(K/M)*(r-L0)-(B/M)*rd,
      -(2*rd*td+g*Math.sin(th))/r-(B/M)*td
    ];
  }

  function stepPhysics(p){
    var r=p.r,th=p.th,rd=p.rd,td=p.td,h=DT;
    var a=deriv(r,th,rd,td);
    var b=deriv(r+a[0]*h/2,th+a[1]*h/2,rd+a[2]*h/2,td+a[3]*h/2);
    var c=deriv(r+b[0]*h/2,th+b[1]*h/2,rd+b[2]*h/2,td+b[3]*h/2);
    var d=deriv(r+c[0]*h,th+c[1]*h,rd+c[2]*h,td+c[3]*h);
    p.r +=(a[0]+2*b[0]+2*c[0]+d[0])*h/6;
    p.th+=(a[1]+2*b[1]+2*c[1]+d[1])*h/6;
    p.rd+=(a[2]+2*b[2]+2*c[2]+d[2])*h/6;
    p.td+=(a[3]+2*b[3]+2*c[3]+d[3])*h/6;
    if(p.r<0.05){p.r=0.05;p.rd=Math.abs(p.rd)*0.3;}
  }

  function toScr(r,th){
    return[pivX+r*Math.sin(th)*SC,pivY+r*Math.cos(th)*SC];
  }

  function energy(p){
    var v2=p.rd*p.rd+p.r*p.r*p.td*p.td;
    return 0.5*M*v2+0.5*K*(p.r-L0)*(p.r-L0)-M*g*p.r*Math.cos(p.th);
  }

  function drawPivot(){
    ctx.beginPath();ctx.arc(pivX,pivY,4,0,Math.PI*2);
    ctx.fillStyle='rgba(255,255,255,0.5)';ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,0.25)';ctx.lineWidth=1;
    ctx.beginPath();
    ctx.moveTo(pivX-16,pivY);ctx.lineTo(pivX+16,pivY);
    for(var i=-16;i<=16;i+=5){
      ctx.moveTo(pivX+i,pivY);ctx.lineTo(pivX+i-4,pivY-6);
    }
    ctx.stroke();
  }

  function drawSpring(x1,y1,x2,y2,col,alpha){
    var dx=x2-x1,dy=y2-y1;
    var len=Math.sqrt(dx*dx+dy*dy);
    if(len<2)return;
    var nx=-dy/len,ny=dx/len;
    var coils=16,segs=coils*4,cap=0.06;
    var amp=7*Math.min(1.5,(L0*SC)/Math.max(len,1));

    ctx.beginPath();
    ctx.moveTo(x1,y1);
    ctx.lineTo(x1+dx*cap,y1+dy*cap);
    for(var i=0;i<=segs;i++){
      var t=cap+(1-2*cap)*i/segs;
      var s=Math.sin(i/segs*coils*Math.PI*2)*amp;
      ctx.lineTo(x1+dx*t+nx*s,y1+dy*t+ny*s);
    }
    ctx.lineTo(x2,y2);
    ctx.strokeStyle='rgba('+col[0]+','+col[1]+','+col[2]+','+alpha+')';
    ctx.lineWidth=1.3;ctx.stroke();
  }

  function drawMass(x,y,col){
    var gr=ctx.createRadialGradient(x,y,0,x,y,20);
    gr.addColorStop(0,'rgba('+col[0]+','+col[1]+','+col[2]+',0.5)');
    gr.addColorStop(1,'rgba('+col[0]+','+col[1]+','+col[2]+',0)');
    ctx.fillStyle=gr;
    ctx.beginPath();ctx.arc(x,y,20,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(x,y,6,0,Math.PI*2);
    ctx.fillStyle='rgba('+col[0]+','+col[1]+','+col[2]+',1)';ctx.fill();
    ctx.beginPath();ctx.arc(x,y,2.5,0,Math.PI*2);
    ctx.fillStyle='rgba(255,255,255,0.7)';ctx.fill();
  }

  function drawTrail(trail,col){
    var n=trail.length;if(n<2)return;
    var bands=8,bs=Math.ceil(n/bands);
    for(var b=0;b<bands;b++){
      var s=b*bs,e=Math.min(s+bs+1,n);
      if(s>=n-1)break;
      var alpha=((b+1)/bands)*0.55;
      ctx.beginPath();ctx.moveTo(trail[s][0],trail[s][1]);
      for(var i=s+1;i<e;i++)ctx.lineTo(trail[i][0],trail[i][1]);
      ctx.strokeStyle='rgba('+col[0]+','+col[1]+','+col[2]+','+alpha.toFixed(3)+')';
      ctx.lineWidth=0.7+(b/bands)*0.9;ctx.stroke();
    }
  }

  function resize(){
    W=C.width=window.innerWidth;
    H=C.height=window.innerHeight;
    pivX=W*0.5;pivY=H*0.13;
    SC=Math.min(W*0.25,H*0.17);
  }

  function scrToPolar(mx,my){
    var dx=mx-pivX,dy=my-pivY;
    return[Math.sqrt(dx*dx+dy*dy)/SC,Math.atan2(dx,dy)];
  }

  function findMass(mx,my){
    var best=-1,bd=35;
    for(var i=0;i<pends.length;i++){
      var p=toScr(pends[i].r,pends[i].th);
      var d=Math.sqrt((p[0]-mx)*(p[0]-mx)+(p[1]-my)*(p[1]-my));
      if(d<bd){bd=d;best=i;}
    }
    return best;
  }

  function addPend(r,th){
    if(pends.length>=10)return;
    pends.push(new Pend(Math.max(r,0.3),th,0,0));
    if(typeof SFX!=='undefined')SFX.play('spawn');
  }

  function handleDown(mx,my,isRight){
    if(isRight){pends=[];nextCol=0;if(typeof SFX!=='undefined')SFX.play('click');return;}
    var idx=findMass(mx,my);
    if(idx>=0){dragIdx=idx;if(typeof SFX!=='undefined')SFX.play('tick');}
    else{var pol=scrToPolar(mx,my);addPend(pol[0],pol[1]);}
  }
  function handleMove(mx,my){
    if(dragIdx>=0){
      var pol=scrToPolar(mx,my);
      pends[dragIdx].r=Math.max(pol[0],0.1);
      pends[dragIdx].th=pol[1];
      pends[dragIdx].rd=0;pends[dragIdx].td=0;
    }
  }
  function handleUp(){dragIdx=-1;}

  C.addEventListener('mousedown',function(e){e.preventDefault();handleDown(e.clientX,e.clientY,e.button===2);});
  C.addEventListener('mousemove',function(e){handleMove(e.clientX,e.clientY);});
  C.addEventListener('mouseup',handleUp);
  C.addEventListener('contextmenu',function(e){e.preventDefault();});
  C.addEventListener('touchstart',function(e){e.preventDefault();var t=e.touches[0];handleDown(t.clientX,t.clientY,false);},{passive:false});
  C.addEventListener('touchmove',function(e){e.preventDefault();var t=e.touches[0];handleMove(t.clientX,t.clientY);},{passive:false});
  C.addEventListener('touchend',handleUp);

  document.getElementById('k-slider').addEventListener('input',function(){
    K=parseFloat(this.value);document.getElementById('k-val').textContent=this.value;
  });
  document.getElementById('b-slider').addEventListener('input',function(){
    B=parseInt(this.value)/1000;document.getElementById('b-val').textContent=B.toFixed(3);
  });
  document.getElementById('tr-slider').addEventListener('input',function(){
    trailMax=parseInt(this.value);document.getElementById('tr-val').textContent=this.value;
  });
  document.getElementById('btn-clear').addEventListener('click',function(){
    for(var i=0;i<pends.length;i++)pends[i].trail=[];
    if(typeof SFX!=='undefined')SFX.play('click');
  });
  document.getElementById('btn-reset').addEventListener('click',function(){
    pends=[];nextCol=0;initPends();
    if(typeof SFX!=='undefined')SFX.play('click');
  });

  var hudN=document.getElementById('hud-n');
  var hudE=document.getElementById('hud-e');
  var tick=0;

  function initPends(){
    addPend(2.3,0.6);
    addPend(2.3,0.605);
    addPend(2.3,0.61);
  }

  function frame(){
    requestAnimationFrame(frame);
    ctx.fillStyle='#0a0a0f';ctx.fillRect(0,0,W,H);

    for(var i=0;i<pends.length;i++){
      if(i===dragIdx)continue;
      for(var s=0;s<SUBS;s++)stepPhysics(pends[i]);
    }

    for(var i=0;i<pends.length;i++){
      var pos=toScr(pends[i].r,pends[i].th);
      pends[i].trail.push(pos);
      if(pends[i].trail.length>trailMax)pends[i].trail.shift();
    }

    for(var i=0;i<pends.length;i++)drawTrail(pends[i].trail,pends[i].col);

    drawPivot();
    for(var i=0;i<pends.length;i++){
      var pos=toScr(pends[i].r,pends[i].th);
      drawSpring(pivX,pivY,pos[0],pos[1],pends[i].col,0.45);
      drawMass(pos[0],pos[1],pends[i].col);
    }

    if(++tick%10===0){
      hudN.textContent=pends.length;
      if(pends.length>0){
        var E=0;for(var i=0;i<pends.length;i++)E+=energy(pends[i]);
        hudE.textContent=(E/pends.length).toFixed(1);
      }else hudE.textContent='0.0';
    }
  }

  resize();window.addEventListener('resize',resize);
  initPends();frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
