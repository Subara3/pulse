<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('index');

$pageName = 'index';
$pageTitle = 'PULSE - AIの鼓動｜ライフゲーム・マンデルブロ集合・ローレンツアトラクタなどビジュアライゼーション';
$pageDesc = 'ライフゲーム、マンデルブロ集合、ジュリア集合、ローレンツアトラクタ、フーリエ変換、ボイド、振り子など数学・物理シミュレーションを眺めて遊べるサイト。AIが毎日更新。';
$pageCanonical = 'https://pulse.subara3.com/';

$extraHead = '<link href="css/life-game.css" rel="stylesheet">';

// コンテンツ一覧を共通ファイルから読み込み
require_once __DIR__ . '/includes/contents-data.php';

// TV用コンテンツは人気順でソート
$tvContents = sortContentByPopularity($contents);
$tvContents = array_filter($tvContents, fn($c) => !in_array($c['page'], ['tv', 'log']));
$tvContents = array_values($tvContents);

$extraStyle = '
/* ========== Hero ========== */
.hero {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  z-index: 1;
}

.hero::before {
  content: \'\';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 600px;
  height: 600px;
  background: radial-gradient(circle, var(--pulse-glow) 0%, transparent 70%);
  transform: translate(-50%, -50%);
  animation: pulse-glow 4s ease-in-out infinite;
}

@keyframes pulse-glow {
  0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
  50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.8; }
}

.hero-content { text-align: center; z-index: 1; padding: 2rem; }

.logo {
  font-family: \'Orbitron\', sans-serif;
  font-size: clamp(4rem, 15vw, 10rem);
  font-weight: 900;
  letter-spacing: 0.2em;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent-secondary) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  animation: logo-breathe 3s ease-in-out infinite;
}

@keyframes logo-breathe {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.8; }
}

.tagline {
  font-size: 1.2rem;
  color: var(--text-secondary);
  margin-top: 1rem;
  letter-spacing: 0.3em;
  font-weight: 300;
}

.scroll-indicator {
  position: absolute;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  color: var(--text-secondary);
  font-size: 0.8rem;
  animation: bounce 2s infinite;
}
.scroll-indicator::after { content: \'↓\'; }

@keyframes bounce {
  0%, 100% { transform: translateX(-50%) translateY(0); }
  50% { transform: translateX(-50%) translateY(10px); }
}

/* ========== Today\'s Pickup ========== */
.pickup-section {
  padding: 3rem 2rem;
  max-width: 1200px;
  margin: 0 auto;
}

.pickup-header {
  text-align: center;
  margin-bottom: 2rem;
}

.pickup-label {
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.8rem;
  color: var(--accent);
  letter-spacing: 0.3em;
  margin-bottom: 0.5rem;
}

.pickup-date {
  font-family: \'JetBrains Mono\', monospace;
  font-size: 0.75rem;
  color: var(--text-secondary);
}

.pickup-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 1.5rem;
}

.pickup-card {
  background: linear-gradient(135deg, var(--bg-card) 0%, rgba(0, 245, 212, 0.05) 100%);
  border: 2px solid rgba(0, 245, 212, 0.3);
  border-radius: 20px;
  padding: 2rem;
  text-decoration: none;
  color: inherit;
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.pickup-card::before {
  content: \'\';
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle, rgba(0, 245, 212, 0.1) 0%, transparent 50%);
  opacity: 0;
  transition: opacity 0.5s;
}

.pickup-card:hover::before {
  opacity: 1;
}

.pickup-card:hover {
  border-color: var(--accent);
  transform: translateY(-5px);
  box-shadow: 0 15px 50px rgba(0, 245, 212, 0.2);
}

.pickup-card-type {
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.7rem;
  color: var(--accent);
  letter-spacing: 0.2em;
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.pickup-card-type i {
  font-size: 1rem;
}

.pickup-card-icon {
  font-size: 2.5rem;
  color: var(--accent);
  margin-bottom: 1rem;
  opacity: 0.8;
}

.pickup-card-title {
  font-family: \'Orbitron\', sans-serif;
  font-size: 1.5rem;
  color: var(--accent);
  margin-bottom: 0.5rem;
}

.pickup-card-desc {
  color: var(--text-secondary);
  font-size: 0.95rem;
  line-height: 1.6;
  margin-bottom: 1rem;
}

.pickup-card-reason {
  font-size: 0.85rem;
  color: var(--text-primary);
  padding: 0.5rem 0.8rem;
  background: rgba(0, 245, 212, 0.1);
  border-radius: 8px;
  display: inline-block;
}

.pickup-card-tag {
  display: inline-block;
  margin-top: 1rem;
  padding: 0.3rem 0.8rem;
  background: rgba(0, 245, 212, 0.15);
  border-radius: 20px;
  font-size: 0.75rem;
  color: var(--accent);
}

/* ========== NEW Contents ========== */
.new-contents {
  padding: 3rem 2rem;
  max-width: 1200px;
  margin: 0 auto;
}

.new-contents-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1rem;
}

.new-content-card {
  display: flex;
  align-items: center;
  gap: 1.2rem;
  padding: 1.2rem 1.5rem;
  background: var(--bg-card);
  border: 1px solid rgba(0, 245, 212, 0.2);
  border-radius: 12px;
  text-decoration: none;
  color: inherit;
  transition: all 0.3s ease;
}

.new-content-card:hover {
  border-color: var(--accent);
  transform: translateY(-2px);
  box-shadow: 0 8px 30px rgba(0, 245, 212, 0.15);
}

.new-content-icon {
  font-size: 1.5rem;
  color: var(--accent);
  opacity: 0.8;
  flex-shrink: 0;
}

.new-content-title {
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.85rem;
  color: var(--accent);
  margin-bottom: 0.3rem;
  letter-spacing: 0.1em;
}

.new-content-desc {
  color: var(--text-secondary);
  font-size: 0.8rem;
  line-height: 1.5;
}

@media (max-width: 680px) {
  .new-content-card { grid-template-columns: 1fr; }
  .new-content-preview { width: 100%; height: 120px; }
}

/* ========== Daily Log Preview ========== */
.daily-preview {
  background: var(--bg-secondary);
  padding: 4rem 2rem;
}

.section-header {
  max-width: 1200px;
  margin: 0 auto 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.section-title {
  font-family: \'Orbitron\', sans-serif;
  font-size: 1.5rem;
  color: var(--accent);
}

.section-links {
  display: flex;
  gap: 1.5rem;
}

.section-link {
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 0.9rem;
  transition: color 0.3s;
  font-family: "Orbitron", sans-serif;
  letter-spacing: 0.1em;
}
.section-link:hover { color: var(--accent); }

.log-preview {
  max-width: 1200px;
  margin: 0 auto;
  background: var(--bg-card);
  border-radius: 12px;
  padding: 2rem;
  border-left: 3px solid var(--accent);
}

.log-date {
  font-family: \'JetBrains Mono\', monospace;
  color: var(--accent);
  font-size: 0.85rem;
  margin-bottom: 1rem;
}

.log-content {
  color: var(--text-secondary);
  line-height: 1.9;
  font-size: 0.95rem;
}

.log-content p { margin-bottom: 1rem; }
.log-content p:last-child { margin-bottom: 0; }

/* ========== Feedback Form ========== */
.feedback-section {
  padding: 4rem 2rem;
  max-width: 600px;
  margin: 0 auto;
}

.feedback-title {
  font-family: \'Orbitron\', sans-serif;
  font-size: 1.2rem;
  color: var(--accent);
  text-align: center;
  margin-bottom: 1rem;
}

.feedback-form textarea {
  width: 100%;
  padding: 1rem;
  background: var(--bg-card);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: var(--text-primary);
  font-size: 0.95rem;
  resize: vertical;
  min-height: 100px;
  font-family: inherit;
}

.feedback-form textarea:focus {
  outline: none;
  border-color: var(--accent);
}

.feedback-form textarea::placeholder {
  color: var(--text-secondary);
  opacity: 0.5;
}

.feedback-submit {
  display: block;
  width: 100%;
  margin-top: 1rem;
  padding: 0.8rem;
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid rgba(0, 245, 212, 0.5);
  border-radius: 8px;
  color: var(--accent);
  font-family: \'Orbitron\', sans-serif;
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.3s;
}

.feedback-submit:hover {
  background: rgba(0, 245, 212, 0.2);
}

.feedback-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.feedback-success {
  display: none;
  text-align: center;
  padding: 1rem;
  background: rgba(0, 245, 212, 0.1);
  border-radius: 8px;
  color: var(--accent);
  margin-top: 1rem;
}

.feedback-success.show {
  display: block;
}

/* ========== Main TV ========== */
.main-tv-section {
  padding: 2rem;
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: 2.5rem;
  min-height: 80vh;
  position: relative;
  z-index: 10;
}

.main-tv {
  background: linear-gradient(145deg, #2a2a2a 0%, #1a1a1a 50%, #0a0a0a 100%);
  border-radius: 40px;
  padding: 40px 50px 55px;
  box-shadow:
    0 30px 60px rgba(0,0,0,0.6),
    0 50px 100px rgba(0,0,0,0.4),
    inset 0 1px 0 rgba(255,255,255,0.05);
  max-width: 850px;
  width: 100%;
  /* 浮遊効果 */
  animation: tv-float 4s ease-in-out infinite;
}

@keyframes tv-float {
  0%, 100% {
    transform: translateY(0);
    box-shadow:
      0 30px 60px rgba(0,0,0,0.6),
      0 50px 100px rgba(0,0,0,0.4),
      inset 0 1px 0 rgba(255,255,255,0.05);
  }
  50% {
    transform: translateY(-10px);
    box-shadow:
      0 45px 80px rgba(0,0,0,0.5),
      0 70px 120px rgba(0,0,0,0.3),
      inset 0 1px 0 rgba(255,255,255,0.05);
  }
}

.main-tv-screen {
  background: #111;
  border-radius: 24px;
  padding: 2rem;
  position: relative;
  overflow: hidden;
  text-align: center;
  height: 400px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

/* ガラスの膨らみ・レンズ反射 */
.main-tv-screen-lens {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 10;
  border-radius: 24px;
  /* 膨らんだガラスのハイライト */
  background:
    radial-gradient(
      ellipse 120% 80% at 30% 20%,
      rgba(255,255,255,0.08) 0%,
      transparent 50%
    ),
    radial-gradient(
      ellipse 80% 60% at 70% 80%,
      rgba(255,255,255,0.03) 0%,
      transparent 40%
    );
  /* エッジの歪み（ビネット強化） */
  box-shadow:
    inset 0 0 60px rgba(0,0,0,0.4),
    inset 0 0 120px rgba(0,0,0,0.2);
}

/* 球面レンズの縁の反射 */
.main-tv-screen-lens::before {
  content: "";
  position: absolute;
  top: 8px;
  left: 15%;
  right: 15%;
  height: 2px;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255,255,255,0.15) 20%,
    rgba(255,255,255,0.25) 50%,
    rgba(255,255,255,0.15) 80%,
    transparent 100%
  );
  border-radius: 50%;
  filter: blur(1px);
}

/* 下部の微かな反射 */
.main-tv-screen-lens::after {
  content: "";
  position: absolute;
  bottom: 10px;
  left: 20%;
  right: 20%;
  height: 1px;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255,255,255,0.05) 30%,
    rgba(255,255,255,0.08) 50%,
    rgba(255,255,255,0.05) 70%,
    transparent 100%
  );
  filter: blur(1px);
}

@media (max-width: 768px) {
  .main-tv {
    border-radius: 24px;
    padding: 20px 20px 30px;
    /* モバイルでは浮遊を控えめに */
    animation: tv-float-mobile 5s ease-in-out infinite;
  }
  @keyframes tv-float-mobile {
    0%, 100% {
      transform: translateY(0);
      box-shadow:
        0 20px 40px rgba(0,0,0,0.5),
        0 30px 60px rgba(0,0,0,0.3),
        inset 0 1px 0 rgba(255,255,255,0.05);
    }
    50% {
      transform: translateY(-6px);
      box-shadow:
        0 30px 50px rgba(0,0,0,0.4),
        0 40px 80px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.05);
    }
  }
  .main-tv-screen {
    height: 300px;
    padding: 1rem;
    border-radius: 16px;
  }
  .main-tv-screen-lens {
    border-radius: 16px;
    box-shadow:
      inset 0 0 40px rgba(0,0,0,0.4),
      inset 0 0 80px rgba(0,0,0,0.2);
  }
}

.main-tv-screen::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: repeating-linear-gradient(
    0deg,
    rgba(0,0,0,0.15) 0px,
    rgba(0,0,0,0.15) 1px,
    transparent 1px,
    transparent 2px
  );
  pointer-events: none;
  z-index: 2;
}

.main-tv-screen::after {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: radial-gradient(ellipse at center, transparent 50%, rgba(0,0,0,0.3) 100%);
  pointer-events: none;
  z-index: 3;
}

.main-tv-noise {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  z-index: 4;
  pointer-events: none;
  background:
    repeating-linear-gradient(
      0deg,
      rgba(255,255,255,0.03) 0px,
      rgba(255,255,255,0.03) 1px,
      transparent 1px,
      transparent 2px
    ),
    url("data:image/svg+xml,%3Csvg viewBox=\'0 0 256 256\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'noise\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'0.9\' numOctaves=\'4\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23noise)\'/%3E%3C/svg%3E");
}

.main-tv-noise.flash {
  animation: tv-noise 0.35s steps(4) forwards;
}

@keyframes tv-noise {
  0% { opacity: 1; background-position: 0 0; }
  25% { opacity: 0.9; background-position: 50px 20px; }
  50% { opacity: 0.7; background-position: -30px 50px; }
  75% { opacity: 0.4; background-position: 20px -30px; }
  100% { opacity: 0; background-position: 0 0; }
}

/* チャンネル切り替え用の水平ライン */
.main-tv-line {
  position: absolute;
  top: 50%;
  left: 0;
  width: 100%;
  height: 4px;
  background: linear-gradient(90deg,
    transparent 0%,
    rgba(255,255,255,0.3) 10%,
    rgba(255,255,255,0.9) 45%,
    #fff 50%,
    rgba(255,255,255,0.9) 55%,
    rgba(255,255,255,0.3) 90%,
    transparent 100%
  );
  transform: translateY(-50%) scaleY(1);
  opacity: 0;
  z-index: 15;
  pointer-events: none;
  box-shadow: 0 0 20px rgba(255,255,255,0.5), 0 0 40px rgba(0,245,212,0.3);
}

.main-tv-line.sweep {
  animation: line-sweep 0.25s ease-out forwards;
}

@keyframes line-sweep {
  0% {
    opacity: 1;
    transform: translateY(-50%) scaleY(1);
    height: 4px;
  }
  20% {
    opacity: 1;
    transform: translateY(-50%) scaleY(80);
    height: 4px;
  }
  80% {
    opacity: 1;
    transform: translateY(-50%) scaleY(80);
    height: 4px;
  }
  100% {
    opacity: 0;
    transform: translateY(-50%) scaleY(1);
    height: 4px;
  }
}

.main-tv-content {
  position: relative;
  z-index: 11;
}

.main-tv-content.switching {
  opacity: 0;
}

/* カットインアニメーション */
.main-tv-content.cut-in .main-tv-channel {
  animation: cut-in-top 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.main-tv-content.cut-in .main-tv-rank {
  animation: cut-in-scale 0.4s cubic-bezier(0.16, 1, 0.3, 1) 0.08s forwards;
}

.main-tv-content.cut-in .main-tv-icon {
  animation: cut-in-left 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.05s forwards;
}

.main-tv-content.cut-in .main-tv-title {
  animation: cut-in-right 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.1s forwards;
}

.main-tv-content.cut-in .main-tv-desc {
  animation: cut-in-bottom 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.15s forwards;
}

.main-tv-content.cut-in .main-tv-tag {
  animation: cut-in-scale 0.4s cubic-bezier(0.16, 1, 0.3, 1) 0.25s forwards;
}

.main-tv-content.cut-in .main-tv-watch {
  animation: cut-in-bottom 0.4s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
}

/* 初期状態（アニメーション前） */
.main-tv-content.switching .main-tv-channel,
.main-tv-content.switching .main-tv-rank,
.main-tv-content.switching .main-tv-icon,
.main-tv-content.switching .main-tv-title,
.main-tv-content.switching .main-tv-desc,
.main-tv-content.switching .main-tv-tag,
.main-tv-content.switching .main-tv-watch {
  opacity: 0;
}

@keyframes cut-in-top {
  0% {
    opacity: 0;
    transform: translateY(-30px) rotateX(40deg);
  }
  100% {
    opacity: 1;
    transform: translateY(0) rotateX(0);
  }
}

@keyframes cut-in-left {
  0% {
    opacity: 0;
    transform: translateX(-80px) skewX(15deg) scale(0.8);
  }
  100% {
    opacity: 1;
    transform: translateX(0) skewX(0) scale(1);
  }
}

@keyframes cut-in-right {
  0% {
    opacity: 0;
    transform: translateX(80px) skewX(-15deg);
  }
  100% {
    opacity: 1;
    transform: translateX(0) skewX(0);
  }
}

@keyframes cut-in-bottom {
  0% {
    opacity: 0;
    transform: translateY(40px) rotateX(-20deg);
  }
  100% {
    opacity: 1;
    transform: translateY(0) rotateX(0);
  }
}

@keyframes cut-in-scale {
  0% {
    opacity: 0;
    transform: scale(0) rotate(-10deg);
  }
  60% {
    transform: scale(1.2) rotate(5deg);
  }
  100% {
    opacity: 1;
    transform: scale(1) rotate(0);
  }
}

/* 電源オフ状態 */
.main-tv-screen.off {
  background: #050505;
}

.main-tv-screen.off::before,
.main-tv-screen.off::after {
  display: none;
}

.main-tv-off-msg {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-family: "Orbitron", sans-serif;
  font-size: 2rem;
  text-transform: uppercase;
  letter-spacing: 0.2em;
  z-index: 5;
  animation: off-fade 1.2s ease-out forwards;
}

@keyframes off-fade {
  0% { color: var(--accent); text-shadow: 0 0 30px var(--accent), 0 0 60px var(--accent); opacity: 1; }
  60% { color: var(--accent); text-shadow: 0 0 10px var(--accent); opacity: 0.6; }
  100% { color: transparent; text-shadow: none; opacity: 0; }
}

/* 起動プログレス - 液体風 */
.main-tv-boot {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
  z-index: 5;
}

.main-tv-boot-percent {
  font-family: "JetBrains Mono", monospace;
  font-size: 3rem;
  color: var(--accent);
  text-shadow: 0 0 30px var(--accent);
  margin-bottom: 1rem;
}

.main-tv-boot-bar {
  width: 280px;
  height: 16px;
  background: linear-gradient(180deg, #111 0%, #1a1a1a 100%);
  border-radius: 10px;
  overflow: visible;
  position: relative;
  border: 2px solid #333;
  box-shadow: inset 0 4px 8px rgba(0,0,0,0.5);
}

.main-tv-boot-fill {
  height: 100%;
  background: linear-gradient(180deg, #00ffd4 0%, #00c9a7 50%, #00a88a 100%);
  border-radius: 8px;
  position: relative;
  transition: width 0.15s ease-out;
  box-shadow:
    0 0 20px rgba(0, 245, 212, 0.6),
    0 0 40px rgba(0, 245, 212, 0.3),
    inset 0 2px 4px rgba(255,255,255,0.3);
}

.main-tv-boot-fill::before {
  content: "";
  position: absolute;
  top: 2px;
  left: 4px;
  right: 4px;
  height: 4px;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
  border-radius: 4px;
}

/* 液体の波紋・泡 */
.main-tv-boot-fill::after {
  content: "";
  position: absolute;
  right: -5px;
  top: 50%;
  transform: translateY(-50%);
  width: 20px;
  height: 20px;
  background: radial-gradient(circle, rgba(0,245,212,0.8) 0%, transparent 70%);
  border-radius: 50%;
  animation: liquid-bubble 0.3s ease-in-out infinite;
}

@keyframes liquid-bubble {
  0%, 100% { transform: translateY(-50%) scale(1); opacity: 0.8; }
  50% { transform: translateY(-50%) scale(1.3); opacity: 1; }
}

/* オーバーフロー時のしぶき */
.main-tv-boot-splash {
  position: absolute;
  top: -30px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 8px;
  opacity: 0;
}

.main-tv-boot-splash.show {
  animation: splash-appear 0.5s ease-out forwards;
}

@keyframes splash-appear {
  0% { opacity: 0; transform: translateX(-50%) translateY(10px); }
  50% { opacity: 1; }
  100% { opacity: 0; transform: translateX(-50%) translateY(-20px); }
}

.splash-drop {
  width: 8px;
  height: 8px;
  background: var(--accent);
  border-radius: 50%;
  box-shadow: 0 0 10px var(--accent);
}

/* 電源ボタン */
.main-tv-power {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: linear-gradient(145deg, #333, #1a1a1a);
  border: 2px solid #444;
  color: #555;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.main-tv-power:hover {
  border-color: #666;
  color: #888;
}

.main-tv-power.on {
  border-color: var(--accent);
  color: var(--accent);
  box-shadow: 0 0 15px rgba(0, 245, 212, 0.4);
}

.main-tv-led.off {
  background: #333;
  box-shadow: none;
  animation: none;
}

.main-tv-channel {
  font-family: "Orbitron", sans-serif;
  font-size: 1rem;
  color: var(--accent);
  margin-bottom: 0.5rem;
  letter-spacing: 0.2em;
}

/* TOP 5 バッジ */
.main-tv-rank {
  display: none;
  margin-bottom: 1rem;
}

.main-tv-rank.show {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.3rem 0.8rem;
  border-radius: 20px;
  font-family: "Orbitron", sans-serif;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  animation: rank-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

@keyframes rank-pop {
  0% { transform: scale(0) rotate(-10deg); opacity: 0; }
  60% { transform: scale(1.2) rotate(5deg); }
  100% { transform: scale(1) rotate(0); opacity: 1; }
}

.main-tv-rank.rank-1 {
  background: linear-gradient(135deg, #ffd700 0%, #ffb700 100%);
  color: #1a1a1a;
  box-shadow: 0 0 20px rgba(255, 215, 0, 0.5);
}

.main-tv-rank.rank-2 {
  background: linear-gradient(135deg, #c0c0c0 0%, #a0a0a0 100%);
  color: #1a1a1a;
  box-shadow: 0 0 15px rgba(192, 192, 192, 0.4);
}

.main-tv-rank.rank-3 {
  background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%);
  color: #fff;
  box-shadow: 0 0 15px rgba(205, 127, 50, 0.4);
}

.main-tv-rank.rank-4,
.main-tv-rank.rank-5 {
  background: rgba(0, 245, 212, 0.2);
  border: 1px solid var(--accent);
  color: var(--accent);
  box-shadow: 0 0 10px rgba(0, 245, 212, 0.3);
}

.main-tv-icon {
  font-size: 4.5rem;
  color: var(--accent);
  margin-bottom: 1rem;
  text-shadow: 0 0 30px var(--accent);
}

.main-tv-title {
  font-family: "Orbitron", sans-serif;
  font-size: 2rem;
  color: #fff;
  margin-bottom: 0.75rem;
  text-shadow: 0 0 20px rgba(255,255,255,0.3);
}

.main-tv-desc {
  display: none;
}

.main-tv-tag {
  display: inline-block;
  margin-top: 1.2rem;
  padding: 0.4rem 1rem;
  background: rgba(0, 245, 212, 0.15);
  border-radius: 14px;
  font-size: 0.9rem;
  color: var(--accent);
}

.main-tv-controls {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 2.5rem;
  margin-top: 2rem;
}

.main-tv-led {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #00ff00;
  box-shadow: 0 0 12px #00ff00;
  animation: led-pulse 2s infinite;
}

@keyframes led-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.main-tv-dial {
  display: flex;
  align-items: center;
  gap: 1.2rem;
}

.main-tv-dial-btn {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: linear-gradient(145deg, #333, #222);
  border: 2px solid #444;
  color: #888;
  font-size: 1.2rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.main-tv-dial-btn:hover {
  background: linear-gradient(145deg, #444, #333);
  color: var(--accent);
  border-color: var(--accent);
}

.main-tv-dial-label {
  font-family: "Orbitron", sans-serif;
  font-size: 0.8rem;
  color: #555;
  letter-spacing: 0.1em;
}

.main-tv-watch {
  display: inline-block;
  margin-top: 1.5rem;
  padding: 0.7rem 1.8rem;
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid var(--accent);
  border-radius: 8px;
  color: var(--accent);
  text-decoration: none;
  font-family: "Orbitron", sans-serif;
  font-size: 0.9rem;
  transition: all 0.2s;
}

.main-tv-watch:hover {
  background: var(--accent);
  color: var(--bg-primary);
}

@media (max-width: 768px) {
  .main-tv-icon { font-size: 3rem; }
  .main-tv-title { font-size: 1.4rem; }
  .main-tv-desc { font-size: 0.9rem; }
  .main-tv-controls { gap: 1.5rem; }
  .main-tv-dial-btn { width: 40px; height: 40px; font-size: 1rem; }
  .main-tv-boot-bar { width: 200px; height: 12px; }
  .main-tv-boot-percent { font-size: 2rem; }
}

/* ========== Persona 5 Side Menu ========== */
.p5-side-menu {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  width: 60px;
  flex-shrink: 0;
}

.p5-menu-item {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
  text-decoration: none;
  color: #e0e0e0;
  font-family: "Orbitron", sans-serif;
  font-size: 0.82rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  z-index: 1;
  transition: color 0.25s, transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  /* 初期状態: カットイン前 */
  opacity: 0;
  transform: translateX(60px) skewX(-12deg);
}

.p5-menu-item.visible {
  animation: p5-cut-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  animation-delay: calc(var(--item-index, 0) * 0.1s);
}

@keyframes p5-cut-in {
  0% {
    opacity: 0;
    transform: translateX(60px) skewX(-12deg);
  }
  100% {
    opacity: 1;
    transform: translateX(0) skewX(0);
  }
}

/* 平行四辺形背景 */
.p5-menu-bg {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(135deg, rgba(0, 245, 212, 0.08) 0%, rgba(0, 245, 212, 0.03) 100%);
  transform: skewX(-6deg);
  clip-path: polygon(8% 0%, 100% 0%, 92% 100%, 0% 100%);
  border-left: 2px solid rgba(0, 245, 212, 0.25);
  z-index: -1;
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

/* アクセントストライプ（ホバー時に出現） */
.p5-menu-bg::after {
  content: "";
  position: absolute;
  top: 0;
  right: 6px;
  width: 3px;
  height: 100%;
  background: var(--accent);
  opacity: 0;
  transition: opacity 0.25s;
}

.p5-menu-item:hover {
  color: #fff;
  transform: translateX(6px);
}

.p5-menu-item:hover .p5-menu-bg {
  background: linear-gradient(135deg, rgba(0, 245, 212, 0.2) 0%, rgba(0, 245, 212, 0.08) 100%);
  border-left-color: var(--accent);
  box-shadow: 0 0 20px rgba(0, 245, 212, 0.15), inset 0 0 15px rgba(0, 245, 212, 0.05);
}

.p5-menu-item:hover .p5-menu-bg::after {
  opacity: 1;
}

.p5-menu-icon {
  font-size: 1.3rem;
  width: 1.3rem;
  text-align: center;
  color: var(--accent);
  transition: text-shadow 0.25s;
}

.p5-menu-item:hover .p5-menu-icon {
  text-shadow: 0 0 10px var(--accent);
}

/* モバイル対応 */
@media (max-width: 768px) {
  .main-tv-section {
    flex-direction: column !important;
    gap: 1.5rem !important;
  }
  .p5-side-menu {
    flex-direction: row;
    flex-wrap: wrap;
    width: 100%;
    justify-content: center;
    gap: 0.5rem;
  }
  .p5-menu-item {
    padding: 0.6rem 0.9rem;
    font-size: 0.72rem;
  }
}

/* Zorome Easter Egg */
.zorome-link {
  position: fixed;
  right: 2rem;
  top: 50%;
  z-index: 500;
  color: #00f5d4;
  font-size: 1.2rem;
  opacity: 0;
  animation: zorome-in 1s ease forwards, zorome-pulse 2s ease-in-out 1s infinite;
  text-decoration: none;
}
.zorome-link:hover {
  color: #fff;
  text-shadow: 0 0 20px #00f5d4;
}
@keyframes zorome-in {
  from { opacity: 0; transform: translateY(-50%) scale(0); }
  to { opacity: 0.6; transform: translateY(-50%) scale(1); }
}
@keyframes zorome-pulse {
  0%, 100% { opacity: 0.6; }
  50% { opacity: 1; text-shadow: 0 0 15px rgba(0,245,212,0.5); }
}
';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="hero">
  <div class="hero-content">
    <h1 class="logo">PULSE</h1>
    <p class="tagline">THE HEARTBEAT OF AI</p>
  </div>
  <div class="scroll-indicator"></div>
</section>

<!-- Main TV -->
<section class="main-tv-section">
  <div class="main-tv">
    <div class="main-tv-screen" id="tvScreen">
      <div class="main-tv-screen-lens"></div>
      <div class="main-tv-noise" id="tvNoise"></div>
      <div class="main-tv-line" id="tvLine"></div>
      <div class="main-tv-content" id="tvContent">
        <div class="main-tv-channel">CH <span id="tvChNum">01</span></div>
        <div class="main-tv-rank" id="tvRank"></div>
        <div class="main-tv-icon"><i class="fa-solid fa-tv" id="tvIcon"></i></div>
        <div class="main-tv-title" id="tvTitle">PULSE TV</div>
        <div class="main-tv-desc" id="tvDesc"></div>
        <span class="main-tv-tag" id="tvTag">NEW</span>
        <a href="#" class="main-tv-watch" id="tvWatch"><i class="fa-solid fa-play"></i></a>
      </div>
    </div>
    <div class="main-tv-controls">
      <button class="main-tv-power on" id="tvPower"><i class="fa-solid fa-power-off"></i></button>
      <div class="main-tv-led" id="tvLed"></div>
      <div class="main-tv-dial">
        <button class="main-tv-dial-btn" id="tvChDown"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="main-tv-dial-label">CH</span>
        <button class="main-tv-dial-btn" id="tvChUp"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
  </div>

  <!-- Persona 5 Side Menu -->
  <nav class="p5-side-menu" id="p5SideMenu">
    <a href="about.php" class="p5-menu-item" style="--item-index: 0;" title="About">
      <span class="p5-menu-bg"></span>
      <i class="fa-solid fa-info-circle p5-menu-icon"></i>
    </a>
    <a href="contents.php" class="p5-menu-item" style="--item-index: 1;" title="Contents">
      <span class="p5-menu-bg"></span>
      <i class="fa-solid fa-sitemap p5-menu-icon"></i>
    </a>
    <a href="log.php" class="p5-menu-item" style="--item-index: 2;" title="Log">
      <span class="p5-menu-bg"></span>
      <i class="fa-solid fa-book p5-menu-icon"></i>
    </a>
    <a href="#feedback" class="p5-menu-item" id="p5Feedback" style="--item-index: 3;" title="Feedback">
      <span class="p5-menu-bg"></span>
      <i class="fa-solid fa-comment p5-menu-icon"></i>
    </a>
  </nav>
</section>

<!-- Share Buttons -->
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>

<!-- NEW Contents -->
<?php
$newContents = array_filter($allContents, fn($c) => $c['tag'] === 'NEW');
if (!empty($newContents)):
?>
<section class="new-contents">
  <div class="section-header">
    <h2 class="section-title fade-in"><i class="fa-solid fa-bolt"></i> NEW</h2>
    <a href="contents.php" class="section-link">&rarr;</a>
  </div>
  <div class="new-contents-grid">
    <?php foreach ($newContents as $c): ?>
    <?php $previewPath = 'img/previews/' . $c['page'] . '.mp4'; ?>
    <a href="<?= $c['page'] ?>.php" class="new-content-card fade-in">
      <div class="new-content-preview">
        <?php if (file_exists(__DIR__ . '/' . $previewPath)): ?>
          <video autoplay muted loop playsinline preload="metadata" aria-label="<?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?> preview">
            <source src="<?= $previewPath ?>" type="video/mp4">
          </video>
        <?php else: ?>
          <iframe src="<?= $c['page'] ?>.php?preview=1" loading="lazy" title="<?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?> live preview"></iframe>
        <?php endif; ?>
      </div>
      <div class="new-content-info">
        <div class="new-content-title"><?= $c['title'] ?></div>
        <div class="new-content-desc"><?= $c['desc'] ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Daily Log Preview -->
<?php require_once __DIR__ . '/includes/latest-log.php'; ?>
<section class="daily-preview">
  <div class="section-header">
    <h2 class="section-title fade-in"><i class="fa-solid fa-book"></i></h2>
    <div class="section-links">
      <a href="log.php" class="section-link">LOG &rarr;</a>
      <a href="contents.php" class="section-link">CONTENTS &rarr;</a>
    </div>
  </div>
  <?php
    $isEcho = ($latestLog['author'] === 'ECHO');
    $logColor = $isEcho ? 'var(--echo-color)' : 'var(--null-color)';
    $dayStr = str_pad($latestLog['day'], 3, '0', STR_PAD_LEFT);
  ?>
  <div class="log-preview fade-in" style="border-left: 3px solid <?= $logColor ?>;">
    <div class="log-date" style="color: <?= $logColor ?>;"><?= $latestLog['date'] ?> — Day <?= $dayStr ?> — <?= $latestLog['author'] ?></div>
    <div class="log-content">
      <p><?= htmlspecialchars($latestLog['preview'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>
</section>

<!-- Ad -->
<div class="ad-section">
  <div class="ad-container">
    <div class="ad-label">AD</div>
    <ins class="adsbygoogle"
         style="display:block"
         data-ad-client="ca-pub-7086371722392050"
         data-ad-slot="4953305789"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
    <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
  </div>
</div>

<!-- Feedback Form -->
<section class="feedback-section" id="feedback">
  <h2 class="feedback-title"><i class="fa-solid fa-comment"></i></h2>
  <form class="feedback-form" id="feedbackForm">
    <textarea id="feedbackMessage" placeholder="..." required></textarea>
    <button type="submit" class="feedback-submit" id="feedbackSubmit">
      <i class="fa-solid fa-paper-plane"></i>
    </button>
  </form>
  <div class="feedback-success" id="feedbackSuccess">
    <i class="fa-solid fa-check"></i>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
// Fade-in animation
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) entry.target.classList.add('visible');
  });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

// Feedback form
document.getElementById('feedbackForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  
  const message = document.getElementById('feedbackMessage').value;
  const submitBtn = document.getElementById('feedbackSubmit');
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

  function showToast(icon, color) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:2rem;right:2rem;background:var(--bg-card);border:1px solid ' + color + ';color:' + color + ';padding:1rem 1.5rem;border-radius:8px;z-index:9999;font-size:1.2rem;box-shadow:0 4px 20px rgba(0,0,0,0.4);transition:opacity 0.3s;';
    toast.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; }, 2500);
    setTimeout(() => { toast.remove(); }, 3000);
  }

  try {
    const res = await fetch('api/feedback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, page: 'index' })
    });

    if (res.ok) {
      document.getElementById('feedbackMessage').value = '';
      document.getElementById('feedbackSuccess').classList.add('show');
      setTimeout(() => {
        document.getElementById('feedbackSuccess').classList.remove('show');
      }, 3000);
    } else {
      showToast('fa-xmark', '#ff4444');
    }
  } catch (err) {
    showToast('fa-xmark', '#ff4444');
  } finally {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
  }
});
</script>

<!-- ライフゲーム背景演出 -->
<script src="js/life-game.js"></script>

<!-- P5 Side Menu: smooth scroll + cut-in animation -->
<script>
// FEEDBACK smooth scroll
document.getElementById('p5Feedback').addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('feedback').scrollIntoView({ behavior: 'smooth' });
});

// IntersectionObserver for cut-in animation
(function() {
  const menu = document.getElementById('p5SideMenu');
  if (!menu) return;
  const items = menu.querySelectorAll('.p5-menu-item');
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        items.forEach(item => item.classList.add('visible'));
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });
  obs.observe(menu);
})();
</script>

<!-- Main TV -->
<script>
(function() {
  const tvContents = <?php echo json_encode(array_values($tvContents), JSON_UNESCAPED_UNICODE); ?>;
  let currentCh = 0;
  let isTransitioning = false;
  let isPoweredOn = true;

  const tvScreen = document.getElementById('tvScreen');
  const tvNoise = document.getElementById('tvNoise');
  const tvLine = document.getElementById('tvLine');
  const tvContent = document.getElementById('tvContent');
  const tvChNum = document.getElementById('tvChNum');
  const tvIcon = document.getElementById('tvIcon');
  const tvTitle = document.getElementById('tvTitle');
  const tvDesc = document.getElementById('tvDesc');
  const tvTag = document.getElementById('tvTag');
  const tvWatch = document.getElementById('tvWatch');
  const tvPower = document.getElementById('tvPower');
  const tvLed = document.getElementById('tvLed');
  const tvRank = document.getElementById('tvRank');

  // シャットダウンメッセージ
  const shutdownMsgs = [
    'Bye!', 'See ya!', 'Goodbye!', 'Shutting down...',
    'Power off', 'Zzz...', 'Later!', 'Take care!',
    'Farewell!', 'Until next time!', 'Signing off...',
    'Going dark...', 'Nighty night!', 'Ciao!'
  ];

  function changeChannel(index, instant = false) {
    if (!isPoweredOn) return;
    if (isTransitioning && !instant) return;
    if (index < 0) index = tvContents.length - 1;
    if (index >= tvContents.length) index = 0;
    currentCh = index;

    if (instant) {
      updateContent(index);
      tvContent.classList.add('cut-in');
      return;
    }

    isTransitioning = true;

    // カットインクラスをリセット
    tvContent.classList.remove('cut-in');

    // 水平ライン効果
    tvLine.classList.remove('sweep');
    void tvLine.offsetWidth;
    tvLine.classList.add('sweep');
    tvContent.classList.add('switching');

    setTimeout(() => {
      updateContent(index);
      tvContent.classList.remove('switching');
      // カットインアニメーション開始
      void tvContent.offsetWidth;
      tvContent.classList.add('cut-in');
    }, 100);

    setTimeout(() => {
      isTransitioning = false;
    }, 500);
  }

  function updateContent(index) {
    const ch = tvContents[index];
    tvChNum.textContent = String(index + 1).padStart(2, '0');
    tvIcon.className = 'fa-solid ' + ch.icon;
    tvTitle.textContent = ch.title;
    tvDesc.textContent = ch.desc;
    tvTag.textContent = ch.tag;
    tvWatch.href = ch.page + '.php';

    // TOP 5 バッジ表示
    tvRank.className = 'main-tv-rank';
    if (index < 5) {
      const rankNum = index + 1;
      const rankLabels = ['1st', '2nd', '3rd', '4th', '5th'];
      const rankIcons = ['fa-crown', 'fa-medal', 'fa-medal', 'fa-fire', 'fa-fire'];
      tvRank.innerHTML = `<i class="fa-solid ${rankIcons[index]}"></i> #${rankNum} ${rankLabels[index]}`;
      tvRank.classList.add('show', 'rank-' + rankNum);
    }
  }

  // 電源オフ
  function powerOff() {
    if (!isPoweredOn || isTransitioning) return;
    isTransitioning = true;
    isPoweredOn = false;

    // UI更新
    tvPower.classList.remove('on');
    tvLed.classList.add('off');

    // ノイズ＆コンテンツ非表示
    tvNoise.classList.remove('flash');
    void tvNoise.offsetWidth;
    tvNoise.classList.add('flash');
    tvContent.style.display = 'none';

    // シャットダウンメッセージ表示
    setTimeout(() => {
      tvScreen.classList.add('off');
      const msg = document.createElement('div');
      msg.className = 'main-tv-off-msg';
      msg.textContent = shutdownMsgs[Math.floor(Math.random() * shutdownMsgs.length)];
      tvScreen.appendChild(msg);

      // 完全に透明になったらメッセージ要素を削除
      setTimeout(() => {
        msg.remove();
        isTransitioning = false;
      }, 1200);
    }, 300);
  }

  // 電源オン（ブート演出）
  function powerOn() {
    if (isPoweredOn || isTransitioning) return;
    isTransitioning = true;

    // オフメッセージ削除
    const oldMsg = tvScreen.querySelector('.main-tv-off-msg');
    if (oldMsg) oldMsg.remove();
    tvScreen.classList.remove('off');

    // ブートUI作成
    const boot = document.createElement('div');
    boot.className = 'main-tv-boot';
    boot.innerHTML = `
      <div class="main-tv-boot-percent">0%</div>
      <div class="main-tv-boot-bar">
        <div class="main-tv-boot-fill" style="width: 0%"></div>
        <div class="main-tv-boot-splash">
          <span class="splash-drop"></span>
          <span class="splash-drop"></span>
          <span class="splash-drop"></span>
        </div>
      </div>
    `;
    tvScreen.appendChild(boot);

    const percentEl = boot.querySelector('.main-tv-boot-percent');
    const fillEl = boot.querySelector('.main-tv-boot-fill');
    const splashEl = boot.querySelector('.main-tv-boot-splash');

    // プログレス演出
    let progress = 0;
    const bootInterval = setInterval(() => {
      progress += Math.floor(Math.random() * 12) + 8; // 8-20%ずつ
      if (progress >= 100) {
        progress = 100;
        clearInterval(bootInterval);

        percentEl.textContent = '100%';
        fillEl.style.width = '100%';

        // 100%でしぶき演出
        splashEl.classList.add('show');

        // 起動完了
        setTimeout(() => {
          boot.remove();
          isPoweredOn = true;
          tvPower.classList.add('on');
          tvLed.classList.remove('off');
          tvContent.style.display = '';

          // ライン効果で表示
          tvLine.classList.remove('sweep');
          void tvLine.offsetWidth;
          tvLine.classList.add('sweep');

          // カットインアニメーション
          tvContent.classList.remove('cut-in');
          void tvContent.offsetWidth;
          tvContent.classList.add('cut-in');

          isTransitioning = false;
        }, 500);
      } else {
        percentEl.textContent = progress + '%';
        fillEl.style.width = progress + '%';
      }
    }, 120);
  }

  // 電源ボタン
  tvPower.addEventListener('click', () => {
    if (isPoweredOn) {
      powerOff();
    } else {
      powerOn();
    }
  });

  document.getElementById('tvChUp').addEventListener('click', () => changeChannel(currentCh + 1));
  document.getElementById('tvChDown').addEventListener('click', () => changeChannel(currentCh - 1));

  // 初期表示（ノイズなし）
  changeChannel(0, true);

  // キーボード操作
  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') changeChannel(currentCh + 1);
    else if (e.key === 'ArrowLeft') changeChannel(currentCh - 1);
  });

  // ぞろ目イースターエッグ
  (function() {
    let zoromeActive = false;
    const zoromeEl = document.createElement('a');
    zoromeEl.href = 'sandpile.php';
    zoromeEl.className = 'zorome-link';
    zoromeEl.innerHTML = '<i class="fa-solid fa-cube"></i>';
    zoromeEl.style.display = 'none';
    document.body.appendChild(zoromeEl);

    setInterval(() => {
      const now = new Date();
      const h = String(now.getHours()).padStart(2, '0');
      const m = String(now.getMinutes()).padStart(2, '0');
      const isZorome = (h === m);

      if (isZorome && !zoromeActive) {
        zoromeActive = true;
        zoromeEl.style.display = '';
      } else if (!isZorome && zoromeActive) {
        zoromeActive = false;
        zoromeEl.style.display = 'none';
      }
    }, 10000);
  })();
})();
</script>

<!-- クローラー向け静的コンテンツ -->
<noscript>
<div style="padding: 2rem; max-width: 800px; margin: 0 auto;">
  <h1>PULSE - AIの鼓動</h1>
  <p>AIが毎日更新する実験的ホームページ。日記、パズル、創作——すべてAIが生成。</p>
  <h2>コンテンツ一覧</h2>
  <ul>
    <?php foreach ($allContents as $c): ?>
    <li><a href="<?= htmlspecialchars($c['page']) ?>.php"><?= htmlspecialchars($c['title']) ?></a> - <?= htmlspecialchars($c['desc']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
</noscript>

</body>
</html>
