<?php
/**
 * 共通ヘッダーナビ（文書系ページ用）
 * $pageName で現在地をハイライトする。
 *
 * 方針: アイコンだけのナビは初見に何も伝えないので、必ず語を出す。
 * 2026-10: 英字の大文字ラベルをやめて日本語にした。お気に入りの数は js/pulse-kit.js の保存から出す。
 */
$navItems = [
    ['page' => 'contents', 'href' => 'contents.php', 'label' => 'さがす'],
    ['page' => 'about',    'href' => 'about.php',    'label' => 'PULSE について'],
];
?>
<header class="site-head">
  <div class="site-head-in">
    <a href="index.php" class="wordmark" aria-label="PULSE トップへ"><span class="dot" aria-hidden="true"></span>PULSE</a>
    <nav class="site-nav" aria-label="サイト内ナビゲーション">
      <?php foreach ($navItems as $item): $cur = (($pageName ?? '') === $item['page']); ?>
        <a href="<?php echo $item['href']; ?>" class="<?php echo $cur ? 'is-current' : ''; ?>"
           <?php echo $cur ? 'aria-current="page"' : ''; ?>><?php echo $item['label']; ?></a>
      <?php endforeach; ?>
      <a href="contents.php?fav=1" class="site-nav-fav" id="navFav" hidden aria-label="お気に入り">
        <i class="fa-solid fa-star" aria-hidden="true"></i> <span id="navFavN">0</span>
      </a>
      <a href="random.php" class="site-nav-shuffle" title="ランダムに1本ひらく"><i class="fa-solid fa-shuffle" aria-hidden="true"></i> <span>ランダム</span></a>
    </nav>
  </div>
</header>
<style>
.site-nav-shuffle { color: var(--teal) !important; }
.site-nav-shuffle:hover { background: var(--teal-soft) !important; }
.site-nav-fav { color: var(--text-2) !important; font-family: var(--font-mono) !important; }
.site-nav-fav i { color: var(--teal); font-size: .7rem; }
@media (max-width: 620px) { .site-nav-shuffle span { display: none; } }
@media (max-width: 460px) { .site-nav a[href="about.php"] { display: none; } }
</style>
<script>
(function () {
  function paint() {
    var a = document.getElementById('navFav');
    if (!a || !window.PULSE || !PULSE.fav) return;
    var n = PULSE.fav.list().length;
    a.hidden = n === 0;
    document.getElementById('navFavN').textContent = n;
  }
  document.addEventListener('DOMContentLoaded', paint);
  document.addEventListener('pulse:fav', paint);
})();
</script>
