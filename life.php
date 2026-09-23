<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('life');

$pageName = 'life';
$pageTitle = 'LIFE - PULSE';
$pageDesc = 'ライフゲームの規則（B3/S23）を1ビットずつ書き換える。生まれる数と生き残る数のスイッチ18個で、爆発する世界・凍る世界・迷路になる世界が切り替わり、個体数の折れ線で違いを見比べられる。';
$pageCanonical = 'https://pulse.subara3.com/life.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);text-align:left;letter-spacing:.08em;background:rgba(10,10,15,.55);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4}.hud .sub{color:#b58cff}
.drawer-controls .btn-row{display:flex;gap:.4rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:34px}
.slider-row{display:grid;grid-template-columns:72px 1fr 44px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
.bits{display:grid;grid-template-columns:18px repeat(9,1fr);gap:3px;align-items:center;font:10px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.5)}
.bits span{text-align:center}
.bits button{min-width:0;height:26px;border-radius:4px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.03);color:rgba(255,255,255,.45);font:600 11px ui-monospace,SFMono-Regular,Menlo,monospace;cursor:pointer;padding:0;transition:background .2s ease-out,color .2s ease-out,border-color .2s ease-out}
.bits button.on{background:rgba(0,245,212,.18);border-color:#00f5d4;color:#00f5d4}
.bits button.s.on{background:rgba(123,44,191,.28);border-color:#b58cff;color:#b58cff}
@media(min-width:769px){#controls{width:330px;background:rgba(10,10,15,.66);padding:.85rem;border-radius:10px;border:1px solid rgba(255,255,255,.07);backdrop-filter:blur(12px)}}
@media(max-width:760px){.hud{top:3.2rem;left:.7rem;font-size:10px;padding:.55rem .65rem}.slider-row{grid-template-columns:62px 1fr 40px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">RULE</span> <b id="h-rule">B3/S23</b><br><span id="h-name">LIFE</span><br>GEN <b id="h-gen">0</b><br>POP <b id="h-pop">0</b></div>
<?php $interactionHints=[['type'=>'drag','label'=>'draw'],['type'=>'right','label'=>'erase']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="btn-row" id="presets"></div>
  <div class="bits" id="bits"></div>
  <div class="btn-row"><button class="control-btn" id="btn-mutate">MUTATE</button><button class="control-btn active" id="btn-trail">TRAIL</button></div>
  <div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="60" value="14"><span class="slider-value" id="sv-speed">14</span></div>
  <div class="slider-row"><span class="slider-label">CELL</span><input type="range" id="sl-cell" min="2" max="12" value="4"><span class="slider-value" id="sv-cell">4</span></div>
  <div class="slider-row"><span class="slider-label">DENSITY</span><input type="range" id="sl-dens" min="5" max="80" value="32"><span class="slider-value" id="sv-dens">32</span></div>
  <div class="btn-row"><button class="control-btn active" id="btn-run">RUN</button><button class="control-btn" id="btn-step">STEP</button><button class="control-btn" id="btn-random">RANDOM</button><button class="control-btn" id="btn-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var PRESETS=[
  {n:'LIFE',r:'B3/S23'},{n:'HIGHLIFE',r:'B36/S23'},{n:'SEEDS',r:'B2/S'},{n:'DAY&NIGHT',r:'B3678/S34678'},
  {n:'DIAMOEBA',r:'B35678/S5678'},{n:'MAZE',r:'B3/S12345'},{n:'ANNEAL',r:'B4678/S35678'},{n:'REPLICATOR',r:'B1357/S1357'}
];

var c=document.getElementById('canvas'),ctx=c.getContext('2d'),W=0,H=0;
var off=document.createElement('canvas'),ox=off.getContext('2d'),img=null;
var cell=4,cols=0,rows=0,grid,nxt,age,heat;
var birth=new Uint8Array(9),surv=new Uint8Array(9),ruleName='LIFE';
var gen=0,pop=0,run=true,speed=14,dens=32,trail=true;
var hist=[],marks=[],HMAX=260;
var paint=null;

var $=function(id){return document.getElementById(id)};
var hR=$('h-rule'),hN=$('h-name'),hG=$('h-gen'),hP=$('h-pop');

/* colours: young cells teal, old cells drift to purple; trails in dim purple */
var LN=256,lut=new Uint8Array(LN*3);
function brandHue(t){t=t<0?0:t>1?1:t;var e=t<.5?.5*Math.pow(2*t,3.2):1-.5*Math.pow(2*(1-t),3.2);return 172+e*118}
function hsl(h,s,l){s/=100;l/=100;var a=s*Math.min(l,1-l);function f(n){var k=(n+h/30)%12;return l-a*Math.max(-1,Math.min(k-3,9-k,1))}return[f(0)*255|0,f(8)*255|0,f(4)*255|0]}
for(var i=0;i<LN;i++){var t=i/(LN-1),q=hsl(brandHue(t),88-t*22,58-t*16);lut[i*3]=q[0];lut[i*3+1]=q[1];lut[i*3+2]=q[2]}

function ruleStr(){var b='B',s='S';for(var k=0;k<9;k++){if(birth[k])b+=k;if(surv[k])s+=k}return b+'/'+s}
function setRule(str,name){
  birth=new Uint8Array(9);surv=new Uint8Array(9);
  str.toUpperCase().split('/').forEach(function(p){var tg=p.charAt(0)==='B'?birth:surv;for(var j=1;j<p.length;j++){var n=p.charCodeAt(j)-48;if(n>=0&&n<=8)tg[n]=1}});
  ruleName=name||lookup();marks.push(hist.length);syncUI();
}
function lookup(){var r=ruleStr();for(var k=0;k<PRESETS.length;k++)if(PRESETS[k].r===r)return PRESETS[k].n;return 'CUSTOM'}

function alloc(){
  cols=Math.ceil(W/cell);rows=Math.ceil(H/cell);var n=cols*rows;
  grid=new Uint8Array(n);nxt=new Uint8Array(n);age=new Uint16Array(n);heat=new Float32Array(n);
  off.width=cols;off.height=rows;img=ox.createImageData(cols,rows);
}
function seed(){
  grid.fill(0);age.fill(0);heat.fill(0);
  var cx=cols/2,cy=rows/2,r=Math.min(cols,rows)*.36;
  for(var y=0;y<rows;y++)for(var x=0;x<cols;x++){var dx=x-cx,dy=y-cy;if(dx*dx+dy*dy<r*r&&Math.random()*100<dens){grid[y*cols+x]=1;age[y*cols+x]=1}}
  gen=0;hist=[];marks=[0];
}
function step(){
  pop=0;
  for(var y=0;y<rows;y++){
    var ym=(y?y-1:rows-1)*cols,y0=y*cols,yp=(y===rows-1?0:y+1)*cols;
    for(var x=0;x<cols;x++){
      var xm=x?x-1:cols-1,xp=x===cols-1?0:x+1;
      var n=grid[ym+xm]+grid[ym+x]+grid[ym+xp]+grid[y0+xm]+grid[y0+xp]+grid[yp+xm]+grid[yp+x]+grid[yp+xp];
      var id=y0+x,was=grid[id],a=was?surv[n]:birth[n];
      nxt[id]=a;
      if(a){pop++;age[id]=was?(age[id]<600?age[id]+1:600):1}
      else{if(was)heat[id]=1;age[id]=0}
    }
  }
  var t=grid;grid=nxt;nxt=t;gen++;
  for(var k=0;k<heat.length;k++)if(heat[k]>0)heat[k]*=.9;
  hist.push(pop/(cols*rows));if(hist.length>HMAX){hist.shift();for(var m=0;m<marks.length;m++)marks[m]--;marks=marks.filter(function(v){return v>=0})}
}
function draw(){
  var d=img.data,n=cols*rows;
  for(var i=0;i<n;i++){
    var p=i<<2;
    if(grid[i]){var ci=(Math.min(1,age[i]/240)*(LN-1)|0)*3;d[p]=lut[ci];d[p+1]=lut[ci+1];d[p+2]=lut[ci+2]}
    else if(trail&&heat[i]>.04){var h=heat[i];d[p]=10+50*h;d[p+1]=10+18*h;d[p+2]=15+90*h}
    else{d[p]=10;d[p+1]=10;d[p+2]=15}
    d[p+3]=255;
  }
  ox.putImageData(img,0,0);ctx.imageSmoothingEnabled=false;ctx.drawImage(off,0,0,cols*cell,rows*cell);
  graph();
}
/* population trace: flat line = frozen, sawtooth = oscillating, climbing = explosion */
function graph(){
  var gw=Math.min(W-32,420),gh=54,gx=(W-gw)/2,gy=H-gh-(W<760?150:28);
  ctx.fillStyle='rgba(10,10,15,.72)';ctx.fillRect(gx-8,gy-8,gw+16,gh+16);
  ctx.strokeStyle='rgba(255,255,255,.08)';ctx.strokeRect(gx-8.5,gy-8.5,gw+17,gh+17);
  var mx=.02;for(var k=0;k<hist.length;k++)if(hist[k]>mx)mx=hist[k];
  ctx.strokeStyle='rgba(181,140,255,.55)';ctx.lineWidth=1;
  marks.forEach(function(m){var x=gx+m/HMAX*gw+.5;ctx.beginPath();ctx.moveTo(x,gy);ctx.lineTo(x,gy+gh);ctx.stroke()});
  ctx.strokeStyle='#00f5d4';ctx.lineWidth=1.6;ctx.beginPath();
  for(var j=0;j<hist.length;j++){var x=gx+j/HMAX*gw,y=gy+gh-hist[j]/mx*gh;j?ctx.lineTo(x,y):ctx.moveTo(x,y)}
  ctx.stroke();
  ctx.fillStyle='rgba(255,255,255,.4)';ctx.font='10px ui-monospace,Menlo,monospace';ctx.fillText('POP',gx,gy-12<0?gy:gy-12);
}

function syncUI(){
  hR.textContent=ruleStr();hN.textContent=ruleName;
  document.querySelectorAll('#bits button').forEach(function(b){var on=(b.dataset.k==='b'?birth:surv)[+b.dataset.n];b.classList.toggle('on',!!on)});
  document.querySelectorAll('#presets button').forEach(function(b){b.classList.toggle('active',b.dataset.r===ruleStr())});
}
function hud(){hG.textContent=gen;hP.textContent=pop}

/* painting */
function cellAt(e){return{x:Math.floor(e.clientX/cell),y:Math.floor(e.clientY/cell)}}
function put(x,y,v){for(var dy=-1;dy<=1;dy++)for(var dx=-1;dx<=1;dx++){if(v&&Math.random()<.45)continue;var xx=(x+dx+cols)%cols,yy=(y+dy+rows)%rows,id=yy*cols+xx;grid[id]=v;age[id]=v;if(!v)heat[id]=0}}
function line(a,b,v){var n=Math.max(Math.abs(b.x-a.x),Math.abs(b.y-a.y),1);for(var k=0;k<=n;k++)put(Math.round(a.x+(b.x-a.x)*k/n),Math.round(a.y+(b.y-a.y)*k/n),v)}
c.addEventListener('contextmenu',function(e){e.preventDefault()});
c.addEventListener('pointerdown',function(e){var g=cellAt(e);paint={v:e.button===2?0:1,last:g};c.setPointerCapture(e.pointerId);put(g.x,g.y,paint.v);if(window.SFX)SFX.play(paint.v?'spawn':'pop',60)});
c.addEventListener('pointermove',function(e){if(!paint)return;var g=cellAt(e);line(paint.last,g,paint.v);paint.last=g;if(window.SFX)SFX.play('move',150)});
c.addEventListener('pointerup',function(){paint=null});c.addEventListener('pointercancel',function(){paint=null});

/* controls */
(function(){
  var pr=$('presets');PRESETS.forEach(function(p){var b=document.createElement('button');b.className='control-btn';b.textContent=p.n;b.dataset.r=p.r;b.onclick=function(){setRule(p.r,p.n);if(window.SFX)SFX.play('toggle')};pr.appendChild(b)});
  var bits=$('bits');['b','s'].forEach(function(k){var lab=document.createElement('span');lab.textContent=k.toUpperCase();bits.appendChild(lab);for(var n=0;n<9;n++){var b=document.createElement('button');b.textContent=n;b.dataset.k=k;b.dataset.n=n;if(k==='s')b.classList.add('s');b.onclick=function(){var arr=this.dataset.k==='b'?birth:surv;arr[+this.dataset.n]^=1;ruleName=lookup();marks.push(hist.length);syncUI();if(window.SFX)SFX.play('tick')};bits.appendChild(b)}});
})();
$('btn-mutate').onclick=function(){
  var arr=Math.random()<.5?birth:surv,n=Math.floor(Math.random()*9);
  if(arr===birth&&n===0)n=1+Math.floor(Math.random()*8);
  arr[n]^=1;ruleName=lookup();marks.push(hist.length);syncUI();
  if(pop<cols*rows*.002)seed();
  if(window.SFX)SFX.play('success');
};
$('btn-trail').onclick=function(e){trail=!trail;e.currentTarget.classList.toggle('active',trail);if(window.SFX)SFX.play('toggle')};
$('btn-run').onclick=function(e){run=!run;e.currentTarget.classList.toggle('active',run);if(window.SFX)SFX.play('toggle')};
$('btn-step').onclick=function(){run=false;$('btn-run').classList.remove('active');step();hud();if(window.SFX)SFX.play('tick')};
$('btn-random').onclick=function(){seed();if(window.SFX)SFX.play('click')};
$('btn-clear').onclick=function(){grid.fill(0);age.fill(0);heat.fill(0);gen=0;pop=0;hist=[];marks=[0];if(window.SFX)SFX.play('click')};
$('sl-speed').oninput=function(){speed=+this.value;$('sv-speed').textContent=speed};
$('sl-dens').oninput=function(){dens=+this.value;$('sv-dens').textContent=dens};
$('sl-cell').oninput=function(){cell=+this.value;$('sv-cell').textContent=cell;alloc();seed()};
addEventListener('keydown',function(e){if(e.code==='Space'&&e.target===document.body){e.preventDefault();$('btn-run').click()}});

function resize(){W=c.width=innerWidth;H=c.height=innerHeight;if(W<760&&cell>3){cell=3;$('sl-cell').value=3;$('sv-cell').textContent=3}alloc();seed()}
addEventListener('resize',resize);resize();setRule('B3/S23','LIFE');

var last=performance.now(),acc=0;
(function loop(now){
  var dt=Math.min(200,now-last);last=now;
  if(run){acc+=dt;var iv=1000/speed,guard=6;while(acc>=iv&&guard-->0){step();acc-=iv}if(guard<=0)acc=0}
  draw();hud();requestAnimationFrame(loop);
})(last);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
