<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fracture');

$pageName = 'fracture';
$pageTitle = 'FRACTURE - PULSE';
$pageDesc = 'バネ格子の脆性破壊。応力集中が亀裂の先端に溜まり、ランダムな結合強度が分岐パターンを決める。';
$pageCanonical = 'https://pulse.subara3.com/fracture.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  BONDS <b id="h-bonds">0</b> / <b id="h-total">0</b><br>
  STRAIN <b id="h-strain">0%</b><br>
  <span class="sub">CRACKS</span> <b id="h-cracks">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'notch'],
  ['type' => 'drag', 'label' => 'pull'],
  ['type' => 'right', 'label' => 'cut'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row">
    <button class="control-btn active" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-pull">PULL</button>
    <button class="control-btn" id="btn-impact">IMPACT</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">TENSION</span><input type="range" id="sl-tension" min="1" max="10" value="4" step="1"><span class="slider-value" id="sv-tension">4</span></div></div>
  <div><div class="slider-row"><span class="slider-label">DISORDER</span><input type="range" id="sl-disorder" min="1" max="10" value="5" step="1"><span class="slider-value" id="sv-disorder">5</span></div></div>
  <div><div class="btn-row"><button class="control-btn" id="btn-reset">RESET</button></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,D,nodes,bonds,sparks,recentB;
var cols,rows,sp,ox,oy;
var mode=0,tension=4,disorder=5;
var autoStr=0,resetCD=0;
var grabbed=-1,mx=0,my=0,mDown=false;
var totalB=0,intactB=0,crackN=0,maxNS=0;
var sinceBreak=0,initCracks=0;

var hB=document.getElementById('h-bonds'),hT=document.getElementById('h-total');
var hS=document.getElementById('h-strain'),hC=document.getElementById('h-cracks');

function resize(){
  D=Math.min(2,devicePixelRatio||1);
  W=C.width=Math.floor(innerWidth*D);H=C.height=Math.floor(innerHeight*D);
  C.style.width=innerWidth+'px';C.style.height=innerHeight+'px';
}

function init(){
  sp=Math.max(10*D,Math.min(28*D,Math.floor(Math.min(W,H)/42)));
  cols=Math.floor((W-sp*4)/sp)+1;rows=Math.floor((H-sp*4)/sp)+1;
  while(cols*rows>3200){sp+=D;cols=Math.floor((W-sp*4)/sp)+1;rows=Math.floor((H-sp*4)/sp)+1;}
  if(cols<5)cols=5;if(rows<5)rows=5;
  ox=(W-(cols-1)*sp)/2;oy=(H-(rows-1)*sp)/2;

  nodes=[];bonds=[];sparks=[];recentB=[];
  autoStr=0;resetCD=0;crackN=0;maxNS=0;grabbed=-1;mDown=false;sinceBreak=0;

  for(var r=0;r<rows;r++)
    for(var c=0;c<cols;c++){
      var x=ox+c*sp,y=oy+r*sp;
      nodes.push({x:x,y:y,px:x,py:y,fx:0,fy:0,ox:x,oy:y,fixed:r===0||r===rows-1});
    }

  var dg=sp*Math.SQRT2,df=disorder/10;
  for(var r=0;r<rows;r++)
    for(var c=0;c<cols;c++){
      var i=r*cols+c;
      if(c<cols-1)bonds.push(mkB(i,i+1,sp,df));
      if(r<rows-1)bonds.push(mkB(i,i+cols,sp,df));
      if(c<cols-1&&r<rows-1)bonds.push(mkB(i,i+cols+1,dg,df));
      if(c>0&&r<rows-1)bonds.push(mkB(i,i+cols-1,dg,df));
    }
  totalB=bonds.length;intactB=totalB;

  var midR=Math.floor(rows/2),nL=Math.max(4,Math.floor(cols*0.08));
  for(var b=0;b<bonds.length;b++){
    var bd=bonds[b];
    var ra=Math.floor(bd.a/cols),ca=bd.a%cols;
    var rb=Math.floor(bd.b/cols),cb=bd.b%cols;
    if(ra!==rb&&Math.min(ra,rb)===midR&&ca<nL&&cb<nL){
      bd.broken=true;crackN++;intactB--;
    }
  }
  initCracks=crackN;
}

function mkB(a,b,rest,df){
  return{a:a,b:b,rest:rest,stiff:0.3,
    thresh:0.07+0.05*(1-df)+Math.random()*(0.24*df+0.04),
    broken:false,strain:0,bt:0};
}

function step(){
  if(resetCD>0){
    resetCD--;
    if(!resetCD){init();if(typeof SFX!=='undefined')SFX.play('click');return;}
  }

  var damp=0.97,mV=sp*0.25;

  if(mode===0&&!resetCD){
    autoStr+=tension*0.0012*sp;
    for(var c=0;c<cols;c++){
      var ti=c,bi=(rows-1)*cols+c;
      nodes[ti].y=nodes[ti].oy-autoStr;nodes[ti].py=nodes[ti].y;
      nodes[bi].y=nodes[bi].oy+autoStr;nodes[bi].py=nodes[bi].y;
    }
  }

  if(grabbed>=0&&mDown&&mode===1){
    nodes[grabbed].x=mx;nodes[grabbed].y=my;
    nodes[grabbed].px=mx;nodes[grabbed].py=my;
  }

  for(var i=0;i<nodes.length;i++){nodes[i].fx=0;nodes[i].fy=0;}

  for(var b=0;b<bonds.length;b++){
    var bd=bonds[b];if(bd.broken)continue;
    var na=nodes[bd.a],nb=nodes[bd.b];
    var dx=nb.x-na.x,dy=nb.y-na.y;
    var d=Math.sqrt(dx*dx+dy*dy);
    if(d<0.01)continue;
    bd.strain=(d-bd.rest)/bd.rest;
    var f=bd.stiff*bd.strain,fx=f*dx/d,fy=f*dy/d;
    na.fx+=fx;na.fy+=fy;nb.fx-=fx;nb.fy-=fy;
  }

  for(var i=0;i<nodes.length;i++){
    var n=nodes[i];
    if(n.fixed)continue;
    if(i===grabbed&&mDown&&mode===1)continue;
    var vx=(n.x-n.px)*damp,vy=(n.y-n.py)*damp;
    var vm=Math.sqrt(vx*vx+vy*vy);
    if(vm>mV){var s=mV/vm;vx*=s;vy*=s;}
    n.px=n.x;n.py=n.y;
    n.x+=vx+n.fx*0.3;n.y+=vy+n.fy*0.3;
  }

  var broke=false;maxNS=0;
  for(var b=0;b<bonds.length;b++){
    var bd=bonds[b];if(bd.broken)continue;
    var ns=Math.abs(bd.strain)/bd.thresh;
    if(ns>maxNS)maxNS=ns;
    if(ns>1){
      bd.broken=true;bd.bt=performance.now();
      crackN++;intactB--;broke=true;recentB.push(b);
      var na=nodes[bd.a],nb=nodes[bd.b];
      var cx=(na.x+nb.x)/2,cy=(na.y+nb.y)/2;
      for(var s=0;s<3;s++)
        sparks.push({x:cx,y:cy,vx:(Math.random()-0.5)*4*D,vy:(Math.random()-0.5)*4*D,
          life:1,dec:0.025+Math.random()*0.02});
    }
  }
  if(broke){sinceBreak=0;if(typeof SFX!=='undefined')SFX.play('pop',40);}
  else sinceBreak++;

  for(var s=sparks.length-1;s>=0;s--){
    var sk=sparks[s];sk.x+=sk.vx;sk.y+=sk.vy;
    sk.life-=sk.dec;if(sk.life<=0)sparks.splice(s,1);
  }

  if(mode===0&&!resetCD){
    if(crackN>initCracks+5&&sinceBreak>150)resetCD=120;
    else if(intactB<totalB*0.3)resetCD=60;
  }
}

function draw(){
  X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);

  var p0=[],p1=[],p2=[],p3=[],p4=[];
  for(var b=0;b<bonds.length;b++){
    var bd=bonds[b];if(bd.broken)continue;
    var ns=Math.abs(bd.strain)/bd.thresh;
    if(ns<0.3)p0.push(bd);
    else if(ns<0.55)p1.push(bd);
    else if(ns<0.75)p2.push(bd);
    else if(ns<0.92)p3.push(bd);
    else p4.push(bd);
  }

  var batches=[[p0,'rgba(0,245,212,0.1)',0.5],
    [p1,'rgba(0,245,212,0.28)',0.8],
    [p2,'rgba(0,245,212,0.55)',1.2],
    [p3,'rgba(123,44,191,0.7)',1.6],
    [p4,'rgba(200,130,255,0.9)',2.5]];

  for(var j=0;j<5;j++){
    var list=batches[j][0];if(!list.length)continue;
    X.strokeStyle=batches[j][1];X.lineWidth=batches[j][2]*D;
    X.beginPath();
    for(var i=0;i<list.length;i++){
      X.moveTo(nodes[list[i].a].x,nodes[list[i].a].y);
      X.lineTo(nodes[list[i].b].x,nodes[list[i].b].y);
    }
    X.stroke();
  }

  var now=performance.now();
  for(var i=recentB.length-1;i>=0;i--){
    var bd=bonds[recentB[i]],age=now-bd.bt;
    if(age>400){recentB.splice(i,1);continue;}
    var a=(1-age/400)*0.6;
    X.strokeStyle='rgba(255,255,255,'+a+')';
    X.lineWidth=(2-age/400*1.5)*D;
    X.beginPath();
    X.moveTo(nodes[bd.a].x,nodes[bd.a].y);
    X.lineTo(nodes[bd.b].x,nodes[bd.b].y);
    X.stroke();
  }

  for(var s=0;s<sparks.length;s++){
    var sk=sparks[s];
    X.fillStyle='rgba(0,245,212,'+sk.life+')';
    X.fillRect(sk.x-D,sk.y-D,2*D,2*D);
  }

  hB.textContent=intactB;hT.textContent=totalB;
  hS.textContent=Math.round(maxNS*100)+'%';hC.textContent=crackN;
}

function loop(){step();draw();requestAnimationFrame(loop);}

function gp(e){
  var r=C.getBoundingClientRect(),t=e.touches?e.touches[0]:e;
  return{x:(t.clientX-r.left)*D,y:(t.clientY-r.top)*D};
}

function findN(x,y){
  var best=-1,bd=sp*1.8;
  for(var i=0;i<nodes.length;i++){
    if(nodes[i].fixed)continue;
    var dx=nodes[i].x-x,dy=nodes[i].y-y,d=Math.sqrt(dx*dx+dy*dy);
    if(d<bd){bd=d;best=i;}
  }return best;
}

function cutAt(x,y){
  var r2=sp*sp*4,cnt=0;
  for(var b=0;b<bonds.length;b++){
    var bd=bonds[b];if(bd.broken)continue;
    var cx=(nodes[bd.a].x+nodes[bd.b].x)/2;
    var cy=(nodes[bd.a].y+nodes[bd.b].y)/2;
    if((cx-x)*(cx-x)+(cy-y)*(cy-y)<r2){
      bd.broken=true;bd.bt=performance.now();
      crackN++;intactB--;cnt++;recentB.push(b);
      for(var s=0;s<2;s++)
        sparks.push({x:cx,y:cy,vx:(Math.random()-0.5)*3*D,vy:(Math.random()-0.5)*3*D,
          life:1,dec:0.03+Math.random()*0.02});
    }
  }
  if(cnt&&typeof SFX!=='undefined')SFX.play('tick');
}

function doImpact(x,y){
  var rad=sp*3.5,str=sp*0.5;
  for(var i=0;i<nodes.length;i++){
    if(nodes[i].fixed)continue;
    var dx=nodes[i].x-x,dy=nodes[i].y-y;
    var d=Math.sqrt(dx*dx+dy*dy);
    if(d<rad&&d>0.1){var f=str*(1-d/rad);nodes[i].px-=dx/d*f;nodes[i].py-=dy/d*f;}
  }
  if(typeof SFX!=='undefined')SFX.play('collide');
}

C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  e.preventDefault();var p=gp(e);mx=p.x;my=p.y;mDown=true;
  if(mode===0)cutAt(p.x,p.y);
  else if(mode===1){grabbed=findN(p.x,p.y);if(grabbed>=0&&typeof SFX!=='undefined')SFX.play('spawn');}
  else doImpact(p.x,p.y);
});
C.addEventListener('mousemove',function(e){
  if(!mDown)return;var p=gp(e);mx=p.x;my=p.y;
  if(mode===0)cutAt(p.x,p.y);
});
C.addEventListener('mouseup',function(){mDown=false;grabbed=-1;});
C.addEventListener('mouseleave',function(){mDown=false;grabbed=-1;});
C.addEventListener('contextmenu',function(e){e.preventDefault();cutAt(gp(e).x,gp(e).y);});

C.addEventListener('touchstart',function(e){
  if(e.touches.length!==1)return;
  e.preventDefault();var p=gp(e);mx=p.x;my=p.y;mDown=true;
  if(mode===0)cutAt(p.x,p.y);
  else if(mode===1){grabbed=findN(p.x,p.y);if(grabbed>=0&&typeof SFX!=='undefined')SFX.play('spawn');}
  else doImpact(p.x,p.y);
},{passive:false});
C.addEventListener('touchmove',function(e){
  if(!mDown)return;e.preventDefault();var p=gp(e);mx=p.x;my=p.y;
  if(mode===0)cutAt(p.x,p.y);
},{passive:false});
C.addEventListener('touchend',function(){mDown=false;grabbed=-1;});

var btnA=document.getElementById('btn-auto'),btnP=document.getElementById('btn-pull');
var btnI=document.getElementById('btn-impact'),btnR=document.getElementById('btn-reset');

function setMode(m){
  mode=m;grabbed=-1;mDown=false;
  btnA.classList.toggle('active',m===0);
  btnP.classList.toggle('active',m===1);
  btnI.classList.toggle('active',m===2);
  if(typeof SFX!=='undefined')SFX.play('toggle');
}

btnA.onclick=function(){setMode(0);};
btnP.onclick=function(){setMode(1);};
btnI.onclick=function(){setMode(2);};
btnR.onclick=function(){init();if(typeof SFX!=='undefined')SFX.play('click');};

document.getElementById('sl-tension').oninput=function(){
  tension=+this.value;document.getElementById('sv-tension').textContent=this.value;
};
document.getElementById('sl-disorder').oninput=function(){
  disorder=+this.value;document.getElementById('sv-disorder').textContent=this.value;
};

window.addEventListener('resize',function(){resize();init();});
resize();init();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
