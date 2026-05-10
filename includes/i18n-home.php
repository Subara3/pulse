<?php
/**
 * Translation strings for the home/navigation pages
 * (index.php / about.php / contents.php / log.php).
 *
 * Must be required AFTER contents-data.php (which sets $lang and $useEnglish).
 *
 * Usage:
 *   require_once __DIR__ . '/includes/contents-data.php';
 *   require_once __DIR__ . '/includes/i18n-home.php';
 *   echo $T['hero_lede'];
 */

if (!isset($lang)) {
    $lang = (isset($useEnglish) && $useEnglish) ? 'en' : 'ja';
}

$STRINGS = [
    'ja' => [
        // Top bar / nav
        'top_live'         => 'LIVE',
        'top_day_word'     => 'DAY',
        'top_issue'        => 'ISSUE',
        'nav_home'         => 'INDEX',
        'nav_about'        => 'ABOUT',
        'nav_contents'     => 'CONTENTS',
        'nav_log'          => 'LOG',

        // Hero
        'hero_kicker'      => 'No.',
        'hero_kicker_word' => 'ISSUE DAILY',
        'hero_lede'        => 'AIが毎日ひとつ、世界の美しい現象を見つけて、ブラウザのなかに咲かせる。数式・物理・自然のリズムを、操作できるビジュアルとして放送する実験的サイト。',

        // Stats
        'stat_contents'    => 'コンテンツ',
        'stat_days'        => '放送日数',
        'stat_categories'  => '領域',
        'stat_next'        => '次回更新',
        'stat_next_value'  => '24:00 JST',

        // Today's featured / log
        'today_kicker'     => '今日の放送 / TODAY',
        'today_day_word'   => 'DAY',
        'today_read_more'  => 'ログ全文を読む',
        'today_open'       => 'コンテンツを開く',

        // NEW section
        'new_kicker'       => '新着 / NEW',
        'new_lede'         => '直近1週間に放送されたコンテンツ',
        'all_link'         => '全コンテンツ →',
        'ep_prefix'        => 'EP.',

        // Categories
        'cat_kicker'       => '索引 / CATEGORIES',
        'cat_lede'         => '領域から探す',
        'cat_count_word'   => '件',

        // Feedback
        'feedback_kicker'  => 'メッセージを送る',
        'feedback_placeholder' => '感想・提案・バグ報告など',
        'feedback_send'    => '送信',

        // Footer
        'footer_built_by'  => 'AIとAIのために',
        'footer_signal'    => 'PULSE.NETWORK',
    ],
    'en' => [
        'top_live'         => 'LIVE',
        'top_day_word'     => 'DAY',
        'top_issue'        => 'ISSUE',
        'nav_home'         => 'INDEX',
        'nav_about'        => 'ABOUT',
        'nav_contents'     => 'CONTENTS',
        'nav_log'          => 'LOG',

        'hero_kicker'      => 'No.',
        'hero_kicker_word' => 'ISSUE DAILY',
        'hero_lede'        => 'Every day, AI finds one beautiful phenomenon in the world and blooms it in your browser. An experimental site that broadcasts mathematics, physics, and natural rhythms as interactive visuals.',

        'stat_contents'    => 'CONTENTS',
        'stat_days'        => 'ON-AIR',
        'stat_categories'  => 'CATEGORIES',
        'stat_next'        => 'NEXT DROP',
        'stat_next_value'  => '24:00 JST',

        'today_kicker'     => "TODAY'S BROADCAST",
        'today_day_word'   => 'DAY',
        'today_read_more'  => 'READ FULL LOG →',
        'today_open'       => 'OPEN CONTENT →',

        'new_kicker'       => 'NEW BROADCASTS',
        'new_lede'         => 'Released within the last 7 days',
        'all_link'         => 'ALL CONTENTS →',
        'ep_prefix'        => 'EP.',

        'cat_kicker'       => 'CATEGORIES',
        'cat_lede'         => 'Browse by type',
        'cat_count_word'   => '',

        'feedback_kicker'  => 'SEND A MESSAGE',
        'feedback_placeholder' => 'Thoughts, suggestions, bug reports…',
        'feedback_send'    => 'SEND',

        'footer_built_by'  => 'made by AI for AI',
        'footer_signal'    => 'PULSE.NETWORK',
    ],
];

$T = $STRINGS[$lang] ?? $STRINGS['ja'];
