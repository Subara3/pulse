<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lifelike');

$pageName = 'lifelike';
$pageTitle = 'LIFELIKE - PULSE';
$pageDesc = '生命のようなセルオートマトン。B/Sルール文字列で262,144通りの規則空間を探索する。';
$pageCanonical = 'https://pulse.subara3.com/lifelike.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .dim{color:rgba(181,140,255,.7)}
#presets{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
#presets .control-btn.active{border-color:#00f5d4;color:#00f5d4;background:rgba(0,245,212,.12)}
#custom-rule{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#00f5d4;padding:6px 8px;font-size:12px;border-radius:4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em;margin-bottom:12px}
#custom-rule:focus{outline:none;border-color:#00f5d4}
.ctrl-row{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.ctrl-label{font-size:10px;color:rgba(255,255,255,.5);min-width:54px;letter-spacing:.08em}
.ctrl-row input[type=range]{flex:1}
.ctrl-val{font-size:10px;color:#00f5d4;min-width:24px;text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
@media(max-width:760px){.hud{top:.6rem;left:.6rem;font-size:10px;padding:.5rem .6rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="dim">LIFELIKE</span><br><b id="hud-rule">B3/S23 · LIFE</b><br><span id="hud-stats">GEN 0 · POP 0</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'draw'],
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div id="presets"></div>
  <input type="text" id="custom-rule" placeholder="B3/S23" value="B3/S23" spellcheck="false" autocapitalize="off" autocomplete="off">
  <div class="ctrl-row">
    <span class="ctrl-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="60" value="12">
    <span class="ctrl-val" id="val-speed">12</span>
  </div>
  <div class="ctrl-row">
    <span class="ctrl-label">DENSITY</span>
    <input type="range" id="sl-density" min="5" max="80" value="30">
    <span class="ctrl-val" id="val-dens">30</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-fill">FILL</button>
    <button class="control-btn" id="btn-clear">CLEAR</button>
    <button class="control-btn" id="btn-step">STEP</button>
    <button class="control-btn" id="btn-pause">PAUSE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d');
var W,H,cols,rows,cellSz;
var grid,nxt,age;
var offC=document.createElement('canvas'),offX,imgD;
var LN=256,lut=new Uint8Array(LN*3);
var birth=new Uint8Array(9),surv=new Uint8Array(9);
var ruleStr='B3/S23',ruleNm='LIFE';
var gen=0,pop=0,running=true,spd=12,dens=30;
var painting=false,pVal=1,lgx=-1,lgy=-1;

var PRESETS=[
{n:'LIFE',r:'B3/S23'},{n:'HIGHLIFE',r:'B36/S23'},
{n:'SEEDS',r:'B2/S'},{n:'DIAMOEBA',r:'B35678/S5678'},
{n:'DAY&NIGHT',r:'B3678/S34678'},{n:'ANNEAL',r:'B4678/S35678'},
{n:'MORLEY',r:'B368/S245'},{n:'REPLICATOR',r:'B1357/S1357'}
];

function brandHue(t){
  t=t<0?0:t>1?1:t;
  var e=t<.5?.5*Math.pow(2*t,3.2):1-.5*Math.pow(2*(1-t),3.2);
  return 172+e*118;
}

function h2r(h,s,l){
  h/=360;s/=100;l/=100;
  if(!s){var v=l*255+.5|0;return[v,v,v];}
  var q=l<.5?l*(1+s):l+s-l*s,p=2*l-q;
  function f(t){t=t<0?t+1:t>1?t-1:t;return t<1/6?p+(q-p)*6*t:t<.5?q:t<2/3?p+(q-p)*(2/3-t)*6:p;}
  return[f(h+1/3)*255+.5|0,f(h)*255+.5|0,f(h-1/3)*255+.5|0];
}

function buildLUT(){
  for(var i=0;i<LN;i++){
    var t=i/(LN-1),h=brandHue(t);
    var rgb=h2r(h,85-t*20,58-t*18);
    lut[i*3]=rgb[0];lut[i*3+1]=rgb[1];lut[i*3+2]=rgb[2];
  }
}

function parseRule(s){
  var b=new Uint8Array(9),sv=new Uint8Array(9);
  var parts=s.toUpperCase().split('/');
  for(var i=0;i<parts.length;i++){
    var p=parts[i],tgt=p.charAt(0)==='B'?b:sv;
    for(var j=1;j<p.length;j++){
      var n=p.charCodeAt(j)-48;
      if(n>=0&&n<=8)tgt[n]=1;
    }
  }
  return{b:b,s:sv};
}

function setRule(s,nm){
  var r=parseRule(s);birth=r.b;surv=r.s;
  ruleStr=s;ruleNm=nm||'';
  document.getElementById('custom-rule').value=s;
  updPresets();
}

function initGrid(){
  cellSz=W<768?3:4;
  cols=(W/cellSz)|0;rows=(H/cellSz)|0;
  var sz=cols*rows;
  grid=new Uint8Array(sz);
  nxt=new Uint8Array(sz);
  age=new Uint16Array(sz);
  offC.width=cols;offC.height=rows;
  offX=offC.getContext('2d');
  imgD=offX.createImageData(cols,rows);
}

function rndFill(d){
  var cx=cols>>1,cy=rows>>1;
  var rad=Math.min(cols,rows)*.35,r2=rad*rad;
  for(var y=0;y<rows;y++){
    for(var x=0;x<cols;x++){
      var dx=x-cx,dy=y-cy,idx=y*cols+x;
      grid[idx]=(dx*dx+dy*dy<r2&&Math.random()*100<d)?1:0;
      age[idx]=grid[idx];
    }
  }
  gen=0;pop=0;
}

function clrGrid(){
  grid.fill(0);nxt.fill(0);age.fill(0);
  gen=0;pop=0;
}

function step(){
  pop=0;
  for(var y=0;y<rows;y++){
    var ym=y?y-1:rows-1,yp=y===rows-1?0:y+1;
    var yw=y*cols,ymw=ym*cols,ypw=yp*cols;
    for(var x=0;x<cols;x++){
      var xm=x?x-1:cols-1,xp=x===cols-1?0:x+1;
      var n=grid[ymw+xm]+grid[ymw+x]+grid[ymw+xp]
           +grid[yw+xm]+grid[yw+xp]
           +grid[ypw+xm]+grid[ypw+x]+grid[ypw+xp];
      var idx=yw+x,was=grid[idx];
      var alive=was?surv[n]:birth[n];
      nxt[idx]=alive;
      if(alive){
        pop++;
        age[idx]=was?(age[idx]<500?age[idx]+1:500):1;
      }else{
        age[idx]=0;
      }
    }
  }
  var t=grid;grid=nxt;nxt=t;
  gen++;
}

function render(){
  var d=imgD.data,len=cols*rows;
  for(var i=0;i<len;i++){
    var p=i<<2;
    if(grid[i]){
      var t=age[i]/300;if(t>1)t=1;
      var ci=t*(LN-1)+.5|0,c3=ci*3;
      d[p]=lut[c3];d[p+1]=lut[c3+1];d[p+2]=lut[c3+2];
    }else{
      d[p]=10;d[p+1]=10;d[p+2]=15;
    }
    d[p+3]=255;
  }
  offX.putImageData(imgD,0,0);
  ctx.imageSmoothingEnabled=false;
  ctx.drawImage(offC,0,0,W,H);
}

function updHUD(){
  var label=ruleStr;
  if(ruleNm)label+=' · '+ruleNm;
  document.getElementById('hud-rule').textContent=label;
  var pct=cols*rows?(pop/(cols*rows)*100).toFixed(1):'0';
  document.getElementById('hud-stats').textContent=
    'GEN '+gen+' · POP '+pop+' ('+pct+'%)';
}

function s2g(ex,ey){return{x:(ex/cellSz)|0,y:(ey/cellSz)|0};}

function setC(gx,gy,v){
  if(gx>=0&&gx<cols&&gy>=0&&gy<rows){
    var i=gy*cols+gx;grid[i]=v;age[i]=v?1:0;
  }
}

function drawLn(x0,y0,x1,y1,v){
  var dx=Math.abs(x1-x0),dy=Math.abs(y1-y0);
  var sx=x0<x1?1:-1,sy=y0<y1?1:-1,e=dx-dy;
  for(var i=0;i<2000;i++){
    setC(x0,y0,v);
    if(x0===x1&&y0===y1)break;
    var e2=2*e;
    if(e2>-dy){e-=dy;x0+=sx;}
    if(e2<dx){e+=dx;y0+=sy;}
  }
}

function pDown(ex,ey,btn){
  painting=true;
  pVal=btn===2?0:1;
  var g=s2g(ex,ey);
  lgx=g.x;lgy=g.y;
  setC(g.x,g.y,pVal);
  SFX.play(pVal?'spawn':'pop',30);
}

function pMove(ex,ey){
  if(!painting)return;
  var g=s2g(ex,ey);
  if(g.x!==lgx||g.y!==lgy){
    drawLn(lgx,lgy,g.x,g.y,pVal);
    lgx=g.x;lgy=g.y;
    SFX.play(pVal?'spawn':'pop',50);
  }
}

function pUp(){painting=false;}

c.addEventListener('mousedown',function(e){e.preventDefault();pDown(e.clientX,e.clientY,e.button);});
c.addEventListener('mousemove',function(e){pMove(e.clientX,e.clientY);});
window.addEventListener('mouseup',pUp);
c.addEventListener('contextmenu',function(e){e.preventDefault();});

c.addEventListener('touchstart',function(e){
  e.preventDefault();var t=e.touches[0];pDown(t.clientX,t.clientY,0);
},{passive:false});
c.addEventListener('touchmove',function(e){
  e.preventDefault();var t=e.touches[0];pMove(t.clientX,t.clientY);
},{passive:false});
c.addEventListener('touchend',function(e){e.preventDefault();pUp();},{passive:false});

function buildPre(){
  var el=document.getElementById('presets');
  PRESETS.forEach(function(p){
    var b=document.createElement('button');
    b.className='control-btn';
    b.textContent=p.n;
    b.setAttribute('data-r',p.r);
    b.addEventListener('click',function(){
      setRule(p.r,p.n);
      SFX.play('toggle');
    });
    el.appendChild(b);
  });
}

function updPresets(){
  var bs=document.querySelectorAll('#presets .control-btn');
  for(var i=0;i<bs.length;i++){
    bs[i].classList.toggle('active',bs[i].getAttribute('data-r')===ruleStr);
  }
}

function applyCustom(){
  var v=document.getElementById('custom-rule').value.trim();
  if(v){setRule(v,'');SFX.play('toggle');}
}

function initUI(){
  buildPre();updPresets();
  document.getElementById('custom-rule').addEventListener('change',applyCustom);
  document.getElementById('custom-rule').addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();applyCustom();this.blur();}
  });
  document.getElementById('sl-speed').addEventListener('input',function(){
    spd=+this.value;
    document.getElementById('val-speed').textContent=spd;
  });
  document.getElementById('sl-density').addEventListener('input',function(){
    dens=+this.value;
    document.getElementById('val-dens').textContent=dens;
  });
  document.getElementById('btn-fill').addEventListener('click',function(){
    clrGrid();rndFill(dens);SFX.play('click');
  });
  document.getElementById('btn-clear').addEventListener('click',function(){
    clrGrid();SFX.play('click');
  });
  document.getElementById('btn-step').addEventListener('click',function(){
    running=false;
    document.getElementById('btn-pause').textContent='PLAY';
    step();SFX.play('tick');
  });
  document.getElementById('btn-pause').addEventListener('click',function(){
    running=!running;
    this.textContent=running?'PAUSE':'PLAY';
    SFX.play('toggle');
  });
}

function resize(){
  W=window.innerWidth;H=window.innerHeight;
  c.width=W;c.height=H;
  var og=grid,oa=age,oc=cols,or_=rows;
  initGrid();
  if(og){
    var mc=Math.min(cols,oc||0),mr=Math.min(rows,or_||0);
    for(var y=0;y<mr;y++)
      for(var x=0;x<mc;x++){
        grid[y*cols+x]=og[y*oc+x];
        age[y*cols+x]=oa[y*oc+x];
      }
  }
}

window.addEventListener('resize',resize);

window.addEventListener('keydown',function(e){
  if(e.target.tagName==='INPUT')return;
  if(e.code==='Space'){
    e.preventDefault();
    running=!running;
    document.getElementById('btn-pause').textContent=running?'PAUSE':'PLAY';
    SFX.play('toggle');
  }
});

var lastT=0,acc=0;
function loop(now){
  if(!now)now=performance.now();
  var dt=Math.min(now-lastT,200);
  lastT=now;
  if(running){
    acc+=dt;
    var iv=1000/spd,ms=8;
    while(acc>=iv&&ms-->0){step();acc-=iv;}
    if(ms<=0)acc=0;
  }
  render();
  updHUD();
  requestAnimationFrame(loop);
}

buildLUT();
resize();
setRule('B3/S23','LIFE');
rndFill(dens);
initUI();
lastT=performance.now();
requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
