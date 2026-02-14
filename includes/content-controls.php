<?php
/**
 * 共通コントロールUI（右下配置）
 * 
 * 使い方:
 * $controls = [
 *   ['id' => 'colorBtn', 'icon' => 'fa-palette', 'label' => 'COLOR'],
 *   ['id' => 'pauseBtn', 'icon' => 'fa-pause', 'label' => 'PAUSE', 'active' => true],
 * ];
 * require_once __DIR__ . '/includes/content-controls.php';
 */

if (!isset($controls) || empty($controls)) return;
?>

<div class="content-controls" id="contentControls">
  <?php foreach ($controls as $ctrl): ?>
    <button class="ctrl-btn<?php echo !empty($ctrl['active']) ? ' active' : ''; ?>" 
            id="<?php echo $ctrl['id']; ?>"
            title="<?php echo $ctrl['label'] ?? ''; ?>">
      <?php if (!empty($ctrl['icon'])): ?>
        <i class="fa-solid <?php echo $ctrl['icon']; ?>"></i>
      <?php endif; ?>
      <?php if (!empty($ctrl['label'])): ?>
        <span class="ctrl-label"><?php echo $ctrl['label']; ?></span>
      <?php endif; ?>
    </button>
  <?php endforeach; ?>
</div>

<style>
/* 共通コントロールUI - 右下配置 */
.content-controls {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  z-index: 999;
  opacity: 0.4;
  transition: opacity 0.3s ease;
}

.content-controls:hover {
  opacity: 1;
}

.ctrl-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  background: rgba(10, 10, 15, 0.8);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 25px;
  color: rgba(255, 255, 255, 0.7);
  font-family: 'Orbitron', sans-serif;
  font-size: 0.65rem;
  letter-spacing: 0.05em;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.ctrl-btn:hover {
  border-color: var(--accent, #00f5d4);
  color: var(--accent, #00f5d4);
  transform: translateX(-3px);
}

.ctrl-btn.active {
  background: rgba(0, 245, 212, 0.15);
  border-color: var(--accent, #00f5d4);
  color: var(--accent, #00f5d4);
}

.ctrl-btn i {
  font-size: 0.9rem;
  width: 1rem;
  text-align: center;
}

.ctrl-label {
  display: inline;
}

/* モバイル対応 */
@media (max-width: 768px) {
  .content-controls {
    bottom: 1.5rem;
    right: 1rem;
  }
  
  .ctrl-btn {
    padding: 0.5rem 0.8rem;
  }
  
  .ctrl-label {
    display: none;
  }
  
  .ctrl-btn i {
    font-size: 1rem;
  }
}

/* floating-navと共存時の調整 */
.floating-nav ~ .content-controls,
.content-controls:has(~ .floating-nav) {
  /* floating-navが左下にあるので、右下はそのまま */
}

/* UI非表示モード */
.hide-ui .content-controls {
  opacity: 0 !important;
  pointer-events: none;
}
</style>
