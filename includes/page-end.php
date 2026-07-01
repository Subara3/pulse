<?php // Shared page ending: noscript fallback + sticky ad bar ?>
<?php $isPreview = isset($_GET['preview']) && $_GET['preview'] === '1'; ?>
<noscript>
<style>canvas{display:none!important}.ui{display:none!important}.floating-nav{display:none!important}body{overflow:auto!important}</style>
<div style="padding:3rem 2rem;max-width:800px;margin:0 auto;text-align:center">
<h1 style="color:#00f5d4;font-family:Orbitron,sans-serif;font-size:1.5rem"><?php echo htmlspecialchars($pageTitle); ?></h1>
<p style="color:#888;font-size:1rem;line-height:1.8;margin:1rem 0"><?php echo htmlspecialchars($pageDesc); ?></p>
<p style="color:#666;font-size:0.85rem">This page requires JavaScript.<br>Please enable JavaScript to view the interactive canvas.</p>
<p style="margin-top:2rem"><a href="index.php" style="color:#00f5d4">Back to home</a></p>
</div>
</noscript>

<?php if (!$isPreview): ?>
<style>
#stickyAd{position:fixed;bottom:0;left:0;right:0;z-index:9000;display:none}
#stickyAdBar{display:flex;align-items:center;background:#1a1a2e;padding:8px 12px;border-bottom:1px solid rgba(0,245,212,0.3)}
#stickyAdClose{background:#00f5d4;color:#0a0a0f;border:none;font-size:0.85rem;font-weight:bold;padding:6px 16px;border-radius:4px;cursor:pointer;font-family:sans-serif}
#stickyAdClose:hover{background:#00dbb8}
#stickyAdBox{position:relative;height:90px;max-height:90px;overflow:hidden;background:#0a0a0f}
#stickyAdBox *{max-height:90px!important}
#stickyAdBox iframe{max-height:90px!important;height:90px!important}
</style>
<div id="stickyAd">
  <div id="stickyAdBar">
    <button id="stickyAdClose" type="button" aria-label="Close ad">Close</button>
  </div>
  <div id="stickyAdBox">
    <ins class="adsbygoogle" style="display:block;height:90px" data-ad-client="ca-pub-7086371722392050" data-ad-slot="4953305789" data-ad-format="horizontal" data-full-width-responsive="false"></ins>
  </div>
</div>
<script>
(function(){
  var wrap=document.getElementById('stickyAd');
  var box=document.getElementById('stickyAdBox');
  var btn=document.getElementById('stickyAdClose');
  if(sessionStorage.getItem('pulse_ad_closed'))return;
  wrap.style.display='block';
  try{(adsbygoogle=window.adsbygoogle||[]).push({});}catch(e){}
  var enforcer=setInterval(function(){
    if(box){box.style.height='90px';box.style.maxHeight='90px';box.style.overflow='hidden';}
    var iframes=box?box.querySelectorAll('iframe'):[];
    for(var i=0;i<iframes.length;i++){iframes[i].style.maxHeight='90px';}
  },500);
  btn.addEventListener('click',function(e){
    e.stopPropagation();e.preventDefault();
    wrap.style.display='none';
    sessionStorage.setItem('pulse_ad_closed','1');
    clearInterval(enforcer);
  });
})();
</script>
<?php endif; ?>
