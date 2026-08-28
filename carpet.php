<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('carpet');

$pageName = 'carpet';
$pageTitle = 'CARPET - PULSE';
$pageDesc = '一般化シェルピンスキー・カーペット。格子の穴あきパターンを自由に設計し、再帰的フラクタルが自己相似に広がる。';
$pageCanonical = 'https://pulse.subara3.com/carpet.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
.grid-editor{display:inline-grid;gap:2px}
.gc{width:28px;height:28px;border:1px solid rgba(255,255,255,.2);background:rgba(0,245,212,.15);cursor:pointer;border-radius:3px;transition:background .15s,border-color .15s}
.gc.off{background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.08)}
.gc:hover{border-color:#00f5d4}
.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}
.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}.slider-row{grid-template-columns:62px 1fr 44px}.gc{width:24px;height:24px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">CARPET</span><br>DIM <b id="h-dim">1.893</b><br>GRID <b id="h-grid">3×3</b><br>DEPTH <b id="h-depth">0</b><br>ZOOM <b id="h-zoom">1.00</b></div>
<div class="mark">CARPET</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'zoom in'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'scroll', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div id="grid-wrap" class="grid-editor"></div>
  <div class="btn-row">
    <button class="control-btn active" data-preset="classic">CLASSIC</button>
    <button class="control-btn" data-preset="cross">CROSS</button>
    <button class="control-btn" data-preset="dust">DUST</button>
    <button class="control-btn" data-preset="check">CHECK</button>
  </div>
  <div class="slider-row"><span class="slider-label">GRID</span><input type="range" id="sl-grid" min="3" max="5" value="3"><span class="slider-value" id="sv-grid">3</span></div>
  <div class="slider-row"><span class="slider-label">DEPTH</span><input type="range" id="sl-depth" min="1" max="8" value="6"><span class="slider-value" id="sv-depth">6</span></div>
  <div class="btn-row">
    <button class="control-btn" id="btn-random">RANDOM</button>
    <button class="control-btn" id="btn-reset">RESET VIEW</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),ctx=c.getContext('2d'),W=0,H=0;
var N=3,maxD=6,step=2;
var grid=[1,1,1,1,0,1,1,1,1];
var vx=0,vy=0,vs=1,needs=true;
var kMap=[],kCnt=0;
var animD=0,animT=0,anim=true;
var drag=null;

var hDim=document.getElementById('h-dim'),hGrid=document.getElementById('h-grid');
var hDep=document.getElementById('h-depth'),hZm=document.getElementById('h-zoom');
var slG=document.getElementById('sl-grid'),slD=document.getElementById('sl-depth');
var svG=document.getElementById('sv-grid'),svD=document.getElementById('sv-depth');

var PR={
  3:{classic:[1,1,1,1,0,1,1,1,1],cross:[0,1,0,1,1,1,0,1,0],dust:[1,0,1,0,0,0,1,0,1],check:[1,0,1,0,1,0,1,0,1]},
  4:{classic:[1,1,1,1,1,0,0,1,1,0,0,1,1,1,1,1],cross:[0,1,1,0,1,1,1,1,1,1,1,1,0,1,1,0],dust:[1,0,0,1,0,0,0,0,0,0,0,0,1,0,0,1],check:[1,0,1,0,0,1,0,1,1,0,1,0,0,1,0,1]},
  5:{classic:[1,1,1,1,1,1,0,0,0,1,1,0,0,0,1,1,0,0,0,1,1,1,1,1,1],cross:[0,0,1,0,0,0,0,1,0,0,1,1,1,1,1,0,0,1,0,0,0,0,1,0,0],dust:[1,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,1],check:[1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1]}
};

function brandHue(t){t=t<0?0:t>1?1:t;var e=t<0.5?0.5*Math.pow(2*t,3.2):1-0.5*Math.pow(2*(1-t),3.2);return 172+e*118}
function hsl2rgb(h,s,l){s/=100;l/=100;var a=s*Math.min(l,1-l);function f(n){var k=(n+h/30)%12;return l-a*Math.max(-1,Math.min(k-3,9-k,1))}return[f(0)*255|0,f(8)*255|0,f(4)*255|0]}

function buildK(){kMap=[];kCnt=0;for(var i=0;i<N*N;i++){if(grid[i])kMap[i]=kCnt++;else kMap[i]=-1}}
function fdim(){if(kCnt<=0)return 0;if(kCnt>=N*N)return 2;return Math.log(kCnt)/Math.log(N)}

function resize(){W=c.width=innerWidth;H=c.height=innerHeight;c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';needs=true}

function buildEditor(){
  var w=document.getElementById('grid-wrap');
  w.innerHTML='';w.style.gridTemplateColumns='repeat('+N+',28px)';
  for(var i=0;i<N*N;i++){(function(idx){
    var d=document.createElement('div');
    d.className='gc'+(grid[idx]?'':' off');
    d.addEventListener('click',function(){
      grid[idx]=grid[idx]?0:1;
      this.className='gc'+(grid[idx]?'':' off');
      buildK();anim=false;animD=maxD;needs=true;hud();
      var bs=document.querySelectorAll('[data-preset]');for(var j=0;j<bs.length;j++)bs[j].classList.remove('active');
      if(window.SFX)SFX.play('tick');
    });
    w.appendChild(d);
  })(i)}
}

function hud(){
  var d=anim?animD:maxD;
  hDim.textContent=fdim().toFixed(3);
  hGrid.textContent=N+'×'+N;
  hDep.textContent=d;
  hZm.textContent=(1/vs).toFixed(2);
  svG.textContent=N;svD.textContent=maxD;
}

function render(){
  var depth=anim?animD:maxD;
  var sz=Math.min(W,H),ox=(W-sz)*0.5,oy=(H-sz)*0.5;
  var img=ctx.createImageData(W,H),buf=img.data;
  var inv=vs/sz;
  var denom=kCnt>1?kCnt-1:1;

  for(var py=0;py<H;py+=step){
    for(var px=0;px<W;px+=step){
      var fx=vx+(px-ox)*inv,fy=vy+(py-oy)*inv;
      var r=10,g=10,b=15;

      if(fx>=0&&fx<1&&fy>=0&&fy<1&&kCnt>0){
        var x=fx,y=fy,ok=true,hd=depth,ca=0;
        for(var dd=0;dd<depth;dd++){
          var col=x*N|0,row=y*N|0;
          if(col>=N)col=N-1;if(row>=N)row=N-1;
          var ci=row*N+col;
          if(!grid[ci]){ok=false;hd=dd;break}
          ca+=kMap[ci]/denom*Math.pow(0.5,dd);
          x=x*N-col;y=y*N-row;
        }
        if(ok){
          var t=Math.min(1,ca*0.52);
          var h=brandHue(t),s=55+15*Math.sin(t*10),l=18+40*t;
          var rgb=hsl2rgb(h,s,l);
          r=rgb[0];g=rgb[1];b=rgb[2];
        }else{
          var gw=(hd+1)/(depth+1)*0.08;
          r=10+(123*gw)|0;g=10+(44*gw)|0;b=15+(191*gw)|0;
        }
      }

      for(var sy=0;sy<step&&py+sy<H;sy++){
        for(var sx=0;sx<step&&px+sx<W;sx++){
          var k=((py+sy)*W+(px+sx))*4;
          buf[k]=r;buf[k+1]=g;buf[k+2]=b;buf[k+3]=255;
        }
      }
    }
  }
  ctx.putImageData(img,0,0);needs=false;
}

function zoomAt(mx,my,f){
  var sz=Math.min(W,H),ox=(W-sz)*0.5,oy=(H-sz)*0.5;
  var fx=vx+(mx-ox)/sz*vs,fy=vy+(my-oy)/sz*vs;
  vs/=f;vx=fx-(mx-ox)/sz*vs;vy=fy-(my-oy)/sz*vs;needs=true;
}

c.addEventListener('pointerdown',function(e){drag={x:e.clientX,y:e.clientY,vx:vx,vy:vy,m:false};c.setPointerCapture(e.pointerId)});
c.addEventListener('pointermove',function(e){
  if(!drag)return;var dx=e.clientX-drag.x,dy=e.clientY-drag.y;
  if(Math.abs(dx)>3||Math.abs(dy)>3)drag.m=true;
  if(!drag.m)return;var sz=Math.min(W,H);
  vx=drag.vx-dx/sz*vs;vy=drag.vy-dy/sz*vs;needs=true;
  if(window.SFX)SFX.play('move',140);
});
c.addEventListener('pointerup',function(e){
  if(drag&&!drag.m){zoomAt(e.clientX,e.clientY,2);anim=false;animD=maxD;if(window.SFX)SFX.play('spawn')}
  drag=null;hud();
});
c.addEventListener('wheel',function(e){
  e.preventDefault();zoomAt(e.clientX,e.clientY,e.deltaY<0?1.3:1/1.3);
  anim=false;animD=maxD;hud();if(window.SFX)SFX.play('ripple',100);
},{passive:false});
c.addEventListener('contextmenu',function(e){
  e.preventDefault();vx=0;vy=0;vs=1;needs=true;hud();if(window.SFX)SFX.play('click');
});

function applyPreset(name){
  var p=PR[N];if(!p||!p[name])return;
  grid=p[name].slice();buildK();buildEditor();
  vx=0;vy=0;vs=1;anim=false;animD=maxD;needs=true;
  var bs=document.querySelectorAll('[data-preset]');
  for(var j=0;j<bs.length;j++)bs[j].classList.remove('active');
  var cb=document.querySelector('[data-preset="'+name+'"]');if(cb)cb.classList.add('active');
  hud();if(window.SFX)SFX.play('toggle');
}

var pBtns=document.querySelectorAll('[data-preset]');
for(var i=0;i<pBtns.length;i++){(function(btn){
  btn.addEventListener('click',function(){applyPreset(this.dataset.preset)});
})(pBtns[i])}

slG.addEventListener('input',function(){
  N=parseInt(this.value);applyPreset('classic');
});
slD.addEventListener('input',function(){
  maxD=parseInt(this.value);anim=false;animD=maxD;needs=true;hud();
});

document.getElementById('btn-random').addEventListener('click',function(){
  grid=[];for(var i=0;i<N*N;i++)grid[i]=Math.random()<0.6?1:0;
  var any=false;for(var i=0;i<N*N;i++)if(grid[i])any=true;
  if(!any)grid[Math.floor(Math.random()*N*N)]=1;
  buildK();buildEditor();vx=0;vy=0;vs=1;anim=false;animD=maxD;needs=true;
  var bs=document.querySelectorAll('[data-preset]');for(var j=0;j<bs.length;j++)bs[j].classList.remove('active');
  hud();if(window.SFX)SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click',function(){
  vx=0;vy=0;vs=1;needs=true;hud();if(window.SFX)SFX.play('click');
});

function tick(now){
  if(anim){
    if(!animT)animT=now;
    var el=now-animT,nd=Math.min(maxD,Math.floor(el/350));
    if(nd>animD){animD=nd;needs=true;if(window.SFX&&animD>0)SFX.play('grow',200)}
    if(animD>=maxD)anim=false;
  }
  if(needs){render();hud()}
  requestAnimationFrame(tick);
}

resize();buildK();buildEditor();hud();
window.addEventListener('resize',resize);
requestAnimationFrame(tick);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
