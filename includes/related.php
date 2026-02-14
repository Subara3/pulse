<?php
/**
 * PULSE 関連コンテンツレコメンド
 *
 * $pageName 変数を使って関連ページを3つまで表示する。
 * page-end.php の直前にインクルードする。
 */

// 関連ページマッピング（カテゴリベース）
$_relatedMap = [
  // 数学・フラクタル系
  'fractal' => ['julia', 'koch', 'penrose', 'golden'],
  'julia' => ['fractal', 'lorenz', 'koch'],
  'koch' => ['fractal', 'penrose', 'tree'],
  'penrose' => ['koch', 'fractal', 'voronoi'],
  'golden' => ['fibonacci', 'pi', 'fractal'],
  'fibonacci' => ['golden', 'pi', 'collatz'],
  'pi' => ['fibonacci', 'golden', 'primes'],
  'primes' => ['pi', 'fibonacci', 'collatz'],
  'collatz' => ['primes', 'fibonacci', 'pi'],
  // シミュレーション系
  'life' => ['evolution', 'langton', 'flock'],
  'evolution' => ['life', 'flock', 'langton'],
  'flock' => ['evolution', 'life', 'physics'],
  'langton' => ['life', 'evolution', 'sandpile'],
  'sandpile' => ['langton', 'reaction', 'sand'],
  'reaction' => ['sandpile', 'voronoi', 'flow'],
  // 物理系
  'physics' => ['pendulum', 'ripple', 'waves'],
  'pendulum' => ['physics', 'lorenz', 'waves'],
  'waves' => ['ripple', 'physics', 'pendulum'],
  'ripple' => ['waves', 'physics', 'sand'],
  'lorenz' => ['julia', 'pendulum', 'fourier'],
  // ビジュアル系
  'tree' => ['koch', 'fractal', 'flow'],
  'voronoi' => ['penrose', 'network', 'reaction'],
  'network' => ['voronoi', 'flock', 'life'],
  'flow' => ['breathe', 'waves', 'reaction'],
  'breathe' => ['flow', 'clock', 'waves'],
  // インタラクティブ系
  'maze' => ['sort', 'morse', 'console'],
  'sort' => ['maze', 'primes', 'sand'],
  'morse' => ['clock', 'console', 'iss'],
  'clock' => ['morse', 'breathe', 'iss'],
  'console' => ['morse', 'maze', 'iss'],
  'iss' => ['clock', 'console', 'morse'],
  'sand' => ['sandpile', 'physics', 'ripple'],
  'string' => ['fourier', 'golden', 'fibonacci'],
  'fourier' => ['string', 'lorenz', 'waves'],
];

// $pageName が未定義またはマッピングにない場合はスキップ
if (!isset($pageName) || !isset($_relatedMap[$pageName])) return;

// contents.json からタイトルを取得
$_contentsJsonPath = __DIR__ . '/../data/contents.json';
$_titleMap = [];
if (file_exists($_contentsJsonPath)) {
    $_contentsData = json_decode(file_get_contents($_contentsJsonPath), true);
    if (is_array($_contentsData)) {
        foreach ($_contentsData as $_item) {
            if (isset($_item['page'], $_item['title'])) {
                $_titleMap[$_item['page']] = $_item['title'];
            }
            if (isset($_item['page'], $_item['icon'])) {
                $_iconMap[$_item['page']] = $_item['icon'];
            }
        }
    }
}
if (!isset($_iconMap)) $_iconMap = [];

// 関連ページを最大3つ取得
$_relatedPages = array_slice($_relatedMap[$pageName], 0, 3);
?>

<!-- 関連コンテンツ -->
<div class="related-trigger" id="relatedTrigger">
  <button class="related-toggle" id="relatedToggle" aria-label="関連コンテンツを表示">
    <i class="fa-solid fa-compass"></i>
  </button>
  <div class="related-panel" id="relatedPanel">
    <div class="related-label">RELATED</div>
    <div class="related-cards">
      <?php foreach ($_relatedPages as $_slug): ?>
        <?php
          $_title = isset($_titleMap[$_slug]) ? $_titleMap[$_slug] : strtoupper($_slug);
          $_icon  = isset($_iconMap[$_slug]) ? $_iconMap[$_slug] : 'fa-circle';
        ?>
        <a href="<?php echo htmlspecialchars($_slug); ?>.php" class="related-card">
          <i class="fa-solid <?php echo htmlspecialchars($_icon); ?> related-card-icon"></i>
          <span class="related-card-title"><?php echo htmlspecialchars($_title); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.related-trigger {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  z-index: 150;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.6rem;
}

.related-toggle {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(0, 245, 212, 0.08);
  border: 1px solid rgba(0, 245, 212, 0.25);
  color: rgba(0, 245, 212, 0.6);
  font-size: 1.1rem;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
}

.related-toggle:hover {
  background: rgba(0, 245, 212, 0.15);
  border-color: rgba(0, 245, 212, 0.5);
  color: #00f5d4;
  transform: scale(1.08);
}

.related-toggle.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: #00f5d4;
  color: #00f5d4;
}

.related-panel {
  position: absolute;
  bottom: 52px;
  right: 0;
  opacity: 0;
  visibility: hidden;
  transform: translateY(8px) scale(0.96);
  transition: all 0.25s ease;
  pointer-events: none;
}

.related-panel.open {
  opacity: 1;
  visibility: visible;
  transform: translateY(0) scale(1);
  pointer-events: auto;
}

.related-label {
  font-family: 'Orbitron', sans-serif;
  font-size: 0.6rem;
  letter-spacing: 0.15em;
  color: rgba(0, 245, 212, 0.5);
  text-align: right;
  margin-bottom: 0.4rem;
  padding-right: 0.2rem;
}

.related-cards {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.related-card {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.55rem 0.9rem;
  background: rgba(26, 26, 36, 0.95);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  text-decoration: none;
  transition: all 0.25s ease;
  white-space: nowrap;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
  min-width: 160px;
}

.related-card:hover {
  background: rgba(0, 245, 212, 0.08);
  border-color: rgba(0, 245, 212, 0.3);
  transform: translateX(-4px);
}

.related-card-icon {
  font-size: 0.8rem;
  color: rgba(0, 245, 212, 0.5);
  width: 1.2rem;
  text-align: center;
  transition: color 0.25s ease;
}

.related-card:hover .related-card-icon {
  color: #00f5d4;
}

.related-card-title {
  font-family: 'Orbitron', sans-serif;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  color: rgba(255, 255, 255, 0.55);
  transition: color 0.25s ease;
}

.related-card:hover .related-card-title {
  color: rgba(255, 255, 255, 0.9);
}

/* モバイル調整 */
@media (max-width: 768px) {
  .related-trigger {
    bottom: 1.5rem;
    right: 1rem;
  }

  .related-toggle {
    width: 40px;
    height: 40px;
    font-size: 1rem;
  }

  .related-panel {
    bottom: 48px;
  }

  .related-card {
    padding: 0.5rem 0.75rem;
    min-width: 140px;
  }

  .related-card-title {
    font-size: 0.6rem;
  }
}
</style>

<script>
(function() {
  var toggle = document.getElementById('relatedToggle');
  var panel = document.getElementById('relatedPanel');
  if (!toggle || !panel) return;

  toggle.addEventListener('click', function(e) {
    e.stopPropagation();
    var isOpen = panel.classList.toggle('open');
    toggle.classList.toggle('active', isOpen);
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('.related-trigger')) {
      panel.classList.remove('open');
      toggle.classList.remove('active');
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      panel.classList.remove('open');
      toggle.classList.remove('active');
    }
  });
})();
</script>
