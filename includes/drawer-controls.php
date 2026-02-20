<?php
/**
 * ドロワー式コントロールUI（モバイル対応）
 * 
 * デスクトップ: 右下に従来通り表示
 * モバイル: トグルボタン + 下からスライドするドロワー
 * 
 * 使い方:
 * <div id="controls" class="drawer-controls">
 *   <!-- コントロール内容 -->
 * </div>
 * <?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
 */
?>

<button id="drawer-toggle" class="drawer-toggle" aria-label="コントロールを開く">
  <i class="fa-solid fa-gear"></i>
</button>

<div id="drawer-overlay" class="drawer-overlay"></div>

<style>
/* ==================== ドロワートグルボタン ==================== */
.drawer-toggle {
  display: none; /* デスクトップでは非表示 */
  position: fixed;
  bottom: 80px;
  right: 16px;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgba(10, 10, 15, 0.9);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(0, 245, 212, 0.4);
  color: #00f5d4;
  font-size: 1.1rem;
  cursor: pointer;
  z-index: 1001;
  transition: all 0.3s ease;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
}

.drawer-toggle:hover {
  transform: scale(1.1);
  border-color: #00f5d4;
  background: rgba(0, 245, 212, 0.1);
}

.drawer-toggle.open {
  transform: rotate(90deg);
  background: rgba(0, 245, 212, 0.2);
}

/* ==================== オーバーレイ ==================== */
.drawer-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  z-index: 999;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.drawer-overlay.visible {
  display: block;
  opacity: 1;
}

/* ==================== ドロワーコントロール ==================== */
.drawer-controls {
  position: fixed;
  bottom: 16px;
  right: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 100;
  transition: all 0.3s ease;
}

/* ==================== モバイル対応 ==================== */
@media (max-width: 768px) {
  .drawer-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .drawer-controls {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    width: 100%;
    max-height: 60vh;
    padding: 20px;
    padding-bottom: calc(20px + env(safe-area-inset-bottom));
    background: linear-gradient(to top, rgba(10, 10, 15, 0.98), rgba(10, 10, 15, 0.95));
    backdrop-filter: blur(20px);
    border-top: 1px solid rgba(0, 245, 212, 0.3);
    border-radius: 20px 20px 0 0;
    z-index: 1000;
    overflow-y: auto;
    
    /* 初期状態: 非表示 */
    transform: translateY(100%);
    opacity: 0;
    pointer-events: none;
  }
  
  .drawer-controls.open {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
  }
  
  /* ドロワー内のスライダーを横幅いっぱいに */
  .drawer-controls input[type="range"] {
    width: 100% !important;
  }
  
  /* ドロワー内のボタンを横並びに */
  .drawer-controls .slider-group {
    width: 100%;
  }
  
  .drawer-controls .slider-group label {
    font-size: 13px;
    margin-bottom: 8px;
    display: block;
  }
  
  /* プリセットボタンを横に広げる */
  .drawer-controls #preset-btns {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    max-width: 100%;
    width: 100%;
  }
  
  .drawer-controls #preset-btns button {
    padding: 12px 8px;
    font-size: 14px;
  }
  
  /* 通常ボタン */
  .drawer-controls > button {
    padding: 14px;
    font-size: 14px;
  }
  
  /* ドロワーヘッダー（ドラッグハンドル） */
  .drawer-controls::before {
    content: '';
    display: block;
    width: 40px;
    height: 4px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    margin: 0 auto 16px;
  }
}

/* ==================== スモールスクリーン（さらに小さい画面） ==================== */
@media (max-width: 375px) {
  .drawer-controls #preset-btns {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .drawer-toggle {
    width: 50px;
    height: 50px;
    font-size: 20px;
    bottom: 16px;
    right: 16px;
  }
}
</style>

<script>
(function() {
  const toggle = document.getElementById('drawer-toggle');
  const overlay = document.getElementById('drawer-overlay');
  const controls = document.querySelector('.drawer-controls');
  
  if (!toggle || !controls) return;
  
  let isOpen = false;
  let touchStartY = 0;
  let touchCurrentY = 0;
  
  function openDrawer() {
    isOpen = true;
    toggle.classList.add('open');
    overlay.classList.add('visible');
    controls.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  
  function closeDrawer() {
    isOpen = false;
    toggle.classList.remove('open');
    overlay.classList.remove('visible');
    controls.classList.remove('open');
    document.body.style.overflow = '';
  }
  
  toggle.addEventListener('click', () => {
    if (isOpen) {
      closeDrawer();
    } else {
      openDrawer();
    }
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  
  overlay.addEventListener('click', closeDrawer);
  
  // スワイプダウンで閉じる
  controls.addEventListener('touchstart', (e) => {
    touchStartY = e.touches[0].clientY;
  }, { passive: true });
  
  controls.addEventListener('touchmove', (e) => {
    touchCurrentY = e.touches[0].clientY;
    const diff = touchCurrentY - touchStartY;
    
    if (diff > 0) {
      controls.style.transform = `translateY(${diff}px)`;
    }
  }, { passive: true });
  
  controls.addEventListener('touchend', () => {
    const diff = touchCurrentY - touchStartY;
    controls.style.transform = '';
    
    if (diff > 80) {
      closeDrawer();
    }
  });
  
  // ESCキーで閉じる
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen) {
      closeDrawer();
    }
  });
})();
</script>
