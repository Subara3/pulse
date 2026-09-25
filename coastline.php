<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('coastline');

$pageName = 'coastline';
$pageTitle = 'COASTLINE - PULSE';
$pageDesc = '海岸線の長さは、定規の長さで変わる。短い定規ほど入り組みを拾い、答えが伸びる。その伸び方がフラクタル次元。';
$pageCanonical = 'https://pulse.subara3.com/coastline.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.5);font:12px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #hud .val{color:rgba(0,245,212,.85)}
  #hud .dim{font-size:18px;color:#00f5d4}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud"></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'regenerate'],
  ['type' => 'drag', 'label' => 'ruler size'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row" id="mode-btns">
    <button class="ctrl-btn active" data-m="0">MEASURE</button>
    <button class="ctrl-btn" data-m="1">STEP</button>
    <button class="ctrl-btn" data-m="2">PREDICT</button>
  </div>
  <div class="slider-row">
    <label class="slider-label">ROUGHNESS (H)</label>
    <input type="range" id="sl-hurst" min="10" max="90" value="50">
    <span class="slider-value" id="sv-hurst">0.50</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">RULER</label>
    <input type="range" id="sl-ruler" min="5" max="95" value="70">
    <span class="slider-value" id="sv-ruler">70</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">DEPTH</label>
    <input type="range" id="sl-depth" min="3" max="11" value="8">
    <span class="slider-value" id="sv-depth">8</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-regen">REGENERATE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C=document.getElementById('canvas'),X=C.getContext('2d');
var W,H,dpr;
var TEAL=[0,245,212],PURP=[123,44,191];
var PI=Math.PI;

var coast=[];
var stepCoasts=[];
var rulerPts=[];
var measurements=[];
var mode=0;
var hurst=0.5;
var rulerFrac=0.7;
var depth=8;
var stepLevel=0;
var stepAnim=0;
var seed=Date.now();
var predictRevealed=false;
var dragStart=null;
var lastFrame=0;
var autoRuler=true;
var autoRulerT=0;

function rgba(c,a){return'rgba('+c[0]+','+c[1]+','+c[2]+','+a+')';}
function brandHue(t){
  t=t<0?0:t>1?1:t;
  var e=t<.5?.5*Math.pow(2*t,3.2):1-.5*Math.pow(2*(1-t),3.2);
  return 172+e*118;
}
function hsl2rgb(h,s,l){
  s/=100;l/=100;
  var c=(1-Math.abs(2*l-1))*s,x=c*(1-Math.abs((h/60)%2-1)),m=l-c/2;
  var r,g,b;
  if(h<60){r=c;g=x;b=0;}else if(h<120){r=x;g=c;b=0;}
  else if(h<180){r=0;g=c;b=x;}else if(h<240){r=0;g=x;b=c;}
  else if(h<300){r=x;g=0;b=c;}else{r=c;g=0;b=x;}
  return[Math.round((r+m)*255),Math.round((g+m)*255),Math.round((b+m)*255)];
}

function seededRandom(s){
  return function(){
    s=(s*16807+0)%2147483647;
    return(s&0x7fffffff)/2147483647;
  };
}

function resize(){
  dpr=window.devicePixelRatio||1;
  W=window.innerWidth;H=window.innerHeight;
  C.width=W*dpr;C.height=H*dpr;
  C.style.width=W+'px';C.style.height=H+'px';
  X.setTransform(dpr,0,0,dpr,0,0);
  generate();
}

function generate(){
  var rng=seededRandom(seed);
  var margin=60;
  var xStart=margin,xEnd=W-margin;
  var yCenter=H*0.42;
  var span=xEnd-xStart;
  var amp=Math.min(H*0.28,span*0.3);

  stepCoasts=[];
  var pts=[{x:xStart,y:yCenter},{x:xEnd,y:yCenter}];
  stepCoasts.push(pts.slice());

  for(var d=0;d<depth;d++){
    var newPts=[pts[0]];
    var scale=amp*Math.pow(0.5,(d+1)*hurst);
    for(var i=0;i<pts.length-1;i++){
      var mx=(pts[i].x+pts[i+1].x)/2;
      var my=(pts[i].y+pts[i+1].y)/2+(rng()-0.5)*2*scale;
      newPts.push({x:mx,y:my});
      newPts.push(pts[i+1]);
    }
    pts=newPts;
    stepCoasts.push(pts.slice());
  }
  coast=pts;
  measureAll();
  if(mode===1){stepLevel=0;stepAnim=0;}
}

function totalLength(pts){
  var L=0;
  for(var i=1;i<pts.length;i++){
    var dx=pts[i].x-pts[i-1].x,dy=pts[i].y-pts[i-1].y;
    L+=Math.sqrt(dx*dx+dy*dy);
  }
  return L;
}

function measureWithRuler(pts,rulerLen){
  if(pts.length<2||rulerLen<2)return{count:0,length:0,endpoints:[]};
  var endpoints=[{x:pts[0].x,y:pts[0].y}];
  var cx=pts[0].x,cy=pts[0].y;
  var idx=1;
  var count=0;
  while(idx<pts.length){
    var dx=pts[idx].x-cx,dy=pts[idx].y-cy;
    var d=Math.sqrt(dx*dx+dy*dy);
    if(d>=rulerLen){
      var frac=rulerLen/d;
      cx=cx+dx*frac;
      cy=cy+dy*frac;
      count++;
      endpoints.push({x:cx,y:cy});
    }else{
      idx++;
    }
  }
  return{count:count,length:count*rulerLen,endpoints:endpoints};
}

function measureAll(){
  measurements=[];
  var L=totalLength(coast);
  var steps=12;
  for(var i=0;i<steps;i++){
    var frac=0.02+0.6*i/(steps-1);
    var rl=L*frac;
    var m=measureWithRuler(coast,rl);
    if(m.count>0){
      measurements.push({ruler:rl,measured:m.length,count:m.count,frac:frac});
    }
  }
  updateRulerMeasurement();
}

function updateRulerMeasurement(){
  var L=totalLength(coast);
  var rl=L*rulerFrac;
  var m=measureWithRuler(coast,rl);
  rulerPts=m.endpoints;
}

function fractalDimension(){
  if(measurements.length<3)return 1;
  var n=measurements.length;
  var sx=0,sy=0,sxx=0,sxy=0;
  for(var i=0;i<n;i++){
    var lx=Math.log(measurements[i].ruler);
    var ly=Math.log(measurements[i].measured);
    sx+=lx;sy+=ly;sxx+=lx*lx;sxy+=lx*ly;
  }
  var slope=(n*sxy-sx*sy)/(n*sxx-sx*sx);
  return 1-slope;
}

function draw(ts){
  requestAnimationFrame(draw);
  var dt=Math.min((ts-lastFrame)/1000,.05);
  lastFrame=ts;

  X.fillStyle='#0a0a0f';
  X.fillRect(0,0,W,H);

  if(mode===0)drawMeasureMode(dt);
  else if(mode===1)drawStepMode(dt);
  else drawPredictMode(dt);

  drawLogLogPlot();
  updateHUD();
}

function drawCoastline(pts,alpha,lw){
  if(pts.length<2)return;
  X.beginPath();
  X.moveTo(pts[0].x,pts[0].y);
  for(var i=1;i<pts.length;i++)X.lineTo(pts[i].x,pts[i].y);
  X.strokeStyle=rgba(TEAL,alpha);
  X.lineWidth=lw||1.5;
  X.stroke();
}

function drawCoastGlow(pts){
  X.save();
  X.globalCompositeOperation='screen';
  X.beginPath();
  X.moveTo(pts[0].x,pts[0].y);
  for(var i=1;i<pts.length;i++)X.lineTo(pts[i].x,pts[i].y);
  X.strokeStyle=rgba(TEAL,.08);
  X.lineWidth=8;
  X.stroke();
  X.restore();
}

function drawRulers(){
  if(rulerPts.length<2)return;
  for(var i=0;i<rulerPts.length-1;i++){
    var t=i/(rulerPts.length-1);
    var h=brandHue(t);
    var col=hsl2rgb(h,65,50);
    X.beginPath();
    X.moveTo(rulerPts[i].x,rulerPts[i].y);
    X.lineTo(rulerPts[i+1].x,rulerPts[i+1].y);
    X.strokeStyle=rgba(col,.7);
    X.lineWidth=2.5;
    X.stroke();

    X.beginPath();
    X.arc(rulerPts[i].x,rulerPts[i].y,3,0,PI*2);
    X.fillStyle=rgba(TEAL,.8);
    X.fill();
  }
  var last=rulerPts[rulerPts.length-1];
  X.beginPath();
  X.arc(last.x,last.y,3,0,PI*2);
  X.fillStyle=rgba(TEAL,.8);
  X.fill();
}

function drawMeasureMode(dt){
  if(autoRuler){
    autoRulerT+=dt*0.08;
    if(autoRulerT>1)autoRulerT=0;
    rulerFrac=0.05+0.55*(0.5-0.5*Math.cos(autoRulerT*PI*2));
    document.getElementById('sl-ruler').value=Math.round(rulerFrac*100);
    document.getElementById('sv-ruler').textContent=Math.round(rulerFrac*100);
    updateRulerMeasurement();
  }
  drawCoastline(coast,.75,1.5);
  drawCoastGlow(coast);
  drawRulers();
}

function drawStepMode(dt){
  stepAnim+=dt*1.2;
  var target=Math.min(stepLevel,stepCoasts.length-1);
  var showIdx=Math.min(Math.floor(stepAnim),stepCoasts.length-1);

  if(showIdx<stepCoasts.length-1){
    var prev=stepCoasts[showIdx];
    var next=stepCoasts[Math.min(showIdx+1,stepCoasts.length-1)];
    var frac=stepAnim-showIdx;
    frac=Math.min(frac,1);

    drawCoastline(prev,.3,1);

    if(frac>0&&next.length>0){
      var count=Math.floor(frac*next.length);
      if(count>1){
        X.beginPath();
        X.moveTo(next[0].x,next[0].y);
        for(var i=1;i<count;i++)X.lineTo(next[i].x,next[i].y);
        X.strokeStyle=rgba(TEAL,.8);
        X.lineWidth=1.5;
        X.stroke();
      }
    }
  }else{
    drawCoastline(stepCoasts[showIdx],.8,1.5);
    drawCoastGlow(stepCoasts[showIdx]);
  }

  // Level indicator
  X.fillStyle=rgba(TEAL,.4);
  X.font='11px "Space Grotesk",monospace';
  X.fillText('LEVEL '+showIdx+'/'+depth,W/2-30,30);
}

function drawPredictMode(dt){
  drawCoastline(coast,.75,1.5);
  drawCoastGlow(coast);

  if(!predictRevealed){
    X.fillStyle=rgba(TEAL,.4);
    X.font='14px "Space Grotesk",monospace';
    X.textAlign='center';
    X.fillText('GUESS THE DIMENSION',W/2,H*0.78);
    X.font='11px "Space Grotesk",monospace';
    X.fillText('click to reveal',W/2,H*0.78+22);
    X.textAlign='left';
  }else{
    drawRulers();
    var D=fractalDimension();
    X.fillStyle='#00f5d4';
    X.font='bold 48px "Space Grotesk",sans-serif';
    X.textAlign='center';
    X.fillText('D = '+D.toFixed(2),W/2,H*0.78);
    X.textAlign='left';
  }
}

function drawLogLogPlot(){
  if(measurements.length<3)return;
  if(mode===2&&!predictRevealed)return;

  var plotW=160,plotH=100;
  var plotX=W-plotW-20,plotY=H-plotH-80;

  X.fillStyle='rgba(0,245,212,.03)';
  X.fillRect(plotX,plotY,plotW,plotH);
  X.strokeStyle='rgba(0,245,212,.1)';
  X.lineWidth=1;
  X.strokeRect(plotX,plotY,plotW,plotH);

  var lrs=[],lms=[];
  for(var i=0;i<measurements.length;i++){
    lrs.push(Math.log(measurements[i].ruler));
    lms.push(Math.log(measurements[i].measured));
  }
  var minLR=lrs[0],maxLR=lrs[lrs.length-1];
  var minLM=Math.min.apply(null,lms),maxLM=Math.max.apply(null,lms);
  var rngR=maxLR-minLR||1,rngM=maxLM-minLM||1;

  // Data points
  for(var i=0;i<measurements.length;i++){
    var px=plotX+10+(lrs[i]-minLR)/rngR*(plotW-20);
    var py=plotY+plotH-10-(lms[i]-minLM)/rngM*(plotH-20);
    X.beginPath();
    X.arc(px,py,2.5,0,PI*2);
    X.fillStyle=rgba(TEAL,.7);
    X.fill();
  }

  // Regression line
  var n=lrs.length,sx=0,sy=0,sxx=0,sxy=0;
  for(var i=0;i<n;i++){sx+=lrs[i];sy+=lms[i];sxx+=lrs[i]*lrs[i];sxy+=lrs[i]*lms[i];}
  var slope=(n*sxy-sx*sy)/(n*sxx-sx*sx);
  var intercept=(sy-slope*sx)/n;
  var y0=slope*minLR+intercept,y1=slope*maxLR+intercept;
  var px0=plotX+10,px1=plotX+plotW-10;
  var py0=plotY+plotH-10-(y0-minLM)/rngM*(plotH-20);
  var py1=plotY+plotH-10-(y1-minLM)/rngM*(plotH-20);
  X.beginPath();X.moveTo(px0,py0);X.lineTo(px1,py1);
  X.strokeStyle=rgba(PURP,.5);X.lineWidth=1;X.stroke();

  // Current ruler position highlight
  var L=totalLength(coast);
  var currRL=L*rulerFrac;
  var currLR=Math.log(currRL);
  if(currLR>=minLR&&currLR<=maxLR){
    var hx=plotX+10+(currLR-minLR)/rngR*(plotW-20);
    X.beginPath();X.moveTo(hx,plotY);X.lineTo(hx,plotY+plotH);
    X.strokeStyle=rgba(TEAL,.2);X.lineWidth=1;X.stroke();
  }

  // Labels
  X.fillStyle=rgba(TEAL,.3);
  X.font='9px "Space Grotesk",monospace';
  X.fillText('log(ruler)',plotX+plotW-55,plotY+plotH+12);
  X.save();
  X.translate(plotX-4,plotY+plotH-10);
  X.rotate(-PI/2);
  X.fillText('log(length)',0,0);
  X.restore();

  var D=1-slope;
  X.fillStyle=rgba(TEAL,.5);
  X.font='10px "Space Grotesk",monospace';
  X.fillText('D = '+D.toFixed(2),plotX+4,plotY-4);
}

function updateHUD(){
  if(mode===2&&!predictRevealed){
    document.getElementById('hud').innerHTML='<div>H <span class="val">'+hurst.toFixed(2)+'</span></div>';
    return;
  }
  var D=fractalDimension();
  var L=totalLength(coast);
  var rl=L*rulerFrac;
  var m=measureWithRuler(coast,rl);
  document.getElementById('hud').innerHTML=
    '<div class="dim">D = '+D.toFixed(2)+'</div>'+
    '<div>H <span class="val">'+hurst.toFixed(2)+'</span></div>'+
    '<div>RULER <span class="val">'+Math.round(rl)+'px</span></div>'+
    '<div>COUNT <span class="val">'+m.count+'</span></div>'+
    '<div>LENGTH <span class="val">'+Math.round(m.length)+'px</span></div>';
}

// --- Events ---
C.addEventListener('mousedown',function(e){
  if(e.button===2)return;
  e.preventDefault();
  if(mode===2&&!predictRevealed){
    predictRevealed=true;
    SFX.play('success');
    return;
  }
  autoRuler=false;
  dragStart={x:e.clientX,startFrac:rulerFrac};
});

C.addEventListener('mousemove',function(e){
  if(!dragStart)return;
  var dx=e.clientX-dragStart.x;
  rulerFrac=Math.max(0.02,Math.min(0.6,dragStart.startFrac+dx*0.001));
  document.getElementById('sl-ruler').value=Math.round(rulerFrac*100);
  document.getElementById('sv-ruler').textContent=Math.round(rulerFrac*100);
  updateRulerMeasurement();
  SFX.play('move',100);
});

C.addEventListener('mouseup',function(){
  if(dragStart&&Math.abs(dragStart.startFrac-rulerFrac)<0.01){
    seed=Date.now();generate();
    SFX.play('spawn');
  }
  dragStart=null;
});

C.addEventListener('contextmenu',function(e){
  e.preventDefault();
  seed=Date.now();generate();
  SFX.play('click');
});

var touchID=null,touchStartX=0,touchStartFrac=0,touchMoved=false;
C.addEventListener('touchstart',function(e){
  if(touchID!==null)return;
  e.preventDefault();
  var t=e.changedTouches[0];
  touchID=t.identifier;
  touchStartX=t.clientX;
  touchStartFrac=rulerFrac;
  touchMoved=false;
  if(mode===2&&!predictRevealed){
    predictRevealed=true;
    SFX.play('success');
    touchID=null;
    return;
  }
  autoRuler=false;
},{passive:false});

C.addEventListener('touchmove',function(e){
  for(var i=0;i<e.changedTouches.length;i++){
    if(e.changedTouches[i].identifier===touchID){
      e.preventDefault();
      var dx=e.changedTouches[i].clientX-touchStartX;
      if(Math.abs(dx)>5)touchMoved=true;
      rulerFrac=Math.max(0.02,Math.min(0.6,touchStartFrac+dx*0.002));
      document.getElementById('sl-ruler').value=Math.round(rulerFrac*100);
      document.getElementById('sv-ruler').textContent=Math.round(rulerFrac*100);
      updateRulerMeasurement();
      SFX.play('move',120);
    }
  }
},{passive:false});

C.addEventListener('touchend',function(e){
  for(var i=0;i<e.changedTouches.length;i++){
    if(e.changedTouches[i].identifier===touchID){
      if(!touchMoved){
        seed=Date.now();generate();
        SFX.play('spawn');
      }
      touchID=null;
    }
  }
});

// Controls
document.querySelectorAll('#mode-btns .ctrl-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    mode=parseInt(this.dataset.m);
    document.querySelectorAll('#mode-btns .ctrl-btn').forEach(function(b){b.classList.remove('active');});
    this.classList.add('active');
    if(mode===1){stepLevel=depth;stepAnim=0;}
    if(mode===2){predictRevealed=false;}
    SFX.play('toggle');
  });
});

document.getElementById('sl-hurst').addEventListener('input',function(){
  hurst=parseInt(this.value)/100;
  document.getElementById('sv-hurst').textContent=hurst.toFixed(2);
  generate();
});

document.getElementById('sl-ruler').addEventListener('input',function(){
  autoRuler=false;
  rulerFrac=parseInt(this.value)/100;
  document.getElementById('sv-ruler').textContent=this.value;
  updateRulerMeasurement();
});

document.getElementById('sl-depth').addEventListener('input',function(){
  depth=parseInt(this.value);
  document.getElementById('sv-depth').textContent=this.value;
  generate();
});

document.getElementById('btn-regen').addEventListener('click',function(){
  seed=Date.now();
  generate();
  SFX.play('click');
});

resize();
window.addEventListener('resize',resize);
requestAnimationFrame(draw);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
