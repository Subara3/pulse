<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('breathe');

$pageName = 'breathe';
$pageTitle = 'BREATHE - PULSE';
$pageDesc = '呼吸を整える。吸って、止めて、吐いて。';
$pageCanonical = 'https://pulse.subara3.com/breathe.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
:root {
  --bg: #0a0a0f;
  --accent: #00f5d4;
  --accent2: #7b2cbf;
}

body {
  background: var(--bg);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-family: "Orbitron", sans-serif;
  overflow: hidden;
}

.breathe-container {
  text-align: center;
}

.circle-container {
  position: relative;
  width: 300px;
  height: 300px;
  margin: 0 auto 2rem;
}

.breathe-circle {
  width: 100px;
  height: 100px;
  background: radial-gradient(circle, var(--accent) 0%, transparent 70%);
  border-radius: 50%;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(1);
  transition: transform 4s ease-in-out;
  box-shadow: 0 0 60px var(--accent);
}

.breathe-circle.inhale {
  transform: translate(-50%, -50%) scale(2.5);
}

.breathe-circle.hold {
  transform: translate(-50%, -50%) scale(2.5);
}

.breathe-circle.exhale {
  transform: translate(-50%, -50%) scale(1);
}

.outer-ring {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 280px;
  height: 280px;
  border: 1px solid rgba(0, 245, 212, 0.2);
  border-radius: 50%;
}

.instruction {
  font-size: 1.5rem;
  color: var(--accent);
  letter-spacing: 0.3em;
  margin-bottom: 1rem;
  min-height: 2rem;
}

.timer {
  font-size: 3rem;
  color: rgba(255, 255, 255, 0.5);
  margin-bottom: 2rem;
}

.cycle-info {
  font-size: 0.8rem;
  color: rgba(255, 255, 255, 0.3);
  letter-spacing: 0.2em;
}

.pattern-select {
  margin-top: 2rem;
  display: flex;
  gap: 1rem;
  justify-content: center;
}

.pattern-btn {
  padding: 0.5rem 1rem;
  background: transparent;
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: rgba(255, 255, 255, 0.5);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
}

.pattern-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.pattern-btn.active {
  border-color: var(--accent);
  color: var(--accent);
  background: rgba(0, 245, 212, 0.1);
}

.ui {
  position: fixed;
  font-family: "Orbitron", sans-serif;
}

.back-link {
  top: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.3);
  text-decoration: none;
  font-size: 0.8rem;
  letter-spacing: 0.1em;
  transition: color 0.3s;
}

.back-link:hover {
  color: rgba(255,255,255,0.7);
}

.title {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

@media (max-width: 768px) {
  .circle-container { width: 200px; height: 200px; }
  .instruction { font-size: 1.2rem; }
  .timer { font-size: 0.7rem; }
  .controls { gap: 0.4rem; }
  .control-btn { padding: 0.4rem 0.7rem; font-size: 0.55rem; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';

?>

<div class="ui title">BREATHE</div>

<div class="breathe-container">
  <div class="circle-container">
    <div class="outer-ring"></div>
    <div class="breathe-circle" id="circle"></div>
  </div>
  <div class="instruction" id="instruction">READY</div>
  <div class="timer" id="timer">0</div>
  <div class="cycle-info" id="cycleInfo">CYCLE 0</div>
  
  <div class="pattern-select">
    <button class="pattern-btn active" data-pattern="4-4-4-4">4-4-4-4</button>
    <button class="pattern-btn" data-pattern="4-7-8">4-7-8</button>
    <button class="pattern-btn" data-pattern="5-5-5-5">5-5-5-5</button>
  </div>
</div>

<script>
const circle = document.getElementById('circle');
const instruction = document.getElementById('instruction');
const timerEl = document.getElementById('timer');
const cycleInfo = document.getElementById('cycleInfo');

const patterns = {
  '4-4-4-4': [4, 4, 4, 4],
  '4-7-8': [4, 7, 8, 0],
  '5-5-5-5': [5, 5, 5, 5]
};

let currentPattern = patterns['4-4-4-4'];
let cycle = 0;
let phase = 0;
let countdown = 0;
let intervalId = null;

const phaseNames = ['INHALE', 'HOLD', 'EXHALE', 'HOLD'];
const phaseClasses = ['inhale', 'hold', 'exhale', 'hold'];

function startBreathing() {
  if (intervalId) clearInterval(intervalId);
  
  phase = 0;
  countdown = currentPattern[0];
  updateDisplay();
  
  intervalId = setInterval(() => {
    countdown--;
    
    if (countdown <= 0) {
      phase++;
      
      while (phase < 4 && currentPattern[phase] === 0) {
        phase++;
      }
      
      if (phase >= 4) {
        phase = 0;
        cycle++;
      }
      
      countdown = currentPattern[phase];
    }
    
    updateDisplay();
  }, 1000);
}

function updateDisplay() {
  circle.className = 'breathe-circle ' + phaseClasses[phase];
  
  instruction.textContent = phaseNames[phase];
  timerEl.textContent = countdown;
  cycleInfo.textContent = `CYCLE ${cycle}`;
  
  if (phase === 0) {
    circle.style.transition = `transform ${currentPattern[0]}s ease-in-out`;
  } else if (phase === 2) {
    circle.style.transition = `transform ${currentPattern[2]}s ease-in-out`;
  }
}

document.querySelectorAll('.pattern-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pattern-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentPattern = patterns[btn.dataset.pattern];
    cycle = 0;
    startBreathing();
  });
});

setTimeout(startBreathing, 1000);
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
