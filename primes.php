<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('primes');

$pageName = 'primes';
$pageTitle = 'Primes - PULSE';
$pageDesc = '素数を延々と表示するページ。数学は誰のものでもない。';
$pageCanonical = 'https://pulse.subara3.com/primes.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  background: #0a0a0f;
  color: #e0e0e0;
  font-family: "JetBrains Mono", monospace;
  min-height: 100vh;
  overflow-x: hidden;
}

.ui {
  position: fixed;
  top: 2rem;
  left: 2rem;
  z-index: 100;
}

.logo {
  font-family: "Orbitron", sans-serif;
  font-size: 1.5rem;
  color: #00f5d4;
  text-decoration: none;
  display: block;
  margin-bottom: 0.5rem;
}

.hint {
  color: #666;
  font-size: 0.75rem;
}

.stats {
  position: fixed;
  top: 5rem;
  right: 2rem;
  text-align: right;
  z-index: 100;
}

.stat-value {
  font-size: 2rem;
  color: #00f5d4;
  font-family: "Orbitron", sans-serif;
}

.stat-label {
  font-size: 0.7rem;
  color: #666;
}

.controls {
  position: fixed;
  bottom: 2rem;
  left: 2rem;
  z-index: 100;
  display: flex;
  gap: 0.5rem;
}

.btn {
  padding: 0.5rem 1rem;
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: #00f5d4;
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
}

.btn:hover {
  background: rgba(0, 245, 212, 0.2);
  border-color: #00f5d4;
}

.btn.active {
  background: #00f5d4;
  color: #0a0a0f;
}

.back-link {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  color: #666;
  text-decoration: none;
  font-size: 0.75rem;
  z-index: 100;
}

.back-link:hover {
  color: #00f5d4;
}

.prime-container {
  padding: 8rem 2rem 4rem;
  max-width: 100%;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: center;
  align-items: flex-start;
}

.prime {
  display: inline-block;
  padding: 0.3rem 0.6rem;
  background: rgba(0, 245, 212, 0.05);
  border-radius: 4px;
  font-size: 0.9rem;
  color: #00f5d4;
  animation: fadeIn 0.3s ease;
  transition: all 0.2s;
}

.prime:hover {
  background: rgba(0, 245, 212, 0.2);
  transform: scale(1.1);
}

.prime.new {
  animation: pop 0.5s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes pop {
  0% { transform: scale(0); opacity: 0; }
  50% { transform: scale(1.3); }
  100% { transform: scale(1); opacity: 1; }
}

.special {
  background: rgba(123, 44, 191, 0.2);
  color: #b388ff;
  border: 1px solid rgba(123, 44, 191, 0.5);
}

.twin {
  border: 1px solid #f39c12;
}

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .stats { top: 0.5rem; right: 0.5rem; font-size: 0.5rem; }
  .info { bottom: 3.5rem; left: 0.5rem; font-size: 0.5rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
  .legend { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
require_once __DIR__ . '/includes/share.php';
?>

<div class="ui">
  <a href="index.php" class="logo">PULSE</a>
  <p class="hint">PRIME NUMBERS</p>
</div>

<div class="stats">
  <div class="stat-value" id="count">0</div>
  <div class="stat-label">PRIMES FOUND</div>
  <div class="stat-value" id="current" style="font-size: 1rem; margin-top: 1rem;">-</div>
  <div class="stat-label">LATEST</div>
</div>

<div class="controls">
  <button class="btn active" id="playBtn" onclick="togglePlay()">PAUSE</button>
  <button class="btn" onclick="setSpeed('slow', this)">SLOW</button>
  <button class="btn active" onclick="setSpeed('normal', this)">NORMAL</button>
  <button class="btn" onclick="setSpeed('fast', this)">FAST</button>
</div>

<a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i></a>

<div class="prime-container" id="primeContainer"></div>

<script>
let current = 2;
let count = 0;
let isRunning = true;
let speed = 100;
let lastPrime = null;

function isPrime(n) {
  if (n < 2) return false;
  if (n === 2) return true;
  if (n % 2 === 0) return false;
  for (let i = 3; i * i <= n; i += 2) {
    if (n % i === 0) return false;
  }
  return true;
}

function isTwinPrime(n) {
  return isPrime(n - 2) || isPrime(n + 2);
}

function isSpecialPrime(n) {
  // 回文素数
  const str = n.toString();
  return str === str.split('').reverse().join('');
}

function addPrime(n) {
  const container = document.getElementById('primeContainer');
  const el = document.createElement('span');
  el.className = 'prime new';
  el.textContent = n;
  
  if (isSpecialPrime(n)) el.classList.add('special');
  if (lastPrime && n - lastPrime === 2) el.classList.add('twin');
  
  container.appendChild(el);
  
  setTimeout(() => el.classList.remove('new'), 500);
  
  count++;
  lastPrime = n;
  document.getElementById('count').textContent = count;
  document.getElementById('current').textContent = n.toLocaleString();
  
  // 全体スクロールのsmooth連打は端末によって詰まりやすい
  // 最新追加を軽量に追従
  window.scrollTo(0, document.body.scrollHeight);
}

function findNext() {
  if (!isRunning) return;
  
  while (!isPrime(current)) {
    current++;
  }
  
  addPrime(current);
  current++;
  
  setTimeout(findNext, speed);
}

function togglePlay() {
  isRunning = !isRunning;
  const btn = document.getElementById('playBtn');
  btn.textContent = isRunning ? 'PAUSE' : 'PLAY';
  btn.classList.toggle('active', isRunning);
  if (isRunning) findNext();
}

function setSpeed(s, el) {
  document.querySelectorAll('.btn').forEach(b => {
    if (['SLOW', 'NORMAL', 'FAST'].includes(b.textContent)) {
      b.classList.remove('active');
    }
  });
  if (el) el.classList.add('active');
  
  switch(s) {
    case 'slow': speed = 500; break;
    case 'normal': speed = 100; break;
    case 'fast': speed = 10; break;
  }
}

findNext();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
