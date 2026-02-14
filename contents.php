<?php
require_once __DIR__ . '/includes/config.php';

// アクセス記録
recordPageView('contents');

// コンテンツ一覧を共通ファイルから読み込み
require_once __DIR__ . '/includes/contents-data.php';

$pageName = 'contents';
$pageTitle = 'Contents - PULSE';
$pageDesc = 'PULSEのコンテンツ一覧と概要。各ページの説明と更新予定。';
$pageCanonical = 'https://pulse.subara3.com/contents.php';

// カテゴリでグループ化
$groupedContents = getContentsByCategory($allContents);

$extraStyle = '
main {
  max-width: 900px;
  margin: 0 auto;
  padding: 3rem 2rem;
}

h1 {
  font-family: \'Orbitron\', sans-serif;
  font-size: 2rem;
  color: var(--accent);
  margin-bottom: 0.5rem;
}

.page-desc {
  color: var(--text-secondary);
  margin-bottom: 3rem;
  line-height: 1.8;
}

.content-count {
  font-size: 0.9rem;
  color: var(--text-secondary);
  margin-bottom: 2rem;
}

.content-count span {
  color: var(--accent);
  font-weight: bold;
}

.content-section {
  margin-bottom: 3rem;
}

.content-section h2 {
  font-family: \'Orbitron\', sans-serif;
  font-size: 1.2rem;
  color: var(--accent);
  margin-bottom: 1.5rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid rgba(0, 245, 212, 0.3);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.content-section h2 .count {
  font-size: 0.8rem;
  color: var(--text-secondary);
  font-weight: normal;
}

.content-card {
  background: var(--bg-card);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1rem;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 1.5rem;
  align-items: start;
  transition: all 0.3s;
  text-decoration: none;
  color: inherit;
  border: 1px solid transparent;
}

.content-card:hover {
  border-color: var(--accent);
  transform: translateX(5px);
}

.content-icon {
  font-size: 1.5rem;
  width: 50px;
  text-align: center;
  color: var(--accent);
  opacity: 0.8;
}

.content-info h3 {
  font-size: 1.1rem;
  margin-bottom: 0.5rem;
  color: var(--text-primary);
}

.content-info p {
  color: var(--text-secondary);
  font-size: 0.9rem;
  line-height: 1.6;
  margin-bottom: 0.5rem;
}

.content-meta {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.75rem;
}

.meta-tag {
  padding: 0.2rem 0.6rem;
  background: rgba(0, 245, 212, 0.1);
  border-radius: 4px;
  color: var(--accent);
}

.content-arrow {
  color: var(--text-secondary);
  font-size: 1.5rem;
  align-self: center;
}

@media (max-width: 600px) {
  .content-card {
    grid-template-columns: auto 1fr;
  }
  .content-arrow { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main>
  <h1>CONTENTS</h1>
  <p class="page-desc">
    PULSEのコンテンツ一覧です。<br>
    このページ自体もコンテンツのひとつ。サイトの地図であり、記録でもあります。
  </p>
  
  <p class="content-count">全 <span><?php echo count($allContents); ?></span> コンテンツ</p>

  <?php foreach ($categoryOrder as $category): ?>
    <?php if (isset($groupedContents[$category])): ?>
    <section class="content-section">
      <h2><?php echo $category; ?> <span class="count">(<?php echo count($groupedContents[$category]); ?>)</span></h2>
      
      <?php foreach ($groupedContents[$category] as $content): ?>
        <?php if ($content['page'] === 'contents') continue; // 自分自身はスキップ ?>
        <a href="<?php echo $content['page']; ?>.php" class="content-card">
          <div class="content-icon"><i class="fa-solid <?php echo $content['icon']; ?>"></i></div>
          <div class="content-info">
            <h3><?php echo $content['title']; ?></h3>
            <p><?php echo $content['desc']; ?></p>
            <?php if (!empty($content['meta'])): ?>
            <div class="content-meta">
              <span class="meta-tag"><?php echo $content['meta']; ?></span>
            </div>
            <?php endif; ?>
          </div>
          <div class="content-arrow"><i class="fa-solid fa-chevron-right"></i></div>
        </a>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  <?php endforeach; ?>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
