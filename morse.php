<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('morse');

$pageName = 'morse';
$pageTitle = 'MORSE - PULSE';
$pageDesc = 'モールス信号を打て。スペースキーで入力。';
$pageCanonical = 'https://pulse.subara3.com/morse.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.back-link { top: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.3); text-decoration: none; font-size: 0.8rem; letter-spacing: 0.1em; transition: color 0.3s; }
.back-link:hover { color: rgba(255,255,255,0.7); }
.game-container { max-width: 600px; width: 90%; padding: 2rem; text-align: center; }
.title { font-family: "Orbitron", sans-serif; font-size: 1.5rem; color: #00f5d4; letter-spacing: 0.2em; margin-bottom: 2rem; }
.stats { display: flex; justify-content: center; gap: 2rem; margin-bottom: 2rem; font-family: "JetBrains Mono", monospace; }
.stat-value { font-size: 2rem; color: #00f5d4; font-weight: 700; }
.stat-label { font-size: 0.7rem; color: rgba(255,255,255,0.4); letter-spacing: 0.1em; }
.target-letter { font-family: "Orbitron", sans-serif; font-size: 6rem; color: #00f5d4; margin-bottom: 1rem; text-shadow: 0 0 40px rgba(0, 245, 212, 0.5); transition: all 0.3s; }
.target-morse { font-family: "JetBrains Mono", monospace; font-size: 2rem; color: rgba(255,255,255,0.5); margin-bottom: 2rem; letter-spacing: 0.3em; }
.input-display { font-family: "JetBrains Mono", monospace; font-size: 2.5rem; color: #fff; min-height: 3rem; margin-bottom: 2rem; letter-spacing: 0.3em; }
.input-display .correct { color: #00f5d4; }
.input-display .wrong { color: #ff6b6b; animation: shake 0.3s; }
@keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
.key-indicator { width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 3px solid rgba(255,255,255,0.2); margin: 0 auto 2rem; display: flex; align-items: center; justify-content: center; transition: all 0.1s; cursor: pointer; }
.key-indicator:hover { border-color: rgba(255,255,255,0.4); }
.key-indicator.pressed { background: rgba(0, 245, 212, 0.3); border-color: #00f5d4; box-shadow: 0 0 40px rgba(0, 245, 212, 0.5); }
.key-indicator span { font-family: "Orbitron", sans-serif; font-size: 0.8rem; color: rgba(255,255,255,0.3); }
.hint { color: rgba(255,255,255,0.3); font-size: 0.8rem; margin-top: 1rem; }
.hint strong { color: rgba(255,255,255,0.5); }

/* 完了演出 */
.complete-flash { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 245, 212, 0.1); pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 50; }
.complete-flash.show { opacity: 1; }
.target-letter.success { animation: success-pulse 0.8s ease-out; }
@keyframes success-pulse { 0% { transform: scale(1); } 30% { transform: scale(1.2); color: #fff; text-shadow: 0 0 60px rgba(0, 245, 212, 0.8); } 100% { transform: scale(1); } }

.morse-table { margin-top: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 0.5rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.3); transition: opacity 0.3s; }
.morse-table.hidden { opacity: 0; pointer-events: none; }
.morse-item { background: rgba(255,255,255,0.02); padding: 0.3rem; border-radius: 4px; }
.morse-item span { color: #00f5d4; }
.mode-toggle { display: flex; justify-content: center; gap: 0.5rem; margin-bottom: 2rem; }
.mode-btn { padding: 0.5rem 1.5rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.mode-btn:hover { border-color: rgba(0, 245, 212, 0.5); }
.mode-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.target-morse.hidden { opacity: 0; }

@media (max-width: 768px) {
  body { padding: 1rem; }
  .page-title { font-size: 1rem; }
  .target-char { font-size: 3rem; }
  .target-morse { font-size: 1rem; }
  .input-display { font-size: 1.5rem; min-height: 40px; }
  .morse-chart { gap: 0.3rem; padding: 0.8rem; }
  .morse-item { font-size: 0.55rem; }
  .mode-btn { padding: 0.4rem 1rem; font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/sfx.php';
?>

<div class="complete-flash" id="completeFlash"></div>

<div class="game-container">
  <div class="title">MORSE CODE</div>
  
  <div class="mode-toggle">
    <button class="mode-btn active" data-mode="easy">EASY</button>
    <button class="mode-btn" data-mode="hard">HARD</button>
  </div>
  
  <div class="stats">
    <div class="stat"><div class="stat-value" id="score">0</div><div class="stat-label">SCORE</div></div>
    <div class="stat"><div class="stat-value" id="streak">0</div><div class="stat-label">STREAK</div></div>
  </div>
  
  <div class="target-letter" id="targetLetter">A</div>
  <div class="target-morse" id="targetMorse">.-</div>
  
  <div class="input-display" id="inputDisplay"></div>
  
  <div class="key-indicator" id="keyIndicator">
    <span>SPACE</span>
  </div>
  
  <div class="hint">
    <strong>Tap</strong> = dot (·)　　<strong>Hold</strong> = dash (—)
  </div>
  
  <div class="morse-table" id="morseTable"></div>
</div>

<script>
const MORSE_CODE = {
  'A': '.-',    'B': '-...',  'C': '-.-.',  'D': '-..',   'E': '.',
  'F': '..-.',  'G': '--.',   'H': '....',  'I': '..',    'J': '.---',
  'K': '-.-',   'L': '.-..',  'M': '--',    'N': '-.',    'O': '---',
  'P': '.--.',  'Q': '--.-',  'R': '.-.',   'S': '...',   'T': '-',
  'U': '..-',   'V': '...-',  'W': '.--',   'X': '-..-',  'Y': '-.--',
  'Z': '--..',
  '0': '-----', '1': '.----', '2': '..---', '3': '...--', '4': '....-',
  '5': '.....', '6': '-....', '7': '--...', '8': '---..', '9': '----.'
};

const EASY_LETTERS = ['E', 'T', 'A', 'I', 'M', 'N', 'S', 'O'];

let currentTarget = '';
let currentMorse = '';
let userInput = '';
let score = 0;
let streak = 0;
let keyDownTime = 0;
let isKeyDown = false;
let isTransitioning = false;
let currentMode = 'easy';

const DOT_THRESHOLD = 200;

const targetLetterEl = document.getElementById('targetLetter');
const targetMorseEl = document.getElementById('targetMorse');
const inputDisplayEl = document.getElementById('inputDisplay');
const scoreEl = document.getElementById('score');
const streakEl = document.getElementById('streak');
const keyIndicator = document.getElementById('keyIndicator');
const completeFlash = document.getElementById('completeFlash');

function generateTarget() {
  isTransitioning = false;
  const letters = score < 5 ? EASY_LETTERS : Object.keys(MORSE_CODE).filter(k => k.match(/[A-Z]/));
  currentTarget = letters[Math.floor(Math.random() * letters.length)];
  currentMorse = MORSE_CODE[currentTarget];
  userInput = '';
  
  targetLetterEl.textContent = currentTarget;
  targetLetterEl.classList.remove('success');
  targetMorseEl.textContent = currentMorse;
  inputDisplayEl.innerHTML = '';
}

let wrongSymbol = '';

function addSymbol(symbol) {
  if (isTransitioning) return;
  
  const expectedSymbol = currentMorse[userInput.length];
  
  // 入力音（ドット・ダッシュで周波数変える）
  if (symbol === '.') {
    SFX.play('click');
  } else {
    SFX.play('move');
  }
  
  if (symbol === expectedSymbol) {
    userInput += symbol;
    wrongSymbol = '';
    renderInput();
    
    // 完了チェック
    if (userInput === currentMorse) {
      isTransitioning = true;
      score++;
      streak++;
      scoreEl.textContent = score;
      streakEl.textContent = streak;
      
      // 成功演出
      SFX.play('correct');
      targetLetterEl.classList.add('success');
      completeFlash.classList.add('show');
      
      setTimeout(() => {
        completeFlash.classList.remove('show');
      }, 200);
      
      // 1.5秒後に次の問題
      setTimeout(() => {
        generateTarget();
      }, 1500);
    }
  } else {
    // 不正解
    SFX.play('fail');
    wrongSymbol = symbol;
    streak = 0;
    streakEl.textContent = streak;
    renderInput();
    
    setTimeout(() => {
      wrongSymbol = '';
      renderInput();
    }, 500);
  }
}

function renderInput() {
  let html = '';
  for (let i = 0; i < userInput.length; i++) {
    html += `<span class="correct">${userInput[i]}</span>`;
  }
  if (wrongSymbol) {
    html += `<span class="wrong">${wrongSymbol}</span>`;
  }
  inputDisplayEl.innerHTML = html;
}

// キー入力
document.addEventListener('keydown', (e) => {
  if (e.code === 'Space' && !isKeyDown) {
    e.preventDefault();
    isKeyDown = true;
    keyDownTime = Date.now();
    keyIndicator.classList.add('pressed');
  }
});

document.addEventListener('keyup', (e) => {
  if (e.code === 'Space' && isKeyDown) {
    e.preventDefault();
    isKeyDown = false;
    const duration = Date.now() - keyDownTime;
    const symbol = duration < DOT_THRESHOLD ? '.' : '-';
    addSymbol(symbol);
    keyIndicator.classList.remove('pressed');
  }
});

// タッチ対応
keyIndicator.addEventListener('touchstart', (e) => {
  e.preventDefault();
  if (!isKeyDown) {
    isKeyDown = true;
    keyDownTime = Date.now();
    keyIndicator.classList.add('pressed');
  }
});

keyIndicator.addEventListener('touchend', (e) => {
  e.preventDefault();
  if (isKeyDown) {
    isKeyDown = false;
    const duration = Date.now() - keyDownTime;
    const symbol = duration < DOT_THRESHOLD ? '.' : '-';
    addSymbol(symbol);
    keyIndicator.classList.remove('pressed');
  }
});

// マウスでも操作可能
keyIndicator.addEventListener('mousedown', (e) => {
  e.preventDefault();
  if (!isKeyDown) {
    isKeyDown = true;
    keyDownTime = Date.now();
    keyIndicator.classList.add('pressed');
  }
});

keyIndicator.addEventListener('mouseup', (e) => {
  e.preventDefault();
  if (isKeyDown) {
    isKeyDown = false;
    const duration = Date.now() - keyDownTime;
    const symbol = duration < DOT_THRESHOLD ? '.' : '-';
    addSymbol(symbol);
    keyIndicator.classList.remove('pressed');
  }
});

keyIndicator.addEventListener('mouseleave', () => {
  if (isKeyDown) {
    isKeyDown = false;
    keyIndicator.classList.remove('pressed');
  }
});

// モード切り替え
document.querySelectorAll('.mode-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentMode = btn.dataset.mode;
    
    if (currentMode === 'hard') {
      document.getElementById('morseTable').classList.add('hidden');
      targetMorseEl.classList.add('hidden');
    } else {
      document.getElementById('morseTable').classList.remove('hidden');
      targetMorseEl.classList.remove('hidden');
    }
    
    score = 0;
    streak = 0;
    scoreEl.textContent = score;
    streakEl.textContent = streak;
    generateTarget();
  });
});

// モールス表を表示
const tableHtml = Object.entries(MORSE_CODE)
  .filter(([k]) => k.match(/[A-Z]/))
  .map(([letter, morse]) => `<div class="morse-item"><span>${letter}</span> ${morse}</div>`)
  .join('');
document.getElementById('morseTable').innerHTML = tableHtml;

generateTarget();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
