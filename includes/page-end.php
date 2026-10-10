<?php // Shared page ending: noscript fallback + sticky ad bar ?>
<?php $isPreview = isset($_GET['preview']) && $_GET['preview'] === '1'; ?>
<noscript>
<style>canvas{display:none!important}.ui{display:none!important}.floating-nav{display:none!important}body{overflow:auto!important}</style>
<div style="padding:3rem 2rem;max-width:800px;margin:0 auto;text-align:center">
<h1 style="color:#5fd0ff;font-family:Space Grotesk,sans-serif;font-size:1.5rem"><?php echo htmlspecialchars($pageTitle); ?></h1>
<p style="color:#888;font-size:1rem;line-height:1.8;margin:1rem 0"><?php echo htmlspecialchars($pageDesc); ?></p>
<p style="color:#666;font-size:0.85rem">This page requires JavaScript.<br>Please enable JavaScript to view the interactive canvas.</p>
<p style="margin-top:2rem"><a href="index.php" style="color:#5fd0ff">Back to home</a></p>
</div>
</noscript>

<?php if (!$isPreview): ?>
<style>
/* 画面下の広告。広告が実際に入ったときだけ下から出す。
   空枠（未配信・ブロック）のまま帯だけが画面を塞ぐことはしない。
   幅が 0 や画面外だと AdSense が枠を埋めないので、待機中も同じ位置で透明にしておく。 */
#stickyAd{position:fixed;bottom:0;left:0;right:0;z-index:9000;opacity:0;pointer-events:none;transition:opacity .38s cubic-bezier(.16,.84,.44,1)}
#stickyAd.is-shown{opacity:1;pointer-events:auto}
#stickyAdBox{position:relative;height:90px;max-height:90px;overflow:hidden;background:rgba(8, 22, 38,.96);border-top:1px solid rgba(255,255,255,.13)}
#stickyAdBox *{max-height:90px!important}
#stickyAdBox iframe{max-height:90px!important;height:90px!important}
#stickyAdClose{position:absolute;left:50%;transform:translateX(-50%);top:-25px;height:25px;display:inline-flex;align-items:center;gap:.45rem;padding:0 .7rem;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:.62rem;letter-spacing:.18em;color:#a9bccd;background:rgba(8, 22, 38,.96);border:1px solid rgba(255,255,255,.13);border-bottom:0;border-radius:4px 4px 0 0;cursor:pointer;transition:color .2s}
#stickyAdClose:hover{color:#5fd0ff}
</style>
<div id="stickyAd" aria-label="広告">
  <button id="stickyAdClose" type="button" aria-label="広告を閉じる">AD <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  <div id="stickyAdBox">
    <ins class="adsbygoogle" style="display:block;height:90px" data-ad-client="ca-pub-7086371722392050" data-ad-slot="4953305789" data-ad-format="horizontal" data-full-width-responsive="false"></ins>
  </div>
</div>
<script>
(function(){
  var wrap=document.getElementById('stickyAd');
  var box=document.getElementById('stickyAdBox');
  var btn=document.getElementById('stickyAdClose');
  var ins=box.querySelector('ins');
  var H=90, lifted=[], enforcer=null;
  if(sessionStorage.getItem('pulse_ad_closed')){wrap.remove();return;}
  try{(adsbygoogle=window.adsbygoogle||[]).push({});}catch(e){}

  /* 画面下に固定された操作部（Canvas ページのボタン類）を、広告の分だけ持ち上げる。
     bottom 基準で置かれているものだけが margin-bottom で上がるので、動かなかったものは戻す。 */
  function liftFixed(){
    var vh=innerHeight;
    document.querySelectorAll('body *').forEach(function(el){
      if(el===wrap||wrap.contains(el)||el.id==='paPrompt')return;
      var cs=getComputedStyle(el);
      if(cs.position!=='fixed'||cs.display==='none')return;
      var r=el.getBoundingClientRect();
      if(r.height===0||r.height>vh*.6||r.bottom<vh-H-8||r.top<vh*.25)return;
      var before=el.style.marginBottom;
      el.style.marginBottom=((parseFloat(cs.marginBottom)||0)+H)+'px';
      if(el.getBoundingClientRect().bottom>r.bottom-1){el.style.marginBottom=before;return;}
      lifted.push([el,before]);
    });
  }
  function unlift(){lifted.forEach(function(x){x[0].style.marginBottom=x[1];});lifted=[];}

  function show(){
    if(wrap.classList.contains('is-shown'))return;
    wrap.classList.add('is-shown');
    document.documentElement.style.setProperty('--pulse-ad-h',H+'px');
    document.body.style.paddingBottom=H+'px';
    liftFixed();
    enforcer=setInterval(function(){
      box.style.height=H+'px';box.style.maxHeight=H+'px';box.style.overflow='hidden';
      box.querySelectorAll('iframe').forEach(function(f){f.style.maxHeight=H+'px';});
    },500);
  }
  function hide(){
    wrap.classList.remove('is-shown');
    document.documentElement.style.removeProperty('--pulse-ad-h');
    document.body.style.paddingBottom='';
    unlift();
    clearInterval(enforcer);
  }

  /* AdSense は配信結果を data-ad-status（filled / unfilled）で返す */
  function check(){
    var st=ins.getAttribute('data-ad-status');
    if(st==='filled')show();
    else if(st==='unfilled')hide();
  }
  if(window.MutationObserver){new MutationObserver(check).observe(ins,{attributes:true,attributeFilter:['data-ad-status']});}
  check();
  /* 状態属性が付かない配信形式への保険：中身の iframe が高さを持ったら出す */
  var tries=0, poll=setInterval(function(){
    tries++;
    var f=box.querySelector('iframe');
    if(f&&f.getBoundingClientRect().height>20&&ins.getAttribute('data-ad-status')!=='unfilled'){show();clearInterval(poll);}
    else if(tries>20||ins.getAttribute('data-ad-status')){clearInterval(poll);}
  },1000);

  btn.addEventListener('click',function(e){
    e.stopPropagation();e.preventDefault();
    hide();
    sessionStorage.setItem('pulse_ad_closed','1');
    setTimeout(function(){wrap.remove();},400);
  });
})();
</script>
<?php endif; ?>
