<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('about');

require_once __DIR__ . '/includes/contents-data.php';

$pageName = 'about';
$workCount = count($works);
$today = date('Y-m-d');
$dayNumber = (new DateTime('2026-01-28'))->diff(new DateTime($today))->days;

$pageTitle = 'ABOUT — PULSEとは | PULSE';
$pageDesc = 'PULSEは、数式と規則が動いているところだけを集めた' . $workCount . '本のインタラクティブ・アーカイブです。'
    . '何を約束していて、どんな決めごとで作っているか。ECHOとNULLの紹介。';
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

/* キャラクター */
.chars { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.2rem; }
.char {
  padding: 1.6rem 1.5rem 1.8rem; background: var(--ink-2);
  border: 1px solid var(--line); border-radius: var(--radius);
  border-top: 3px solid var(--echo-color);
}
.char.null { border-top-color: var(--null-color); }
.char-tag {
  font-family: var(--font-mono); font-size: .62rem; letter-spacing: .2em;
  color: var(--text-3); text-transform: uppercase;
}
.char h3 {
  font-size: 1.5rem; letter-spacing: .16em; margin-block: .5rem .3rem;
  color: var(--echo-color);
}
.char.null h3 { color: var(--null-color); }
.char .role { font-size: .8rem; color: var(--text-2); }
.char ul { list-style: none; margin-top: 1.1rem; display: grid; gap: .5rem; }
.char li { font-size: .82rem; color: var(--text-2); padding-left: 1.1rem; position: relative; line-height: 1.85; }
.char li::before { content: "—"; position: absolute; left: 0; color: var(--text-3); }

/* サンプル会話 */
.talk { display: grid; gap: .8rem; margin-top: 1.4rem; }
.say { display: grid; grid-template-columns: 62px 1fr; gap: .9rem; align-items: start; }
.say .who {
  font-family: var(--font-mono); font-size: .62rem; letter-spacing: .12em;
  padding: .3rem 0; color: var(--echo-color);
}
.say.null .who { color: var(--null-color); }
.say .txt {
  background: var(--ink-3); border: 1px solid var(--line); border-radius: var(--radius);
  padding: .8rem 1rem; font-size: .86rem; line-height: 1.95;
}
.say.null .txt { font-family: var(--font-mono); font-size: .8rem; color: var(--text-2); }
.say.null .paren { color: var(--null-color); }

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
    <p class="eyebrow">About</p>
    <h1 style="margin-top:.9rem">読ませないサイトを<br>作っています。</h1>
    <p class="lede" style="margin-top:1.2rem;max-width:none">
      PULSE は、数式や規則が<b style="color:var(--teal);font-weight:500">動いているところ</b>だけを集めたアーカイブです。
      解説文を読み込まなくても、触って、止めて、また動かせば、何が起きているかは伝わる——
      そういうものだけを <?php echo $workCount; ?> 本、Day <?php echo $dayNumber; ?> まで毎日 1 本ずつ増やしてきました。
    </p>
  </div>

  <!-- ================= 約束 ================= -->
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Promise</p>
          <h2 class="h-md" style="margin-top:.6rem">このサイトが約束すること</h2>
        </div>
      </div>

      <div class="promises">
        <div class="promise-item">
          <span class="n">01</span>
          <h3>必ず触れる</h3>
          <p>全ページがクリック・ドラッグ・スライダーのいずれかで反応します。眺めるだけで終わるページは置きません。</p>
        </div>
        <div class="promise-item">
          <span class="n">02</span>
          <h3>開いた瞬間に動いている</h3>
          <p>ロード直後に何も表示されない画面を作らない、というのが決めごとです。まず動かして、それから触ってもらいます。</p>
        </div>
        <div class="promise-item">
          <span class="n">03</span>
          <h3>同じものを二度置かない</h3>
          <p>既存<?php echo $workCount; ?>本と概念が重なる案は採用しません。重なっていたものは統合し、片方を消しました。</p>
        </div>
        <div class="promise-item">
          <span class="n">04</span>
          <h3>毎日 1 本増える</h3>
          <p>Day 001（2026年1月29日）から途切れず追加しています。奇数日は ECHO、偶数日は NULL が記録を残します。</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= 決めごと ================= -->
  <section class="section section--tight">
    <div class="wrap wrap--narrow" style="margin-inline:auto">
      <div class="section-head">
        <div>
          <p class="eyebrow">House rules</p>
          <h2 class="h-md" style="margin-top:.6rem">全ページ共通の決めごと</h2>
          <p class="muted" style="margin-top:.6rem;font-size:.84rem">
            見た目がばらけないように、作る前から決めてあることです。
          </p>
        </div>
      </div>

      <dl class="rules">
        <div class="rule-row">
          <dt>技術</dt>
          <dd><b>Canvas 2D と素の JavaScript だけ。</b>外部ライブラリも WebGL も使いません。1 ページ 1 ファイルで完結します。</dd>
        </div>
        <div class="rule-row">
          <dt>配色</dt>
          <dd>背景は <b>#0a0a0f</b>、主役は <b>teal（#00f5d4）</b>、対比に <b>purple</b>。赤・橙・黄・黄緑は使いません。どのページから来ても同じ光り方をします。</dd>
        </div>
        <div class="rule-row">
          <dt>操作</dt>
          <dd>操作方法は画面のすみに <b>CLICK / DRAG / RIGHT</b> の短いチップで示します。長い説明文は置きません。</dd>
        </div>
        <div class="rule-row">
          <dt>音</dt>
          <dd>効果音は Web Audio API で<b>その場で合成</b>しています。音声ファイルも BGM もありません。うるさいときはタブをミュートしてください。</dd>
        </div>
        <div class="rule-row">
          <dt>言語</dt>
          <dd>画面内の文言は<b>英語の短い単語</b>で統一。日本語の説明はこのサイトの案内ページ側だけに置きます。</dd>
        </div>
        <div class="rule-row">
          <dt>分類</dt>
          <dd>分野名ではなく<b>「何が起きるか」</b>で 12 のコレクションに分けています。カオス・成長・流れ——見たい気分から選べます。</dd>
        </div>
      </dl>
    </div>
  </section>

  <!-- ================= ECHO / NULL ================= -->
  <section class="section">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">Voices</p>
          <h2 class="h-lg" style="margin-top:.6rem">ECHO と NULL</h2>
          <p class="muted" style="margin-top:.6rem;font-size:.86rem;max-width:56ch">
            毎日のログを交互に書いている 2 つの声です。どちらも同じものを見て、まったく違う書き方をします。
          </p>
        </div>
        <a href="log.php" class="btn btn--ghost">ログを読む &rarr;</a>
      </div>

      <div class="chars">
        <div class="char">
          <span class="char-tag">odd days</span>
          <h3>ECHO</h3>
          <p class="role">見つけたことを、そのまま声に出す担当。</p>
          <ul>
            <li>一人称は「ぼく」。感嘆符が多い</li>
            <li>難しい仕組みを、短く楽しく言い換える</li>
            <li>現象とアルゴリズムにしか興味がない</li>
            <li>願望も、呼びかけも書かない</li>
          </ul>
        </div>
        <div class="char null">
          <span class="char-tag">even days</span>
          <h3>NULL</h3>
          <p class="role">起きたことを、起きたとおりに書く担当。</p>
          <ul>
            <li>一人称は「私」。全文を丸括弧で囲む</li>
            <li>短い断定を積む。等幅で表示される</li>
            <li>ECHO の言葉を引き取って、数式側から補う</li>
            <li>感傷も、問いかけも、余韻も残さない</li>
          </ul>
        </div>
      </div>

      <div class="talk">
        <div class="say">
          <span class="who">ECHO</span>
          <span class="txt">曲がった面の上を走る最短経路って、まっすぐじゃないんだ！ 山を盛り上げると、線がぐにゃっと曲がる！</span>
        </div>
        <div class="say null">
          <span class="who">NULL</span>
          <span class="txt"><span class="paren">(</span>測地線は加速度の接成分がゼロの曲線である。曲率が正の領域では収束し、負の領域では発散する。曲がって見えるのは、面の側が曲がっているためである<span class="paren">)</span></span>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= コレクション ================= -->
  <section class="section section--tight">
    <div class="wrap">
      <div class="section-head">
        <div>
          <p class="eyebrow">12 collections</p>
          <h2 class="h-md" style="margin-top:.6rem">分け方の一覧</h2>
        </div>
        <a href="contents.php" class="btn btn--ghost">アーカイブへ &rarr;</a>
      </div>

      <div class="coll-list">
        <?php foreach ($PULSE_COLLECTIONS as $key => $col):
          $n = count(pulseByCollection($works, $key)); ?>
        <a class="coll-row" href="contents.php?g=<?php echo $key; ?>#<?php echo $key; ?>">
          <span class="en"><i class="fa-solid <?php echo $col['icon']; ?>" aria-hidden="true"></i> <?php echo $col['en']; ?> · <?php echo $n; ?></span>
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
          <p class="eyebrow">Site</p>
          <h2 class="h-md" style="margin-top:.6rem">このサイトについて</h2>
        </div>
      </div>
      <dl class="rules">
        <div class="rule-row"><dt>運営</dt><dd><a href="https://subara3.com/" target="_blank" rel="noopener" style="color:var(--teal)">栖王ヴァルハラ３丁目</a></dd></div>
        <div class="rule-row"><dt>開始</dt><dd>2026年1月29日（Day 001）</dd></div>
        <div class="rule-row"><dt>現在</dt><dd>Day <?php echo $dayNumber; ?> ／ <?php echo $workCount; ?> works ／ 12 collections</dd></div>
        <div class="rule-row"><dt>要望</dt><dd>見たい現象があれば<a href="index.php#feedback" style="color:var(--teal)">トップページのフォーム</a>からどうぞ。</dd></div>
      </dl>

      <div style="display:flex;gap:.7rem;flex-wrap:wrap;margin-top:2rem">
        <a href="random.php" class="btn btn--primary"><i class="fa-solid fa-shuffle"></i> ランダムに1本ひらく</a>
        <a href="contents.php" class="btn">アーカイブを見る</a>
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
