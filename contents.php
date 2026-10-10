<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('contents');

require_once __DIR__ . '/includes/contents-data.php';
require_once __DIR__ . '/includes/work-tile.php';

$pageName = 'contents';
$workCount = count($works);
$pageTitle = '作品をさがす — 数式で動く' . $workCount . '本の一覧 | PULSE';
$pageDesc = 'PULSEの全' . $workCount . '本の一覧です。12のグループに分けてあり、名前やキーワードでも探せます。';
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
  font-size: .84rem; letter-spacing: .06em;
  color: var(--text-3); margin-top: 1rem;
}
.archive-count b { color: var(--teal); font-size: 1.5rem; font-weight: 700; }

/* --- 操作バー: 検索 + コレクション絞り込み --- */
.filterbar {
  position: sticky; top: 53px; z-index: 800;
  background: rgba(13, 34, 56,.92); backdrop-filter: blur(14px);
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

/* --- コレクション見出し --- */
.coll-block { padding-block: clamp(2rem, 5vw, 3.2rem); scroll-margin-top: 130px; }
.coll-head {
  display: flex; align-items: baseline; gap: 1rem; flex-wrap: wrap;
  padding-bottom: .9rem; margin-bottom: 1.3rem;
  border-bottom: 1px solid var(--line);
}
.coll-head h2 { font-size: clamp(1.25rem, 3vw, 1.7rem); }
.coll-head .promise { color: var(--text-2); font-size: .82rem; flex: 1 1 100%; }
.coll-head .shuffle {
  margin-left: auto; font-size: .76rem; color: var(--text-3); text-decoration: none;
}
.coll-head .shuffle:hover { color: var(--teal); }

.toggles { display: flex; gap: .4rem; flex: none; }
.toggles .chip i { font-size: .62rem; color: var(--teal); }
.toggles .chip.is-on i { color: var(--ink-0); }
.coll-block[hidden] { display: none; }
@media (max-width: 620px) { .filter-row { flex-wrap: wrap; } .search { max-width: none; } }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main class="paper">
  <div class="wrap archive-hero">
    <h1>作品一覧</h1>
    <p class="archive-count"><b><?php echo $workCount; ?></b> 本 / 12 のコレクション</p>
  </div>

  <div class="filterbar">
    <div class="wrap">
      <div class="filter-row">
        <label class="search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input type="search" id="q" placeholder="名前・キーワードで探す（例: 波、群れ、フラクタル）"
                 autocomplete="off" aria-label="作品を検索">
          <kbd>/</kbd>
        </label>
        <div class="toggles" role="group" aria-label="しるしで絞り込む">
          <button class="chip" type="button" id="fFav" aria-pressed="false"><i class="fa-solid fa-star" aria-hidden="true"></i> お気に入り <span class="n" id="fFavN">0</span></button>
        </div>
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
      <span id="noHitMsg">その言葉にあたるものはまだありません。別の言い方か、コレクションから探してみてください。</span>
    </p>

    <?php foreach ($grouped as $key => $items):
      $col = $PULSE_COLLECTIONS[$key]; ?>
    <section class="coll-block" id="<?php echo $key; ?>" data-block="<?php echo $key; ?>">
      <div class="coll-head">
        <h2><?php echo $col['name']; ?></h2>
        <p class="promise"><?php echo $col['promise']; ?></p>
        <a class="shuffle" href="random.php?g=<?php echo $key; ?>"><i class="fa-solid fa-shuffle" aria-hidden="true"></i> この中から1本</a>
      </div>
      <div class="work-grid">
        <?php foreach ($items as $c):
          $needle = mb_strtolower($c['page'] . ' ' . $c['title'] . ' ' . $c['desc'] . ' ' . ($c['meta'] ?? '') . ' ' . ' ' . $col['name'] . ' ' . $col['en']);
          echo pulseWorkTile($c, ['sub' => false, 'search' => $needle]);
        endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
  /* ---- 検索・コレクション・しるし（お気に入り / 挑戦）で絞り込む ---- */
  var q = document.getElementById('q');
  var chips = Array.prototype.slice.call(document.querySelectorAll('.chips .chip'));
  var tiles = Array.prototype.slice.call(document.querySelectorAll('.work[data-search]'));
  var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-block]'));
  var noHit = document.getElementById('noHit');
  var noHitMsg = document.getElementById('noHitMsg');
  var fFav = document.getElementById('fFav');
  var group = '', onlyFav = false;
  var MSG_WORD = noHitMsg.textContent;

  function favs() { return (window.PULSE && PULSE.fav) ? PULSE.fav.list() : []; }

  function apply(keepUrl) {
    var term = (q.value || '').trim().toLowerCase();
    var fl = favs(), hits = 0;
    tiles.forEach(function (t) {
      var ok = (!group || t.dataset.group === group) &&
               (!term || t.dataset.search.indexOf(term) !== -1) &&
               (!onlyFav || fl.indexOf(t.dataset.page) !== -1);
      t.hidden = !ok;
      if (ok) hits++;
    });
    blocks.forEach(function (b) { b.hidden = !b.querySelector('.work:not([hidden])'); });
    noHit.hidden = hits > 0;
    noHitMsg.textContent = (onlyFav && !fl.length)
      ? 'まだお気に入りがありません。'
      : MSG_WORD;
    if (keepUrl) return;
    var url = new URL(location.href);
    [['g', group], ['q', term], ['fav', onlyFav ? '1' : '']].forEach(function (kv) {
      if (kv[1]) url.searchParams.set(kv[0], kv[1]); else url.searchParams.delete(kv[0]);
    });
    history.replaceState(null, '', url.pathname + url.search);
  }

  function press(btn, on) { btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('is-on', on); }

  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (o) { press(o, false); });
      press(c, true);
      group = c.dataset.group;
      apply();
      if (group) {
        var b = document.getElementById(group);
        if (b) window.scrollTo({ top: b.offsetTop - 140, behavior: 'smooth' });
      }
    });
  });
  fFav.addEventListener('click', function () { onlyFav = !onlyFav; press(fFav, onlyFav); apply(); });

  function paintFavCount() { document.getElementById('fFavN').textContent = favs().length; }
  document.addEventListener('pulse:fav', function () { paintFavCount(); if (onlyFav) apply(true); });

  var t = null;
  q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 110); });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
    if (e.key === 'Escape' && document.activeElement === q) { q.value = ''; apply(); q.blur(); }
  });

  /* URL からの復元。お気に入りは保存を読むので、pulse-kit.js の後で当てる */
  document.addEventListener('DOMContentLoaded', function () {
    paintFavCount();
    var params = new URLSearchParams(location.search);
    if (params.get('q')) q.value = params.get('q');
    if (params.get('g')) {
      var c = chips.filter(function (x) { return x.dataset.group === params.get('g'); })[0];
      if (c) { chips.forEach(function (o) { press(o, false); }); press(c, true); group = c.dataset.group; }
    }
    if (params.get('fav') === '1') { onlyFav = true; press(fFav, true); }
    if (params.toString()) apply(true);
  });
})();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
