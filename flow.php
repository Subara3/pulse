<?php
require_once __DIR__ . '/includes/config.php';

// アクセス記録
recordPageView('flow');

$pageName = 'flow';
$pageTitle = 'Flow - PULSE';
$pageDesc = 'ゆっくり変化するグラデーション。ぼんやり眺めてください。';
$pageCanonical = 'https://pulse.subara3.com/flow.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  min-height: 100vh;
  overflow: hidden;
  font-family: "Orbitron", sans-serif;
}

.gradient-bg {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(-45deg, #0a0a0f, #1a1a2e, #16213e, #0f3460);
  background-size: 400% 400%;
  animation: gradient-flow 20s ease infinite;
}

@keyframes gradient-flow {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

.orbs {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
  pointer-events: none;
}

.orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.4;
  animation: orb-float 25s ease-in-out infinite;
}

.orb-1 {
  width: 500px;
  height: 500px;
  background: #00f5d4;
  top: -100px;
  left: -100px;
  animation-delay: 0s;
}

.orb-2 {
  width: 400px;
  height: 400px;
  background: #7b2cbf;
  bottom: -50px;
  right: -50px;
  animation-delay: -5s;
}

.orb-3 {
  width: 300px;
  height: 300px;
  background: #f72585;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  animation-delay: -10s;
}

.orb-4 {
  width: 350px;
  height: 350px;
  background: #4361ee;
  bottom: 20%;
  left: 20%;
  animation-delay: -15s;
}

@keyframes orb-float {
  0%, 100% { transform: translate(0, 0) scale(1); }
  25% { transform: translate(50px, -50px) scale(1.1); }
  50% { transform: translate(-30px, 30px) scale(0.9); }
  75% { transform: translate(30px, 50px) scale(1.05); }
}

.ui {
  position: fixed;
  top: 2rem;
  left: 2rem;
  z-index: 100;
}

.logo {
  font-size: 1.5rem;
  color: #fff;
  text-decoration: none;
  display: block;
  margin-bottom: 0.5rem;
  text-shadow: 0 0 20px rgba(0, 245, 212, 0.5);
}

.hint {
  color: rgba(255,255,255,0.4);
  font-size: 0.75rem;
  letter-spacing: 0.1em;
}

.time {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  color: rgba(255,255,255,0.3);
  font-size: 3rem;
  z-index: 100;
  font-weight: 700;
}

.back-link {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  color: rgba(255,255,255,0.3);
  text-decoration: none;
  font-size: 0.75rem;
  z-index: 100;
}

.back-link:hover {
  color: #fff;
}

.presets {
  position: fixed;
  top: 5rem;
  right: 2rem;
  z-index: 100;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.preset-btn {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 2px solid rgba(255,255,255,0.2);
  cursor: pointer;
  transition: all 0.3s;
}

.preset-btn:hover {
  border-color: #fff;
  transform: scale(1.1);
}

.preset-btn.active {
  border-color: #fff;
  box-shadow: 0 0 15px rgba(255,255,255,0.3);
}

.preset-ocean { background: linear-gradient(135deg, #0a0a0f, #16213e); }
.preset-sunset { background: linear-gradient(135deg, #2d1b4e, #c94b4b); }
.preset-forest { background: linear-gradient(135deg, #0a1a0a, #134e5e); }
.preset-night { background: linear-gradient(135deg, #0f0c29, #302b63); }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';

?>

<div class="gradient-bg" id="gradientBg"></div>

<div class="orbs" id="orbs">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
  <div class="orb orb-4"></div>
</div>

<div class="ui">
  <a href="index.php" class="logo">PULSE</a>
  <p class="hint">JUST BREATHE</p>
</div>

<div class="presets">
  <button class="preset-btn preset-ocean active" data-preset="ocean" title="Ocean"></button>
  <button class="preset-btn preset-sunset" data-preset="sunset" title="Sunset"></button>
  <button class="preset-btn preset-forest" data-preset="forest" title="Forest"></button>
  <button class="preset-btn preset-night" data-preset="night" title="Night"></button>
</div>

<div class="time" id="time">00:00</div>

<a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i></a>

<script>
const presets = {
  ocean: {
    bg: 'linear-gradient(-45deg, #0a0a0f, #1a1a2e, #16213e, #0f3460)',
    orbs: ['#00f5d4', '#7b2cbf', '#4cc9f0', '#4361ee']
  },
  sunset: {
    bg: 'linear-gradient(-45deg, #2d1b4e, #8b2f5f, #c94b4b, #f4a261)',
    orbs: ['#f72585', '#ff6b35', '#f4a261', '#7209b7']
  },
  forest: {
    bg: 'linear-gradient(-45deg, #0a1a0a, #134e5e, #2c5f2d, #1b4332)',
    orbs: ['#40916c', '#52b788', '#74c69d', '#2d6a4f']
  },
  night: {
    bg: 'linear-gradient(-45deg, #0f0c29, #302b63, #24243e, #0f0c29)',
    orbs: ['#6c63ff', '#9d4edd', '#5a189a', '#3c096c']
  }
};

function setPreset(name) {
  const preset = presets[name];
  const bg = document.getElementById('gradientBg');
  const orbs = document.querySelectorAll('.orb');
  
  bg.style.background = preset.bg;
  bg.style.backgroundSize = '400% 400%';
  
  orbs.forEach((orb, i) => {
    orb.style.background = preset.orbs[i % preset.orbs.length];
  });
  
  document.querySelectorAll('.preset-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.preset === name);
  });
}

function updateTime() {
  const now = new Date();
  const h = String(now.getHours()).padStart(2, '0');
  const m = String(now.getMinutes()).padStart(2, '0');
  document.getElementById('time').textContent = `${h}:${m}`;
}

document.querySelectorAll('.preset-btn').forEach(btn => {
  btn.addEventListener('click', () => setPreset(btn.dataset.preset));
});

updateTime();
setInterval(updateTime, 1000);
</script>

</body>
</html>
