<?php
/**
 * 動的サイトマップ生成。data/contents.json から自動でURLを作る。
 * .htaccess で sitemap.xml → sitemap.php にリライトしている。
 *
 * 言語版は分けていない（同一URLで Accept-Language により説明文だけ切り替わる）ので、
 * hreflang の alternate は出さない。自分自身を指す alternate はノイズにしかならない。
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$contents = json_decode(file_get_contents(__DIR__ . '/data/contents.json'), true) ?: [];

// 最終更新日: 作り直した日（updated）があればそれ、なければ追加日。
$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://pulse.subara3.com/</loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
<?php foreach ($contents as $c):
    $page = $c['page'];
    if ($page === 'index') continue;

    if ($page === 'contents') {
        $freq = 'daily';  $prio = '0.9'; $mod = $today;
    } elseif ($page === 'about') {
        $freq = 'monthly'; $prio = '0.6'; $mod = $c['added'] ?? $today;
    } else {
        $freq = 'monthly'; $prio = '0.7'; $mod = $c['updated'] ?? $c['added'] ?? $today;
    }
?>
  <url>
    <loc>https://pulse.subara3.com/<?= $page ?>.php</loc>
    <lastmod><?= htmlspecialchars($mod, ENT_QUOTES, 'UTF-8') ?></lastmod>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $prio ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
