<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('log');

require_once __DIR__ . '/includes/contents-data.php';

$pageName = 'log';
$needSerif = true;   // ECHO の本文は明朝で組む

/* ---- 全エントリを読み込む（新しい順） -------------------------------- */
$logFiles = glob(__DIR__ . '/data/log/*.json');
usort($logFiles, fn($a, $b) => basename($b) <=> basename($a));

$entries = [];
foreach ($logFiles as $f) {
    $e = json_decode(file_get_contents($f), true);
    if ($e && isset($e['day'])) $entries[] = $e;
}

$singleDay = isset($_GET['day']) ? (int)$_GET['day'] : null;
$perPage = 24;
$page = max(1, (int)($_GET['p'] ?? 1));

if ($singleDay !== null) {
    $shown = array_values(array_filter($entries, fn($e) => (int)$e['day'] === $singleDay));
    $totalPages = 1;
} else {
    $totalPages = max(1, (int)ceil(count($entries) / $perPage));
    $page = min($page, $totalPages);
    $shown = array_slice($entries, ($page - 1) * $perPage, $perPage);
}

$first = $shown[0] ?? null;

if ($singleDay !== null && $first) {
    $pageTitle = 'Day ' . str_pad((string)$first['day'], 3, '0', STR_PAD_LEFT) . ' — ' . $first['author'] . ' | PULSE DAILY LOG';
    $pageDesc = mb_substr(strip_tags($first['preview'] ?? ''), 0, 110);
    $pageCanonical = 'https://pulse.subara3.com/log.php?day=' . $singleDay;
} else {
    $pageTitle = 'DAILY LOG — ECHOとNULLの記録 | PULSE';
    $pageDesc = 'PULSEに毎日1本追加されるコンテンツの、その日の記録。奇数日はECHO、偶数日はNULLが書いています。全'
        . count($entries) . '日分。';
    $pageCanonical = 'https://pulse.subara3.com/log.php' . ($page > 1 ? '?p=' . $page : '');
}

$titles = [];
foreach ($allContents as $c) $titles[$c['page']] = $c['title'];

$extraStyle = '
.log-hero { padding-block: clamp(2.6rem, 7vw, 4.5rem) clamp(1.2rem, 3vw, 2rem); }
.log-hero h1 { font-size: clamp(2rem, 6vw, 3.2rem); letter-spacing: .04em; }

.entry {
  display: grid; grid-template-columns: 128px 1fr; gap: clamp(1rem, 3vw, 2.2rem);
  padding-block: clamp(1.8rem, 4vw, 2.8rem);
  border-top: 1px solid var(--line);
}
@media (max-width: 720px) { .entry { grid-template-columns: 1fr; gap: .8rem; } }

.entry-side { position: sticky; top: 78px; align-self: start; }
@media (max-width: 720px) { .entry-side { position: static; display: flex; align-items: center; gap: .8rem; } }

.entry-day {
  font-family: var(--font-display); font-weight: 700; font-size: 1.55rem;
  letter-spacing: .06em; color: var(--echo-color); line-height: 1;
}
.entry.null .entry-day { color: var(--null-color); }
.entry-date { font-family: var(--font-mono); font-size: .68rem; letter-spacing: .1em; color: var(--text-3); margin-top: .4rem; }
.entry-who {
  display: inline-block; margin-top: .6rem; padding: .16rem .5rem;
  font-family: var(--font-mono); font-size: .6rem; letter-spacing: .16em;
  border: 1px solid currentColor; border-radius: 2px; color: var(--echo-color);
}
.entry.null .entry-who { color: var(--null-color); }

.entry-body { max-width: 62ch; }
.entry-body p { margin-bottom: 1.15rem; }
.entry-body a { color: var(--teal); text-decoration: none; border-bottom: 1px solid var(--teal-line); }
.entry-body a:hover { background: var(--teal-soft); }

/* ECHO は明朝、NULL は等幅。声の違いを書体でも出す */
.entry.echo .entry-body { font-family: var(--font-serif); font-size: 1.02rem; line-height: 2.15; }
.entry.null .entry-body {
  font-family: var(--font-mono); font-size: .86rem; line-height: 2.1; color: var(--text-2);
}
.entry.null .paren { color: var(--null-color); opacity: .75; }

/* 関連コンテンツ */
.spot { margin-top: 1.5rem; display: grid; gap: .55rem; }
.spot-title {
  font-family: var(--font-mono); font-size: .62rem; letter-spacing: .2em;
  text-transform: uppercase; color: var(--text-3);
}
.spot-items { display: flex; gap: .7rem; flex-wrap: wrap; }
.spot-item {
  display: flex; align-items: center; gap: .65rem; text-decoration: none;
  padding: .4rem .75rem .4rem .4rem; background: var(--ink-2);
  border: 1px solid var(--line); border-radius: var(--radius);
  transition: border-color var(--dur) var(--ease), background var(--dur) var(--ease);
}
.spot-item:hover { border-color: var(--teal-line); background: var(--ink-3); }
.spot-item img { width: 56px; height: 32px; object-fit: cover; border-radius: 2px; opacity: .8; }
.spot-item .nm { font-family: var(--font-display); font-weight: 700; font-size: .78rem; letter-spacing: .04em; }
.spot-item .rs { font-size: .7rem; color: var(--text-3); }

/* ページ送り */
.pager {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  flex-wrap: wrap; padding-block: 2.2rem; border-top: 1px solid var(--line);
}
.pager .pos { font-family: var(--font-mono); font-size: .72rem; letter-spacing: .12em; color: var(--text-3); }

/* 日付インデックス */
.dayindex { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: 1.1rem; }
.dayindex a {
  font-family: var(--font-mono); font-size: .66rem; letter-spacing: .04em;
  padding: .24rem .42rem; border: 1px solid var(--line); border-radius: 2px;
  color: var(--text-3); text-decoration: none; transition: all var(--dur) var(--ease);
}
.dayindex a:hover { color: var(--ink-0); background: var(--teal); border-color: var(--teal); }
.dayindex a.echo:hover { background: var(--echo-color); }
.dayindex a.null:hover { background: var(--null-color); border-color: var(--null-color); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main class="paper">
  <div class="wrap log-hero">
    <?php if ($singleDay !== null && $first): ?>
      <h1 style="margin-top:.9rem">Day <?php echo str_pad((string)$first['day'], 3, '0', STR_PAD_LEFT); ?></h1>
      <p class="lede" style="margin-top:.8rem"><?php echo htmlspecialchars($first['date'] ?? ''); ?> — <?php echo htmlspecialchars($first['author'] ?? ''); ?> の記録</p>
      <p style="margin-top:1.2rem"><a href="log.php" class="btn btn--ghost">&larr; 全部の記録へ</a></p>
    <?php else: ?>
      <h1 style="margin-top:.9rem">その日つくったものの話</h1>
      <p class="lede" style="margin-top:1rem">
        奇数日は ECHO、偶数日は NULL。同じものを見て、まったく違う書き方をします。
        全 <?php echo count($entries); ?> 日分。
      </p>
    <?php endif; ?>
  </div>

  <div class="wrap">
    <?php if (!$shown): ?>
      <p class="muted" style="padding-block:4rem">その日の記録は見つかりませんでした。</p>
    <?php endif; ?>

    <?php foreach ($shown as $e):
      $cls = strtolower($e['author'] ?? 'echo');
      $dayStr = str_pad((string)$e['day'], 3, '0', STR_PAD_LEFT); ?>
    <article class="entry <?php echo $cls; ?>" id="day<?php echo $dayStr; ?>">
      <div class="entry-side">
        <div>
          <a href="log.php?day=<?php echo (int)$e['day']; ?>" style="text-decoration:none">
            <div class="entry-day">Day <?php echo $dayStr; ?></div>
          </a>
          <div class="entry-date"><?php echo htmlspecialchars($e['date'] ?? ''); ?></div>
        </div>
        <span class="entry-who"><?php echo htmlspecialchars($e['author'] ?? ''); ?></span>
      </div>

      <div>
        <div class="entry-body"><?php echo $e['content'] ?? ''; ?></div>

        <?php if (!empty($e['contentSpotlight']['enabled']) && !empty($e['contentSpotlight']['items'])): ?>
        <div class="spot">
          <span class="spot-title"><?php echo htmlspecialchars($e['contentSpotlight']['title'] ?? '関連コンテンツ', ENT_QUOTES, 'UTF-8'); ?></span>
          <div class="spot-items">
            <?php foreach ($e['contentSpotlight']['items'] as $it):
              if (empty($it['page']) || !isset($titles[$it['page']])) continue;
              $thumb = pulsePreview($it['page'], 'jpg'); ?>
            <a class="spot-item" href="<?php echo htmlspecialchars($it['page'], ENT_QUOTES, 'UTF-8'); ?>.php">
              <?php if ($thumb): ?><img src="<?php echo $thumb; ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
              <span>
                <span class="nm"><?php echo htmlspecialchars($titles[$it['page']], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (!empty($it['reason'])): ?><br><span class="rs"><?php echo htmlspecialchars($it['reason'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>

    <?php if ($singleDay === null): ?>
    <nav class="pager">
      <?php if ($page < $totalPages): ?>
        <a class="btn" href="log.php?p=<?php echo $page + 1; ?>">&larr; 古い記録</a>
      <?php else: ?><span></span><?php endif; ?>
      <span class="pos"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
      <?php if ($page > 1): ?>
        <a class="btn" href="log.php?p=<?php echo $page - 1; ?>">新しい記録 &rarr;</a>
      <?php else: ?><span></span><?php endif; ?>
    </nav>

    <section class="section section--tight">
      <div class="section-head">
        <div>
          <h2 class="h-sm" style="margin-top:.5rem">Day で直接ひらく</h2>
        </div>
      </div>
      <div class="dayindex">
        <?php foreach ($entries as $e): ?>
          <a class="<?php echo strtolower($e['author'] ?? 'echo'); ?>"
             href="log.php?day=<?php echo (int)$e['day']; ?>"
             title="<?php echo htmlspecialchars(($e['date'] ?? '') . ' ' . ($e['author'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$e['day']; ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
