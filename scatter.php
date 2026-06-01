<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('scatter');

$pageName = 'scatter';
$pageTitle = 'SCATTER - PULSE';
$pageDesc = 'ローレンツガス。周期配列された円形散乱体を粒子が跳ね回り、カオス的軌跡を積み重ねる。シナイ・ビリヤードの可視化。';
$pageCanonical = 'https://pulse.subara3.com/scatter.php';

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
  PARTICLES: <span class="v" id="hud-p">0</span><br>
  BOUNCES: <span class="v" id="hud-b">0</span><br>
  MFP: <span class="v" id="hud-mfp">&mdash;</span>
</div>

<div id="info">
  <span class="v">Lorentz Gas</span><br>
  Sinai billiard · chaotic scattering
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'launch'],
  ['type' => 'drag', 'label' => 'move disc'],
  ['type' => 'right', 'label' => 'add / remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">RADIUS</span>
      <input type="range" id="sl-r" min="10" max="60" value="30">
      <span class="slider-value" id="sv-r">30</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-s" min="1" max="10" value="4">
      <span class="slider-value" id="sv-s">4</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">FADE</span>
      <input type="range" id="sl-f" min="1" max="10" value="3">
      <span class="slider-value" id="sv-f">3</span>
    </div>
  </div>
  <div>
    <span class="slider-label">LAYOUT</span>
    <div class="btn-row" id="layout-btns">
      <button class="control-btn active" data-v="hex">HEX</button>
      <button class="control-btn" data-v="square">SQUARE</button>
      <button class="control-btn" data-v="random">RANDOM</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="clear-btn">CLEAR</button>
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H;

  var discs=[],parts=[];
  var R=30,spd=4,fadeR=3,layout='hex';
  var totalB=0;

  var FC,FX;
  var flashes=[];
  var hueSet=[175,185,195,270,280,290,180,200,265,275];

  function mkFade(){FC=document.createElement('canvas');FX=FC.getContext('2d');FC.width=W;FC.height=H;}

  function genHex(){
    discs=[];
    var sp=R*3.5,rh=sp*0.866;
    var rows=Math.ceil(H/rh)+2,cols=Math.ceil(W/sp)+2;
    for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
      var x=c*sp+(r%2)*sp*0.5-sp*0.5;
      var y=r*rh-rh*0.5;
      discs.push({x:x,y:y});
    }
  }
  function genSquare(){
    discs=[];
    var sp=R*3.5;
    var rows=Math.ceil(H/sp)+2,cols=Math.ceil(W/sp)+2;
    for(var r=0;r<rows;r++)for(var c=0;c<cols;c++){
      discs.push({x:c*sp-sp*0.5,y:r*sp-sp*0.5});
    }
  }
  function genRandom(){
    discs=[];
    var n=Math.floor((W*H)/(R*R*18));
    var att=n*20;
    while(discs.length<n&&att-->0){
      var x=Math.random()*W,y=Math.random()*H,ok=true;
      for(var j=0;j<discs.length;j++){
        var dx=x-discs[j].x,dy=y-discs[j].y;
        if(dx*dx+dy*dy<R*R*6.25){ok=false;break;}
      }
      if(ok)discs.push({x:x,y:y});
    }
  }
  function genLayout(){
    if(layout==='hex')genHex();
    else if(layout==='square')genSquare();
    else genRandom();
  }

  function inGap(x,y){
    for(var i=0;i<discs.length;i++){
      var dx=x-discs[i].x,dy=y-discs[i].y;
      if(dx*dx+dy*dy<(R+3)*(R+3))return false;
    }
    return true;
  }

  function launch(x,y){
    if(!inGap(x,y)||parts.length>=20)return false;
    var a=Math.random()*6.2832;
    parts.push({x:x,y:y,dx:Math.cos(a),dy:Math.sin(a),
      px:x,py:y,bc:0,dSinceB:0,mfpS:0,mfpN:0,
      hue:hueSet[parts.length%hueSet.length]});
    return true;
  }

  function autoLaunch(n){
    for(var i=0;i<n;i++){
      for(var a=0;a<200;a++){
        var x=50+Math.random()*(W-100),y=50+Math.random()*(H-100);
        if(launch(x,y))break;
      }
    }
  }

  function rayCircle(ox,oy,dx,dy,cx,cy,r){
    var fx=ox-cx,fy=oy-cy;
    var b=2*(fx*dx+fy*dy);
    var c=fx*fx+fy*fy-r*r;
    var disc=b*b-4*c;
    if(disc<0)return-1;
    var sq=Math.sqrt(disc);
    var t1=(-b-sq)*0.5;
    if(t1>0.005)return t1;
    var t2=(-b+sq)*0.5;
    if(t2>0.005)return t2;
    return-1;
  }

  function stepP(p){
    var dx=p.dx,dy=p.dy;
    var rem=spd*2,hit=false,it=0;
    while(rem>0.01&&it++<30){
      var mt=rem,mi=-1;
      for(var i=0;i<discs.length;i++){
        var t=rayCircle(p.x,p.y,dx,dy,discs[i].x,discs[i].y,R);
        if(t>0&&t<mt){mt=t;mi=i;}
      }
      p.x+=dx*mt;p.y+=dy*mt;
      p.dSinceB+=mt;
      rem-=mt;
      if(mi>=0){
        var d=discs[mi];
        var nx=p.x-d.x,ny=p.y-d.y;
        var nl=Math.sqrt(nx*nx+ny*ny);
        if(nl>0){nx/=nl;ny/=nl;}
        var dot=dx*nx+dy*ny;
        dx-=2*dot*nx;dy-=2*dot*ny;
        var ln=Math.sqrt(dx*dx+dy*dy);
        if(ln>0){dx/=ln;dy/=ln;}
        p.x+=nx*0.02;p.y+=ny*0.02;
        hit=true;p.bc++;totalB++;
        p.mfpS+=p.dSinceB;p.mfpN++;p.dSinceB=0;
        flashes.push({x:p.x,y:p.y,a:0,h:p.hue});
      }
    }
    if(p.x<0){p.x=-p.x;dx=Math.abs(dx);}
    if(p.x>W){p.x=2*W-p.x;dx=-Math.abs(dx);}
    if(p.y<0){p.y=-p.y;dy=Math.abs(dy);}
    if(p.y>H){p.y=2*H-p.y;dy=-Math.abs(dy);}
    p.dx=dx;p.dy=dy;
    return hit;
  }

  function stepAll(){
    var any=false;
    for(var i=0;i<parts.length;i++){if(stepP(parts[i]))any=true;}
    if(any)SFX.play('collide',50);
    for(var i=flashes.length-1;i>=0;i--){
      flashes[i].a+=0.06;
      if(flashes[i].a>1)flashes.splice(i,1);
    }
  }

  function render(){
    FX.fillStyle='rgba(10,10,15,'+(0.002+fadeR*0.004)+')';
    FX.fillRect(0,0,W,H);
    for(var i=0;i<parts.length;i++){
      var p=parts[i];
      FX.strokeStyle='hsla('+p.hue+',80%,55%,0.35)';
      FX.lineWidth=1.5;
      FX.beginPath();FX.moveTo(p.px,p.py);FX.lineTo(p.x,p.y);FX.stroke();
      p.px=p.x;p.py=p.y;
    }

    X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);
    X.drawImage(FC,0,0);

    for(var i=0;i<discs.length;i++){
      var d=discs[i];
      if(d.x<-R-10||d.x>W+R+10||d.y<-R-10||d.y>H+R+10)continue;
      X.beginPath();X.arc(d.x,d.y,R,0,6.2832);
      X.fillStyle='rgba(10,12,20,0.85)';X.fill();
      X.strokeStyle='rgba(0,245,212,0.12)';X.lineWidth=1;X.stroke();
    }

    for(var i=0;i<flashes.length;i++){
      var f=flashes[i];
      var al=(1-f.a)*0.5;
      X.beginPath();X.arc(f.x,f.y,4+f.a*12,0,6.2832);
      X.fillStyle='hsla('+f.h+',80%,60%,'+al+')';X.fill();
    }

    for(var i=0;i<parts.length;i++){
      var p=parts[i];
      X.beginPath();X.arc(p.x,p.y,3,0,6.2832);
      X.fillStyle='hsla('+p.hue+',80%,70%,0.9)';X.fill();
      X.beginPath();X.arc(p.x,p.y,7,0,6.2832);
      X.fillStyle='hsla('+p.hue+',80%,60%,0.25)';X.fill();
    }

    var mfp=0,ms=0,mn=0;
    for(var i=0;i<parts.length;i++){ms+=parts[i].mfpS;mn+=parts[i].mfpN;}
    if(mn>0)mfp=(ms/mn).toFixed(1);
    document.getElementById('hud-p').textContent=parts.length;
    document.getElementById('hud-b').textContent=totalB;
    document.getElementById('hud-mfp').textContent=mn>0?mfp:'—';
  }

  function loop(){stepAll();render();requestAnimationFrame(loop);}

  function findDisc(x,y){
    for(var i=0;i<discs.length;i++){
      var dx=x-discs[i].x,dy=y-discs[i].y;
      if(dx*dx+dy*dy<R*R)return i;
    }
    return-1;
  }

  var dragI=-1,mdx=0,mdy=0,mmoved=false;
  C.addEventListener('mousedown',function(e){
    if(e.button===2){
      var idx=findDisc(e.clientX,e.clientY);
      if(idx>=0){discs.splice(idx,1);SFX.play('pop');}
      else{discs.push({x:e.clientX,y:e.clientY});SFX.play('spawn');}
      return;
    }
    mdx=e.clientX;mdy=e.clientY;mmoved=false;
    dragI=findDisc(e.clientX,e.clientY);
  });
  C.addEventListener('mousemove',function(e){
    var dx=e.clientX-mdx,dy=e.clientY-mdy;
    if(dx*dx+dy*dy>16)mmoved=true;
    if(dragI>=0){discs[dragI].x=e.clientX;discs[dragI].y=e.clientY;}
  });
  C.addEventListener('mouseup',function(e){
    if(dragI>=0){if(mmoved)SFX.play('move');dragI=-1;return;}
    if(!mmoved){
      if(launch(e.clientX,e.clientY))SFX.play('spawn');
    }
    dragI=-1;
  });
  C.addEventListener('contextmenu',function(e){e.preventDefault();});

  var tI=-1,tSX=0,tSY=0,tMoved=false,tTime=0;
  C.addEventListener('touchstart',function(e){
    e.preventDefault();var t=e.touches[0];
    tSX=t.clientX;tSY=t.clientY;tMoved=false;tTime=Date.now();
    tI=findDisc(t.clientX,t.clientY);
  },{passive:false});
  C.addEventListener('touchmove',function(e){
    e.preventDefault();var t=e.touches[0];
    var dx=t.clientX-tSX,dy=t.clientY-tSY;
    if(dx*dx+dy*dy>25)tMoved=true;
    if(tI>=0){discs[tI].x=t.clientX;discs[tI].y=t.clientY;}
  },{passive:false});
  C.addEventListener('touchend',function(e){
    e.preventDefault();var el=Date.now()-tTime;
    if(tI>=0){
      if(!tMoved&&el>400){discs.splice(tI,1);SFX.play('pop');}
      else if(tMoved)SFX.play('move');
    }else if(!tMoved){
      var ct=e.changedTouches[0];
      if(el>400){discs.push({x:ct.clientX,y:ct.clientY});SFX.play('spawn');}
      else{if(launch(ct.clientX,ct.clientY))SFX.play('spawn');}
    }
    tI=-1;
  },{passive:false});

  document.querySelectorAll('#layout-btns .control-btn').forEach(function(b){
    b.addEventListener('click',function(){
      document.querySelectorAll('#layout-btns .control-btn').forEach(function(x){x.classList.remove('active');});
      b.classList.add('active');layout=b.dataset.v;resetAll();SFX.play('toggle');
    });
  });
  document.getElementById('sl-r').addEventListener('input',function(){
    R=parseInt(this.value);document.getElementById('sv-r').textContent=R;resetAll();
  });
  document.getElementById('sl-s').addEventListener('input',function(){
    spd=parseInt(this.value);document.getElementById('sv-s').textContent=spd;
  });
  document.getElementById('sl-f').addEventListener('input',function(){
    fadeR=parseInt(this.value);document.getElementById('sv-f').textContent=fadeR;
  });
  document.getElementById('clear-btn').addEventListener('click',function(){
    parts=[];totalB=0;flashes=[];
    FX.fillStyle='#0a0a0f';FX.fillRect(0,0,W,H);
    SFX.play('click');
  });
  document.getElementById('reset-btn').addEventListener('click',function(){
    resetAll();SFX.play('click');
  });

  function resetAll(){
    genLayout();parts=[];totalB=0;flashes=[];
    FX.fillStyle='#0a0a0f';FX.fillRect(0,0,W,H);
    autoLaunch(3);
  }
  function resize(){W=C.width=innerWidth;H=C.height=innerHeight;mkFade();resetAll();}
  window.addEventListener('resize',resize);
  resize();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
