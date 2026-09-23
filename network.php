<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('network');

$pageName = 'network';
$pageTitle = 'NETWORK - PULSE';
$pageDesc = '点と点をでたらめに結んでいくと、ある瞬間に全体がひとつにつながる。HUBS に切り替えると、同じ数の点と線でも「人気の点ほど選ばれる」だけで巨大なハブが生まれ、つながり方の分布がまるで別物になる。';
$pageCanonical = 'https://pulse.subara3.com/network.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.55);border:1px solid rgba(0,245,212,.16);padding:.7rem .85rem;border-radius:8px;backdrop-filter:blur(12px);pointer-events:none}
.hud b{color:#00f5d4}.hud .sub{color:#b58cff}
.modes{position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:.3rem;padding:.3rem;background:rgba(10,10,15,.55);border:1px solid rgba(0,245,212,.14);border-radius:999px;backdrop-filter:blur(12px)}
.modes button{font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.14em;color:rgba(255,255,255,.6);background:none;border:0;border-radius:999px;padding:.55rem .95rem;cursor:pointer;transition:color .2s ease-out,background .2s ease-out}
.modes button.active{color:#0a0a0f;background:#00f5d4}
.flash{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:15;font:700 1.2rem ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.3em;color:#00f5d4;text-shadow:0 0 30px rgba(0,245,212,.5);opacity:0;transition:opacity .4s ease-out;pointer-events:none}
.flash.on{opacity:1}
.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}
.slider-row{display:grid;grid-template-columns:72px 1fr 54px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
body.m-giant .only-hubs,body.m-hubs .only-giant{display:none!important}
@media(max-width:760px){body.m-hubs .hud{display:none}}
@media(max-width:760px){.hud{top:5.9rem;left:.7rem;font-size:10px;padding:.45rem .55rem;line-height:1.5}.modes{top:3.1rem}.slider-row{grid-template-columns:62px 1fr 44px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="modes" id="modes"><button data-m="giant" class="active">GIANT</button><button data-m="hubs">HUBS</button></div>
<div class="hud" id="hud"></div>
<div class="flash" id="flash">CONNECTED</div>
<?php $interactionHints=[['type'=>'click','label'=>'add / auto'],['type'=>'drag','label'=>'move node']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="slider-row only-giant"><span class="slider-label">p</span><input type="range" id="sl-p" min="0" max="150" value="12"><span class="slider-value" id="sv-p">0.012</span></div>
  <div class="slider-row only-hubs"><span class="slider-label">LINKS</span><input type="range" id="sl-m" min="1" max="3" value="1"><span class="slider-value" id="sv-m">1</span></div>
  <div class="btn-row"><button class="control-btn active" id="btn-auto">AUTO</button><button class="control-btn" id="btn-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),x=c.getContext('2d'),W=0,H=0,mobile=innerWidth<=768;
var TEAL='0,245,212',VIO='181,140,255',PUR='123,44,191';
var mode='giant',auto=true,t=0,drag=null;
var hud=document.getElementById('hud'),flash=document.getElementById('flash');

/* ---------- a panel = one graph with its own layout box ---------- */
function Panel(kind){this.kind=kind;this.nodes=[];this.edges=[];this.box={x:0,y:0,w:0,h:0}}
Panel.prototype.addNode=function(px,py){var b=this.box;this.nodes.push({x:px!=null?px:b.x+b.w/2+(Math.random()-.5)*40,y:py!=null?py:(b.g0+b.g1)/2+(Math.random()-.5)*40,vx:0,vy:0,deg:0,born:t});return this.nodes.length-1};
Panel.prototype.link=function(a,b){if(a===b)return false;for(var k=0;k<this.edges.length;k++){var e=this.edges[k];if((e[0]===a&&e[1]===b)||(e[0]===b&&e[1]===a))return false}this.edges.push([a,b]);this.nodes[a].deg++;this.nodes[b].deg++;return true};
Panel.prototype.layout=function(rest){
  var n=this.nodes,N=n.length,b=this.box,cx=b.x+b.w/2,cy=(b.g0+b.g1)/2,rep=Math.min(N>90?420:700,b.w*(b.g1-b.g0)/(Math.max(N,1)*12));
  for(var i=0;i<N;i++)for(var j=i+1;j<N;j++){var dx=n[j].x-n[i].x,dy=n[j].y-n[i].y,d2=dx*dx+dy*dy+.01,d=Math.sqrt(d2),f=rep/d2;dx/=d;dy/=d;n[i].vx-=dx*f;n[i].vy-=dy*f;n[j].vx+=dx*f;n[j].vy+=dy*f}
  for(var k=0;k<this.edges.length;k++){var e=this.edges[k],a=n[e[0]],q=n[e[1]],ex=q.x-a.x,ey=q.y-a.y,ed=Math.sqrt(ex*ex+ey*ey)||1,ff=(ed-rest)*.006;ex/=ed;ey/=ed;a.vx+=ex*ff;a.vy+=ey*ff;q.vx-=ex*ff;q.vy-=ey*ff}
  for(i=0;i<N;i++){var p=n[i];if(drag&&drag.panel===this&&drag.i===i){p.vx=p.vy=0;continue}p.vx+=(cx-p.x)*.0012;p.vy+=(cy-p.y)*.0012;p.vx*=.84;p.vy*=.84;p.x+=p.vx;p.y+=p.vy;
    p.x=Math.max(b.x+14,Math.min(b.x+b.w-14,p.x));p.y=Math.max(b.g0,Math.min(b.g1,p.y))}
};

/* ---------- GIANT: Erdos-Renyi with a fixed seed per pair ---------- */
var G=new Panel('er'),N_ER=mobile?44:84,seeds=null,p=0.012,giant=0,giantRoot=-1,flashed=false,parent;
function erInit(){G.nodes=[];G.edges=[];for(var i=0;i<N_ER;i++){var a=i/N_ER*Math.PI*2,r=Math.min(W,H)*.32*(.3+Math.random()*.7);G.addNode(W/2+Math.cos(a)*r,H/2+Math.sin(a)*r)}seeds=new Float32Array(N_ER*N_ER);for(i=0;i<seeds.length;i++)seeds[i]=Math.random();flashed=false;erBuild()}
function find(a){while(parent[a]!==a){parent[a]=parent[parent[a]];a=parent[a]}return a}
function erBuild(){
  G.edges=[];G.nodes.forEach(function(n){n.deg=0});parent=new Int32Array(N_ER);for(var i=0;i<N_ER;i++)parent[i]=i;
  for(i=0;i<N_ER;i++)for(var j=i+1;j<N_ER;j++)if(seeds[i*N_ER+j]<p){G.edges.push([i,j]);G.nodes[i].deg++;G.nodes[j].deg++;parent[find(i)]=find(j)}
  var cnt={};giant=0;for(i=0;i<N_ER;i++){var r=find(i);cnt[r]=(cnt[r]||0)+1;if(cnt[r]>giant){giant=cnt[r];giantRoot=r}}
  if(!flashed&&giant>N_ER*.5){flashed=true;flash.classList.add('on');setTimeout(function(){flash.classList.remove('on')},1800);if(window.SFX)SFX.play('success')}
}

/* ---------- HUBS: same growth, uniform vs preferential choice ---------- */
var U=new Panel('uniform'),P=new Panel('pref'),m=1,N_MAX=mobile?70:130,growT=0;
function hubsInit(){[U,P].forEach(function(pn){pn.nodes=[];pn.edges=[];var a=pn.addNode(),b=pn.addNode(),cc=pn.addNode();pn.link(a,b);pn.link(b,cc);pn.link(cc,a)})}
function grow(px,py,panel){
  [U,P].forEach(function(pn){
    var usePos=panel===pn,id=pn.addNode(usePos?px:null,usePos?py:null),links=0,guard=0;
    if(!usePos){var host=pn.nodes[Math.floor(Math.random()*(pn.nodes.length-1))];pn.nodes[id].x=host.x+(Math.random()-.5)*30;pn.nodes[id].y=host.y+(Math.random()-.5)*30}
    while(links<Math.min(m,id)&&guard++<60){
      var target;
      if(pn.kind==='uniform')target=Math.floor(Math.random()*id);
      else{var tot=pn.edges.length*2,r=Math.random()*tot;target=0;for(var k=0;k<id;k++){r-=pn.nodes[k].deg;if(r<=0){target=k;break}}}
      if(pn.link(id,target))links++;
    }
  });
  if(window.SFX)SFX.play('spawn',120);
}

/* ---------- layout of panels ---------- */
function boxes(){
  G.box={x:0,y:0,w:W,h:H,g0:40,g1:H-30};
  if(W>=H){U.box={x:0,y:56,w:W/2,h:H-56,ht:128};P.box={x:W/2,y:56,w:W/2,h:H-56,ht:128}}
  else{var top=mobile?150:100,hh=(H-top-70)/2;U.box={x:0,y:top,w:W,h:hh,ht:0};P.box={x:0,y:top+hh,w:W,h:hh,ht:0}}
  [U,P].forEach(function(pn){var b=pn.box,hist=Math.min(90,b.h*.26);b.hy=b.y+b.ht+hist;b.hh=hist-34;b.g0=b.hy+26;b.g1=b.y+b.h-18})
}

/* ---------- drawing ---------- */
function drawGraph(pn,hi){
  var n=pn.nodes,maxd=1;n.forEach(function(q){if(q.deg>maxd)maxd=q.deg});
  x.lineWidth=1;
  for(var k=0;k<pn.edges.length;k++){var e=pn.edges[k],a=n[e[0]],b=n[e[1]],on=hi?hi(e[0])&&hi(e[1]):true;x.strokeStyle=on?'rgba('+TEAL+','+(pn.kind==='er'?.22+giant/N_ER*.35:.28)+')':'rgba(70,70,95,.35)';x.beginPath();x.moveTo(a.x,a.y);x.lineTo(b.x,b.y);x.stroke()}
  for(var i=0;i<n.length;i++){var q=n[i],on2=hi?hi(i):true,r=pn.kind==='er'?3.5:2+Math.sqrt(q.deg)*1.7,hub=pn.kind!=='er'&&q.deg>=Math.max(6,maxd*.6);
    if(on2&&(hub||pn.kind==='er')){var g=x.createRadialGradient(q.x,q.y,0,q.x,q.y,r*3.2);g.addColorStop(0,'rgba('+(hub?PUR:TEAL)+',.35)');g.addColorStop(1,'rgba(0,0,0,0)');x.fillStyle=g;x.beginPath();x.arc(q.x,q.y,r*3.2,0,6.283);x.fill()}
    x.fillStyle=!on2?'rgb(60,60,80)':hub?'rgb('+VIO+')':'rgb('+TEAL+')';x.beginPath();x.arc(q.x,q.y,r,0,6.283);x.fill()}
}
/* degree histogram under each HUBS panel: uniform -> short tail, preferential -> long tail */
function drawHist(pn,label){
  var b=pn.box,n=pn.nodes,maxd=1,cnt=[];n.forEach(function(q){cnt[q.deg]=(cnt[q.deg]||0)+1;if(q.deg>maxd)maxd=q.deg});
  var hw=Math.min(b.w-48,360),hh=b.hh,hx=b.x+(b.w-hw)/2,hy=b.hy,top=1;for(var d=0;d<=maxd;d++)if((cnt[d]||0)>top)top=cnt[d];
  var span=Math.max(maxd,24),bw=hw/(span+1);
  x.fillStyle='rgba(10,10,15,.7)';x.fillRect(hx-10,hy-hh-24,hw+20,hh+44);
  for(d=1;d<=maxd;d++){var v=cnt[d]||0;if(!v)continue;var bh=Math.max(2,v/top*hh);x.fillStyle=d>=Math.max(6,maxd*.6)?'rgb('+VIO+')':'rgba('+TEAL+',.85)';x.fillRect(hx+d*bw,hy-bh,Math.max(1,bw-1),bh)}
  x.strokeStyle='rgba(255,255,255,.15)';x.beginPath();x.moveTo(hx,hy+.5);x.lineTo(hx+hw,hy+.5);x.stroke();
  x.font='10px ui-monospace,Menlo,monospace';x.fillStyle='rgba(255,255,255,.55)';x.fillText(label,hx,hy-hh-10);
  x.textAlign='right';x.fillStyle='rgba('+TEAL+',.9)';x.fillText('MAX DEG '+maxd,hx+hw,hy-hh-10);x.textAlign='left';
  x.fillStyle='rgba(255,255,255,.3)';x.fillText('degree →',hx,hy+14);
}

function hudText(){
  if(mode==='giant')hud.innerHTML='<span class="sub">RANDOM GRAPH</span><br>NODES <b>'+N_ER+'</b><br>EDGES <b>'+G.edges.length+'</b><br>GIANT <b>'+Math.round(giant/N_ER*100)+'%</b><br>p <b>'+p.toFixed(3)+'</b>'+(p>.8/N_ER&&p<1.6/N_ER?' <span class="sub">CRITICAL</span>':'');
  else hud.innerHTML='<span class="sub">SAME GROWTH</span><br>NODES <b>'+P.nodes.length+'</b><br>EDGES <b>'+P.edges.length+'</b><br>LINKS/NODE <b>'+m+'</b>';
}

function frame(){
  t++;x.fillStyle='#0a0a0f';x.fillRect(0,0,W,H);
  if(mode==='giant'){
    if(auto){p=Math.min(.07,p+.00007);sl.value=Math.round(p*1000);sv.textContent=p.toFixed(3);erBuild();if(p>=.07){p=.002;erInit()}}
    G.layout(80);drawGraph(G,function(i){return find(i)===giantRoot&&giant>1});
  }else{
    if(auto&&P.nodes.length<N_MAX){growT++;if(growT>=(P.nodes.length<20?14:7)){growT=0;grow()}}
    else if(auto&&P.nodes.length>=N_MAX){growT++;if(growT>480){growT=0;hubsInit()}}
    U.layout(28);P.layout(28);
    x.strokeStyle='rgba(255,255,255,.07)';x.beginPath();if(W>=H){x.moveTo(W/2+.5,70);x.lineTo(W/2+.5,H-20)}else{x.moveTo(12,U.box.y+U.box.h+.5);x.lineTo(W-12,U.box.y+U.box.h+.5)}x.stroke();
    drawGraph(U);drawGraph(P);drawHist(U,'UNIFORM · pick anyone');drawHist(P,'PREFERENTIAL · pick the popular');
  }
  hudText();requestAnimationFrame(frame);
}

/* ---------- input ---------- */
function hit(pn,px,py){var best=-1,bd=900;pn.nodes.forEach(function(q,i){var d=(q.x-px)*(q.x-px)+(q.y-py)*(q.y-py);if(d<bd){bd=d;best=i}});return best}
function inBox(pn,px,py){var b=pn.box;return px>=b.x&&px<b.x+b.w&&py>=b.y&&py<b.y+b.h}
c.addEventListener('pointerdown',function(e){
  var px=e.clientX,py=e.clientY,list=mode==='giant'?[G]:[U,P];
  for(var k=0;k<list.length;k++){if(mode!=='giant'&&!inBox(list[k],px,py))continue;var i=hit(list[k],px,py);if(i>=0){drag={panel:list[k],i:i,moved:false,x0:px,y0:py};c.setPointerCapture(e.pointerId);if(window.SFX)SFX.play('tick',60);return}}
  drag={panel:null,moved:false,x0:px,y0:py};
});
c.addEventListener('pointermove',function(e){if(!drag)return;if(Math.abs(e.clientX-drag.x0)+Math.abs(e.clientY-drag.y0)>6)drag.moved=true;if(drag.panel){var q=drag.panel.nodes[drag.i];q.x=e.clientX;q.y=e.clientY;if(window.SFX)SFX.play('move',160)}});
c.addEventListener('pointerup',function(e){
  if(drag&&!drag.panel&&!drag.moved){
    if(mode==='giant'){setAuto(!auto);if(window.SFX)SFX.play('toggle')}
    else if(P.nodes.length<N_MAX+40){grow(e.clientX,e.clientY,inBox(U,e.clientX,e.clientY)?U:P)}
  }
  drag=null;
});
c.addEventListener('pointercancel',function(){drag=null});

/* ---------- controls ---------- */
var sl=document.getElementById('sl-p'),sv=document.getElementById('sv-p'),slm=document.getElementById('sl-m'),svm=document.getElementById('sv-m'),ba=document.getElementById('btn-auto');
function setAuto(v){auto=v;ba.classList.toggle('active',v)}
function setMode(md){mode=md;document.body.classList.toggle('m-giant',md==='giant');document.body.classList.toggle('m-hubs',md==='hubs');document.querySelectorAll('#modes button').forEach(function(b){b.classList.toggle('active',b.dataset.m===md)});setAuto(true);if(md==='hubs')hubsInit();else{p=.002;erInit()}}
document.querySelectorAll('#modes button').forEach(function(b){b.onclick=function(){setMode(b.dataset.m);if(window.SFX)SFX.play('toggle')}});
sl.oninput=function(){p=sl.value/1000;sv.textContent=p.toFixed(3);setAuto(false);erBuild();if(window.SFX)SFX.play('tick',80)};
slm.oninput=function(){m=+slm.value;svm.textContent=m;hubsInit();if(window.SFX)SFX.play('tick')};
ba.onclick=function(){setAuto(!auto);if(window.SFX)SFX.play('toggle')};
document.getElementById('btn-reset').onclick=function(){setMode(mode);if(window.SFX)SFX.play('click')};

function resize(){W=c.width=innerWidth;H=c.height=innerHeight;boxes()}
addEventListener('resize',resize);resize();setMode('giant');frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
