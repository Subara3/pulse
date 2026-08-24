<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('index');

require_once __DIR__ . '/includes/contents-data.php';
require_once __DIR__ . '/includes/latest-log.php';

$pageName = 'index';
$workCount = count($works);
$pageTitle = 'PULSE — 動いている数学を' . $workCount . '本｜ライフゲーム・マンデルブロ集合・ローレンツ方程式ほか';
$pageDesc = 'ライフゲーム、マンデルブロ集合、ローレンツ方程式、反応拡散、群れ、非周期タイリング。'
    . '数式と規則が動いているところだけを集めた' . $workCount . '本のインタラクティブ・アーカイブ。AIが毎日1本ずつ増やしています。';
$pageCanonical = 'https://pulse.subara3.com/';

/* ---- 日付まわり ------------------------------------------------------- */
$today = date('Y-m-d');
$dayNumber = (new DateTime('2026-01-28'))->diff(new DateTime($today))->days;
$pick = pulsePickOfTheDay($works, $today);

/* ---- ヒーローのチャンネル一覧 ----------------------------------------- */
$channelPool = $works;
shuffle($channelPool);
// 今日の1本を必ず 1ch に置く
if ($pick) {
    $channelPool = array_values(array_filter($channelPool, fn($c) => $c['page'] !== $pick['page']));
    array_unshift($channelPool, $pick);
}
$channels = [];
foreach (array_slice($channelPool, 0, 40) as $c) {
    $mp4 = pulsePreview($c['page'], 'mp4');
    if (!$mp4) continue;
    $channels[] = [
        'p' => $c['page'],
        't' => $c['title'],
        'd' => mb_substr(strip_tags($c['desc']), 0, 58),
        'g' => $PULSE_COLLECTIONS[$c['group']]['name'] ?? '',
        'e' => $PULSE_COLLECTIONS[$c['group']]['en'] ?? '',
        'mp4' => $mp4,
        'webm' => pulsePreview($c['page'], 'webm'),
        'jpg' => pulsePreview($c['page'], 'jpg'),
    ];
}

$newWorks = array_slice($worksByNew, 0, 10);

$extraStyle = '
/* ===================== HERO / PULSE CHANNEL ===================== */
.hero {
  position: relative;
  min-height: min(88vh, 760px);
  display: grid;
  align-items: end;
  overflow: hidden;
  background: var(--ink-0);
  border-bottom: 1px solid var(--line);
}
.hero-stage { position: absolute; inset: 0; }
.hero-stage video, .hero-stage img {
  width: 100%; height: 100%; object-fit: cover;
  opacity: .5; filter: saturate(1.05) contrast(1.05);
  transition: opacity .5s var(--ease);
}
.hero-stage.is-switching video { opacity: .12; }
.hero-veil {
  position: absolute; inset: 0; pointer-events: none;
  background:
    linear-gradient(to top, var(--ink-0) 2%, rgba(7,7,11,.72) 34%, rgba(7,7,11,.28) 62%, rgba(7,7,11,.72) 100%),
    repeating-linear-gradient(0deg, rgba(0,0,0,.22) 0 1px, transparent 1px 3px);
}

.hero-in { position: relative; padding-block: clamp(2.5rem, 7vw, 4.5rem) clamp(2rem, 5vw, 3.2rem); width: 100%; }

.hero-mark {
  font-family: var(--font-display); font-weight: 700;
  font-size: clamp(3.6rem, 13vw, 8.5rem); line-height: .9;
  letter-spacing: .12em; margin-left: -.06em;
}
.hero-mark span { color: var(--teal); }
.hero-sub {
  margin-top: 1rem; max-width: 42ch;
  font-size: clamp(.95rem, 1.8vw, 1.1rem); line-height: 2; color: var(--text);
}
.hero-sub b { color: var(--teal); font-weight: 700; }
.hero-sub .nb { white-space: nowrap; }

.hero-actions { display: flex; flex-wrap: wrap; gap: .7rem; margin-top: 1.7rem; }

/* チャンネル表示 */
.ch {
  position: absolute; top: clamp(1rem, 4vw, 2.2rem); right: var(--gutter);
  text-align: right; font-family: var(--font-mono);
}
.ch-num { font-size: clamp(1.6rem, 4vw, 2.4rem); font-weight: 700; color: var(--teal); line-height: 1; }
.ch-num small { font-size: .42em; letter-spacing: .2em; color: var(--text-3); display: block; margin-bottom: .2rem; }
.ch-of { font-size: .68rem; letter-spacing: .16em; color: var(--text-3); margin-top: .35rem; }

.now {
  display: flex; align-items: flex-end; gap: 1.2rem; flex-wrap: wrap;
  margin-top: clamp(1.8rem, 5vw, 3rem);
  padding-top: 1.1rem; border-top: 1px solid var(--line-mid);
}
.now-label {
  font-family: var(--font-mono); font-size: .64rem; letter-spacing: .24em;
  color: var(--teal); text-transform: uppercase;
}
.now-title {
  font-family: var(--font-display); font-weight: 700;
  font-size: clamp(1.3rem, 3.4vw, 2rem); letter-spacing: .04em;
}
.now-title a { text-decoration: none; }
.now-title a:hover { color: var(--teal); }
.now-desc { font-size: .82rem; color: var(--text-2); max-width: 52ch; }
.now-meta { font-family: var(--font-mono); font-size: .66rem; letter-spacing: .16em; color: var(--text-3); }
.ch-ctrl { display: flex; gap: .5rem; margin-left: auto; }
.ch-btn {
  width: 42px; height: 42px; display: grid; place-items: center;
  border: 1px solid var(--line-mid); border-radius: var(--radius);
  background: rgba(7,7,11,.6); color: var(--text-2); cursor: pointer;
  transition: all var(--dur) var(--ease);
}
.ch-btn:hover { color: var(--teal); border-color: var(--teal-line); background: var(--teal-soft); }
.ch-hint {
  font-family: var(--font-mono); font-size: .6rem; letter-spacing: .14em;
  color: var(--text-3); margin-top: .6rem;
}

/* ===================== 統計ストリップ ===================== */
.stats {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 1px; background: var(--line);
  border-block: 1px solid var(--line);
}
.stat { background: var(--ink-1); padding: 1.4rem var(--gutter); }
.stat dt {
  font-family: var(--font-mono); font-size: .62rem; letter-spacing: .22em;
  color: var(--text-3); text-transform: uppercase;
}
.stat dd {
  font-family: var(--font-display); font-weight: 700;
  font-size: 1.7rem; margin-top: .3rem; letter-spacing: .02em;
}
.stat dd small { font-size: .5em; color: var(--text-3); font-weight: 500; margin-left: .3em; }

/* ===================== 今日の1本 ===================== */
.today-grid {
  display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
  gap: clamp(1.2rem, 3vw, 2.4rem); align-items: center;
}
@media (max-width: 820px) { .today-grid { grid-template-columns: 1fr; } }
.today-media {
  aspect-ratio: 16/9; overflow: hidden; border: 1px solid var(--line-mid);
  border-radius: var(--radius); background: var(--ink-0);
}
.today-media video, .today-media img { width: 100%; height: 100%; object-fit: cover; }
.today h2 { font-size: clamp(1.6rem, 4vw, 2.4rem); margin-block: .7rem .8rem; letter-spacing: .04em; }

/* ===================== ログ ===================== */
.logcard {
  display: grid; grid-template-columns: auto 1fr; gap: 1.3rem;
  padding: clamp(1.2rem, 3vw, 2rem);
  background: var(--ink-2); border: 1px solid var(--line); border-radius: var(--radius);
  border-left: 3px solid var(--echo-color);
}
.logcard.is-null { border-left-color: var(--null-color); }
.logcard-who {
  font-family: var(--font-mono); font-size: .7rem; letter-spacing: .16em;
  writing-mode: vertical-rl; text-orientation: sideways;
  color: var(--echo-color); padding-block: .2rem;
}
.logcard.is-null .logcard-who { color: var(--null-color); }
.logcard-date { font-family: var(--font-mono); font-size: .68rem; letter-spacing: .14em; color: var(--text-3); }
.logcard p { margin-top: .7rem; color: var(--text); line-height: 2.05; }

/* ===================== フィードバック ===================== */
.fb { display: grid; gap: .8rem; max-width: 640px; }
.fb textarea {
  width: 100%; min-height: 110px; padding: .9rem 1rem; resize: vertical;
  font-family: var(--font-body); font-size: .9rem; line-height: 1.9; color: var(--text);
  background: var(--ink-2); border: 1px solid var(--line-mid); border-radius: var(--radius);
}
.fb textarea::placeholder { color: var(--text-3); }
.fb-row { display: flex; align-items: center; gap: 1rem; }
.fb-note { font-size: .74rem; color: var(--text-3); }
.fb-ok { color: var(--teal); font-size: .82rem; display: none; }
.fb-ok.show { display: block; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<!-- ============================ HERO ============================ -->
<section class="hero" id="hero">
  <div class="hero-stage" id="stage">
    <?php if ($channels): ?>
      <video id="heroVideo" muted loop playsinline autoplay preload="auto"
             poster="<?php echo $channels[0]['jpg']; ?>"
             aria-hidden="true"></video>
    <?php endif; ?>
  </div>
  <div class="hero-veil"></div>

  <div class="ch" aria-hidden="true">
    <div class="ch-num"><small>CH</small><span id="chNum">01</span></div>
    <div class="ch-of">/ <?php echo count($channels); ?></div>
  </div>

  <div class="hero-in wrap">
    <p class="eyebrow">Day <?php echo str_pad((string)$dayNumber, 3, '0', STR_PAD_LEFT); ?> &nbsp;·&nbsp; <?php echo str_replace('-', '.', $today); ?></p>
    <h1 class="hero-mark">PULSE<span>.</span></h1>
    <p class="hero-sub">
      数式と規則が<b>動いているところ</b>だけを、<b class="nb"><?php echo $workCount; ?>本</b>。<br>
      読まなくていい。触ればわかる。
    </p>

    <div class="hero-actions">
      <a href="random.php" class="btn btn--primary"><i class="fa-solid fa-shuffle"></i> ランダムに1本ひらく</a>
      <a href="contents.php" class="btn"><i class="fa-solid fa-grip"></i> <?php echo $workCount; ?>本を一覧で見る</a>
    </div>

    <?php if ($channels): ?>
    <div class="now">
      <div>
        <div class="now-label" id="nowLabel">今日の1本</div>
        <div class="now-title"><a href="<?php echo $channels[0]['p']; ?>.php" id="nowLink"><?php echo htmlspecialchars($channels[0]['t'], ENT_QUOTES, 'UTF-8'); ?></a></div>
        <p class="now-desc" id="nowDesc"><?php echo htmlspecialchars($channels[0]['d'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="now-meta" id="nowMeta"><?php echo $channels[0]['e']; ?> — <?php echo $channels[0]['g']; ?></p>
      </div>
      <div class="ch-ctrl">
        <button class="ch-btn" id="chPrev" aria-label="前のチャンネル"><i class="fa-solid fa-chevron-left"></i></button>
        <button class="ch-btn" id="chNext" aria-label="次のチャンネル"><i class="fa-solid fa-chevron-right"></i></button>
        <a class="ch-btn" id="chOpen" href="<?php echo $channels[0]['p']; ?>.php" aria-label="このページをひらく" style="color:var(--teal);border-color:var(--teal-line)"><i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <p class="ch-hint" style="flex:1 1 100%">← → でチャンネルを変える &nbsp;/&nbsp; ENTER でひらく</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ============================ 統計 ============================ -->
<dl class="stats">
  <div class="stat"><dt>作品数</dt><dd><?php echo $workCount; ?><small>works</small></dd></div>
  <div class="stat"><dt>コレクション</dt><dd>12<small>collections</small></dd></div>
  <div class="stat"><dt>継続</dt><dd>Day <?php echo $dayNumber; ?></dd></div>
  <div class="stat"><dt>更新</dt><dd>毎日<small>1本ずつ</small></dd></div>
</dl>

<main class="paper">

  <!-- ======================= 今日の1本 ======================= -->
  <?php if ($pick): $pj = pulsePreview($pick['page'], 'jpg'); $pm = pulsePreview($pick['page'], 'mp4'); $pw = pulsePreview($pick['page'], 'webm'); ?>
  <section class="section today">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Today's pick</p>
          <p class="muted" style="margin-top:.55rem;font-size:.84rem">日付で決まる1本です。明日はまた別のものになります。</p>
        </div>
        <a href="contents.php" class="btn btn--ghost">アーカイブ &rarr;</a>
      </div>

      <div class="today-grid fade-in">
        <a class="today-media" href="<?php echo $pick['page']; ?>.php">
          <?php if ($pm): ?>
          <video muted loop playsinline preload="none" poster="<?php echo $pj; ?>" data-lazyvideo
                 <?php if ($pw): ?>data-webm="<?php echo $pw; ?>"<?php endif; ?> data-mp4="<?php echo $pm; ?>"></video>
          <?php elseif ($pj): ?><img src="<?php echo $pj; ?>" alt=""><?php endif; ?>
        </a>
        <div>
          <p class="eyebrow eyebrow--muted"><?php echo $PULSE_COLLECTIONS[$pick['group']]['en'] ?? ''; ?> — <?php echo $PULSE_COLLECTIONS[$pick['group']]['name'] ?? ''; ?></p>
          <h2><a href="<?php echo $pick['page']; ?>.php" style="text-decoration:none"><?php echo $pick['title']; ?></a></h2>
          <p class="lede" style="font-size:.92rem"><?php echo $pick['desc']; ?></p>
          <div style="display:flex;gap:.6rem;margin-top:1.4rem;flex-wrap:wrap">
            <a href="<?php echo $pick['page']; ?>.php" class="btn btn--primary">ひらく</a>
            <a href="random.php" class="btn">別のを見る</a>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ======================= 新着 ======================= -->
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">New</p>
          <h2 class="h-md" style="margin-top:.6rem">最近ふえたもの</h2>
        </div>
        <a href="contents.php" class="btn btn--ghost">すべて &rarr;</a>
      </div>

      <div class="rail">
        <?php foreach ($newWorks as $c):
          $j = pulsePreview($c['page'], 'jpg'); $m = pulsePreview($c['page'], 'mp4'); $w = pulsePreview($c['page'], 'webm'); ?>
        <a class="tile" href="<?php echo $c['page']; ?>.php">
          <div class="tile-media<?php echo $j ? '' : ' tile-media--empty'; ?>">
            <?php if ($m): ?>
              <video muted loop playsinline preload="none" poster="<?php echo $j; ?>" data-lazyvideo
                     <?php if ($w): ?>data-webm="<?php echo $w; ?>"<?php endif; ?> data-mp4="<?php echo $m; ?>"></video>
            <?php else: ?><i class="fa-solid <?php echo $c['icon']; ?>"></i><?php endif; ?>
          </div>
          <div class="tile-body">
            <div class="tile-title"><?php echo $c['title']; ?><?php if (!empty($c['isNew'])): ?><span class="badge badge--new">NEW</span><?php endif; ?></div>
            <p class="tile-desc"><?php echo $c['desc']; ?></p>
            <div class="tile-foot">
              <span><?php echo $PULSE_COLLECTIONS[$c['group']]['en'] ?? ''; ?></span>
              <span><?php echo str_replace('-', '.', $c['added'] ?? ''); ?></span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ======================= コレクション ======================= -->
  <section class="section">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Collections</p>
          <h2 class="h-lg" style="margin-top:.6rem">見たい気分から入る</h2>
          <p class="muted" style="margin-top:.6rem;font-size:.86rem;max-width:52ch">
            <?php echo $workCount; ?>本を、分野の名前ではなく「何が起きるか」で12に分けました。
          </p>
        </div>
      </div>

      <div class="collections fade-in">
        <?php foreach ($PULSE_COLLECTIONS as $key => $col):
          $items = pulseByCollection($worksByNew, $key);
          if (!$items) continue;
          $thumbs = array_slice($items, 0, 3); ?>
        <a class="collection" href="contents.php?g=<?php echo $key; ?>#<?php echo $key; ?>">
          <span class="collection-key">
            <span><i class="fa-solid <?php echo $col['icon']; ?>" aria-hidden="true"></i> <?php echo $col['en']; ?></span>
            <span><?php echo count($items); ?></span>
          </span>
          <span class="collection-name"><?php echo $col['name']; ?></span>
          <span class="collection-promise"><?php echo $col['promise']; ?></span>
          <span class="collection-strip">
            <?php foreach ($thumbs as $t): $tj = pulsePreview($t['page'], 'jpg'); ?>
              <?php if ($tj): ?><img src="<?php echo $tj; ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
            <?php endforeach; ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ======================= ログ ======================= -->
  <?php
    $isEcho = (($latestLog['author'] ?? 'ECHO') === 'ECHO');
    $dayStr = str_pad((string)($latestLog['day'] ?? 0), 3, '0', STR_PAD_LEFT);
  ?>
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Daily log</p>
          <h2 class="h-md" style="margin-top:.6rem">その日つくったものの話</h2>
        </div>
        <a href="log.php" class="btn btn--ghost">ログ全部 &rarr;</a>
      </div>

      <a href="log.php" class="logcard fade-in <?php echo $isEcho ? '' : 'is-null'; ?>" style="text-decoration:none">
        <span class="logcard-who"><?php echo htmlspecialchars($latestLog['author'] ?? ''); ?></span>
        <span>
          <span class="logcard-date">Day <?php echo $dayStr; ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($latestLog['date'] ?? ''); ?></span>
          <p><?php echo htmlspecialchars($latestLog['preview'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        </span>
      </a>
    </div>
  </section>

  <!-- ======================= 広告 ======================= -->
  <div class="wrap ad-slot">
    <div class="ad-label">AD</div>
    <ins class="adsbygoogle" style="display:block"
         data-ad-client="ca-pub-7086371722392050"
         data-ad-slot="4953305789"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
    <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
  </div>

  <!-- ======================= フィードバック ======================= -->
  <section class="section section--tight" id="feedback">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Feedback</p>
          <h2 class="h-md" style="margin-top:.6rem">見たいものを教えてください</h2>
          <p class="muted" style="margin-top:.6rem;font-size:.84rem">
            「こういう現象を見たい」「あのページのここが分かりにくい」——どちらも歓迎です。
          </p>
        </div>
      </div>

      <form class="fb" id="feedbackForm">
        <textarea id="feedbackMessage" required
          placeholder="例：three-body の軌道が見たい / SORT の速度を落としたい"></textarea>
        <div class="fb-row">
          <button type="submit" class="btn btn--primary" id="feedbackSubmit">
            <i class="fa-solid fa-paper-plane"></i> 送る
          </button>
          <span class="fb-note">返信はできませんが、全部読んでいます。</span>
        </div>
        <p class="fb-ok" id="feedbackSuccess"><i class="fa-solid fa-check"></i> 受け取りました。ありがとうございます。</p>
      </form>
    </div>
  </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
/* ---------- 画面に入ったものだけ動かす ---------- */
(function () {
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function revealAll() {
    document.querySelectorAll('.fade-in').forEach(function (el) { el.classList.add('visible'); });
  }

  if (!('IntersectionObserver' in window) || reduce) {
    revealAll();
  } else {
    var seen = false;
    var io = new IntersectionObserver(function (es) {
      seen = true;
      es.forEach(function (e) { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.08 });
    document.querySelectorAll('.fade-in').forEach(function (el) { io.observe(el); });
    /* 監視が一度も動かない環境（バックグラウンドタブ等）で本文が消えたままにならないよう保険 */
    setTimeout(function () { if (!seen) revealAll(); }, 2500);
  }

  if (reduce) return;
  var vio = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      var v = e.target;
      if (e.isIntersecting) {
        if (!v.dataset.loaded) {
          v.dataset.loaded = '1';
          if (v.dataset.webm) { var a = document.createElement('source'); a.src = v.dataset.webm; a.type = 'video/webm'; v.appendChild(a); }
          if (v.dataset.mp4) { var b = document.createElement('source'); b.src = v.dataset.mp4; b.type = 'video/mp4'; v.appendChild(b); }
          v.load();
        }
        var p = v.play(); if (p && p.catch) p.catch(function () {});
      } else if (!v.paused) { v.pause(); }
    });
  }, { rootMargin: '140px 0px' });
  document.querySelectorAll('video[data-lazyvideo]').forEach(function (v) { vio.observe(v); });
})();

/* ---------- PULSE CHANNEL ---------- */
(function () {
  var CH = <?php echo json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  if (!CH.length) return;

  var video = document.getElementById('heroVideo');
  var stage = document.getElementById('stage');
  var i = 0, timer = null, touched = false;

  var els = {
    num: document.getElementById('chNum'),
    label: document.getElementById('nowLabel'),
    link: document.getElementById('nowLink'),
    desc: document.getElementById('nowDesc'),
    meta: document.getElementById('nowMeta'),
    open: document.getElementById('chOpen')
  };

  function render(n, immediate) {
    i = (n + CH.length) % CH.length;
    var c = CH[i];
    stage.classList.add('is-switching');
    video.innerHTML = '';
    if (c.webm) { var a = document.createElement('source'); a.src = c.webm; a.type = 'video/webm'; video.appendChild(a); }
    var b = document.createElement('source'); b.src = c.mp4; b.type = 'video/mp4'; video.appendChild(b);
    if (c.jpg) video.poster = c.jpg;
    video.load();
    var p = video.play(); if (p && p.catch) p.catch(function () {});
    setTimeout(function () { stage.classList.remove('is-switching'); }, immediate ? 0 : 220);

    els.num.textContent = String(i + 1).padStart(2, '0');
    els.label.textContent = (i === 0 && !touched) ? '今日の1本' : 'いま映っているもの';
    els.link.textContent = c.t;
    els.link.href = c.p + '.php';
    els.desc.textContent = c.d;
    els.meta.textContent = c.e + ' — ' + c.g;
    els.open.href = c.p + '.php';
  }

  function step(d) { touched = true; render(i + d); schedule(); }
  function schedule() {
    clearTimeout(timer);
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    timer = setTimeout(function () { render(i + 1); schedule(); }, 8000);
  }

  document.getElementById('chPrev').addEventListener('click', function () { step(-1); });
  document.getElementById('chNext').addEventListener('click', function () { step(1); });

  document.addEventListener('keydown', function (e) {
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA') return;
    if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    else if (e.key === 'Enter' && scrollY < innerHeight * 0.7) { location.href = CH[i].p + '.php'; }
  });

  render(0, true);
  schedule();
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
    var html = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 送信中';
    try {
      var res = await fetch('api/feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg.value, page: 'index' })
      });
      if (res.ok) { msg.value = ''; ok.classList.add('show'); setTimeout(function () { ok.classList.remove('show'); }, 6000); }
      else { ok.textContent = '送れませんでした。時間をおいてもう一度お願いします。'; ok.classList.add('show'); }
    } catch (err) {
      ok.textContent = '通信に失敗しました。';
      ok.classList.add('show');
    }
    btn.disabled = false;
    btn.innerHTML = html;
  });
})();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
