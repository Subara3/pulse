<?php
/**
 * 共通フッター。
 * アイコンだけの列は「何のリンクか分からない」ので、語とセットで出す。
 */
if (!isset($PULSE_COLLECTIONS)) require_once __DIR__ . '/collections.php';
if (!isset($allContents)) require_once __DIR__ . '/contents-data.php';

$_workCount = count(array_filter(
    $allContents,
    fn($c) => !in_array($c['page'], ['about', 'contents', 'log'], true)
));
$_footCollections = array_slice(array_keys($PULSE_COLLECTIONS), 0, 6);
?>
<footer class="site-foot">
  <div class="wrap">
    <div class="foot-grid">

      <div class="foot-col">
        <a href="index.php" class="wordmark" style="font-size:1.15rem"><span class="dot" aria-hidden="true"></span>PULSE</a>
        <p class="dim" style="margin-top:.9rem;font-size:.8rem;line-height:1.9">
          数式と規則が動いているところだけを集めた、<br>
          <?php echo $_workCount; ?> 本のインタラクティブ・アーカイブ。
        </p>
      </div>

      <div class="foot-col">
        <h4>サイト</h4>
        <ul>
          <li><a href="index.php">ホーム</a></li>
          <li><a href="contents.php">作品をさがす（全<?php echo $_workCount; ?>本）</a></li>
          <li><a href="log.php">日記</a></li>
          <li><a href="about.php">PULSE について</a></li>
          <li><a href="random.php">ランダムに1本</a></li>
        </ul>
      </div>

      <div class="foot-col">
        <h4>コレクション</h4>
        <ul>
          <?php foreach ($_footCollections as $k): ?>
            <li><a href="index.php#<?php echo $k; ?>"><?php echo $PULSE_COLLECTIONS[$k]['name']; ?></a></li>
          <?php endforeach; ?>
          <li><a href="index.php#rooms">12 のコレクションすべて &rarr;</a></li>
        </ul>
      </div>

      <div class="foot-col">
        <h4>ほかのサイト</h4>
        <ul>
          <li><a href="https://subara3.com/" target="_blank" rel="noopener">栖王ヴァルハラ３丁目</a></li>
          <li><a href="https://books.subara3.com/" target="_blank" rel="noopener">Kindleセールの本棚</a></li>
          <li><a href="https://steam.subara3.com/" target="_blank" rel="noopener">Steamの本棚</a></li>
          <li><a href="https://snowdebgm.subara3.com/" target="_blank" rel="noopener">SNOWDE BGM</a></li>
        </ul>
      </div>

    </div>

    <div class="foot-bottom">
      <span>&copy; 2026 PULSE</span>
      <span>ECHO &amp; NULL — 毎日 1 本ずつ増えています</span>
    </div>
  </div>
</footer>
