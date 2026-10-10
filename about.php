<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('about');

require_once __DIR__ . '/includes/contents-data.php';

$pageName = 'about';
$workCount = count($works);
$today = date('Y-m-d');

$pageTitle = 'PULSE について | PULSE';
$pageDesc = 'PULSE は、数式どおりに動く絵を' . $workCount . '本集めたサイトです。'
    . '作るときに決めていることと、作品の分け方を説明しています。';
$pageCanonical = 'https://pulse.subara3.com/about.php';

$extraStyle = '
.about-hero { padding-block: clamp(2.8rem, 8vw, 5rem) clamp(1.5rem, 4vw, 2.5rem); }
.about-hero h1 { font-size: clamp(2rem, 6.5vw, 3.4rem); letter-spacing: .03em; }

/* 約束 */
.promises { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); }
.promise-item { background: var(--ink-1); padding: 1.6rem 1.4rem 1.8rem; }
.promise-item .n { font-family: var(--font-mono); font-size: .66rem; letter-spacing: .22em; color: var(--teal); }
.promise-item h3 { margin-block: .7rem .55rem; font-size: 1.08rem; letter-spacing: .02em; }
.promise-item p { font-size: .84rem; color: var(--text-2); line-height: 1.95; }

/* 決めごと */
.rules { display: grid; gap: 0; border-top: 1px solid var(--line); }
.rule-row {
  display: grid; grid-template-columns: 190px 1fr; gap: 1.2rem;
  padding: 1.15rem 0; border-bottom: 1px solid var(--line);
}
@media (max-width: 640px) { .rule-row { grid-template-columns: 1fr; gap: .35rem; } }
.rule-row dt {
  font-family: var(--font-mono); font-size: .7rem; letter-spacing: .14em;
  color: var(--teal); text-transform: uppercase; padding-top: .28rem;
}
.rule-row dd { font-size: .88rem; color: var(--text-2); line-height: 1.95; }
.rule-row dd b { color: var(--text); font-weight: 500; }

/* コレクション早見 */
.coll-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); }
.coll-row {
  background: var(--ink-1); padding: 1rem 1.1rem; text-decoration: none; color: inherit;
  display: grid; gap: .3rem; transition: background var(--dur) var(--ease);
}
.coll-row:hover { background: var(--ink-2); }
.coll-row .en { font-family: var(--font-mono); font-size: .62rem; letter-spacing: .2em; color: var(--teal); }
.coll-row .ja { font-weight: 700; font-family: var(--font-display); font-size: .98rem; }
.coll-row .pr { font-size: .76rem; color: var(--text-3); line-height: 1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main class="paper">

  <div class="wrap wrap--narrow about-hero">
    <h1 style="margin-top:.9rem">PULSE について</h1>
    <p class="lede" style="margin-top:1.2rem;max-width:none">
      PULSE は、水面の波や鳥の群れ、結晶の成長、マンデルブロ集合などを、数式どおりに動かして描いたページの集まりです。
      どのページも開くとすぐに動き出し、クリックやドラッグで動きが変わります。仕組みの説明は書いていません。
      いまは <?php echo $workCount; ?> 本あり、毎日 1 本ずつ追加しています。
    </p>
  </div>

  <!-- ================= 約束 ================= -->
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <h2 class="h-md" style="margin-top:.6rem">どのページにも共通すること</h2>
        </div>
      </div>

      <div class="promises">
        <div class="promise-item">
          <span class="n">01</span>
          <h3>開くとすぐに動き出す</h3>
          <p>読み込みが終わるとすぐに描き始め、何もしなくても動き続けます。</p>
        </div>
        <div class="promise-item">
          <span class="n">02</span>
          <h3>クリックやドラッグで変わる</h3>
          <p>クリックやドラッグをすると、その場所に波が立ったり、粒が集まったりします。スマートフォンでは指でなぞって操作します。点数や正解はありません。</p>
        </div>
        <div class="promise-item">
          <span class="n">03</span>
          <h3>画面に文字を出さない</h3>
          <p>タイトルや数値、操作の説明は画面に出しません。速さなどを変えたいときは、右下のボタンから設定を開いてください。</p>
        </div>
        <div class="promise-item">
          <span class="n">04</span>
          <h3>毎日 1 本ずつ増える</h3>
          <p>2026年1月29日から毎日 1 本ずつ追加しています。追加する前に、すでにあるページと似ていないかを確かめています。</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= 決めごと ================= -->
  <section class="section section--tight">
    <div class="wrap wrap--narrow" style="margin-inline:auto">
      <div class="section-head">
        <div>
          <h2 class="h-md" style="margin-top:.6rem">作るときの決めごと</h2>
          <p class="muted" style="margin-top:.6rem;font-size:.84rem">
            どのページも同じ見た目と操作になるように、作る前に決めていることです。
          </p>
        </div>
      </div>

      <dl class="rules">
        <div class="rule-row">
          <dt>見た目</dt>
          <dd>設計図の青焼きのように、<b>紺色の地に白い線</b>で描いています。色を付けるのはクリックしたところなど一部だけで、その色は水色にそろえています。</dd>
        </div>
        <div class="rule-row">
          <dt>技術</dt>
          <dd><b>Canvas 2D と JavaScript だけ</b>で描いています。外部のライブラリや WebGL は使っていません。1 ページが 1 ファイルで完結します。</dd>
        </div>
        <div class="rule-row">
          <dt>音</dt>
          <dd>音はすべてサイン波を<b>ブラウザ上で合成</b>しています。音声ファイルは使っていません。ページごとに調が決まっていて、クリックしたときの音もその調で鳴ります。最初は鳴らない状態で、左下の音のボタンか M キーで切り替えられます。</dd>
        </div>
        <div class="rule-row">
          <dt>分類</dt>
          <dd>学問の分野ではなく、<b>画面で何が起きるか</b>で 12 のグループに分けています。「流れるもの」「群れるもの」「育つもの」などです。</dd>
        </div>
      </dl>
    </div>
  </section>

  <!-- ================= コレクション ================= -->
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <h2 class="h-md" style="margin-top:.6rem">分け方の一覧</h2>
        </div>
        <a href="contents.php" class="btn btn--ghost">作品をさがす &rarr;</a>
      </div>

      <div class="coll-list">
        <?php foreach ($PULSE_COLLECTIONS as $key => $col):
          $n = count(pulseByCollection($works, $key)); ?>
        <a class="coll-row" href="contents.php?g=<?php echo $key; ?>#<?php echo $key; ?>">
          <span class="en"><i class="fa-solid <?php echo $col['icon']; ?>" aria-hidden="true"></i> <?php echo $n; ?> 本</span>
          <span class="ja"><?php echo $col['name']; ?></span>
          <span class="pr"><?php echo $col['promise']; ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ================= 運営 ================= -->
  <section class="section section--tight">
    <div class="wrap wrap--narrow" style="margin-inline:auto">
      <div class="section-head">
        <div>
          <h2 class="h-md" style="margin-top:.6rem">このサイトについて</h2>
        </div>
      </div>
      <dl class="rules">
        <div class="rule-row"><dt>運営</dt><dd><a href="https://subara3.com/" target="_blank" rel="noopener" style="color:var(--cyan)">栖王ヴァルハラ３丁目</a></dd></div>
        <div class="rule-row"><dt>開始</dt><dd>2026年1月29日</dd></div>
        <div class="rule-row"><dt>いま</dt><dd><?php echo $workCount; ?> 本 ／ 12 のコレクション</dd></div>
        <div class="rule-row"><dt>要望</dt><dd>見たいものがあれば<a href="index.php#feedback" style="color:var(--cyan)">トップページのフォーム</a>からどうぞ。</dd></div>
      </dl>

      <div style="display:flex;gap:.7rem;flex-wrap:wrap;margin-top:2rem">
        <a href="random.php" class="btn btn--primary"><i class="fa-solid fa-shuffle"></i> ランダムに1本ひらく</a>
        <a href="contents.php" class="btn">作品をさがす</a>
      </div>
    </div>
  </section>

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
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
