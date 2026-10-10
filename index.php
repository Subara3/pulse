<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('index');

require_once __DIR__ . '/includes/contents-data.php';
require_once __DIR__ . '/includes/work-tile.php';

$pageName = 'index';
$workCount = count($works);
$pageTitle = 'PULSE — 数式で動く絵' . $workCount . '本｜水面・群れ・フラクタル・反応拡散ほか';
$pageDesc = '雨の水面、鳥の群れ、マンデルブロ集合、反応拡散、ペンローズタイル。'
    . '数式どおりに動く絵を' . $workCount . '本集めました。クリックやドラッグで動きが変わります。毎日1本ずつ追加しています。';
$pageCanonical = 'https://pulse.subara3.com/';

/* ---- 日付まわり ------------------------------------------------------- */
$today = date('Y-m-d');
$pick = pulsePickOfTheDay($works, $today);

/* 一覧用の短い説明。途中で切るときは切ったことが分かるように省略記号を付ける */
function pulseClip(string $s, int $n): string {
    $s = trim($s);
    return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
}

/* ---- 入口で映すもの。1 本目は今日の1本、あとは毎回まぜる ------------- */
$channelPool = $works;
shuffle($channelPool);
if ($pick) {
    $channelPool = array_values(array_filter($channelPool, fn($c) => $c['page'] !== $pick['page']));
    array_unshift($channelPool, $pick);
}
$channels = [];
foreach (array_slice($channelPool, 0, 24) as $c) {
    $mp4 = pulsePreview($c['page'], 'mp4');
    if (!$mp4) continue;
    $channels[] = [
        'p' => $c['page'],
        't' => $c['title'],
        'd' => pulseClip(strip_tags($c['desc']), 64),
        'g' => $PULSE_COLLECTIONS[$c['group']]['name'] ?? '',
        'mp4' => $mp4,
        'jpg' => pulsePreview($c['page'], 'jpg'),
    ];
}

/* ---- コレクションごとの並び。大きく見せる 1 本は日付で入れ替わる -------- */
$rooms = [];
foreach ($PULSE_COLLECTIONS as $key => $col) {
    $items = pulseByCollection($worksByNew, $key);
    if (!$items) continue;
    $fi = crc32($today . $key) % count($items);
    $feature = $items[$fi];
    array_splice($items, $fi, 1);
    $rooms[$key] = ['col' => $col, 'feature' => $feature, 'rest' => $items];
}

$extraStyle = '
/* ===================== 入口 ===================== */
.stage {
  position: relative;
  height: calc(100svh - 53px);
  min-height: 520px;
  max-height: 980px;
  overflow: hidden;
  background: var(--ink-0);
}
.stage video {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
  transition: opacity .6s var(--ease);
}
.stage.is-switching video { opacity: 0; }
.stage-veil {
  position: absolute; inset: 0; pointer-events: none;
  background:
    linear-gradient(to top, rgba(8, 22, 38,.96) 0%, rgba(8, 22, 38,.55) 26%, rgba(8, 22, 38,0) 52%),
    linear-gradient(to right, rgba(8, 22, 38,.55) 0%, rgba(8, 22, 38,0) 45%);
}
.stage-in {
  position: absolute; inset: auto 0 0 0;
  display: grid; grid-template-columns: minmax(0, 1fr) auto;
  align-items: end; gap: 2rem;
  padding: 0 var(--gutter) clamp(1.6rem, 4.5vw, 3rem);
  max-width: calc(var(--maxw) + 2 * var(--gutter)); margin: 0 auto;
}
.stage-when { font-size: .78rem; color: var(--text-2); letter-spacing: .06em; }
.stage-when .mono { color: var(--text-3); margin-left: .5rem; }
.stage-title {
  margin-top: .5rem;
  font-family: var(--font-display); font-weight: 700;
  font-size: clamp(2.4rem, 7.5vw, 5.4rem); line-height: 1; letter-spacing: .04em;
}
.stage-title a { text-decoration: none; }
.stage-title a:hover { color: var(--teal); }
.stage-desc { margin-top: 1rem; max-width: 40ch; font-size: .92rem; line-height: 1.95; color: var(--text); }
.stage-actions { display: flex; align-items: center; flex-wrap: wrap; gap: .6rem; margin-top: 1.5rem; }
.stage-btn {
  width: 44px; height: 44px; display: grid; place-items: center;
  border: 1px solid var(--line-mid); border-radius: 50%;
  background: rgba(8, 22, 38,.55); color: var(--text-2); cursor: pointer;
  transition: all var(--dur) var(--ease);
}
.stage-btn:hover { color: var(--teal); border-color: var(--teal-line); }
.stage-count { font-size: .7rem; letter-spacing: .12em; color: var(--text-3); margin-left: .4rem; }

.stage-intro { text-align: right; max-width: 30em; }
.stage-intro h1 {
  font-family: var(--font-body); font-weight: 500;
  font-size: clamp(.95rem, 1.5vw, 1.08rem); line-height: 2; letter-spacing: .06em; color: var(--text);
}
.stage-intro h1 b { color: var(--teal); font-weight: 700; }
.stage-intro p { font-size: .8rem; color: var(--text-3); margin-top: .3rem; }
.stage-down {
  display: inline-flex; align-items: center; gap: .5rem; margin-top: 1rem;
  font-size: .78rem; color: var(--text-2); text-decoration: none;
}
.stage-down:hover { color: var(--teal); }

@media (max-width: 760px) {
  .stage { height: calc(100svh - 53px); }
  .stage-in { grid-template-columns: 1fr; gap: 1.4rem; }
  .stage-intro { text-align: left; order: -1; }
  .stage-intro p, .stage-down { display: none; }
  .stage-veil { background: linear-gradient(to top, rgba(8, 22, 38,.97) 0%, rgba(8, 22, 38,.6) 40%, rgba(8, 22, 38,.1) 70%); }
}

/* ===================== 目次 ===================== */
.toc {
  display: flex; flex-wrap: wrap; gap: .3rem 1.4rem;
  padding-block: 1.4rem; border-bottom: 1px solid var(--line);
}
.toc a { font-size: .8rem; color: var(--text-3); text-decoration: none; transition: color var(--dur) var(--ease); }
.toc a:hover { color: var(--teal); }
.toc .mono { font-size: .64rem; margin-right: .35rem; }

/* ===================== お気に入り ===================== */
.fav-room { padding-block: clamp(2.5rem, 6vw, 4rem) 0; }
.fav-head { display: flex; align-items: baseline; gap: 1rem; margin-bottom: 1.4rem; }
.fav-head h2 { font-family: var(--font-body); font-size: 1.15rem; letter-spacing: .06em; }
.fav-head h2 i { color: var(--teal); font-size: .85rem; margin-right: .4rem; }
.fav-head a { margin-left: auto; font-size: .78rem; color: var(--text-3); text-decoration: none; }
.fav-head a:hover { color: var(--teal); }

/* ===================== 見たいもの ===================== */
.after {
  max-width: 640px;
  padding-block: clamp(3rem, 7vw, 5rem);
  border-top: 1px solid var(--line);
}
.after h2 { font-family: var(--font-body); font-size: 1.05rem; letter-spacing: .06em; }
.after-sub { font-size: .8rem; color: var(--text-3); margin-top: .4rem; }

.fb { display: grid; gap: .8rem; margin-top: 1.3rem; }
.fb textarea {
  width: 100%; min-height: 96px; padding: .85rem 1rem; resize: vertical;
  font-family: var(--font-body); font-size: .88rem; line-height: 1.9; color: var(--text);
  background: var(--ink-1); border: 1px solid var(--line-mid); border-radius: var(--radius);
}
.fb textarea::placeholder { color: var(--text-3); }
.fb-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.fb-note { font-size: .74rem; color: var(--text-3); }
.fb-ok { color: var(--teal); font-size: .82rem; display: none; }
.fb-ok.show { display: block; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<!-- ============================ 入口 ============================ -->
<section class="stage" id="stage" aria-label="今日の1本">
  <?php if ($channels): ?>
  <video id="heroVideo" muted loop playsinline autoplay preload="auto"
         poster="<?php echo $channels[0]['jpg']; ?>" aria-hidden="true">
    <source src="<?php echo $channels[0]['mp4']; ?>" type="video/mp4">
  </video>
  <?php endif; ?>
  <div class="stage-veil"></div>

  <div class="stage-in">
    <?php if ($channels): ?>
    <div>
      <p class="stage-when"><span id="nowLabel">今日の1本</span><span class="mono" id="nowMeta"><?php echo htmlspecialchars($channels[0]['g'], ENT_QUOTES, 'UTF-8'); ?></span></p>
      <h2 class="stage-title"><a href="<?php echo $channels[0]['p']; ?>.php" id="nowLink"><?php echo htmlspecialchars($channels[0]['t'], ENT_QUOTES, 'UTF-8'); ?></a></h2>
      <p class="stage-desc" id="nowDesc"><?php echo htmlspecialchars($channels[0]['d'], ENT_QUOTES, 'UTF-8'); ?></p>
      <div class="stage-actions">
        <a class="btn btn--primary" id="chOpen" href="<?php echo $channels[0]['p']; ?>.php">ひらく <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        <button class="stage-btn" id="chPrev" type="button" aria-label="前の作品を映す"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
        <button class="stage-btn" id="chNext" type="button" aria-label="次の作品を映す"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
        <span class="stage-count mono" aria-hidden="true"><span id="chNum">01</span> / <?php echo count($channels); ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div class="stage-intro">
      <h1>数式で動く絵を<b><?php echo $workCount; ?>本</b>置いています。</h1>
      <p>クリックやドラッグで動きが変わります。</p>
      <a class="stage-down" href="#rooms"><?php echo count($rooms); ?>のコレクションを見る <i class="fa-solid fa-arrow-down" aria-hidden="true"></i></a>
    </div>
  </div>
</section>

<main class="paper">
  <div class="wrap">

    <nav class="toc" aria-label="コレクション">
      <?php $n = 0; foreach ($rooms as $key => $r): $n++; ?>
        <a href="#<?php echo $key; ?>"><span class="mono"><?php echo str_pad((string)$n, 2, '0', STR_PAD_LEFT); ?></span><?php echo $r['col']['name']; ?></a>
      <?php endforeach; ?>
    </nav>

    <section class="fav-room" id="favRoom" hidden aria-labelledby="favTitle">
      <div class="fav-head">
        <h2 id="favTitle"><i class="fa-solid fa-star" aria-hidden="true"></i>お気に入り</h2>
        <a href="contents.php?fav=1">一覧で見る &rarr;</a>
      </div>
      <div class="work-grid" id="favGrid"></div>
    </section>

    <div id="rooms">
      <?php $n = 0; foreach ($rooms as $key => $r): $n++; $col = $r['col']; ?>
      <section class="room" id="<?php echo $key; ?>" aria-labelledby="room-<?php echo $key; ?>">
        <div class="room-head">
          <p class="room-no"><b><?php echo str_pad((string)$n, 2, '0', STR_PAD_LEFT); ?></b> / <?php echo str_pad((string)count($rooms), 2, '0', STR_PAD_LEFT); ?></p>
          <h2 class="room-name" id="room-<?php echo $key; ?>"><?php echo $col['name']; ?></h2>
          <p class="room-promise"><?php echo $col['promise']; ?></p>
          <p class="room-links">
            <a href="random.php?g=<?php echo $key; ?>"><i class="fa-solid fa-shuffle" aria-hidden="true"></i> この中から1本</a>
          </p>
        </div>
        <div class="room-grid">
          <?php echo pulseWorkTile($r['feature'], ['big' => true, 'autoplay' => true]); ?>
          <?php foreach ($r['rest'] as $c) echo pulseWorkTile($c, ['sub' => false]); ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>

    <section class="after">
      <div id="feedback">
        <h2>見たいものを教えてください</h2>
        <p class="after-sub">「こういう現象を見たい」「あのページのここが分かりにくい」、どちらも歓迎です。</p>
        <form class="fb" id="feedbackForm">
          <textarea id="feedbackMessage" required aria-label="見たいもの・気になったこと"
            placeholder="例：オーロラのような動きが見たい / WAVES の雨をもっと静かにしたい"></textarea>
          <div class="fb-row">
            <button type="submit" class="btn btn--primary" id="feedbackSubmit">送る</button>
            <span class="fb-note">返信はできませんが、全部読んでいます。</span>
          </div>
          <p class="fb-ok" id="feedbackSuccess">受け取りました。ありがとうございます。</p>
        </form>
      </div>
    </section>

  </div>

  <div class="wrap ad-slot">
    <div class="ad-label">AD</div>
    <ins class="adsbygoogle" style="display:block"
         data-ad-client="ca-pub-7086371722392050"
         data-ad-slot="4953305789"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
    <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
/* ---------- 入口：今日の1本から始めて、ほかの作品も映せる ---------- */
(function () {
  var CH = <?php echo json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  if (!CH.length) return;
  var video = document.getElementById('heroVideo');
  var stage = document.getElementById('stage');
  var i = 0, timer = null, touched = false;
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (id) { return document.getElementById(id); };

  function render(n) {
    i = (n + CH.length) % CH.length;
    var c = CH[i];
    stage.classList.add('is-switching');
    setTimeout(function () {
      video.innerHTML = '';
      var s = document.createElement('source'); s.src = c.mp4; s.type = 'video/mp4'; video.appendChild(s);
      if (c.jpg) video.poster = c.jpg;
      video.load();
      var p = video.play(); if (p && p.catch) p.catch(function () {});
      stage.classList.remove('is-switching');
    }, 260);
    $('chNum').textContent = String(i + 1).padStart(2, '0');
    $('nowLabel').textContent = (i === 0 && !touched) ? '今日の1本' : 'いま映っているもの';
    $('nowMeta').textContent = c.g;
    $('nowLink').textContent = c.t;
    $('nowLink').href = $('chOpen').href = c.p + '.php';
    $('nowDesc').textContent = c.d;
  }
  function schedule() {
    clearTimeout(timer);
    if (reduce) return;
    timer = setTimeout(function () { render(i + 1); schedule(); }, 12000);
  }
  function step(d) { touched = true; render(i + d); schedule(); }
  $('chPrev').addEventListener('click', function () { step(-1); });
  $('chNext').addEventListener('click', function () { step(1); });
  document.addEventListener('keydown', function (e) {
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || scrollY > innerHeight * 0.6) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    else if (e.key === 'Enter') { location.href = CH[i].p + '.php'; }
  });
  schedule();
})();

/* ---------- お気に入り：コレクションの中のタイルを写して並べる ---------- */
(function () {
  var room = document.getElementById('favRoom');
  var grid = document.getElementById('favGrid');
  function render() {
    if (!window.PULSE || !PULSE.fav) return;
    var list = PULSE.fav.list();
    grid.innerHTML = '';
    list.forEach(function (p) {
      var src = document.querySelector('#rooms .work[data-page="' + p + '"]');
      if (!src) return;
      var t = src.cloneNode(true);
      t.classList.remove('work--big');
      delete t.dataset.bound;
      var v = t.querySelector('video');
      if (v) { v.removeAttribute('data-autoplay'); delete v.dataset.loaded; v.innerHTML = ''; }
      t.querySelectorAll('.work-desc').forEach(function (x) { x.remove(); });
      grid.appendChild(t);
    });
    room.hidden = grid.children.length === 0;
    PULSE.tiles(grid);
  }
  document.addEventListener('DOMContentLoaded', render);
  document.addEventListener('pulse:fav', render);
})();

/* ---------- フィードバック ---------- */
(function () {
  var form = document.getElementById('feedbackForm');
  if (!form) return;
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var msg = document.getElementById('feedbackMessage');
    var btn = document.getElementById('feedbackSubmit');
    var ok = document.getElementById('feedbackSuccess');
    btn.disabled = true;
    btn.textContent = '送信中';
    try {
      var res = await fetch('api/feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg.value, page: 'index' })
      });
      if (res.ok) { msg.value = ''; ok.textContent = '受け取りました。ありがとうございます。'; }
      else { ok.textContent = '送れませんでした。時間をおいてもう一度お願いします。'; }
    } catch (err) {
      ok.textContent = '通信に失敗しました。';
    }
    ok.classList.add('show');
    setTimeout(function () { ok.classList.remove('show'); }, 6000);
    btn.disabled = false;
    btn.textContent = '送る';
  });
})();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
