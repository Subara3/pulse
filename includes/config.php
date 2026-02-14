<?php
$lang = 'ja';
$basePath = '';

// secrets読み込み（親ディレクトリのwebから）
$secretsPath = __DIR__ . '/../../web/config/secrets.php';
if (file_exists($secretsPath)) {
    if (!defined('ALLOW_INCLUDE')) {
        define('ALLOW_INCLUDE', true);
    }
    require_once $secretsPath;
}

// データディレクトリ
define('PULSE_DATA_DIR', __DIR__ . '/../data');
define('PULSE_DB_FILE', PULSE_DATA_DIR . '/pulse.db');

// データディレクトリ作成
if (!is_dir(PULSE_DATA_DIR)) {
    mkdir(PULSE_DATA_DIR, 0755, true);
}

/**
 * DBインスタンスを取得（シングルトン）
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . PULSE_DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            error_log('PULSE DB Error: ' . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

/**
 * ページアクセスを記録（DB版）
 */
function recordPageView($pageName) {
    $pdo = getDB();
    if (!$pdo) return;
    
    try {
        // view_countカラムが存在するか確認、なければ追加
        $pdo->exec("ALTER TABLE contents ADD COLUMN view_count INTEGER DEFAULT 0");
    } catch (PDOException $e) {
        // カラム既存の場合は無視
    }
    
    try {
        // 既存レコードがあればview_countを増加
        $stmt = $pdo->prepare("UPDATE contents SET view_count = view_count + 1, updated_at = DATE('now') WHERE slug = :slug");
        $stmt->execute(['slug' => $pageName]);
        
        // 更新されなかった場合は新規挿入
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO contents (slug, view_count, created_at, updated_at) VALUES (:slug, 1, DATE('now'), DATE('now'))");
            $stmt->execute(['slug' => $pageName]);
        }
    } catch (PDOException $e) {
        error_log('recordPageView Error: ' . $e->getMessage());
    }
}

/**
 * ページを人気順でソート（DB版）
 */
function getPagesByPopularity() {
    $pdo = getDB();
    if (!$pdo) return [];
    
    try {
        $stmt = $pdo->query("SELECT slug, view_count FROM contents ORDER BY view_count DESC");
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['slug']] = (int)$row['view_count'];
        }
        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * コンテンツリストを人気順でソート
 */
function sortContentByPopularity($contents) {
    $popularity = getPagesByPopularity();
    
    usort($contents, function($a, $b) use ($popularity) {
        $aViews = $popularity[$a['page']] ?? 0;
        $bViews = $popularity[$b['page']] ?? 0;
        return $bViews - $aViews;
    });
    
    return $contents;
}

/**
 * DBからコンテンツ一覧を取得
 */
function getContentsFromDB() {
    $pdo = getDB();
    if (!$pdo) return [];
    
    try {
        $stmt = $pdo->query("SELECT * FROM contents WHERE is_active = 1 ORDER BY view_count DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
?>
