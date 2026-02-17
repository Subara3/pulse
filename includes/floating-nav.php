<?php
// フローティングナビゲーション（眺める系ページ用）
// 戻る + ランダム移動 + 簡易メニュー

// 公開コンテンツ一覧をマスターデータから取得
require_once __DIR__ . '/contents-data.php';

// ページ名だけを抽出（about, contentsは除外）
$navigableContents = array_filter($allContents, fn($c) => !in_array($c['page'], ['about', 'contents']));
$navigablePages = array_map(fn($c) => $c['page'], $navigableContents);

// 現在のページを除外
$otherContents = array_filter($navigablePages, fn($c) => $c !== $pageName);
$otherContents = array_values($otherContents);
?>

<div class="floating-nav" id="floatingNav">
  <!-- 戻るボタン -->
  <a href="index.php" class="float-btn float-back">
    <i class="fa-solid fa-house"></i>
  </a>
  
  <!-- ランダム移動ボタン -->
  <button class="float-btn float-random" id="randomBtn">
    <i class="fa-solid fa-shuffle"></i>
  </button>
  
  <!-- メニュー展開ボタン -->
  <button class="float-btn float-menu" id="floatMenuBtn">
    <i class="fa-solid fa-ellipsis"></i>
  </button>
  
  <?php if (!empty($showLangToggle)): ?>
  <!-- 言語切り替え（テキストコンテンツがあるページのみ） -->
  <button class="float-btn float-lang" id="langToggle" title="Switch language">
    <span class="lang-icon" data-lang-ja>EN</span>
    <span class="lang-icon" data-lang-en>JA</span>
  </button>
  <?php endif; ?>
  
  <!-- 展開メニュー -->
  <div class="float-submenu" id="floatSubmenu">
    <a href="about.php"><i class="fa-solid fa-info-circle"></i></a>
    <a href="contents.php"><i class="fa-solid fa-sitemap"></i></a>
    <a href="log.php"><i class="fa-solid fa-book"></i></a>
  </div>
</div>

<style>
.floating-nav {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  display: flex;
  flex-direction: column-reverse;
  gap: 0.8rem;
  z-index: 1000;
}

.float-btn {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: var(--bg-card);
  border: 1px solid rgba(255,255,255,0.1);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  cursor: pointer;
  transition: all 0.3s ease;
  text-decoration: none;
  box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}

.float-btn:hover {
  background: var(--accent);
  color: var(--bg-primary);
  transform: scale(1.1);
  border-color: var(--accent);
}

.float-back {
  background: rgba(0, 245, 212, 0.1);
  border-color: rgba(0, 245, 212, 0.3);
  color: var(--accent);
}

.float-random {
  background: rgba(123, 44, 191, 0.1);
  border-color: rgba(123, 44, 191, 0.3);
  color: var(--null-color);
}

.float-random:hover {
  background: var(--null-color);
}

.float-lang {
  font-family: 'Orbitron', sans-serif;
  font-size: 0.75rem;
  font-weight: 700;
}

.lang-icon { display: none; }
[data-current-lang="ja"] .lang-icon[data-lang-ja] { display: inline; }
[data-current-lang="en"] .lang-icon[data-lang-en] { display: inline; }

/* 言語によるテキスト切り替え */
[data-lang-ja], [data-lang-en] { display: none !important; }
[data-current-lang="ja"] [data-lang-ja] { display: inline !important; }
[data-current-lang="en"] [data-lang-en] { display: inline !important; }
[data-lang-ja-block], [data-lang-en-block] { display: none !important; }
[data-current-lang="ja"] [data-lang-ja-block] { display: block !important; }
[data-current-lang="en"] [data-lang-en-block] { display: block !important; }

/* サブメニュー */
.float-submenu {
  position: absolute;
  bottom: 0;
  left: 60px;
  background: var(--bg-card);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 12px;
  padding: 0.5rem;
  display: flex;
  flex-direction: row;
  gap: 0.5rem;
  opacity: 0;
  visibility: hidden;
  transform: translateX(-10px);
  transition: all 0.3s ease;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.float-submenu.open {
  opacity: 1;
  visibility: visible;
  transform: translateX(0);
}

.float-submenu a {
  padding: 0.6rem 1rem;
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 0.9rem;
  border-radius: 8px;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.float-submenu a:hover {
  background: rgba(0, 245, 212, 0.1);
  color: var(--accent);
}

/* モバイル調整 */
@media (max-width: 768px) {
  .floating-nav {
    bottom: 1.5rem;
    left: 1rem;
  }
  
  .float-btn {
    width: 45px;
    height: 45px;
    font-size: 1rem;
  }
}
</style>

<script>
(function() {
  // ランダム移動
  const contents = <?php echo json_encode($otherContents); ?>;
  const randomBtn = document.getElementById('randomBtn');
  
  if (randomBtn && contents.length > 0) {
    randomBtn.addEventListener('click', function() {
      const randomIndex = Math.floor(Math.random() * contents.length);
      const randomPage = contents[randomIndex];
      window.location.href = randomPage + '.php';
    });
  }
  
  // サブメニュー開閉
  const menuBtn = document.getElementById('floatMenuBtn');
  const submenu = document.getElementById('floatSubmenu');
  
  if (menuBtn && submenu) {
    menuBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      submenu.classList.toggle('open');
    });
    
    document.addEventListener('click', function() {
      submenu.classList.remove('open');
    });
  }
  
  // ESCキーで戻る
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      window.location.href = 'index.php';
    }
  });
})();
</script>
