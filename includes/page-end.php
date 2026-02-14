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
<div id="stickyAd" style="position:fixed;bottom:0;left:0;right:0;z-index:9000;display:none">
  <div style="display:flex;justify-content:flex-start;background:#0a0a0f;padding:2px 8px">
    <button id="stickyAdClose" style="background:none;border:1px solid rgba(255,255,255,0.25);color:#ccc;font-size:0.75rem;padding:3px 12px;border-radius:4px;cursor:pointer;font-family:sans-serif;line-height:1.2">✕ 閉じる</button>
  </div>
  <div style="position:relative;max-height:90px;height:90px;overflow:hidden;background:#0a0a0f;border-top:1px solid rgba(255,255,255,0.08)">
    <ins class="adsbygoogle" style="display:block;width:100%;height:90px;max-height:90px" data-ad-client="ca-pub-7086371722392050" data-ad-slot="4953305789" data-ad-format="horizontal" data-full-width-responsive="false"></ins>
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
})();
</script>
