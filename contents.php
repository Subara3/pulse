<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('contents');

require_once __DIR__ . '/includes/contents-data.php';

$pageName = 'contents';
$workCount = count($works);
$pageTitle = 'ARCHIVE — ' . $workCount . '本の数学・物理ビジュアライゼーション一覧 | PULSE';
$pageDesc = 'ライフゲーム、マンデルブロ集合、ローレンツ方程式、反応拡散、群れ、非周期タイリング——PULSEの'
    . $workCount . '本を12のコレクションに分けて、検索・絞り込みしながら探せる一覧。';
$pageCanonical = 'https://pulse.subara3.com/contents.php';

// コレクションごとに新しい順で並べる
$grouped = [];
foreach (array_keys($PULSE_COLLECTIONS) as $key) {
    $items = pulseByCollection($worksByNew, $key);
    if ($items) $grouped[$key] = $items;
}

$extraStyle = '
.archive-hero { padding-block: clamp(2.5rem, 7vw, 4.5rem) clamp(1.5rem, 4vw, 2.5rem); }
.archive-hero h1 { font-size: clamp(2.1rem, 7vw, 3.6rem); letter-spacing: .04em; }
.archive-count {
  font-family: var(--font-mono); font-size: .8rem; letter-spacing: .14em;
  color: var(--text-3); margin-top: 1rem;
}
.archive-count b { color: var(--teal); font-size: 1.5rem; font-weight: 700; }

/* --- 操作バー: 検索 + コレクション絞り込み --- */
.filterbar {
  position: sticky; top: 53px; z-index: 800;
  background: rgba(10,10,15,.92); backdrop-filter: blur(14px);
  border-block: 1px solid var(--line);
  padding-block: .8rem;
}
.filter-row { display: flex; gap: .75rem; align-items: center; }
.search {
  position: relative; flex: 1 1 260px; max-width: 420px;
}
.search input {
  width: 100%; padding: .62rem .9rem .62rem 2.2rem;
  font-family: var(--font-mono); font-size: .82rem; color: var(--text);
  background: var(--ink-2); border: 1px solid var(--line-mid);
  border-radius: var(--radius);
}
.search input::placeholder { color: var(--text-3); letter-spacing: .04em; }
.search i {
  position: absolute; left: .8rem; top: 50%; transform: translateY(-50%);
  color: var(--text-3); font-size: .82rem; pointer-events: none;
}
.search kbd {
  position: absolute; right: .6rem; top: 50%; transform: translateY(-50%);
  font-family: var(--font-mono); font-size: .62rem; color: var(--text-3);
  border: 1px solid var(--line-mid); border-radius: 3px; padding: .1rem .32rem;
}
/* タッチ端末にキーボードショートカットの案内を出しても意味がない */
@media (pointer: coarse) { .search kbd { display: none; } }
.chips {
  display: flex; gap: .4rem; overflow-x: auto; padding-block: .35rem;
  scrollbar-width: none; margin-top: .6rem;
}
.chips::-webkit-scrollbar { display: none; }
@media (min-width: 900px) { .chips { flex-wrap: wrap; overflow: visible; } }

.no-hit { padding: 4rem 0; text-align: center; color: var(--text-3); }
.no-hit .mono { display: block; margin-bottom: .8rem; color: var(--teal); letter-spacing: .2em; font-size: .72rem; }

/* --- コレクション見出し --- */
.coll-block { padding-block: clamp(2rem, 5vw, 3.2rem); scroll-margin-top: 130px; }
.coll-head {
  display: flex; align-items: baseline; gap: 1rem; flex-wrap: wrap;
  padding-bottom: .9rem; margin-bottom: 1.3rem;
  border-bottom: 1px solid var(--line);
}
.coll-head h2 { font-size: clamp(1.25rem, 3vw, 1.7rem); }
.coll-head .en {
  font-family: var(--font-mono); font-size: .68rem; letter-spacing: .24em;
  color: var(--teal); text-transform: uppercase;
}
.coll-head .promise { color: var(--text-2); font-size: .82rem; flex: 1 1 100%; }
.coll-head .shuffle {
  margin-left: auto; font-family: var(--font-mono); font-size: .68rem;
  letter-spacing: .12em; color: var(--text-3); text-decoration: none;
}
.coll-head .shuffle:hover { color: var(--teal); }

.tile[hidden] { display: none; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main class="paper">
  <div class="wrap archive-hero">
    <p class="eyebrow">ARCHIVE</p>
    <h1 style="margin-top:.9rem">動いている数学、<br>ぜんぶここに。</h1>
    <p class="lede" style="margin-top:1.1rem">
      読むものではなく、触って眺めるものだけを集めています。
      どれも 1 ページ完結・全画面 Canvas・その場で操作できます。
    </p>
    <p class="archive-count"><b><?php echo $workCount; ?></b> WORKS &nbsp;/&nbsp; 12 COLLECTIONS</p>
  </div>

  <div class="filterbar">
    <div class="wrap">
      <div class="filter-row">
        <label class="search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" id="q" placeholder="名前・キーワードで探す（例: 波、フラクタル、ising）"
                 autocomplete="off" aria-label="コンテンツを検索">
          <kbd>/</kbd>
        </label>
        <a href="random.php" class="btn btn--primary" style="flex:none"><i class="fa-solid fa-shuffle"></i> ランダム</a>
      </div>
      <div class="chips" role="group" aria-label="コレクションで絞り込む">
        <button class="chip is-on" data-group="" aria-pressed="true">すべて <span class="n"><?php echo $workCount; ?></span></button>
        <?php foreach ($grouped as $key => $items): ?>
          <button class="chip" data-group="<?php echo $key; ?>" aria-pressed="false">
            <?php echo $PULSE_COLLECTIONS[$key]['name']; ?> <span class="n"><?php echo count($items); ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="wrap">
    <p class="no-hit" id="noHit" hidden>
      <span class="mono">NO MATCH</span>
      その言葉にあたるものはまだありません。別の言い方か、コレクションから探してみてください。
    </p>

    <?php foreach ($grouped as $key => $items):
      $col = $PULSE_COLLECTIONS[$key]; ?>
    <section class="coll-block" id="<?php echo $key; ?>" data-block="<?php echo $key; ?>">
      <div class="coll-head">
        <span class="en"><i class="fa-solid <?php echo $col['icon']; ?>" aria-hidden="true"></i> <?php echo $col['en']; ?></span>
        <h2><?php echo $col['name']; ?></h2>
        <span class="mono dim" style="font-size:.7rem"><?php echo count($items); ?> works</span>
        <a class="shuffle" href="random.php?g=<?php echo $key; ?>">この中から1本 &rarr;</a>
        <p class="promise"><?php echo $col['promise']; ?></p>
      </div>

      <div class="tile-grid">
        <?php foreach ($items as $c):
          $mp4 = pulsePreview($c['page'], 'mp4');
          $webm = pulsePreview($c['page'], 'webm');
          $jpg = pulsePreview($c['page'], 'jpg');
          $needle = mb_strtolower($c['page'] . ' ' . $c['title'] . ' ' . $c['desc'] . ' ' . ($c['meta'] ?? '') . ' ' . $col['name'] . ' ' . $col['en']);
        ?>
        <a class="tile" href="<?php echo $c['page']; ?>.php"
           data-group="<?php echo $key; ?>"
           data-search="<?php echo htmlspecialchars($needle, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="tile-media<?php echo $jpg ? '' : ' tile-media--empty'; ?>">
            <?php if ($jpg): ?>
              <video muted loop playsinline preload="none" poster="<?php echo $jpg; ?>"
                     aria-label="<?php echo htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8'); ?> のプレビュー"
                     <?php if ($webm): ?>data-webm="<?php echo $webm; ?>"<?php endif; ?>
                     <?php if ($mp4): ?>data-mp4="<?php echo $mp4; ?>"<?php endif; ?>></video>
            <?php else: ?>
              <i class="fa-solid <?php echo $c['icon']; ?>" aria-hidden="true"></i>
            <?php endif; ?>
          </div>
          <div class="tile-body">
            <div class="tile-title">
              <?php echo $c['title']; ?>
              <?php if (!empty($c['isNew'])): ?><span class="badge badge--new">NEW</span><?php endif; ?>
            </div>
            <p class="tile-desc"><?php echo $c['desc']; ?></p>
            <div class="tile-foot">
              <span><?php echo $col['en']; ?></span>
              <span><?php echo str_replace('-', '.', $c['added'] ?? ''); ?></span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
  /* ---- プレビュー動画は「見えている間だけ」再生する ------------------ */
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var saveData = navigator.connection && navigator.connection.saveData;
  var autoplay = !reduce && !saveData;

  function attach(v) {
    if (v.dataset.loaded) return;
    v.dataset.loaded = '1';
    if (v.dataset.webm) { var s1 = document.createElement('source'); s1.src = v.dataset.webm; s1.type = 'video/webm'; v.appendChild(s1); }
    if (v.dataset.mp4)  { var s2 = document.createElement('source'); s2.src = v.dataset.mp4;  s2.type = 'video/mp4';  v.appendChild(s2); }
    v.load();
  }

  if (autoplay && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        var v = e.target;
        if (e.isIntersecting) { attach(v); var p = v.play(); if (p && p.catch) p.catch(function () {}); }
        else if (!v.paused) { v.pause(); }
      });
    }, { rootMargin: '160px 0px' });
    document.querySelectorAll('.tile-media video').forEach(function (v) {
      io.observe(v);
      /* IntersectionObserver が発火しない状況（バックグラウンドタブ等）でも
         ホバーすれば必ず動くようにしておく。ポスター画像が常に出ているので
         再生されなくても「真っ黒なタイル」にはならない。 */
      v.addEventListener('mouseenter', function () {
        attach(v); var p = v.play(); if (p && p.catch) p.catch(function () {});
      });
    });
  } else {
    /* 動画を再生しない環境ではポスター画像のまま置く（何も見えない状態は作らない） */
    document.querySelectorAll('.tile-media video').forEach(function (v) {
      v.addEventListener('mouseenter', function () { attach(v); v.play().catch(function(){}); }, { once: true });
    });
  }

  /* ---- 検索とコレクション絞り込み ------------------------------------ */
  var q = document.getElementById('q');
  var chips = Array.prototype.slice.call(document.querySelectorAll('.chip'));
  var tiles = Array.prototype.slice.call(document.querySelectorAll('.tile[data-search]'));
  var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-block]'));
  var noHit = document.getElementById('noHit');
  var group = '';

  function apply() {
    var term = (q.value || '').trim().toLowerCase();
    var hits = 0;
    tiles.forEach(function (t) {
      var ok = (!group || t.dataset.group === group) &&
               (!term || t.dataset.search.indexOf(term) !== -1);
      t.hidden = !ok;
      if (ok) hits++;
    });
    blocks.forEach(function (b) {
      b.hidden = !b.querySelector('.tile:not([hidden])');
    });
    noHit.hidden = hits > 0;

    var url = new URL(location.href);
    if (group) url.searchParams.set('g', group); else url.searchParams.delete('g');
    if (term) url.searchParams.set('q', term); else url.searchParams.delete('q');
    history.replaceState(null, '', url.pathname + url.search);
  }

  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (o) { o.classList.remove('is-on'); o.setAttribute('aria-pressed', 'false'); });
      c.classList.add('is-on'); c.setAttribute('aria-pressed', 'true');
      group = c.dataset.group;
      apply();
      if (group) {
        var b = document.getElementById(group);
        if (b) window.scrollTo({ top: b.offsetTop - 120, behavior: 'smooth' });
      }
    });
  });

  var t = null;
  q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 110); });

  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
    if (e.key === 'Escape' && document.activeElement === q) { q.value = ''; apply(); q.blur(); }
  });

  /* URL からの復元 */
  var params = new URLSearchParams(location.search);
  if (params.get('q')) q.value = params.get('q');
  if (params.get('g')) {
    var c = chips.filter(function (x) { return x.dataset.group === params.get('g'); })[0];
    if (c) { chips.forEach(function (o) { o.classList.remove('is-on'); }); c.classList.add('is-on'); group = c.dataset.group; }
  }
  if (params.get('q') || params.get('g')) apply();
})();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
