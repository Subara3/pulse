<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('stipple');

$pageName = 'stipple';
$pageTitle = 'STIPPLE - PULSE';
$pageDesc = '重み付きボロノイ・スティップリング。ロイドのアルゴリズムで点群が密度場に収束する過程を可視化。';
$pageCanonical = 'https://pulse.subara3.com/stipple.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  POINTS: <span class="v" id="hud-pts">0</span><br>
  STEP: <span class="v" id="hud-step">0</span>
</div>

<div id="info">
  <span class="v">Lloyd's algorithm</span><br>
  centroidal Voronoi tessellation
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'paint'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <span class="slider-label">PATTERN</span>
    <div class="btn-row" id="pattern-btns">
      <button class="control-btn active" data-pattern="rings">RINGS</button>
      <button class="control-btn" data-pattern="wave">WAVE</button>
      <button class="control-btn" data-pattern="spiral">SPIRAL</button>
      <button class="control-btn" data-pattern="noise">NOISE</button>
      <button class="control-btn" data-pattern="blank">BLANK</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">POINTS</span>
      <input type="range" id="pts-slider" min="300" max="4000" value="1500" step="100">
      <span class="slider-value" id="pts-val">1500</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="spd-slider" min="1" max="5" value="2">
      <span class="slider-value" id="spd-val">2</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="cells-btn">CELLS</button>
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d'),W,H;
  var G=120;
  var density=new Float32Array(G*G);
  var own=new Int32Array(G*G);
  var px=[],py=[];
  var nPts=1500,spd=2,showCells=false,iter=0;
  var painting=false,erasing=false;

  function resize(){
    var oW=W||0,oH=H||0;
    W=C.width=innerWidth;H=C.height=innerHeight;
    if(oW&&oH){
      for(var i=0;i<px.length;i++){px[i]=px[i]/oW*W;py[i]=py[i]/oH*H;}
    }
  }

  function genDensity(type){
    var cx=G/2,cy=G/2;
    for(var y=0;y<G;y++){
      for(var x=0;x<G;x++){
        var dx=x-cx,dy=y-cy,r=Math.hypot(dx,dy),a=Math.atan2(dy,dx);
        var d=0.05;
        switch(type){
          case'rings':
            d=0.05+0.95*Math.pow(Math.cos(r*0.18),2);
            if(r<6)d=1;
            break;
          case'wave':
            d=0.05+0.95*Math.pow(0.5+0.5*Math.sin(x*0.1)*Math.cos(y*0.13),2);
            break;
          case'spiral':
            var s=Math.sin(r*0.12-a*3);
            d=0.05+0.7*Math.pow(Math.max(0,s),1.5)*Math.exp(-r*0.012)+0.35*Math.exp(-r*0.02);
            break;
          case'noise':
            d=0.5+0.25*Math.sin(x*0.08+y*0.12)+0.15*Math.cos(x*0.15-y*0.05)+0.1*Math.sin(x*0.2+0.5)*Math.cos(y*0.18+0.3);
            break;
          case'blank':
            d=0.5;
            break;
        }
        density[y*G+x]=Math.max(0.01,Math.min(1,d));
      }
    }
  }

  function initPts(){
    px=[];py=[];
    var mx=0;
    for(var i=0;i<density.length;i++)if(density[i]>mx)mx=density[i];
    var att=0;
    while(px.length<nPts&&att<nPts*200){
      var gx=Math.random()*G,gy=Math.random()*G;
      var gi=Math.min(G-1,gx|0)+Math.min(G-1,gy|0)*G;
      if(Math.random()<density[gi]/mx){px.push(gx/G*W);py.push(gy/G*H);}
      att++;
    }
    iter=0;
  }

  function lloyd(){
    var N=px.length;
    if(!N)return;
    var sX=W/G,sY=H/G;
    var cz=Math.max(W,H)/Math.max(1,Math.sqrt(N))*1.8;
    var cols=(W/cz|0)+2,rows=(H/cz|0)+2;
    var bk=new Array(cols*rows);
    for(var i=0;i<bk.length;i++)bk[i]=[];
    for(var i=0;i<N;i++){
      var c=px[i]/cz|0,r=py[i]/cz|0;
      if(c>=0&&c<cols&&r>=0&&r<rows)bk[r*cols+c].push(i);
    }
    var sx=new Float64Array(N),sy=new Float64Array(N),sw=new Float64Array(N);
    for(var gy=0;gy<G;gy++){
      var ppy=(gy+.5)*sY,gr=ppy/cz|0;
      for(var gx=0;gx<G;gx++){
        var ppx=(gx+.5)*sX,gc=ppx/cz|0;
        var w=density[gy*G+gx],mn=1e18,best=0;
        for(var dr=-2;dr<=2;dr++){
          var rr=gr+dr;if(rr<0||rr>=rows)continue;
          for(var dc=-2;dc<=2;dc++){
            var cc=gc+dc;if(cc<0||cc>=cols)continue;
            var b=bk[rr*cols+cc];
            for(var k=0;k<b.length;k++){
              var idx=b[k],ddx=px[idx]-ppx,ddy=py[idx]-ppy;
              var d2=ddx*ddx+ddy*ddy;
              if(d2<mn){mn=d2;best=idx;}
            }
          }
        }
        if(mn>1e17){
          for(var j=0;j<N;j++){
            var ddx=px[j]-ppx,ddy=py[j]-ppy;
            var d2=ddx*ddx+ddy*ddy;
            if(d2<mn){mn=d2;best=j;}
          }
        }
        own[gy*G+gx]=best;
        sx[best]+=ppx*w;sy[best]+=ppy*w;sw[best]+=w;
      }
    }
    for(var i=0;i<N;i++){
      if(sw[i]>0){
        var cx=sx[i]/sw[i],cy=sy[i]/sw[i];
        px[i]+=(cx-px[i])*0.3;
        py[i]+=(cy-py[i])*0.3;
        px[i]=Math.max(2,Math.min(W-2,px[i]));
        py[i]=Math.max(2,Math.min(H-2,py[i]));
      }
    }
    iter++;
  }

  function render(){
    X.fillStyle='#0a0a0f';
    X.fillRect(0,0,W,H);
    var N=px.length;
    if(showCells){
      X.strokeStyle='rgba(123,44,191,0.18)';
      X.lineWidth=0.5;
      var sX=W/G,sY=H/G;
      X.beginPath();
      for(var gy=0;gy<G-1;gy++){
        for(var gx=0;gx<G-1;gx++){
          var o=own[gy*G+gx];
          if(o!==own[gy*G+gx+1]){
            var ex=(gx+1)*sX;
            X.moveTo(ex,gy*sY);X.lineTo(ex,(gy+1)*sY);
          }
          if(o!==own[(gy+1)*G+gx]){
            var ey=(gy+1)*sY;
            X.moveTo(gx*sX,ey);X.lineTo((gx+1)*sX,ey);
          }
        }
      }
      X.stroke();
    }
    for(var i=0;i<N;i++){
      var gx=Math.min(G-1,Math.max(0,px[i]/W*G|0));
      var gy=Math.min(G-1,Math.max(0,py[i]/H*G|0));
      var d=density[gy*G+gx];
      var radius=1+d*2.2;
      var alpha=0.35+d*0.65;
      X.fillStyle='rgba(0,245,212,'+alpha+')';
      X.beginPath();
      X.arc(px[i],py[i],radius,0,6.2832);
      X.fill();
    }
    document.getElementById('hud-pts').textContent=N;
    document.getElementById('hud-step').textContent=iter;
  }

  function paintDensity(cx,cy,val){
    var rad=Math.min(W,H)*0.04;
    var gcx=cx/W*G,gcy=cy/H*G,gr=rad/Math.max(W,H)*G*2;
    var x0=Math.max(0,(gcx-gr)|0),x1=Math.min(G-1,(gcx+gr)|0);
    var y0=Math.max(0,(gcy-gr)|0),y1=Math.min(G-1,(gcy+gr)|0);
    for(var y=y0;y<=y1;y++){
      for(var x=x0;x<=x1;x++){
        var dist=Math.hypot(x-gcx,y-gcy)/gr;
        if(dist<1){
          var infl=(1-dist*dist)*0.2;
          var idx=y*G+x;
          if(val>0)density[idx]=Math.min(1,density[idx]+infl);
          else density[idx]=Math.max(0.01,density[idx]-infl);
        }
      }
    }
  }

  function loop(){
    for(var i=0;i<spd;i++)lloyd();
    render();
    requestAnimationFrame(loop);
  }

  C.addEventListener('mousedown',function(e){
    if(e.button===2){erasing=true;paintDensity(e.clientX,e.clientY,-1);SFX.play('pop');}
    else{painting=true;paintDensity(e.clientX,e.clientY,1);SFX.play('spawn');}
  });
  C.addEventListener('mousemove',function(e){
    if(painting){paintDensity(e.clientX,e.clientY,1);SFX.play('move',80);}
    else if(erasing){paintDensity(e.clientX,e.clientY,-1);SFX.play('move',80);}
  });
  C.addEventListener('mouseup',function(){painting=erasing=false;});
  C.addEventListener('contextmenu',function(e){e.preventDefault();});

  C.addEventListener('touchstart',function(e){
    e.preventDefault();var t=e.touches[0];
    painting=true;paintDensity(t.clientX,t.clientY,1);SFX.play('spawn');
  },{passive:false});
  C.addEventListener('touchmove',function(e){
    e.preventDefault();
    if(painting){var t=e.touches[0];paintDensity(t.clientX,t.clientY,1);SFX.play('move',80);}
  },{passive:false});
  C.addEventListener('touchend',function(){painting=false;});

  window.addEventListener('resize',resize);

  document.querySelectorAll('#pattern-btns .control-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('#pattern-btns .control-btn').forEach(function(b){b.classList.remove('active');});
      btn.classList.add('active');
      genDensity(btn.dataset.pattern);
      initPts();
      SFX.play('toggle');
    });
  });

  var ptsSl=document.getElementById('pts-slider');
  ptsSl.addEventListener('input',function(){
    document.getElementById('pts-val').textContent=ptsSl.value;
  });
  ptsSl.addEventListener('change',function(){
    nPts=parseInt(ptsSl.value);
    initPts();
    SFX.play('click');
  });

  var spdSl=document.getElementById('spd-slider');
  spdSl.addEventListener('input',function(){
    spd=parseInt(spdSl.value);
    document.getElementById('spd-val').textContent=spd;
  });

  document.getElementById('cells-btn').addEventListener('click',function(){
    showCells=!showCells;
    this.classList.toggle('active',showCells);
    SFX.play('toggle');
  });

  document.getElementById('reset-btn').addEventListener('click',function(){
    var active=document.querySelector('#pattern-btns .active');
    genDensity(active?active.dataset.pattern:'rings');
    initPts();
    SFX.play('click');
  });

  resize();
  genDensity('rings');
  initPts();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
