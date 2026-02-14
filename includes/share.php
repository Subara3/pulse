<?php
// SNSシェアボタン（フローティング）
// 必須変数: $pageTitle, $pageCanonical

$_shareText = rawurlencode($pageTitle);
$_shareUrl = rawurlencode($pageCanonical);
$_shareCanonicalEsc = htmlspecialchars($pageCanonical, ENT_QUOTES, 'UTF-8');
?>
<div class="share-buttons" id="shareButtons">
  <a href="https://twitter.com/intent/tweet?text=<?php echo $_shareText; ?>&url=<?php echo $_shareUrl; ?>"
     target="_blank" rel="noopener noreferrer" class="share-btn" title="X(Twitter)">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
    </svg>
  </a>
  <button class="share-btn" id="shareUrlCopy" title="URLをコピー">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" id="shareUrlCopyIcon">
      <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
    </svg>
  </button>
</div>

<style>
.share-buttons {
  position: fixed;
  bottom: 5rem;
  right: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  z-index: 150;
}
.share-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.5);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  transition: all 0.3s;
  text-decoration: none;
  padding: 0;
  line-height: 1;
}
.share-btn:hover {
  background: rgba(0,245,212,0.15);
  border-color: #00f5d4;
  color: #00f5d4;
}
.share-btn.copied {
  background: rgba(0,245,212,0.25);
  border-color: #00f5d4;
  color: #00f5d4;
}
@media (max-width: 768px) {
  .share-buttons {
    bottom: 5rem;
    right: 0.5rem;
  }
  .share-btn {
    width: 32px;
    height: 32px;
  }
  .share-btn svg {
    width: 14px;
    height: 14px;
  }
}
</style>

<script>
(function() {
  var btn = document.getElementById('shareUrlCopy');
  if (!btn) return;
  var url = <?php echo json_encode($pageCanonical); ?>;
  var iconEl = document.getElementById('shareUrlCopyIcon');
  var origHTML = iconEl.outerHTML;
  var checkSVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

  btn.addEventListener('click', function() {
    navigator.clipboard.writeText(url).then(function() {
      btn.classList.add('copied');
      btn.innerHTML = checkSVG;
      setTimeout(function() {
        btn.classList.remove('copied');
        btn.innerHTML = origHTML;
      }, 1500);
    });
  });
})();
</script>
