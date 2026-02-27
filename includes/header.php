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

<!-- 多言語SEO: hreflang -->
<link rel="alternate" hreflang="ja" href="<?php echo htmlspecialchars($pageCanonical); ?>">
<link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars($pageCanonical); ?>">
<link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($pageCanonical); ?>">

<?php if (!empty($pageTitleEn) && !empty($pageDescEn)): ?>
<!-- 英語版メタ情報（Google多言語対応） -->
<meta name="title" lang="en" content="<?php echo htmlspecialchars($pageTitleEn); ?>">
<meta name="description" lang="en" content="<?php echo htmlspecialchars($pageDescEn); ?>">
<?php endif; ?>

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
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;700&family=Orbitron:wght@400;700;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
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
* { margin: 0; padding: 0; box-sizing: border-box; }

:root {
  --bg-primary: #0a0a0f;
  --bg-secondary: #12121a;
  --bg-card: #1a1a24;
  --text-primary: #e0e0e0;
  --text-secondary: #888;
  --accent: #00f5d4;
  --accent-secondary: #7b2cbf;
  --pulse-glow: rgba(0, 245, 212, 0.3);
  --echo-color: #00f5d4;
  --echo-glow: rgba(0, 245, 212, 0.4);
  --null-color: #7b2cbf;
  --null-glow: rgba(123, 44, 191, 0.4);
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Noto Sans JP', sans-serif;
  background: var(--bg-primary);
  color: var(--text-primary);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ========== 共通フッター ========== */
footer {
  background: var(--bg-primary);
  border-top: 1px solid rgba(255,255,255,0.1);
  padding: 3rem 2rem;
  text-align: center;
}

.footer-logo {
  font-family: 'Orbitron', sans-serif;
  font-size: 1.5rem;
  color: var(--accent);
  margin-bottom: 1rem;
}

.footer-links {
  margin-top: 1.5rem;
  display: flex;
  justify-content: center;
  gap: 2rem;
  flex-wrap: wrap;
}

.footer-links a {
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 1.2rem;
  transition: color 0.3s;
}

.footer-links a:hover { color: var(--accent); }

.footer-external {
  margin-top: 1.5rem;
  display: flex;
  justify-content: center;
  gap: 2rem;
  flex-wrap: wrap;
}

.footer-external a {
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 0.85rem;
  transition: all 0.3s;
  padding: 0.4rem 0.8rem;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 20px;
}

.footer-external a:hover {
  color: var(--accent);
  border-color: var(--accent);
}

.footer-external a i {
  margin-right: 0;
}

.footer-copy {
  margin-top: 2rem;
  font-size: 0.8rem;
  color: var(--text-secondary);
  opacity: 0.5;
}

/* ========== 共通ヘッダーナビ ========== */
header {
  padding: 2rem;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}

.header-inner {
  max-width: 900px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.logo-link {
  font-family: 'Orbitron', sans-serif;
  font-size: 1.5rem;
  color: var(--accent);
  text-decoration: none;
}

header nav a {
  color: var(--text-secondary);
  text-decoration: none;
  margin-left: 2rem;
  font-size: 0.9rem;
  transition: color 0.3s;
}

header nav a:hover { color: var(--accent); }

/* ========== 広告 ========== */
.ad-section {
  padding: 2rem;
  text-align: center;
}

.ad-container { max-width: 728px; margin: 0 auto; }
.ad-label { font-size: 0.7rem; color: var(--text-secondary); opacity: 0.5; margin-bottom: 0.5rem; }

/* ========== アニメーション ========== */
.fade-in {
  opacity: 0;
  transform: translateY(30px);
  transition: all 0.8s ease;
}

.fade-in.visible {
  opacity: 1;
  transform: translateY(0);
}
<?php if (isset($extraStyle)) echo $extraStyle; ?>
</style>
</head>
<body>
