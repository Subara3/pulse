<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ifs');

$pageName = 'ifs';
$pageTitle = 'IFS - PULSE';
$pageDesc = '反復関数系。アフィン変換の確率的繰り返しが自己相似フラクタルを生む。プリセットを切り替え、突然変異で未知の形態を探索する。';
$pageCanonical = 'https://pulse.subara3.com/ifs.php';

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
  POINTS: <span class="v" id="hud-pts">0</span><br>
  MODE: <span class="v" id="hud-mode">FERN</span>
</div>

<div id="info">
  <span class="v">Iterated Function System</span><br>
  affine transform attractor
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'trace'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <span class="slider-label">PRESET</span>
    <div class="btn-row" id="preset-btns">
      <button class="control-btn active" data-preset="fern">FERN</button>
      <button class="control-btn" data-preset="tree">TREE</button>
      <button class="control-btn" data-preset="maple">MAPLE</button>
      <button class="control-btn" data-preset="shell">SHELL</button>
      <button class="control-btn" data-preset="swirl">SWIRL</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="spd-slider" min="1" max="5" value="3">
      <span class="slider-value" id="spd-val">3</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="mutate-btn">MUTATE</button>
    <button class="control-btn" id="clear-btn">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H,bW,bH;
  var OC=document.createElement('canvas'),OX=OC.getContext('2d');
  var imgData;

  var px=0,py=0,totalPts=0,spd=3;
  var curName='fern';
  var mobile='ontouchstart' in window;
  var baseIter=mobile?4000:8000;

  var den,cR,cG,cB,maxD;
  var vx0,vy0,vx1,vy1;
  var T=[],TC=[];
  var tracers=[];

  var presets={
    fern:{t:[
      {a:0,b:0,c:0,d:0.16,e:0,f:0,p:0.01},
      {a:0.85,b:0.04,c:-0.04,d:0.85,e:0,f:1.6,p:0.85},
      {a:0.2,b:-0.26,c:0.23,d:0.22,e:0,f:1.6,p:0.07},
      {a:-0.15,b:0.28,c:0.26,d:0.24,e:0,f:0.44,p:0.07}
    ]},
    tree:{t:[
      {a:0.01,b:0,c:0,d:0.45,e:0,f:0,p:0.04},
      {a:0.354,b:-0.354,c:0.354,d:0.354,e:0,f:0.4,p:0.48},
      {a:0.354,b:0.354,c:-0.354,d:0.354,e:0,f:0.4,p:0.48}
    ]},
    maple:{t:[
      {a:0.14,b:0.01,c:0,d:0.51,e:-0.08,f:-1.31,p:0.25},
      {a:0.43,b:0.52,c:-0.45,d:0.50,e:1.49,f:-0.75,p:0.25},
      {a:0.45,b:-0.49,c:0.47,d:0.47,e:-1.62,f:-0.74,p:0.25},
      {a:0.49,b:0,c:0,d:0.51,e:0.02,f:1.62,p:0.25}
    ]},
    shell:{t:[
      {a:0.837,b:-0.272,c:0.272,d:0.837,e:0.18,f:-0.05,p:0.90},
      {a:0.3,b:0,c:0,d:0.3,e:-0.1,f:0.75,p:0.10}
    ]},
    swirl:{t:[
      {a:0.728,b:-0.529,c:0.529,d:0.728,e:0,f:0,p:0.85},
      {a:0.3,b:0,c:0,d:0.3,e:1,f:1,p:0.15}
    ]}
  };

  function hsl2rgb(h,s,l){
    h/=360;
    if(s===0)return[(l*255)|0,(l*255)|0,(l*255)|0];
    function hue(p,q,t){
      if(t<0)t++;if(t>1)t--;
      if(t<1/6)return p+(q-p)*6*t;
      if(t<1/2)return q;
      if(t<2/3)return p+(q-p)*(2/3-t)*6;
      return p;
    }
    var q=l<0.5?l*(1+s):l+s-l*s,p=2*l-q;
    return[(hue(p,q,h+1/3)*255)|0,(hue(p,q,h)*255)|0,(hue(p,q,h-1/3)*255)|0];
  }

  function mkColors(){
    TC=[];
    var n=T.length;
    for(var i=0;i<n;i++){
      var t=n>1?i/(n-1):0;
      TC.push(hsl2rgb(175+t*100,0.85,0.55));
    }
  }

  function autoBounds(){
    var tx=0,ty=0;
    var mn=[1e9,1e9],mx=[-1e9,-1e9];
    for(var i=0;i<30000;i++){
      var r=Math.random(),s=0,ti=0;
      for(var j=0;j<T.length;j++){s+=T[j].p;if(r<=s){ti=j;break;}}
      var tf=T[ti];
      var nx=tf.a*tx+tf.b*ty+tf.e;
      var ny=tf.c*tx+tf.d*ty+tf.f;
      tx=nx;ty=ny;
      if(Math.abs(tx)>1e8||Math.abs(ty)>1e8||isNaN(tx))return false;
      if(i>50){
        if(tx<mn[0])mn[0]=tx;if(tx>mx[0])mx[0]=tx;
        if(ty<mn[1])mn[1]=ty;if(ty>mx[1])mx[1]=ty;
      }
    }
    var dx=mx[0]-mn[0]||1,dy=mx[1]-mn[1]||1;
    var pad=0.1;
    vx0=mn[0]-dx*pad;vx1=mx[0]+dx*pad;
    vy0=mn[1]-dy*pad;vy1=mx[1]+dy*pad;
    var ba=(vx1-vx0)/(vy1-vy0),ca=bW/bH;
    if(ba>ca){
      var h=(vx1-vx0)/ca,cy=(vy0+vy1)/2;
      vy0=cy-h/2;vy1=cy+h/2;
    }else{
      var w=(vy1-vy0)*ca,cx=(vx0+vx1)/2;
      vx0=cx-w/2;vx1=cx+w/2;
    }
    return true;
  }

  function clearBuf(){
    var sz=bW*bH;
    den=new Float32Array(sz);
    cR=new Float32Array(sz);
    cG=new Float32Array(sz);
    cB=new Float32Array(sz);
    maxD=0;totalPts=0;px=0;py=0;
    tracers=[];
  }

  function setPreset(name){
    var p=presets[name];if(!p)return;
    T=p.t.map(function(t){return{a:t.a,b:t.b,c:t.c,d:t.d,e:t.e,f:t.f,p:t.p};});
    curName=name;
    mkColors();
    clearBuf();
    autoBounds();
    clearBuf();
    document.getElementById('hud-mode').textContent=name.toUpperCase();
  }

  function iterate(n){
    var sx=bW/(vx1-vx0),sy=bH/(vy1-vy0);
    var tLen=T.length;
    for(var i=0;i<n;i++){
      var r=Math.random(),s=0,ti=0;
      for(var j=0;j<tLen;j++){s+=T[j].p;if(r<=s){ti=j;break;}}
      var t=T[ti];
      var nx=t.a*px+t.b*py+t.e;
      var ny=t.c*px+t.d*py+t.f;
      px=nx;py=ny;
      totalPts++;
      if(totalPts<30)continue;
      var bx=((px-vx0)*sx)|0;
      var by=((vy1-py)*sy)|0;
      if(bx<0||bx>=bW||by<0||by>=bH)continue;
      var idx=by*bW+bx;
      den[idx]++;
      cR[idx]+=TC[ti][0];
      cG[idx]+=TC[ti][1];
      cB[idx]+=TC[ti][2];
      if(den[idx]>maxD)maxD=den[idx];
    }
  }

  function render(){
    var d=imgData.data;
    var lm=Math.log1p(maxD);if(lm<1)lm=1;
    var sz=bW*bH;
    for(var i=0;i<sz;i++){
      var c=den[i],i4=i<<2;
      if(c>0){
        var b=Math.pow(Math.log1p(c)/lm,0.6)*1.3;
        d[i4]=Math.min(255,(cR[i]/c*b)|0);
        d[i4+1]=Math.min(255,(cG[i]/c*b)|0);
        d[i4+2]=Math.min(255,(cB[i]/c*b)|0);
      }else{
        d[i4]=10;d[i4+1]=10;d[i4+2]=15;
      }
      d[i4+3]=255;
    }
    OX.putImageData(imgData,0,0);
    X.imageSmoothingEnabled=true;
    X.drawImage(OC,0,0,W,H);

    // Tracers
    for(var k=tracers.length-1;k>=0;k--){
      var tr=tracers[k];
      var r=Math.random(),s=0,ti=0;
      for(var j=0;j<T.length;j++){s+=T[j].p;if(r<=s){ti=j;break;}}
      var tf=T[ti];
      var nx=tf.a*tr.x+tf.b*tr.y+tf.e;
      var ny=tf.c*tr.x+tf.d*tr.y+tf.f;
      tr.x=nx;tr.y=ny;
      tr.pts.push(nx,ny);
      tr.age++;
      var alpha=Math.max(0.05,1-tr.age/120);
      var pLen=tr.pts.length;
      var start=Math.max(0,pLen-120);
      if(start%2!==0)start++;
      X.strokeStyle='rgba(255,255,255,'+(alpha*0.35).toFixed(2)+')';
      X.lineWidth=1;
      X.beginPath();
      for(var m=start;m<pLen;m+=2){
        var ex=(tr.pts[m]-vx0)/(vx1-vx0)*W;
        var ey=(vy1-tr.pts[m+1])/(vy1-vy0)*H;
        if(m===start)X.moveTo(ex,ey);else X.lineTo(ex,ey);
      }
      X.stroke();
      var cx=(tr.x-vx0)/(vx1-vx0)*W;
      var cy=(vy1-tr.y)/(vy1-vy0)*H;
      X.fillStyle='rgba(0,245,212,'+alpha.toFixed(2)+')';
      X.beginPath();X.arc(cx,cy,3,0,6.2832);X.fill();
      if(tr.age>150)tracers.splice(k,1);
    }

    var tp=totalPts;
    var label=tp>1e6?(tp/1e6).toFixed(1)+'M':tp>1e3?(tp/1e3|0)+'K':''+tp;
    document.getElementById('hud-pts').textContent=label;
  }

  function addTracer(ex,ey){
    var fx=ex/W*(vx1-vx0)+vx0;
    var fy=(1-ey/H)*(vy1-vy0)+vy0;
    var spread=Math.max(vx1-vx0,vy1-vy0)*0.2;
    for(var i=0;i<5;i++){
      tracers.push({
        x:fx+(Math.random()-0.5)*spread,
        y:fy+(Math.random()-0.5)*spread,
        pts:[],age:0
      });
    }
  }

  function resize(){
    W=C.width=innerWidth;H=C.height=innerHeight;
    bW=Math.ceil(W/2);bH=Math.ceil(H/2);
    OC.width=bW;OC.height=bH;
    imgData=OX.createImageData(bW,bH);
    setPreset(curName);
  }

  function loop(){
    iterate(baseIter*spd);
    render();
    requestAnimationFrame(loop);
  }

  C.addEventListener('click',function(e){
    addTracer(e.clientX,e.clientY);
    SFX.play('spawn');
  });
  C.addEventListener('touchstart',function(e){
    e.preventDefault();
    var t=e.touches[0];
    addTracer(t.clientX,t.clientY);
    SFX.play('spawn');
  },{passive:false});

  document.querySelectorAll('#preset-btns .control-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('#preset-btns .control-btn').forEach(function(b){b.classList.remove('active');});
      btn.classList.add('active');
      setPreset(btn.dataset.preset);
      SFX.play('toggle');
    });
  });

  document.getElementById('spd-slider').addEventListener('input',function(){
    spd=parseInt(this.value);
    document.getElementById('spd-val').textContent=spd;
  });

  document.getElementById('mutate-btn').addEventListener('click',function(){
    var backup=T.map(function(t){return{a:t.a,b:t.b,c:t.c,d:t.d,e:t.e,f:t.f,p:t.p};});
    for(var i=0;i<T.length;i++){
      T[i].a+=(Math.random()-0.5)*0.08;
      T[i].b+=(Math.random()-0.5)*0.08;
      T[i].c+=(Math.random()-0.5)*0.08;
      T[i].d+=(Math.random()-0.5)*0.08;
      T[i].e+=(Math.random()-0.5)*0.15;
      T[i].f+=(Math.random()-0.5)*0.15;
    }
    clearBuf();
    if(!autoBounds()){
      T=backup;
      autoBounds();
      SFX.play('fail');
    }else{
      SFX.play('toggle');
    }
    clearBuf();
  });

  document.getElementById('clear-btn').addEventListener('click',function(){
    setPreset(curName);
    SFX.play('click');
  });

  window.addEventListener('resize',resize);
  resize();
  iterate(80000);
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
