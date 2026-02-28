<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

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
  grid-template-columns: 176px auto 1fr auto;
  gap: 1rem;
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


.content-preview {
  width: 176px;
  height: 99px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.12);
  background: linear-gradient(135deg, rgba(0,245,212,0.08), rgba(123,44,191,0.14));
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255,255,255,0.45);
}
.content-preview video,
.content-preview iframe {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border: 0;
  background: #0a0a0f;
}
.content-preview iframe {
  transform: scale(0.58);
  transform-origin: top left;
  width: 172%;
  height: 172%;
  pointer-events: none;
}
.content-preview i { font-size: 1.1rem; }
.content-icon {
  font-size: 1.5rem;
  width: 50px;
  text-align: center;
  color: var(--accent);
  opacity: 0.8;
}

.content-info { min-width: 0; }

.content-info h3 {
  font-size: 1.05rem;
  margin-bottom: 0.45rem;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.content-info p {
  color: var(--text-secondary);
  font-size: 0.88rem;
  line-height: 1.55;
  margin-bottom: 0.5rem;
  overflow-wrap: anywhere;
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

@media (max-width: 900px) {
  .content-card {
    grid-template-columns: 1fr;
  }
  .content-preview {
    width: 100%;
    height: 160px;
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
        <?php $previewPath = 'img/previews/' . $content['page'] . '.mp4'; ?>
        <?php $posterPath = 'img/previews/' . $content['page'] . '.jpg'; ?>
        <?php $previewVer = file_exists(__DIR__ . '/' . $previewPath) ? filemtime(__DIR__ . '/' . $previewPath) : time(); ?>
        <?php $posterVer = file_exists(__DIR__ . '/' . $posterPath) ? filemtime(__DIR__ . '/' . $posterPath) : $previewVer; ?>
        <a href="<?php echo $content['page']; ?>.php" class="content-card">
          <div class="content-preview">
            <?php if (file_exists(__DIR__ . '/' . $previewPath)): ?>
              <video class="js-preview-video" autoplay muted loop playsinline preload="metadata"<?php if (file_exists(__DIR__ . '/' . $posterPath)): ?> poster="<?php echo $posterPath; ?>?v=<?php echo $posterVer; ?>"<?php endif; ?> aria-label="<?php echo htmlspecialchars($content['title'], ENT_QUOTES, 'UTF-8'); ?> preview">
                <source src="<?php echo $previewPath; ?>?v=<?php echo $previewVer; ?>" type="video/mp4">
              </video>
            <?php else: ?>
              <i class="fa-solid <?php echo $content['icon']; ?>"></i>
            <?php endif; ?>
          </div>
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

<script>
(function() {
  const videos = document.querySelectorAll('.js-preview-video');
  videos.forEach((video) => {
    const ensurePlaying = () => {
      if (document.hidden) return;
      const p = video.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    };

    video.addEventListener('pause', () => {
      try { video.currentTime = Math.max(0, video.currentTime - 0.001); } catch (e) {}
      ensurePlaying();
    });
    video.addEventListener('ended', () => {
      try { video.currentTime = 0; } catch (e) {}
      ensurePlaying();
    });

    ensurePlaying();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    videos.forEach((video) => {
      const p = video.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
