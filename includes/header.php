<?php
// 共通ヘッダー
// 必要な変数: $pageTitle, $pageDesc, $pageCanonical, $pageName, $lang, $basePath
// オプション: $ogType (デフォルト: 'website'), $ogImage

if (!isset($ogType)) $ogType = 'website';
if (!isset($ogImage)) $ogImage = 'https://pulse.subara3.com/img/ogp.png';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" id="htmlRoot">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?></title>

<!-- SEO -->
<meta name="description" content="<?php echo htmlspecialchars($pageDesc); ?>">
<link rel="canonical" href="<?php echo htmlspecialchars($pageCanonical); ?>">


<!-- OGP -->
<meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($pageDesc); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($pageCanonical); ?>">
<meta property="og:type" content="<?php echo $ogType; ?>">
<meta property="og:site_name" content="PULSE">
<meta property="og:image" content="<?php echo htmlspecialchars($ogImage); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="ja_JP">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@_subara3">
<meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
<meta name="twitter:description" content="<?php echo htmlspecialchars($pageDesc); ?>">
<meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage); ?>">

<!-- Theme Color -->
<meta name="theme-color" content="#0a0a0f">

<!-- 効果音のスタブ。includes/sfx.php はページ本体スクリプトより後に読まれるため、
     初期化中に SFX.play() が呼ばれると ReferenceError でページごと死ぬ。
     先に無害な no-op を置いておき、sfx.php が本物に差し替える。 -->
<script>window.SFX={play:function(){},toggle:function(){},isEnabled:function(){return false},stub:true};</script>

<!-- Favicon -->
<link rel="icon" type="image/svg+xml" href="<?php echo $basePath; ?>favicon.svg">
<link rel="apple-touch-icon" href="<?php echo $basePath; ?>favicon.svg">

<!-- JSON-LD WebSite -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "PULSE - AIの鼓動",
  "alternateName": "PULSE - AI Heartbeat",
  "url": "https://pulse.subara3.com/",
  "description": "AIとアルゴリズムで動くインタラクティブなコンテンツ集",
  "inLanguage": ["ja", "en"],
  "publisher": {
    "@type": "Organization",
    "name": "栖王ヴァルハラ３丁目",
    "url": "https://subara3.com/",
    "sameAs": [
      "https://subara3.com/",
      "https://steam.subara3.com/",
      "https://pulse.subara3.com/"
    ]
  }
}
</script>

<?php
// ページ別JSON-LD
if (isset($pageName) && !in_array($pageName, ['index', 'about', 'contents'])) {
    $schemaType = ($pageName === 'log') ? 'Article' : 'CreativeWork';
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => $schemaType,
        'name' => $pageTitle,
        'description' => $pageDesc,
        'url' => $pageCanonical,
        'image' => $ogImage,
        'inLanguage' => 'ja',
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => 'PULSE',
            'url' => 'https://pulse.subara3.com/'
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => '栖王ヴァルハラ３丁目',
            'url' => 'https://subara3.com/'
        ]
    ];
    if ($schemaType === 'CreativeWork') {
        $schema['genre'] = 'Interactive Visualization';
        $schema['accessMode'] = ['visual', 'interactive'];
    }
    echo '<script type="application/ld+json">' . "\n";
    echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n</script>\n";
    
    // BreadcrumbList
    $breadcrumb = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'PULSE', 'item' => 'https://pulse.subara3.com/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle, 'item' => $pageCanonical]
        ]
    ];
    echo '<script type="application/ld+json">' . "\n";
    echo json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n</script>\n";
}
?>

<?php $isPreview = isset($_GET['preview']) && $_GET['preview'] === '1'; ?>
<?php if (!$isPreview): ?>
<!-- Google AdSense -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7086371722392050"
     crossorigin="anonymous"></script>
<?php endif; ?>

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=JetBrains+Mono:wght@400;500;700&family=Zen+Kaku+Gothic+New:wght@400;500;700<?php echo isset($needSerif) && $needSerif ? '&family=Shippori+Mincho:wght@400;600' : ''; ?>&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<!-- PULSE デザインシステム（唯一の基準。ページ固有の装飾は $extraStyle へ） -->
<link href="<?php echo $basePath; ?>css/pulse.css?v=20260825" rel="stylesheet">
<noscript><style>.fade-in{opacity:1!important;transform:none!important}</style></noscript>
<?php if (isset($extraHead)) echo $extraHead; ?>

<?php if ($isPreview): ?>
<style>
/* Embedded live-preview mode */
.floating-nav, nav, header, footer, .info, .hud, .controls, .content-controls, .drawer-controls,
#controls, .ui, .ad-section, .ad-container, .share-buttons, .feedback-section {
  display: none !important;
}
body { overflow: hidden !important; }
</style>
<?php endif; ?>

<style>
<?php if (isset($extraStyle)) echo $extraStyle; ?>
</style>
</head>
<body>
<?php
// サウンド。ページ本体のスクリプトより先に立てる必要があるので、ここで読む。
// 各ページ末尾の require_once includes/sfx.php は同じパスなので二重には読まれない。
// プレビュー撮影（?preview=1）では音もゲートも出さない。
if (!$isPreview) {
    require_once __DIR__ . '/sfx.php';
    require_once __DIR__ . '/audio-consent.php';
}
?>
