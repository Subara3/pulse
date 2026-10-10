<?php
/**
 * Common interaction hints (language-neutral, icon-first)
 *
 * Usage:
 * $interactionHints = [
 *   ['type' => 'click', 'label' => 'spawn'],
 *   ['type' => 'drag', 'label' => 'move'],
 *   ['type' => 'right', 'label' => 'erase'],
 * ];
 * require_once __DIR__ . '/includes/interaction-hints.php';
 */

if (!isset($interactionHints) || empty($interactionHints)) return;

$iconMap = [
    'click' => 'fa-hand-pointer',
    'drag'  => 'fa-up-down-left-right',
    'right' => 'fa-computer-mouse',
    'wheel' => 'fa-arrows-up-down',
    'tap'   => 'fa-hand-pointer',
    'pinch' => 'fa-maximize',
];
?>
<div class="interaction-hints" aria-label="interaction hints">
  <?php foreach ($interactionHints as $hint):
    $type = $hint['type'] ?? 'click';
    $icon = $iconMap[$type] ?? 'fa-circle-dot';
    $label = strtoupper($hint['label'] ?? $type);
  ?>
    <div class="hint-chip" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
      <i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i>
      <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php endforeach; ?>
</div>

<style>
.interaction-hints {
  position: fixed;
  top: 1.1rem;
  right: 1rem;
  z-index: 998;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  max-width: min(76vw, 540px);
}

.hint-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.33rem 0.58rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.16);
  background: rgba(13, 34, 56,0.72);
  backdrop-filter: blur(8px);
  color: rgba(255,255,255,0.78);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 0.62rem;
  letter-spacing: 0.05em;
  line-height: 1;
}

.hint-chip i {
  color: var(--accent, #5fd0ff);
  font-size: 0.72rem;
}

@media (max-width: 768px) {
  .interaction-hints {
    top: 0.75rem;
    right: 0.75rem;
    max-width: 88vw;
  }
  .hint-chip span {
    font-size: 0.56rem;
  }
}

.hide-ui .interaction-hints {
  opacity: 0 !important;
  pointer-events: none;
}
</style>
