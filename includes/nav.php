<?php
/**
 * 共通ヘッダーナビ（文書系ページ用）
 * $pageName で現在地をハイライトする。
 *
 * 方針: アイコンだけのナビは初見に何も伝えないので、必ず語を出す。
 */
$navItems = [
    ['page' => 'index',    'href' => 'index.php',    'label' => 'HOME'],
    ['page' => 'contents', 'href' => 'contents.php', 'label' => 'ARCHIVE'],
    ['page' => 'log',      'href' => 'log.php',      'label' => 'LOG'],
    ['page' => 'about',    'href' => 'about.php',    'label' => 'ABOUT'],
];
?>
<header class="site-head">
  <div class="site-head-in">
    <a href="index.php" class="wordmark"><span class="dot" aria-hidden="true"></span>PULSE</a>
    <nav class="site-nav" aria-label="サイト内ナビゲーション">
      <?php foreach ($navItems as $item): ?>
        <a href="<?php echo $item['href']; ?>"
           class="<?php echo trim(((($pageName ?? '') === $item['page']) ? 'is-current' : '') . ($item['page'] === 'index' ? ' site-nav-home' : '')); ?>"
           <?php echo (($pageName ?? '') === $item['page']) ? 'aria-current="page"' : ''; ?>><?php echo $item['label']; ?></a>
      <?php endforeach; ?>
      <a href="random.php" class="site-nav-shuffle" title="ランダムに1本ひらく"><i class="fa-solid fa-shuffle" aria-hidden="true"></i> <span>SHUFFLE</span></a>
    </nav>
  </div>
</header>
<style>
.site-nav-shuffle { color: var(--teal) !important; }
.site-nav-shuffle:hover { background: var(--teal-soft) !important; }
@media (max-width: 620px) { .site-nav-shuffle span { display: none; } }
/* 狭い画面ではワードマークがホームを兼ねるので HOME を畳む（390px で横にはみ出していた） */
@media (max-width: 460px) { .site-nav .site-nav-home { display: none; } }
</style>
