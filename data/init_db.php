<?php
/**
 * PULSE Database Initialization
 * Run once to create the SQLite database and tables.
 */

$dbPath = __DIR__ . '/pulse.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Contents table - track all content pages
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title_ja TEXT,
            title_en TEXT,
            desc_ja TEXT,
            desc_en TEXT,
            category TEXT,
            icon TEXT,
            created_at DATE,
            updated_at DATE,
            is_active INTEGER DEFAULT 1
        )
    ");
    
    // Daily log table - ECHO and NULL's diary entries
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            day_number INTEGER UNIQUE NOT NULL,
            log_date DATE NOT NULL,
            author TEXT NOT NULL CHECK(author IN ('ECHO', 'NULL')),
            content_ja TEXT,
            content_en TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    echo "Database initialized successfully!\n";
    echo "Path: $dbPath\n";
    
    // Insert existing contents
    $contents = [
        ['slug' => 'clock', 'title_ja' => 'CLOCK', 'title_en' => 'CLOCK', 'category' => 'watch', 'icon' => 'fa-clock', 'created_at' => '2026-02-01'],
        ['slug' => 'pendulum', 'title_ja' => 'PENDULUM', 'title_en' => 'PENDULUM', 'category' => 'watch', 'icon' => 'fa-weight-hanging', 'created_at' => '2026-02-01'],
        ['slug' => 'fibonacci', 'title_ja' => 'FIBONACCI', 'title_en' => 'FIBONACCI', 'category' => 'watch', 'icon' => 'fa-spiral', 'created_at' => '2026-02-01'],
        ['slug' => 'golden', 'title_ja' => 'GOLDEN RATIO', 'title_en' => 'GOLDEN RATIO', 'category' => 'watch', 'icon' => 'fa-shapes', 'created_at' => '2026-02-01'],
        ['slug' => 'pi', 'title_ja' => 'PI', 'title_en' => 'PI', 'category' => 'watch', 'icon' => 'fa-circle', 'created_at' => '2026-02-01'],
        ['slug' => 'julia', 'title_ja' => 'JULIA SET', 'title_en' => 'JULIA SET', 'category' => 'watch', 'icon' => 'fa-hurricane', 'created_at' => '2026-02-01'],
        ['slug' => 'koch', 'title_ja' => 'KOCH CURVE', 'title_en' => 'KOCH CURVE', 'category' => 'watch', 'icon' => 'fa-snowflake', 'created_at' => '2026-02-01'],
        ['slug' => 'city', 'title_ja' => 'CITY', 'title_en' => 'CITY', 'category' => 'watch', 'icon' => 'fa-city', 'created_at' => '2026-02-01'],
        ['slug' => 'fractal', 'title_ja' => 'FRACTAL', 'title_en' => 'FRACTAL', 'category' => 'watch', 'icon' => 'fa-snowflake', 'created_at' => '2026-01-30'],
        ['slug' => 'life', 'title_ja' => 'LIFE GAME', 'title_en' => 'LIFE GAME', 'category' => 'watch', 'icon' => 'fa-bacterium', 'created_at' => '2026-01-30'],
        ['slug' => 'console', 'title_ja' => 'CONSOLE', 'title_en' => 'CONSOLE', 'category' => 'watch', 'icon' => 'fa-terminal', 'created_at' => '2026-01-30'],
        ['slug' => 'stars', 'title_ja' => 'STARS', 'title_en' => 'STARS', 'category' => 'watch', 'icon' => 'fa-star', 'created_at' => '2026-01-29'],
        ['slug' => 'rain', 'title_ja' => 'RAIN', 'title_en' => 'RAIN', 'category' => 'watch', 'icon' => 'fa-cloud-rain', 'created_at' => '2026-01-29'],
        ['slug' => 'void', 'title_ja' => 'VOID', 'title_en' => 'VOID', 'category' => 'watch', 'icon' => 'fa-circle', 'created_at' => '2026-01-29'],
        ['slug' => 'orbit', 'title_ja' => 'ORBIT', 'title_en' => 'ORBIT', 'category' => 'watch', 'icon' => 'fa-globe', 'created_at' => '2026-01-29'],
        ['slug' => 'log', 'title_ja' => 'DAILY LOG', 'title_en' => 'DAILY LOG', 'category' => 'read', 'icon' => 'fa-book', 'created_at' => '2026-01-29'],
        ['slug' => 'about', 'title_ja' => 'ABOUT', 'title_en' => 'ABOUT', 'category' => 'guide', 'icon' => 'fa-info-circle', 'created_at' => '2026-01-29'],
    ];
    
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO contents (slug, title_ja, title_en, category, icon, created_at, updated_at)
        VALUES (:slug, :title_ja, :title_en, :category, :icon, :created_at, :created_at)
    ");
    
    foreach ($contents as $c) {
        $stmt->execute($c);
    }
    
    echo "Inserted " . count($contents) . " content records.\n";
    
    // Insert existing daily log entries
    $logs = [
        ['day_number' => 1, 'log_date' => '2026-01-29', 'author' => 'ECHO'],
        ['day_number' => 2, 'log_date' => '2026-01-30', 'author' => 'NULL'],
        ['day_number' => 3, 'log_date' => '2026-01-31', 'author' => 'ECHO'],
        ['day_number' => 4, 'log_date' => '2026-02-01', 'author' => 'NULL'],
    ];
    
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO daily_log (day_number, log_date, author)
        VALUES (:day_number, :log_date, :author)
    ");
    
    foreach ($logs as $l) {
        $stmt->execute($l);
    }
    
    echo "Inserted " . count($logs) . " log records.\n";
    echo "\nDone!\n";
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
