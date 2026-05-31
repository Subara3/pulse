<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spanning');

$pageName = 'spanning';
$pageTitle = 'SPANNING - PULSE';
$pageDesc = '最小全域木。漂う点群をリアルタイムで最短結合し続ける。Primのアルゴリズムが描く、生きたネットワーク。';
$pageCanonical = 'https://pulse.subara3.com/spanning.php';

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
  NODES: <span class="v" id="hud-nodes">0</span><br>
  EDGES: <span class="v" id="hud-edges">0</span><br>
  COST: <span class="v" id="hud-cost">0</span>
</div>

<div id="info">
  <span class="v">Minimum Spanning Tree</span><br>
  Prim's algorithm · real-time
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add node'],
  ['type' => 'drag', 'label' => 'move node'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">DRIFT</span>
      <input type="range" id="drift-slider" min="0" max="10" value="3">
      <span class="slider-value" id="drift-val">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">NODES</span>
      <input type="range" id="count-slider" min="5" max="200" value="40">
      <span class="slider-value" id="count-val">40</span>
    </div>
  </div>
  <div>
    <span class="slider-label">STYLE</span>
    <div class="btn-row" id="style-btns">
      <button class="control-btn active" data-style="tree">TREE</button>
      <button class="control-btn" data-style="pulse">PULSE</button>
      <button class="control-btn" data-style="glow">GLOW</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="scatter-btn">SCATTER</button>
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H;
  var mobile='ontouchstart' in window;

  var nodes=[],edges=[];
  var driftSpeed=3,targetCount=40;
  var style='tree';
  var time=0;
  var dragNode=null,dragOffX=0,dragOffY=0;
  var pulsePhases=[];
  var edgeAge=[];

  function hsl2rgb(h,s,l){
    h/=360;
    if(s===0){var v=(l*255)|0;return [v,v,v];}
    function hue(p,q,t){
      if(t<0)t++;if(t>1)t--;
      if(t<1/6)return p+(q-p)*6*t;
      if(t<1/2)return q;
      if(t<2/3)return p+(q-p)*(2/3-t)*6;
      return p;
    }
    var q=l<0.5?l*(1+s):l+s-l*s,p=2*l-q;
    return [(hue(p,q,h+1/3)*255)|0,(hue(p,q,h)*255)|0,(hue(p,q,h-1/3)*255)|0];
  }

  function addNode(x,y){
    var vx=(Math.random()-0.5)*0.5;
    var vy=(Math.random()-0.5)*0.5;
    nodes.push({x:x,y:y,vx:vx,vy:vy,r:3+Math.random()*2});
    pulsePhases.push(Math.random()*Math.PI*2);
  }

  function removeNode(idx){
    nodes.splice(idx,1);
    pulsePhases.splice(idx,1);
  }

  function initNodes(n){
    nodes=[];pulsePhases=[];
    var margin=80;
    for(var i=0;i<n;i++){
      addNode(margin+Math.random()*(W-2*margin),margin+Math.random()*(H-2*margin));
    }
  }

  function dist(a,b){
    var dx=a.x-b.x,dy=a.y-b.y;
    return Math.sqrt(dx*dx+dy*dy);
  }

  function computeMST(){
    var n=nodes.length;
    if(n<2){edges=[];edgeAge=[];return;}

    var inMST=new Uint8Array(n);
    var minCost=new Float32Array(n);
    var minEdge=new Int32Array(n);
    var newEdges=[];

    for(var i=0;i<n;i++){minCost[i]=1e30;minEdge[i]=-1;}
    inMST[0]=1;

    for(var i=1;i<n;i++){
      var d=dist(nodes[0],nodes[i]);
      minCost[i]=d;
      minEdge[i]=0;
    }

    for(var iter=1;iter<n;iter++){
      var u=-1,best=1e30;
      for(var i=0;i<n;i++){
        if(!inMST[i]&&minCost[i]<best){
          best=minCost[i];u=i;
        }
      }
      if(u===-1)break;
      inMST[u]=1;
      newEdges.push({a:minEdge[u],b:u,cost:best});

      for(var i=0;i<n;i++){
        if(!inMST[i]){
          var d=dist(nodes[u],nodes[i]);
          if(d<minCost[i]){
            minCost[i]=d;
            minEdge[i]=u;
          }
        }
      }
    }

    var oldMap={};
    for(var i=0;i<edges.length;i++){
      var e=edges[i];
      var key=Math.min(e.a,e.b)+'_'+Math.max(e.a,e.b);
      oldMap[key]=edgeAge[i]||0;
    }

    edgeAge=[];
    for(var i=0;i<newEdges.length;i++){
      var e=newEdges[i];
      var key=Math.min(e.a,e.b)+'_'+Math.max(e.a,e.b);
      edgeAge.push(oldMap[key]!==undefined?oldMap[key]:0);
    }
    edges=newEdges;
  }

  function updateNodes(dt){
    var ds=driftSpeed*0.15;
    var margin=30;
    for(var i=0;i<nodes.length;i++){
      if(nodes[i]===dragNode)continue;
      var nd=nodes[i];
      nd.vx+=(Math.random()-0.5)*0.1*ds;
      nd.vy+=(Math.random()-0.5)*0.1*ds;
      var spd=Math.sqrt(nd.vx*nd.vx+nd.vy*nd.vy);
      var maxSpd=0.3+ds*0.4;
      if(spd>maxSpd){nd.vx*=maxSpd/spd;nd.vy*=maxSpd/spd;}
      nd.x+=nd.vx;
      nd.y+=nd.vy;
      if(nd.x<margin){nd.x=margin;nd.vx=Math.abs(nd.vx);}
      if(nd.x>W-margin){nd.x=W-margin;nd.vx=-Math.abs(nd.vx);}
      if(nd.y<margin){nd.y=margin;nd.vy=Math.abs(nd.vy);}
      if(nd.y>H-margin){nd.y=H-margin;nd.vy=-Math.abs(nd.vy);}
    }
  }

  function render(){
    X.fillStyle='#0a0a0f';
    X.fillRect(0,0,W,H);

    time+=0.016;
    for(var i=0;i<edgeAge.length;i++){
      edgeAge[i]=Math.min(edgeAge[i]+0.05,1);
    }

    var totalCost=0;

    if(style==='tree'){
      for(var i=0;i<edges.length;i++){
        var e=edges[i];
        var na=nodes[e.a],nb=nodes[e.b];
        var age=edgeAge[i];
        var len=e.cost;
        var maxLen=Math.sqrt(W*W+H*H);
        var t=Math.min(len/maxLen*3,1);
        var col=hsl2rgb(175+t*80,0.7,0.35+age*0.15);
        X.strokeStyle='rgba('+col[0]+','+col[1]+','+col[2]+','+(0.3+age*0.5)+')';
        X.lineWidth=1+age*1.5;
        X.beginPath();
        X.moveTo(na.x,na.y);
        X.lineTo(nb.x,nb.y);
        X.stroke();
        totalCost+=len;
      }
    }else if(style==='pulse'){
      for(var i=0;i<edges.length;i++){
        var e=edges[i];
        var na=nodes[e.a],nb=nodes[e.b];
        var age=edgeAge[i];
        var len=e.cost;
        var pulse=Math.sin(time*3-i*0.5)*0.5+0.5;
        var col=hsl2rgb(175+pulse*80,0.8,0.3+pulse*0.25);
        X.strokeStyle='rgba('+col[0]+','+col[1]+','+col[2]+','+(0.4+age*0.4)+')';
        X.lineWidth=1+pulse*2;
        X.beginPath();
        X.moveTo(na.x,na.y);
        X.lineTo(nb.x,nb.y);
        X.stroke();

        var pt=Math.abs(((time*2+i*0.7)%2)-1);
        var px=na.x+(nb.x-na.x)*pt;
        var py=na.y+(nb.y-na.y)*pt;
        X.fillStyle='rgba(0,245,212,'+(0.5+pulse*0.5)+')';
        X.beginPath();X.arc(px,py,2,0,6.2832);X.fill();
        totalCost+=len;
      }
    }else{
      for(var i=0;i<edges.length;i++){
        var e=edges[i];
        var na=nodes[e.a],nb=nodes[e.b];
        var age=edgeAge[i];
        var len=e.cost;
        var t=Math.min(len/(Math.sqrt(W*W+H*H)*0.3),1);
        var col=hsl2rgb(175+t*80,0.8,0.4);
        X.save();
        X.shadowColor='rgba(0,245,212,0.4)';
        X.shadowBlur=8+age*6;
        X.strokeStyle='rgba('+col[0]+','+col[1]+','+col[2]+','+(0.5+age*0.4)+')';
        X.lineWidth=1.5+age;
        X.beginPath();
        X.moveTo(na.x,na.y);
        X.lineTo(nb.x,nb.y);
        X.stroke();
        X.restore();
        totalCost+=len;
      }
    }

    for(var i=0;i<nodes.length;i++){
      var nd=nodes[i];
      var ph=pulsePhases[i];
      var glow=Math.sin(time*2+ph)*0.3+0.7;
      var r=nd.r*glow;

      X.fillStyle='rgba(0,245,212,'+(0.6+glow*0.3)+')';
      X.beginPath();X.arc(nd.x,nd.y,r,0,6.2832);X.fill();

      if(style==='glow'){
        X.save();
        X.shadowColor='rgba(0,245,212,0.6)';
        X.shadowBlur=12;
        X.fillStyle='rgba(0,245,212,0.3)';
        X.beginPath();X.arc(nd.x,nd.y,r+2,0,6.2832);X.fill();
        X.restore();
      }
    }

    document.getElementById('hud-nodes').textContent=nodes.length;
    document.getElementById('hud-edges').textContent=edges.length;
    document.getElementById('hud-cost').textContent=(totalCost|0);
  }

  function findNode(x,y){
    var best=-1,bestD=30;
    for(var i=0;i<nodes.length;i++){
      var d=Math.sqrt((nodes[i].x-x)*(nodes[i].x-x)+(nodes[i].y-y)*(nodes[i].y-y));
      if(d<bestD){bestD=d;best=i;}
    }
    return best;
  }

  function loop(){
    updateNodes(16);
    computeMST();
    render();
    requestAnimationFrame(loop);
  }

  C.addEventListener('mousedown',function(e){
    if(e.button===2){
      var idx=findNode(e.clientX,e.clientY);
      if(idx>=0){removeNode(idx);SFX.play('pop');}
      return;
    }
    var idx=findNode(e.clientX,e.clientY);
    if(idx>=0){
      dragNode=nodes[idx];
      dragOffX=e.clientX-dragNode.x;
      dragOffY=e.clientY-dragNode.y;
    }else{
      addNode(e.clientX,e.clientY);
      SFX.play('spawn');
    }
  });
  C.addEventListener('mousemove',function(e){
    if(dragNode){
      dragNode.x=e.clientX-dragOffX;
      dragNode.y=e.clientY-dragOffY;
      dragNode.vx=0;dragNode.vy=0;
    }
  });
  C.addEventListener('mouseup',function(){
    if(dragNode){SFX.play('move');dragNode=null;}
  });
  C.addEventListener('contextmenu',function(e){e.preventDefault();});

  var touchDragIdx=-1;
  var touchStartTime=0;
  var touchMoved=false;
  C.addEventListener('touchstart',function(e){
    e.preventDefault();
    var t=e.touches[0];
    touchStartTime=Date.now();
    touchMoved=false;
    var idx=findNode(t.clientX,t.clientY);
    if(idx>=0){
      touchDragIdx=idx;
      dragNode=nodes[idx];
      dragOffX=t.clientX-dragNode.x;
      dragOffY=t.clientY-dragNode.y;
    }else{
      touchDragIdx=-1;
    }
  },{passive:false});
  C.addEventListener('touchmove',function(e){
    e.preventDefault();
    touchMoved=true;
    if(dragNode){
      var t=e.touches[0];
      dragNode.x=t.clientX-dragOffX;
      dragNode.y=t.clientY-dragOffY;
      dragNode.vx=0;dragNode.vy=0;
    }
  },{passive:false});
  C.addEventListener('touchend',function(e){
    e.preventDefault();
    var elapsed=Date.now()-touchStartTime;
    if(dragNode&&!touchMoved&&elapsed>300){
      removeNode(touchDragIdx);
      SFX.play('pop');
    }else if(!dragNode&&!touchMoved){
      var t=e.changedTouches[0];
      addNode(t.clientX,t.clientY);
      SFX.play('spawn');
    }else if(dragNode){
      SFX.play('move');
    }
    dragNode=null;touchDragIdx=-1;
  },{passive:false});

  document.querySelectorAll('#style-btns .control-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('#style-btns .control-btn').forEach(function(b){b.classList.remove('active');});
      btn.classList.add('active');
      style=btn.dataset.style;
      SFX.play('toggle');
    });
  });

  document.getElementById('drift-slider').addEventListener('input',function(){
    driftSpeed=parseInt(this.value);
    document.getElementById('drift-val').textContent=driftSpeed;
  });

  document.getElementById('count-slider').addEventListener('input',function(){
    targetCount=parseInt(this.value);
    document.getElementById('count-val').textContent=targetCount;
    initNodes(targetCount);
    SFX.play('click');
  });

  document.getElementById('scatter-btn').addEventListener('click',function(){
    for(var i=0;i<nodes.length;i++){
      nodes[i].vx=(Math.random()-0.5)*5;
      nodes[i].vy=(Math.random()-0.5)*5;
    }
    SFX.play('ripple');
  });

  document.getElementById('reset-btn').addEventListener('click',function(){
    initNodes(targetCount);
    SFX.play('click');
  });

  function resize(){
    W=C.width=innerWidth;H=C.height=innerHeight;
    if(nodes.length===0)initNodes(targetCount);
  }

  window.addEventListener('resize',resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
