<?php // 共通ページ末尾: noscript + 固定バナー広告 ?>
<noscript>
<style>canvas{display:none!important}.ui{display:none!important}.floating-nav{display:none!important}body{overflow:auto!important}</style>
<div style="padding:3rem 2rem;max-width:800px;margin:0 auto;text-align:center">
<h1 style="color:#00f5d4;font-family:Orbitron,sans-serif;font-size:1.5rem"><?php echo htmlspecialchars($pageTitle); ?></h1>
<p style="color:#888;font-size:1rem;line-height:1.8;margin:1rem 0"><?php echo htmlspecialchars($pageDesc); ?></p>
<p style="color:#666;font-size:0.85rem">このページはJavaScriptが必要です。<br>JavaScriptを有効にしてご覧ください。</p>
<p style="margin-top:2rem"><a href="index.php" style="color:#00f5d4">← ホームに戻る</a></p>
</div>
</noscript>

<!-- 固定バナー広告（下部） -->
<div id="stickyAd" style="position:fixed;bottom:0;left:0;right:0;z-index:9000;background:#0d0d14;border-top:1px solid rgba(255,255,255,0.08);padding:0.4rem 0;text-align:center;display:none">
<button id="stickyAdClose" style="position:absolute;top:-32px;left:8px;z-index:9999;background:#0d0d14;border:1px solid rgba(255,255,255,0.2);border-bottom:none;color:#aaa;font-size:0.8rem;padding:6px 14px;border-radius:8px 8px 0 0;cursor:pointer;line-height:1;font-family:sans-serif;transition:all 0.2s" aria-label="広告を閉じる">✕ 閉じる</button>
<div style="max-width:728px;margin:0 auto">
<ins class="adsbygoogle" style="display:block;height:90px" data-ad-client="ca-pub-7086371722392050" data-ad-slot="4953305789" data-ad-format="horizontal" data-full-width-responsive="true"></ins>
</div>
</div>
<script>
(function(){
  var wrap = document.getElementById('stickyAd');
  var btn = document.getElementById('stickyAdClose');
  if (sessionStorage.getItem('pulse_ad_closed')) return;
  wrap.style.display = '';
  try { (adsbygoogle = window.adsbygoogle || []).push({}); } catch(e) {}
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    e.preventDefault();
    wrap.style.display = 'none';
    sessionStorage.setItem('pulse_ad_closed', '1');
  });
  btn.addEventListener('mouseenter', function(){ this.style.color = '#00f5d4'; this.style.borderColor = '#00f5d4'; });
  btn.addEventListener('mouseleave', function(){ this.style.color = '#aaa'; this.style.borderColor = 'rgba(255,255,255,0.2)'; });
})();
</script>
