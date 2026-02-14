<?php
/**
 * 動的サイトマップ生成
 * data/contents.json から自動的にURLを生成する。
 * .htaccess で sitemap.xml → sitemap.php にリライト。
 */
header('Content-Type: application/xml; charset=UTF-8');

$jsonPath = __DIR__ . '/data/contents.json';
$contents = json_decode(file_get_contents($jsonPath), true) ?: [];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://pulse.subara3.com/</loc>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
<?php foreach ($contents as $c):
    $freq = ($c['page'] === 'log') ? 'daily' : 'monthly';
    $prio = ($c['page'] === 'log' || $c['page'] === 'contents') ? '0.7' : '0.8';
    if ($c['category'] === 'ガイド' && $c['page'] === 'about') $prio = '0.7';
?>
  <url>
    <loc>https://pulse.subara3.com/<?= $c['page'] ?>.php</loc>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $prio ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
