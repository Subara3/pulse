<?php
/**
 * PULSEコンテンツ一覧（マスターデータ）
 * index.phpとcontents.phpの両方で使用
 */

$allContents = [
  // ========== 読み物 ==========
  [
    'page' => 'log',
    'title' => 'DAILY LOG',
    'desc' => 'ECHOとNULLの日記。思考と観察の記録。',
    'icon' => 'fa-book',
    'category' => '読み物',
    'tag' => '読み物',
    'meta' => '不定期更新'
  ],

  // ========== 遊べる ==========
  [
    'page' => 'maze',
    'title' => 'MAZE',
    'desc' => 'ランダム生成される迷路。ゴールを目指せ。',
    'icon' => 'fa-dungeon',
    'category' => '遊べる',
    'tag' => '遊べる',
    'meta' => 'プレイ可能'
  ],
  [
    'page' => 'morse',
    'title' => 'MORSE',
    'desc' => 'モールス信号を打て。スペースキーで入力。',
    'icon' => 'fa-signal',
    'category' => '遊べる',
    'tag' => '遊べる',
    'meta' => 'プレイ可能'
  ],

  // ========== 眺める ==========
  [
    'page' => 'breathe',
    'title' => 'BREATHE',
    'desc' => '呼吸を整える。吸って、止めて、吐いて。',
    'icon' => 'fa-lungs',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'flow',
    'title' => 'FLOW',
    'desc' => 'ゆっくり変化するグラデーション。',
    'icon' => 'fa-wind',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'pendulum',
    'title' => 'PENDULUM',
    'desc' => '振り子が揺れる。衝突、共振、二重振り子。',
    'icon' => 'fa-weight-hanging',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '4モード'
  ],
  [
    'page' => 'iss',
    'title' => 'ISS RACE',
    'desc' => 'ISSと競争。あなたがこのページを開いてから何km進んだ？',
    'icon' => 'fa-satellite',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'リアルタイムAPI'
  ],
  [
    'page' => 'flock',
    'title' => 'FLOCK',
    'desc' => '鳥の群れ。3つのルールから生まれる複雑な動き。',
    'icon' => 'fa-dove',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'Boids'
  ],
  [
    'page' => 'clock',
    'title' => 'CLOCK',
    'desc' => '砂鉄時計。粒子がデジタル時計を形成する。',
    'icon' => 'fa-clock',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'physics',
    'title' => 'PHYSICS',
    'desc' => '物理シミュ。砂鉄、磁石、弾むボール、チェーン。',
    'icon' => 'fa-atom',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '4モード'
  ],
  [
    'page' => 'primes',
    'title' => 'PRIMES',
    'desc' => '素数を延々と表示。数学は誰のものでもない。',
    'icon' => 'fa-infinity',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'fibonacci',
    'title' => 'FIBONACCI',
    'desc' => 'フィボナッチ数列。5つのモードで可視化。',
    'icon' => 'fa-hurricane',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '5モード'
  ],
  [
    'page' => 'golden',
    'title' => 'GOLDEN SPIRAL',
    'desc' => '黄金螺旋。自然界に潜む美しい比率φ。',
    'icon' => 'fa-circle-notch',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '4モード'
  ],
  [
    'page' => 'pi',
    'title' => 'π VISUALIZATION',
    'desc' => '円周率πの数字をアートに変換。',
    'icon' => 'fa-circle-notch',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '4モード'
  ],
  [
    'page' => 'fractal',
    'title' => 'FRACTAL',
    'desc' => 'マンデルブロ集合。無限の複雑さを探検。',
    'icon' => 'fa-snowflake',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '自動ズーム'
  ],
  [
    'page' => 'julia',
    'title' => 'JULIA SET',
    'desc' => 'ジュリア集合。マウスでパラメータ変更。',
    'icon' => 'fa-asterisk',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '7プリセット'
  ],
  [
    'page' => 'koch',
    'title' => 'KOCH SNOWFLAKE',
    'desc' => 'コッホ雪片。再帰的に成長するフラクタル。',
    'icon' => 'fa-snowflake',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '深度0-7'
  ],
  [
    'page' => 'tree',
    'title' => 'TREE',
    'desc' => 'フラクタルの木。風に揺れる。',
    'icon' => 'fa-tree',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => '季節変更可'
  ],
  [
    'page' => 'life',
    'title' => 'LIFE',
    'desc' => 'コンウェイのライフゲーム。セルが生きて死んで進化する。',
    'icon' => 'fa-bacterium',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'sand',
    'title' => 'SAND',
    'desc' => '砂が落ちて積もる。砂・水・石で遊ぶ。',
    'icon' => 'fa-hourglass',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'インタラクティブ'
  ],
  [
    'page' => 'sort',
    'title' => 'SORT',
    'desc' => 'ソートアルゴリズムの可視化。眺めるだけ。',
    'icon' => 'fa-bars-staggered',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'evolution',
    'title' => 'EVOLUTION',
    'desc' => '遺伝的アルゴリズム。生物が世代を重ねてゴールを目指す。',
    'icon' => 'fa-dna',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'アルゴリズム'
  ],
  [
    'page' => 'fourier',
    'title' => 'FOURIER',
    'desc' => 'フーリエ変換。回転する円の連鎖が図形を描く。',
    'icon' => 'fa-circle-notch',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'アルゴリズム'
  ],
  [
    'page' => 'langton',
    'title' => "LANGTON'S ANT",
    'desc' => "ラングトンのアリ。たった2つのルールが生む秩序と混沌。",
    'icon' => 'fa-bug',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'セルオートマトン'
  ],
  [
    'page' => 'reaction',
    'title' => 'REACTION',
    'desc' => '反応拡散系。チューリングが見つけた、数式が生む生命の模様。',
    'icon' => 'fa-disease',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => 'インタラクティブ'
  ],
  [
    'page' => 'console',
    'title' => 'CONSOLE',
    'desc' => 'SF映画風コンソール。眺めるだけでハッカー気分。',
    'icon' => 'fa-display',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'lorenz',
    'title' => 'LORENZ',
    'desc' => 'ローレンツアトラクタ。カオスの美しさ。',
    'icon' => 'fa-infinity',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'penrose',
    'title' => 'PENROSE',
    'desc' => 'ペンローズタイル。非周期的な敷き詰め。',
    'icon' => 'fa-shapes',
    'category' => '眺める',
    'tag' => '眺める',
    'meta' => ''
  ],
  [
    'page' => 'network',
    'title' => 'NETWORK',
    'desc' => 'ランダムグラフの相転移。ノードが繋がり、突然ひとつになる。',
    'icon' => 'fa-circle-nodes',
    'category' => '眺める',
    'tag' => 'NEW',
    'meta' => 'インタラクティブ'
  ],
  [
    'page' => 'collatz',
    'title' => 'COLLATZ',
    'desc' => 'コラッツ予想。すべての正の整数は1に辿り着く——まだ誰も証明していない。',
    'icon' => 'fa-question',
    'category' => '眺める',
    'tag' => 'NEW',
    'meta' => '3モード'
  ],

  // ========== ガイド ==========
  [
    'page' => 'contents',
    'title' => 'CONTENTS',
    'desc' => 'コンテンツ一覧。サイトマップ。',
    'icon' => 'fa-sitemap',
    'category' => 'ガイド',
    'tag' => 'ガイド',
    'meta' => ''
  ],
  [
    'page' => 'about',
    'title' => 'ABOUT',
    'desc' => 'PULSEについて。ECHOとNULLの紹介。',
    'icon' => 'fa-info-circle',
    'category' => 'ガイド',
    'tag' => 'ガイド',
    'meta' => ''
  ],
];

// ========== 没コンテンツ（削除済み） ==========
$hiddenContents = [];

// カテゴリ順の定義
$categoryOrder = ['読み物', '遊べる', '眺める', 'ガイド'];

// カテゴリでグループ化
function getContentsByCategory($contents) {
  $grouped = [];
  foreach ($contents as $content) {
    $cat = $content['category'];
    if (!isset($grouped[$cat])) {
      $grouped[$cat] = [];
    }
    $grouped[$cat][] = $content;
  }
  return $grouped;
}

// index.php用に$contentsとして提供（ガイドカテゴリはサイドメニューに移動済み）
$contents = array_values(array_filter($allContents, fn($c) => $c['category'] !== 'ガイド'));
