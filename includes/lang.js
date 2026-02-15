/**
 * PULSE 言語切り替え
 * LocalStorageで記憶、ブラウザ言語でデフォルト判定
 */
(function() {
  const STORAGE_KEY = 'pulse_lang';
  
  // 保存された言語を取得、なければブラウザ言語から判定
  function getLang() {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'ja' || saved === 'en') return saved;
    
    // ブラウザ言語から判定
    const browserLang = navigator.language || navigator.userLanguage;
    return browserLang.startsWith('ja') ? 'ja' : 'en';
  }
  
  // 言語を設定
  function setLang(lang) {
    localStorage.setItem(STORAGE_KEY, lang);
    document.documentElement.setAttribute('data-current-lang', lang);
    
    // html lang属性も更新
    document.documentElement.lang = lang;
  }
  
  // 言語を切り替え
  function toggleLang() {
    const current = getLang();
    const next = current === 'ja' ? 'en' : 'ja';
    setLang(next);
  }
  
  // 初期化
  function init() {
    // 現在の言語を適用
    setLang(getLang());
    
    // 切り替えボタンにイベント追加
    const btn = document.getElementById('langToggle');
    if (btn) {
      btn.addEventListener('click', toggleLang);
    }
  }
  
  // DOM ready後に初期化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  
  // グローバルに公開
  window.PULSE_LANG = { getLang, setLang, toggleLang };
})();
