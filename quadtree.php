<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('quadtree');

$pageName = 'quadtree';
$pageTitle = 'QUADTREE - PULSE';
$pageDesc = '四分木空間分割。粒子の密度に応じて空間が再帰的に分割され、範囲検索と最近傍探索を加速する。';
$pageCanonical = 'https://pulse.subara3.com/quadtree.php';

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
  PARTICLES: <span class="v" id="hud-n">0</span><br>
  DEPTH: <span class="v" id="hud-d">0</span><br>
  NODES: <span class="v" id="hud-c">0</span>
</div>

<div id="info">
  <span class="v">Quadtree</span><br>
  recursive spatial partitioning · O(log n) query
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'stream'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">CAPACITY</span>
      <input type="range" id="sl-cap" min="1" max="12" value="4">
      <span class="slider-value" id="sv-cap">4</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="0" max="10" value="3">
      <span class="slider-value" id="sv-speed">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">RANGE</span>
      <input type="range" id="sl-range" min="30" max="200" value="80">
      <span class="slider-value" id="sv-range">80</span>
    </div>
  </div>
  <div>
    <span class="slider-label">MODE</span>
    <div class="btn-row" id="mode-btns">
      <button class="control-btn active" data-v="spawn">SPAWN</button>
      <button class="control-btn" data-v="range">RANGE</button>
      <button class="control-btn" data-v="nearest">NEAREST</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="tree-btn">GRID</button>
    <button class="control-btn" id="clear-btn">CLEAR</button>
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
  var C=document.getElementById('canvas'),X=C.getContext('2d');
  var W,H;
  var particles=[];
  var cap=4,spd=3,rng=80,mode='spawn',showGrid=true;
  var mx=-999,my=-999,drag=false;
  var hudN=document.getElementById('hud-n');
  var hudD=document.getElementById('hud-d');
  var hudC=document.getElementById('hud-c');

  function QT(cx,cy,hw,hh,depth){
    this.cx=cx;this.cy=cy;this.hw=hw;this.hh=hh;
    this.dp=depth||0;this.pts=[];this.ch=null;
  }
  QT.prototype.has=function(p){
    return p.x>=this.cx-this.hw&&p.x<this.cx+this.hw&&
           p.y>=this.cy-this.hh&&p.y<this.cy+this.hh;
  };
  QT.prototype.hit=function(qx,qy,qr){
    var dx=Math.abs(qx-this.cx),dy=Math.abs(qy-this.cy);
    if(dx>this.hw+qr||dy>this.hh+qr)return false;
    if(dx<=this.hw||dy<=this.hh)return true;
    return(dx-this.hw)*(dx-this.hw)+(dy-this.hh)*(dy-this.hh)<=qr*qr;
  };
  QT.prototype.split=function(){
    var hw2=this.hw/2,hh2=this.hh/2,nd=this.dp+1;
    this.ch=[
      new QT(this.cx-hw2,this.cy-hh2,hw2,hh2,nd),
      new QT(this.cx+hw2,this.cy-hh2,hw2,hh2,nd),
      new QT(this.cx-hw2,this.cy+hh2,hw2,hh2,nd),
      new QT(this.cx+hw2,this.cy+hh2,hw2,hh2,nd)
    ];
    for(var i=0;i<this.pts.length;i++)this._ic(this.pts[i]);
    this.pts=[];
  };
  QT.prototype._ic=function(p){
    for(var i=0;i<4;i++)if(this.ch[i].has(p)){this.ch[i].ins(p);return;}
  };
  QT.prototype.ins=function(p){
    if(!this.has(p))return;
    if(!this.ch){
      if(this.pts.length<cap){this.pts.push(p);return;}
      if(this.dp<14)this.split();else{this.pts.push(p);return;}
    }
    this._ic(p);
  };
  QT.prototype.qry=function(qx,qy,qr,f){
    if(!this.hit(qx,qy,qr))return;
    if(!this.ch){
      for(var i=0;i<this.pts.length;i++){
        var dx=this.pts[i].x-qx,dy=this.pts[i].y-qy;
        if(dx*dx+dy*dy<=qr*qr)f.push(this.pts[i]);
      }
      return;
    }
    for(var j=0;j<4;j++)this.ch[j].qry(qx,qy,qr,f);
  };
  QT.prototype.near=function(px,py,b){
    var ex=Math.max(0,Math.abs(px-this.cx)-this.hw);
    var ey=Math.max(0,Math.abs(py-this.cy)-this.hh);
    if(ex*ex+ey*ey>=b.d)return;
    if(!this.ch){
      for(var i=0;i<this.pts.length;i++){
        var dd=(this.pts[i].x-px)*(this.pts[i].x-px)+(this.pts[i].y-py)*(this.pts[i].y-py);
        if(dd<b.d){b.d=dd;b.p=this.pts[i];}
      }
      return;
    }
    var cs=this.ch.slice().sort(function(a,c){
      return((a.cx-px)*(a.cx-px)+(a.cy-py)*(a.cy-py))-((c.cx-px)*(c.cx-px)+(c.cy-py)*(c.cy-py));
    });
    for(var j=0;j<4;j++)cs[j].near(px,py,b);
  };
  QT.prototype.stat=function(s){
    s.n++;if(this.dp>s.d)s.d=this.dp;
    if(this.ch)for(var i=0;i<4;i++)this.ch[i].stat(s);
  };
  QT.prototype.draw=function(ctx){
    if(!this.ch)return;
    var a=Math.min(0.1+this.dp*0.06,0.5);
    var h=175+this.dp*6;if(h>200)h=200;
    ctx.strokeStyle='hsla('+h+',80%,50%,'+a+')';
    ctx.lineWidth=Math.max(0.3,1.2-this.dp*0.1);
    ctx.beginPath();
    ctx.moveTo(this.cx,this.cy-this.hh);ctx.lineTo(this.cx,this.cy+this.hh);
    ctx.moveTo(this.cx-this.hw,this.cy);ctx.lineTo(this.cx+this.hw,this.cy);
    ctx.stroke();
    for(var i=0;i<4;i++)this.ch[i].draw(ctx);
  };
  QT.prototype.drawHL=function(ctx,qx,qy,qr){
    if(!this.hit(qx,qy,qr))return;
    if(!this.ch){
      ctx.fillStyle='rgba(123,44,191,0.06)';
      ctx.fillRect(this.cx-this.hw,this.cy-this.hh,this.hw*2,this.hh*2);
      return;
    }
    for(var i=0;i<4;i++)this.ch[i].drawHL(ctx,qx,qy,qr);
  };

  function spawn(x,y,n){
    for(var i=0;i<(n||1);i++){
      var a=Math.random()*6.2832,s=Math.random()*0.4*spd;
      particles.push({
        x:x+(Math.random()-0.5)*14,
        y:y+(Math.random()-0.5)*14,
        vx:Math.cos(a)*s,vy:Math.sin(a)*s,
        h:170+Math.random()*30,
        r:1.2+Math.random()*1.3
      });
    }
  }

  function initP(){
    particles=[];
    var cls=[
      {x:W*0.25,y:H*0.3,n:50,spread:70},
      {x:W*0.75,y:H*0.6,n:50,spread:70},
      {x:W*0.5,y:H*0.45,n:40,spread:60},
      {x:W*0.15,y:H*0.72,n:30,spread:50},
      {x:W*0.85,y:H*0.25,n:30,spread:50}
    ];
    for(var i=0;i<cls.length;i++){
      var cl=cls[i];
      for(var j=0;j<cl.n;j++){
        var a=Math.random()*6.2832,d=Math.random()*cl.spread;
        spawn(cl.x+Math.cos(a)*d,cl.y+Math.sin(a)*d);
      }
    }
    for(var k=0;k<50;k++)spawn(Math.random()*W,Math.random()*H);
  }

  function update(){
    for(var i=0;i<particles.length;i++){
      var p=particles[i];
      p.vx+=(Math.random()-0.5)*0.08*spd;
      p.vy+=(Math.random()-0.5)*0.08*spd;
      p.vx*=0.993;p.vy*=0.993;
      p.x+=p.vx;p.y+=p.vy;
      if(p.x<0){p.x=0;p.vx*=-0.7;}
      if(p.x>W){p.x=W;p.vx*=-0.7;}
      if(p.y<0){p.y=0;p.vy*=-0.7;}
      if(p.y>H){p.y=H;p.vy*=-0.7;}
    }
  }

  function render(){
    X.fillStyle='#0a0a0f';X.fillRect(0,0,W,H);

    var tree=new QT(W/2,H/2,W/2,H/2,0);
    for(var i=0;i<particles.length;i++)tree.ins(particles[i]);
    var st={n:0,d:0};tree.stat(st);

    if(showGrid)tree.draw(X);

    for(var i=0;i<particles.length;i++){
      var p=particles[i];
      X.beginPath();X.arc(p.x,p.y,p.r,0,6.2832);
      X.fillStyle='hsla('+p.h+',80%,55%,0.85)';X.fill();
    }

    if(mode==='range'&&mx>-900){
      tree.drawHL(X,mx,my,rng);
      X.beginPath();X.arc(mx,my,rng,0,6.2832);
      X.strokeStyle='rgba(123,44,191,0.5)';X.lineWidth=1.5;X.stroke();
      X.fillStyle='rgba(123,44,191,0.03)';X.fill();
      var found=[];tree.qry(mx,my,rng,found);
      for(var j=0;j<found.length;j++){
        X.beginPath();X.arc(found[j].x,found[j].y,found[j].r+2.5,0,6.2832);
        X.fillStyle='rgba(0,245,212,0.9)';X.fill();
      }
      X.fillStyle='rgba(0,245,212,0.7)';
      X.font='10px "Orbitron",sans-serif';X.textAlign='center';
      X.fillText(found.length+' FOUND',mx,my-rng-8);
    }

    if(mode==='nearest'&&mx>-900&&particles.length>0){
      var best={p:null,d:Infinity};tree.near(mx,my,best);
      if(best.p){
        X.beginPath();X.moveTo(mx,my);X.lineTo(best.p.x,best.p.y);
        X.strokeStyle='rgba(123,44,191,0.6)';X.lineWidth=1;
        X.setLineDash([4,4]);X.stroke();X.setLineDash([]);
        X.beginPath();X.arc(best.p.x,best.p.y,best.p.r+3,0,6.2832);
        X.strokeStyle='#00f5d4';X.lineWidth=2;X.stroke();
        var dd=Math.sqrt(best.d);
        X.fillStyle='rgba(0,245,212,0.6)';
        X.font='9px "Orbitron",sans-serif';X.textAlign='center';
        X.fillText('d='+dd.toFixed(1),(mx+best.p.x)/2,(my+best.p.y)/2-8);
      }
    }

    hudN.textContent=particles.length;
    hudD.textContent=st.d;
    hudC.textContent=st.n;
  }

  function loop(){update();render();requestAnimationFrame(loop);}

  function resize(){
    var dpr=Math.min(window.devicePixelRatio||1,2);
    W=window.innerWidth;H=window.innerHeight;
    C.width=W*dpr;C.height=H*dpr;
    C.style.width=W+'px';C.style.height=H+'px';
    X.setTransform(dpr,0,0,dpr,0,0);
  }

  C.addEventListener('pointerdown',function(e){
    e.preventDefault();
    if(e.button===2){
      var r2=3600,out=[];
      for(var i=0;i<particles.length;i++){
        var dx=particles[i].x-e.clientX,dy=particles[i].y-e.clientY;
        if(dx*dx+dy*dy>r2)out.push(particles[i]);
      }
      if(out.length<particles.length&&typeof SFX!=='undefined')SFX.play('pop');
      particles=out;
      return;
    }
    drag=true;
    spawn(e.clientX,e.clientY,8);
    if(typeof SFX!=='undefined')SFX.play('spawn');
  });

  C.addEventListener('pointermove',function(e){
    mx=e.clientX;my=e.clientY;
    if(drag){
      spawn(e.clientX,e.clientY,2);
      if(typeof SFX!=='undefined')SFX.play('move',80);
    }
  });

  window.addEventListener('pointerup',function(){drag=false;});
  C.addEventListener('contextmenu',function(e){e.preventDefault();});
  window.addEventListener('resize',resize);

  document.getElementById('sl-cap').addEventListener('input',function(){
    cap=+this.value;document.getElementById('sv-cap').textContent=cap;
  });
  document.getElementById('sl-speed').addEventListener('input',function(){
    spd=+this.value;document.getElementById('sv-speed').textContent=spd;
  });
  document.getElementById('sl-range').addEventListener('input',function(){
    rng=+this.value;document.getElementById('sv-range').textContent=rng;
  });

  var mBtns=document.querySelectorAll('#mode-btns .control-btn');
  for(var i=0;i<mBtns.length;i++)(function(btn){
    btn.addEventListener('click',function(){
      for(var j=0;j<mBtns.length;j++)mBtns[j].classList.remove('active');
      btn.classList.add('active');mode=btn.dataset.v;
      if(typeof SFX!=='undefined')SFX.play('toggle');
    });
  })(mBtns[i]);

  document.getElementById('tree-btn').addEventListener('click',function(){
    showGrid=!showGrid;this.classList.toggle('active');
    if(typeof SFX!=='undefined')SFX.play('toggle');
  });
  document.getElementById('clear-btn').addEventListener('click',function(){
    particles=[];if(typeof SFX!=='undefined')SFX.play('click');
  });
  document.getElementById('reset-btn').addEventListener('click',function(){
    initP();if(typeof SFX!=='undefined')SFX.play('click');
  });

  resize();initP();loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
