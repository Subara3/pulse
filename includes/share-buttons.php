<?php
// シェアボタンコンポーネント
// 使用前に $pageTitle, $pageCanonical を設定しておくこと

$shareText = urlencode($pageTitle);
$shareUrl = urlencode($pageCanonical);
?>
<div class="share-buttons">
  <span class="share-label"><i class="fa-solid fa-share-nodes"></i></span>
  <a href="https://twitter.com/intent/tweet?text=<?php echo $shareText; ?>&url=<?php echo $shareUrl; ?>" 
     target="_blank" rel="noopener" class="share-btn twitter" title="Share on Twitter">
    <i class="fa-brands fa-x-twitter"></i>
  </a>
  <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $shareUrl; ?>" 
     target="_blank" rel="noopener" class="share-btn facebook" title="Share on Facebook">
    <i class="fa-brands fa-facebook-f"></i>
  </a>
  <a href="https://line.me/R/msg/text/?<?php echo $shareText; ?>%20<?php echo $shareUrl; ?>" 
     target="_blank" rel="noopener" class="share-btn line" title="Share on LINE">
    <i class="fa-brands fa-line"></i>
  </a>
  <button class="share-btn copy" onclick="copyToClipboard('<?php echo htmlspecialchars($pageCanonical); ?>')" title="Copy URL">
    <i class="fa-solid fa-link"></i>
  </button>
</div>

<style>
.share-buttons {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  justify-content: center;
  margin: 2rem 0;
}

.share-label {
  font-family: 'Orbitron', sans-serif;
  font-size: 0.75rem;
  color: var(--text-secondary);
  letter-spacing: 0.1em;
}

.share-btn {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  color: var(--text-secondary);
  background: var(--bg-card);
  border: 1px solid rgba(255,255,255,0.1);
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.3s;
}

.share-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.3);
}

.share-btn.twitter:hover {
  background: #000;
  border-color: #000;
  color: #fff;
}

.share-btn.facebook:hover {
  background: #1877f2;
  border-color: #1877f2;
  color: #fff;
}

.share-btn.line:hover {
  background: #00b900;
  border-color: #00b900;
  color: #fff;
}

.share-btn.copy:hover {
  background: var(--accent);
  border-color: var(--accent);
  color: var(--bg-primary);
}

.share-btn.copied {
  background: var(--accent) !important;
  border-color: var(--accent) !important;
  color: var(--bg-primary) !important;
}
</style>

<script>
function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.querySelector('.share-btn.copy');
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    setTimeout(() => {
      btn.classList.remove('copied');
      btn.innerHTML = '<i class="fa-solid fa-link"></i>';
    }, 2000);
  });
}
</script>
