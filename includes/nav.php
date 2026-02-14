<?php
// ヘッダーナビゲーション
// 主要コンテンツへのリンク
// $pageName で現在地をハイライト

$navItems = [
  ['page' => 'index', 'icon' => 'fa-house'],
  ['page' => 'about', 'icon' => 'fa-info-circle'],
  ['page' => 'contents', 'icon' => 'fa-sitemap'],
];
?>
<header class="main-header">
  <div class="header-inner">
    <a href="index.php" class="logo-link">PULSE</a>
    
    <!-- モバイル用ハンバーガーボタン -->
    <button class="hamburger" id="hamburgerBtn" aria-label="menu">
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
    </button>
    
    <!-- ナビゲーション -->
    <nav class="main-nav" id="mainNav">
      <?php foreach ($navItems as $item): ?>
        <a href="<?php echo $item['page']; ?>.php"
           class="<?php echo ($pageName === $item['page']) ? 'active' : ''; ?>">
          <i class="fa-solid <?php echo $item['icon']; ?>"></i>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<style>
/* ハンバーガーメニュー */
.hamburger {
  display: none;
  flex-direction: column;
  justify-content: center;
  gap: 5px;
  width: 30px;
  height: 30px;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  z-index: 1001;
}

.hamburger-line {
  width: 100%;
  height: 2px;
  background: var(--text-primary);
  transition: all 0.3s ease;
}

.hamburger.active .hamburger-line:nth-child(1) {
  transform: rotate(45deg) translate(5px, 5px);
}

.hamburger.active .hamburger-line:nth-child(2) {
  opacity: 0;
}

.hamburger.active .hamburger-line:nth-child(3) {
  transform: rotate(-45deg) translate(5px, -5px);
}

/* 現在地ハイライト */
.main-nav a.active {
  color: var(--accent) !important;
  position: relative;
}

.main-nav a.active::after {
  content: '';
  position: absolute;
  bottom: -4px;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--accent);
  border-radius: 1px;
}

/* モバイル対応 */
@media (max-width: 768px) {
  .hamburger {
    display: flex;
  }
  
  .main-nav {
    position: fixed;
    top: 0;
    right: -100%;
    width: 70%;
    max-width: 300px;
    height: 100vh;
    background: var(--bg-secondary);
    flex-direction: column;
    padding: 80px 2rem 2rem;
    transition: right 0.3s ease;
    z-index: 1000;
    box-shadow: -5px 0 20px rgba(0, 0, 0, 0.3);
  }
  
  .main-nav.open {
    right: 0;
  }
  
  .main-nav a {
    margin: 0;
    padding: 1rem 0;
    font-size: 1.1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
  }
  
  .main-nav a.active::after {
    display: none;
  }
  
  .main-nav a.active {
    border-left: 3px solid var(--accent);
    padding-left: 1rem;
  }
  
  /* オーバーレイ */
  .nav-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    z-index: 999;
  }
  
  .nav-overlay.open {
    opacity: 1;
    visibility: visible;
  }
}
</style>

<!-- オーバーレイ（モバイル用） -->
<div class="nav-overlay" id="navOverlay"></div>

<script>
(function() {
  const hamburger = document.getElementById('hamburgerBtn');
  const nav = document.getElementById('mainNav');
  const overlay = document.getElementById('navOverlay');
  
  if (hamburger && nav && overlay) {
    hamburger.addEventListener('click', function() {
      hamburger.classList.toggle('active');
      nav.classList.toggle('open');
      overlay.classList.toggle('open');
    });
    
    overlay.addEventListener('click', function() {
      hamburger.classList.remove('active');
      nav.classList.remove('open');
      overlay.classList.remove('open');
    });
  }
})();
</script>
