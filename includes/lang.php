<?php
/**
 * 言語設定ヘルパー
 * デフォルトは日本語、LocalStorage（JS側）で切り替え
 */

// 言語切り替えボタンを出力
function renderLangToggle() {
    ?>
    <button id="langToggle" class="lang-toggle" title="Switch language" aria-label="Switch language">
        <span class="lang-label" data-lang="ja">EN</span>
        <span class="lang-label" data-lang="en">JA</span>
    </button>
    <style>
    .lang-toggle {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.2);
        color: var(--text-secondary);
        padding: 0.3rem 0.6rem;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'Orbitron', sans-serif;
        font-size: 0.75rem;
        transition: all 0.3s;
        margin-left: 1rem;
    }
    .lang-toggle:hover {
        border-color: var(--accent);
        color: var(--accent);
    }
    .lang-label { display: none; }
    [data-current-lang="ja"] .lang-label[data-lang="ja"] { display: inline; }
    [data-current-lang="en"] .lang-label[data-lang="en"] { display: inline; }
    
    /* 言語によるテキスト切り替え */
    [data-lang-ja], [data-lang-en] { display: none; }
    [data-current-lang="ja"] [data-lang-ja] { display: inline; }
    [data-current-lang="en"] [data-lang-en] { display: inline; }
    
    /* ブロック要素用 */
    [data-lang-ja-block], [data-lang-en-block] { display: none; }
    [data-current-lang="ja"] [data-lang-ja-block] { display: block; }
    [data-current-lang="en"] [data-lang-en-block] { display: block; }
    </style>
    <?php
}
?>
