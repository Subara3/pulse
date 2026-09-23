<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('penrose');

$pageName = 'penrose';
$pageTitle = 'PENROSE - PULSE';
$pageDesc = '三角形を黄金比で切り分け続けると、どこまで行っても同じ並びが繰り返さない床ができる。菱形・カイトとダート・帽子（1種類の形だけで敷き詰める2023年のタイル）を切り替え、タップしたタイルがどの大きなタイルから生まれたかをたどれる。';
$pageCanonical = 'https://pulse.subara3.com/penrose.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.58);border:1px solid rgba(0,245,212,.16);padding:.7rem .85rem;border-radius:8px;backdrop-filter:blur(12px);pointer-events:none}
.hud b{color:#00f5d4}.hud .sub{color:#b58cff}
.modes{position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:.3rem;padding:.3rem;background:rgba(10,10,15,.58);border:1px solid rgba(0,245,212,.14);border-radius:999px;backdrop-filter:blur(12px)}
.modes button{font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.14em;color:rgba(255,255,255,.6);background:none;border:0;border-radius:999px;padding:.55rem .9rem;cursor:pointer;transition:color .2s ease-out,background .2s ease-out}
.modes button.active{color:#0a0a0f;background:#00f5d4}
.drawer-controls .btn-row{display:flex;gap:.5rem;flex-wrap:wrap}.drawer-controls .control-btn{min-height:36px}
.slider-row{display:grid;grid-template-columns:72px 1fr 44px;gap:.65rem;align-items:center}
.slider-label,.slider-value{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.72);letter-spacing:.08em}
.slider-value{text-align:right;color:#00f5d4}
input[type=range]{width:100%;accent-color:#00f5d4}
@media(max-width:760px){.hud{top:5.9rem;left:.7rem;font-size:10px;padding:.45rem .55rem;line-height:1.55}.modes{top:3.1rem}.modes button{padding:.5rem .65rem;letter-spacing:.08em}.slider-row{grid-template-columns:62px 1fr 40px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="modes" id="modes"><button data-m="rhomb" class="active">RHOMB</button><button data-m="kite">KITE</button><button data-m="hat">HAT</button></div>
<div class="hud" id="hud"></div>
<?php $interactionHints=[['type'=>'click','label'=>'ancestry'],['type'=>'drag','label'=>'pan'],['type'=>'scroll','label'=>'zoom']];require_once __DIR__ . '/includes/interaction-hints.php'; ?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><span class="slider-label">DEPTH</span><input type="range" id="sl-depth" min="1" max="8" value="6"><span class="slider-value" id="sv-depth">6</span></div>
  <div class="btn-row"><button class="control-btn" id="btn-grow">GROW</button><button class="control-btn active" id="btn-spin">SPIN</button><button class="control-btn" id="btn-view">RESET VIEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c=document.getElementById('canvas'),x=c.getContext('2d'),W=0,H=0,D=1;
var PHI=(1+Math.sqrt(5))/2,mode='rhomb',depth=6,shown=0,growT=0,spin=true,rot=0;
var leaves=[],sel=-1,selT=0,view={x:0,y:0,s:1},fit={x:0,y:0,s:1},drag=null,pinch=null;
var hud=document.getElementById('hud'),slD=document.getElementById('sl-depth'),svD=document.getElementById('sv-depth');
var MAXD={rhomb:8,kite:8,hat:4};

/* ============ Penrose via Robinson triangles ============
   each triangle: [kind, A, B, C]; leaves remember their ancestors' outlines */
function sub(a,b){return[a[0]-b[0],a[1]-b[1]]}function add(a,b){return[a[0]+b[0],a[1]+b[1]]}function scl(a,k){return[a[0]*k,a[1]*k]}
function lerp(a,b,k){return add(a,scl(sub(b,a),k))}
function penrose(kite,n){
  /* P3: Robinson triangles [A,B,C], halves pair along the base BC.
     P2: half-kite [apex,outer,axisEnd] / half-dart [tip,side,reflex], halves pair along the axis (first-last). */
  var tris=[],i;
  if(!kite){for(i=0;i<10;i++){var b=[Math.cos((2*i-1)*Math.PI/10),Math.sin((2*i-1)*Math.PI/10)],cc=[Math.cos((2*i+1)*Math.PI/10),Math.sin((2*i+1)*Math.PI/10)];if(i%2===0){var t=b;b=cc;cc=t}tris.push({k:0,A:[0,0],B:b,C:cc,anc:[]})}}
  else{for(i=0;i<5;i++){var th=2*Math.PI*i/5,ax=[PHI*Math.cos(th),PHI*Math.sin(th)];[1,-1].forEach(function(sg){var a=th+sg*Math.PI/5;tris.push({k:0,A:[0,0],B:[PHI*Math.cos(a),PHI*Math.sin(a)],C:ax,anc:[]})})}}
  for(var s=0;s<n;s++){
    var out=[];
    tris.forEach(function(T){
      var A=T.A,B=T.B,C=T.C,anc=T.anc.concat([[A,B,C]]);
      if(!kite){
        if(T.k===0){var P=lerp(A,B,1/PHI);out.push({k:0,A:C,B:P,C:B,anc:anc},{k:1,A:P,B:C,C:A,anc:anc})}
        else{var Q=lerp(B,A,1/PHI),R=lerp(B,C,1/PHI);out.push({k:1,A:R,B:C,C:A,anc:anc},{k:1,A:Q,B:R,C:B,anc:anc},{k:0,A:R,B:Q,C:A,anc:anc})}
      }else{
        if(T.k===0){var ac=lerp(A,C,1/PHI),ba=lerp(B,A,1/PHI);out.push({k:0,A:B,B:C,C:ac,anc:anc},{k:0,A:B,B:ba,C:ac,anc:anc},{k:1,A:A,B:ac,C:ba,anc:anc})}
        else{var ts=lerp(A,B,1/PHI);out.push({k:0,A:A,B:ts,C:C,anc:anc},{k:1,A:B,B:C,C:ts,anc:anc})}
      }
    });
    tris=out;
  }
  return tris.map(function(T){return{poly:[T.A,T.B,T.C],k:T.k,edge:kite?[0,1,2]:[1,0,2],anc:T.anc.slice(-3).reverse(),d:Math.hypot((T.A[0]+T.B[0]+T.C[0])/3,(T.A[1]+T.B[1]+T.C[1])/3)}});
}

/* ============ Hat monotile via metatile substitution (Smith, Myers, Kaplan, Goodman-Strauss 2023) ============ */
var hr3=Math.sqrt(3)/2;
function pt(a,b){return{x:a,y:b}}function hexPt(a,b){return pt(a+.5*b,hr3*b)}
function mul(A,B){return[A[0]*B[0]+A[1]*B[3],A[0]*B[1]+A[1]*B[4],A[0]*B[2]+A[1]*B[5]+A[2],A[3]*B[0]+A[4]*B[3],A[3]*B[1]+A[4]*B[4],A[3]*B[2]+A[4]*B[5]+A[5]]}
function inv(T){var d=T[0]*T[4]-T[1]*T[3];return[T[4]/d,-T[1]/d,(T[1]*T[5]-T[2]*T[4])/d,-T[3]/d,T[0]/d,(T[2]*T[3]-T[0]*T[5])/d]}
function trot(a){var cs=Math.cos(a),sn=Math.sin(a);return[cs,-sn,0,sn,cs,0]}function ttrans(a,b){return[1,0,a,0,1,b]}
function rotAbout(p,a){return mul(ttrans(p.x,p.y),mul(trot(a),ttrans(-p.x,-p.y)))}
function tp(M,P){return pt(M[0]*P.x+M[1]*P.y+M[2],M[3]*P.x+M[4]*P.y+M[5])}
function padd(p,q){return pt(p.x+q.x,p.y+q.y)}function psub(p,q){return pt(p.x-q.x,p.y-q.y)}
function matchSeg(p,q){return[q.x-p.x,p.y-q.y,p.x,q.y-p.y,q.x-p.x,p.y]}
function matchTwo(p1,q1,p2,q2){return mul(matchSeg(p2,q2),inv(matchSeg(p1,q1)))}
function intersect(p1,q1,p2,q2){var d=(q2.y-p2.y)*(q1.x-p1.x)-(q2.x-p2.x)*(q1.y-p1.y),u=((q2.x-p2.x)*(p1.y-p2.y)-(q2.y-p2.y)*(p1.x-p2.x))/d;return pt(p1.x+u*(q1.x-p1.x),p1.y+u*(q1.y-p1.y))}
var IDENT=[1,0,0,0,1,0];
var HAT=[hexPt(0,0),hexPt(-1,-1),hexPt(0,-2),hexPt(2,-2),hexPt(2,-1),hexPt(4,-2),hexPt(5,-1),hexPt(4,0),hexPt(3,0),hexPt(2,2),hexPt(0,3),hexPt(0,2),hexPt(-1,2)];
function Hat(l){this.label=l;this.hat=true}
function Meta(shape){this.shape=shape;this.children=[]}
Meta.prototype.add=function(T,g){this.children.push({T:T,g:g})};
Meta.prototype.ev=function(n,i){return tp(this.children[n].T,this.children[n].g.shape[i])};
Meta.prototype.recentre=function(){var cx=0,cy=0;this.shape.forEach(function(p){cx+=p.x;cy+=p.y});cx/=this.shape.length;cy/=this.shape.length;var tr=pt(-cx,-cy);this.shape=this.shape.map(function(p){return padd(p,tr)});var M=ttrans(-cx,-cy);this.children.forEach(function(ch){ch.T=mul(M,ch.T)})};
var H1h=new Hat('H1'),Hh=new Hat('H'),Th=new Hat('T'),Ph=new Hat('P'),Fh=new Hat('F');
function initH(){var o=[pt(0,0),pt(4,0),pt(4.5,hr3),pt(2.5,5*hr3),pt(1.5,5*hr3),pt(-.5,hr3)],m=new Meta(o);m.name='H';
  m.add(matchTwo(HAT[5],HAT[7],o[5],o[0]),Hh);m.add(matchTwo(HAT[9],HAT[11],o[1],o[2]),Hh);m.add(matchTwo(HAT[5],HAT[7],o[3],o[4]),Hh);
  m.add(mul(ttrans(2.5,hr3),mul([-.5,-hr3,0,hr3,-.5,0],[.5,0,0,0,-.5,0])),H1h);return m}
function initT(){var m=new Meta([pt(0,0),pt(3,0),pt(1.5,3*hr3)]);m.name='T';m.add([.5,0,.5,0,.5,hr3],Th);return m}
function initP(){var m=new Meta([pt(0,0),pt(4,0),pt(3,2*hr3),pt(-1,2*hr3)]);m.name='P';m.add([.5,0,1.5,0,.5,hr3],Ph);m.add(mul(ttrans(0,2*hr3),mul([.5,hr3,0,-hr3,.5,0],[.5,0,0,0,.5,0])),Ph);return m}
function initF(){var m=new Meta([pt(0,0),pt(3,0),pt(3.5,hr3),pt(3,2*hr3),pt(-1,2*hr3)]);m.name='F';m.add([.5,0,1.5,0,.5,hr3],Fh);m.add(mul(ttrans(0,2*hr3),mul([.5,hr3,0,-hr3,.5,0],[.5,0,0,0,.5,0])),Fh);return m}
var RULES=[['H'],[0,0,'P',2],[1,0,'H',2],[2,0,'P',2],[3,0,'H',2],[4,4,'P',2],[0,4,'F',3],[2,4,'F',3],[4,1,3,2,'F',0],[8,3,'H',0],[9,2,'P',0],[10,2,'H',0],[11,4,'P',2],[12,0,'H',2],[13,0,'F',3],[14,2,'F',1],[15,3,'H',4],[8,2,'F',1],[17,3,'H',0],[18,2,'P',0],[19,2,'H',2],[20,4,'F',3],[20,0,'P',2],[22,0,'H',2],[23,4,'F',3],[23,0,'F',3],[16,0,'P',2],[9,4,0,2,'T',2],[4,0,'F',3]];
function patch(Hm,Tm,Pm,Fm){
  var ret=new Meta([]),S={H:Hm,T:Tm,P:Pm,F:Fm};
  RULES.forEach(function(r){
    if(r.length===1){ret.add(IDENT,S[r[0]]);return}
    var P,Q,ns=S[r[r.length-2]],np=ns.shape,e=r[r.length-1];
    if(r.length===4){var ch=ret.children[r[0]],poly=ch.g.shape;P=tp(ch.T,poly[(r[1]+1)%poly.length]);Q=tp(ch.T,poly[r[1]])}
    else{var cP=ret.children[r[0]],cQ=ret.children[r[2]];P=tp(cQ.T,cQ.g.shape[r[3]]);Q=tp(cP.T,cP.g.shape[r[1]])}
    ret.add(matchTwo(np[e],np[(e+1)%np.length],P,Q),ns);
  });
  return ret;
}
function metas(p){
  var b1=p.ev(8,2),b2=p.ev(21,2),rb=tp(rotAbout(b1,-2*Math.PI/3),b2),p72=p.ev(7,2),p252=p.ev(25,2);
  var llc=intersect(b1,rb,p.ev(6,2),p72),w=psub(p.ev(6,2),llc);
  var Ho=[llc,b1];w=tp(trot(-Math.PI/3),w);Ho.push(padd(Ho[1],w));Ho.push(p.ev(14,2));w=tp(trot(-Math.PI/3),w);Ho.push(psub(Ho[3],w));Ho.push(p.ev(6,2));
  var nH=new Meta(Ho);nH.name='H';[0,9,16,27,26,6,1,8,10,15].forEach(function(i){nH.add(p.children[i].T,p.children[i].g)});
  var nP=new Meta([p72,padd(p72,psub(b1,llc)),b1,llc]);nP.name='P';[7,2,3,4,28].forEach(function(i){nP.add(p.children[i].T,p.children[i].g)});
  var nF=new Meta([b2,p.ev(24,2),p.ev(25,0),p252,padd(p252,psub(llc,b1))]);nF.name='F';[21,20,22,23,24,25].forEach(function(i){nF.add(p.children[i].T,p.children[i].g)});
  var A=Ho[2],B=padd(Ho[1],psub(Ho[4],Ho[5])),C=tp(rotAbout(B,-Math.PI/3),A);
  var nT=new Meta([B,C,A]);nT.name='T';nT.add(p.children[11].T,p.children[11].g);
  [nH,nP,nF,nT].forEach(function(m){m.recentre()});
  return[nH,nT,nP,nF];
}
function hats(n){
  var t=[initH(),initT(),initP(),initF()];
  for(var i=0;i<n;i++){var pp=patch(t[0],t[1],t[2],t[3]);t=metas(pp)}
  var out=[];
  (function walk(g,M,anc){
    if(g.hat){var poly=HAT.map(function(q){var r=tp(M,q);return[r.x,r.y]});var cx=0,cy=0;poly.forEach(function(q){cx+=q[0];cy+=q[1]});out.push({poly:poly,label:g.label,anc:anc.slice(-3).reverse(),d:Math.hypot(cx/13,cy/13)});return}
    var here=anc.concat([g.shape.map(function(q){var r=tp(M,q);return[r.x,r.y]})]);
    g.children.forEach(function(ch){walk(ch.g,mul(M,ch.T),here)});
  })(t[0],IDENT,[]);
  return out;
}

/* ============ build + view ============ */
function build(){
  leaves=mode==='hat'?hats(depth):penrose(mode==='kite',depth);
  leaves.sort(function(a,b){return a.d-b.d});
  var minx=1e9,miny=1e9,maxx=-1e9,maxy=-1e9;
  leaves.forEach(function(L){L.poly.forEach(function(q){if(q[0]<minx)minx=q[0];if(q[0]>maxx)maxx=q[0];if(q[1]<miny)miny=q[1];if(q[1]>maxy)maxy=q[1]})});
  var cx=(minx+maxx)/2,cy=(miny+maxy)/2,span=Math.max(maxx-minx,maxy-miny);
  // penrose wheel is round: zoom in so it fills the screen; the hat patch is a ragged blob: show all of it
  fit={x:cx,y:cy,s:mode==='hat'?Math.min(W,H)/span*1.12:Math.max(W,H)/span*1.9};view={x:fit.x,y:fit.y,s:fit.s};
  sel=-1;shown=0;growT=0;
}
function toScreen(q){var dx=q[0]-view.x,dy=q[1]-view.y,cs=Math.cos(rot),sn=Math.sin(rot);return[W/2+(dx*cs-dy*sn)*view.s,H/2+(dx*sn+dy*cs)*view.s]}
function toWorld(sx,sy){var dx=(sx-W/2)/view.s,dy=(sy-H/2)/view.s,cs=Math.cos(-rot),sn=Math.sin(-rot);return[view.x+dx*cs-dy*sn,view.y+dx*sn+dy*cs]}

var COL={rhomb:['rgba(0,245,212,.30)','rgba(123,44,191,.42)'],kite:['rgba(0,245,212,.30)','rgba(123,44,191,.42)']};
var HATCOL={H:'rgba(0,245,212,.34)',T:'rgba(0,200,190,.22)',P:'rgba(0,160,170,.26)',F:'rgba(0,210,200,.18)',H1:'rgba(181,140,255,.78)'};
function path(poly,closeAll){var p=toScreen(poly[0]);x.beginPath();x.moveTo(p[0],p[1]);for(var i=1;i<poly.length;i++){p=toScreen(poly[i]);x.lineTo(p[0],p[1])}if(closeAll)x.closePath()}
function draw(){
  x.fillStyle='#0a0a0f';x.fillRect(0,0,W,H);
  var n=Math.min(leaves.length,Math.floor(shown)),lw=Math.max(.4,Math.min(1.4,view.s*(mode==='hat'?.05:.012)));
  x.lineJoin='round';
  for(var i=0;i<n;i++){
    var L=leaves[i],fresh=Math.min(1,(shown-i)/Math.max(30,leaves.length*.05));
    x.globalAlpha=fresh;
    if(mode==='hat'){path(L.poly,true);x.fillStyle=HATCOL[L.label];x.fill();x.strokeStyle='rgba(10,10,15,.95)';x.lineWidth=lw;x.stroke()}
    else{
      path(L.poly,true);x.fillStyle=COL[mode][L.k];x.fill();
      var e=L.edge,a=toScreen(L.poly[e[0]]),b=toScreen(L.poly[e[1]]),cc=toScreen(L.poly[e[2]]);  // skip the edge shared by the two halves of one tile
      x.strokeStyle='rgba(0,245,212,.55)';x.lineWidth=lw;x.beginPath();x.moveTo(a[0],a[1]);x.lineTo(b[0],b[1]);x.lineTo(cc[0],cc[1]);x.stroke();
    }
  }
  x.globalAlpha=1;
  if(sel>=0&&leaves[sel]){
    var S=leaves[sel],pulse=.55+.45*Math.sin(selT*.12);
    S.anc.forEach(function(poly,k){path(poly,true);x.strokeStyle=k===0?'rgba(0,245,212,'+(.95*pulse)+')':k===1?'rgba(181,140,255,.85)':'rgba(255,255,255,.5)';x.lineWidth=[2.6,2,1.4][k];x.setLineDash(k===2?[6,5]:[]);x.stroke()});
    x.setLineDash([]);path(S.poly,true);x.fillStyle='rgba(255,255,255,.85)';x.fill();
  }
}
function stats(){
  var s='<span class="sub">'+(mode==='hat'?'HAT · ONE SHAPE':mode==='kite'?'KITE & DART':'THICK & THIN')+'</span><br>TILES <b>'+(mode==='hat'?leaves.length:Math.round(leaves.length/2))+'</b><br>DEPTH <b>'+depth+'</b><br>';
  if(mode==='hat'){var f=leaves.filter(function(L){return L.label==='H1'}).length;s+='FLIPPED <b>1 / '+(f?(leaves.length/f).toFixed(2):'-')+'</b><br><span class="sub">1+φ⁴</span> '+(1+Math.pow(PHI,4)).toFixed(2)}
  else{var a=0,b=0;leaves.forEach(function(L){L.k?b++:a++});s+=(mode==='kite'?'KITE/DART':'THICK/THIN')+' <b>'+(a&&b?(mode==='kite'?a/b:b/a).toFixed(4):'-')+'</b><br><span class="sub">φ</span> '+PHI.toFixed(4)}
  hud.innerHTML=s;
}

/* ============ input ============ */
function pick(sx,sy){var w=toWorld(sx,sy);for(var i=Math.min(leaves.length,Math.floor(shown))-1;i>=0;i--){var p=leaves[i].poly,ins=false;for(var a=0,b=p.length-1;a<p.length;b=a++){if((p[a][1]>w[1])!==(p[b][1]>w[1])&&w[0]<(p[b][0]-p[a][0])*(w[1]-p[a][1])/(p[b][1]-p[a][1])+p[a][0])ins=!ins}if(ins)return i}return -1}
c.addEventListener('pointerdown',function(e){if(pinch)return;drag={x:e.clientX,y:e.clientY,vx:view.x,vy:view.y,moved:false,id:e.pointerId};c.setPointerCapture(e.pointerId)});
c.addEventListener('pointermove',function(e){if(!drag||e.pointerId!==drag.id||pinch)return;var dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(!drag.moved&&dx*dx+dy*dy<30)return;drag.moved=true;var cs=Math.cos(-rot),sn=Math.sin(-rot);view.x=drag.vx-(dx*cs-dy*sn)/view.s;view.y=drag.vy-(dx*sn+dy*cs)/view.s;if(window.SFX)SFX.play('move',160)});
c.addEventListener('pointerup',function(e){if(drag&&!drag.moved&&!pinch){var i=pick(e.clientX,e.clientY);sel=i;selT=0;if(window.SFX)SFX.play(i>=0?'spawn':'tick')}drag=null});
c.addEventListener('pointercancel',function(){drag=null});
c.addEventListener('wheel',function(e){e.preventDefault();var w=toWorld(e.clientX,e.clientY);view.s*=e.deltaY<0?1.15:.87;var w2=toWorld(e.clientX,e.clientY);view.x+=w[0]-w2[0];view.y+=w[1]-w2[1];if(window.SFX)SFX.play('tick',110)},{passive:false});
c.addEventListener('touchstart',function(e){if(e.touches.length===2){var a=e.touches[0],b=e.touches[1];pinch={d:Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY),s:view.s};drag=null}},{passive:true});
c.addEventListener('touchmove',function(e){if(pinch&&e.touches.length===2){e.preventDefault();var a=e.touches[0],b=e.touches[1];view.s=pinch.s*Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY)/pinch.d}},{passive:false});
c.addEventListener('touchend',function(e){if(e.touches.length<2)setTimeout(function(){pinch=null},60)},{passive:true});

/* ============ controls ============ */
function setMode(m){mode=m;depth=Math.min(depth,MAXD[m]);if(m==='hat'&&depth>3)depth=3;if(m!=='hat'&&depth<5)depth=6;slD.max=MAXD[m];slD.value=depth;svD.textContent=depth;document.querySelectorAll('#modes button').forEach(function(b){b.classList.toggle('active',b.dataset.m===m)});build()}
document.querySelectorAll('#modes button').forEach(function(b){b.onclick=function(){setMode(b.dataset.m);if(window.SFX)SFX.play('toggle')}});
slD.oninput=function(){depth=+slD.value;svD.textContent=depth;build();if(window.SFX)SFX.play('tick')};
document.getElementById('btn-grow').onclick=function(){shown=0;if(window.SFX)SFX.play('success')};
document.getElementById('btn-spin').onclick=function(e){spin=!spin;e.currentTarget.classList.toggle('active',spin);if(window.SFX)SFX.play('toggle')};
document.getElementById('btn-view').onclick=function(){view={x:fit.x,y:fit.y,s:fit.s};rot=0;if(window.SFX)SFX.play('click')};

function resize(){D=1;W=c.width=innerWidth;H=c.height=innerHeight;build()}
addEventListener('resize',resize);resize();
(function loop(){
  if(shown<leaves.length)shown+=Math.max(6,leaves.length/170);
  if(spin)rot+=.0006;selT++;
  draw();stats();requestAnimationFrame(loop);
})();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
