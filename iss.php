<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('iss');

$pageName = 'iss';
$pageTitle = 'ISS RACE - PULSE';
$pageDesc = '国際宇宙ステーションと競争しよう。あなたがこのページを開いてから、ISSは何km進んだ？';
$pageCanonical = 'https://pulse.subara3.com/iss.php';

$extraHead = '';

$extraStyle = '
body {
  background: #020408;
  min-height: 100vh;
  overflow-x: hidden;
  position: relative;
}

/* スキャンライン効果 */
body::before {
  content: "";
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: repeating-linear-gradient(
    0deg,
    rgba(0, 0, 0, 0.1) 0px,
    rgba(0, 0, 0, 0.1) 1px,
    transparent 1px,
    transparent 2px
  );
  pointer-events: none;
  z-index: 9999;
}

/* グリッドバックグラウンド */
body::after {
  content: "";
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background:
    radial-gradient(ellipse at center, rgba(0,245,212,0.03) 0%, transparent 70%),
    linear-gradient(rgba(0,245,212,0.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,245,212,0.02) 1px, transparent 1px);
  background-size: 100% 100%, 50px 50px, 50px 50px;
  pointer-events: none;
  z-index: -1;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 1rem;
  min-height: 100vh;
}

/* ヘッダー */
.header-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.8rem 1.5rem;
  background: rgba(0,20,30,0.8);
  border: 1px solid rgba(0,245,212,0.2);
  border-radius: 4px;
  margin-bottom: 1rem;
}

.system-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  color: #00f5d4;
  letter-spacing: 0.3em;
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.status-indicator {
  width: 8px;
  height: 8px;
  background: #00f5d4;
  border-radius: 50%;
  animation: blink 1s infinite;
  box-shadow: 0 0 10px #00f5d4;
}

@keyframes blink {
  0%, 50% { opacity: 1; }
  51%, 100% { opacity: 0.3; }
}

.system-time {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.75rem;
  color: rgba(0,245,212,0.7);
}

/* メイン表示エリア */
.main-display {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 1rem;
  margin-bottom: 1rem;
}

@media (max-width: 900px) {
  .main-display { grid-template-columns: 1fr; }
}

/* 地図コンテナ */
.map-wrapper {
  position: relative;
  background: rgba(0,15,25,0.9);
  border: 1px solid rgba(0,245,212,0.3);
  border-radius: 4px;
  padding: 1rem;
}

.map-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid rgba(0,245,212,0.1);
}

.map-label {
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: rgba(0,245,212,0.5);
  letter-spacing: 0.2em;
}

.map-container {
  position: relative;
  width: 100%;
  aspect-ratio: 2/1;
  background: #010408;
  border-radius: 2px;
  overflow: hidden;
}

/* 世界地図SVG */
.world-map {
  width: 100%;
  height: 100%;
}

/* ISSマーカー */
.iss-marker {
  position: absolute;
  width: 40px;
  height: 40px;
  transform: translate(-50%, -50%);
  transition: left 1s linear, top 1s linear;
  z-index: 10;
}

.iss-core {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 10px;
  height: 10px;
  background: #00f5d4;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 20px #00f5d4, 0 0 40px #00f5d4, 0 0 60px rgba(0,245,212,0.5);
}

.iss-ring {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 30px;
  height: 30px;
  border: 1px solid rgba(0,245,212,0.5);
  border-radius: 50%;
  transform: translate(-50%, -50%);
  animation: ring-pulse 2s infinite;
}

.iss-ring:nth-child(2) { animation-delay: 0.5s; }
.iss-ring:nth-child(3) { animation-delay: 1s; width: 40px; height: 40px; }

@keyframes ring-pulse {
  0% { transform: translate(-50%, -50%) scale(0.5); opacity: 1; }
  100% { transform: translate(-50%, -50%) scale(2); opacity: 0; }
}

.iss-crosshair {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 60px;
  height: 60px;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.iss-crosshair::before,
.iss-crosshair::after {
  content: "";
  position: absolute;
  background: rgba(0,245,212,0.3);
}

.iss-crosshair::before {
  top: 50%;
  left: 0;
  width: 100%;
  height: 1px;
}

.iss-crosshair::after {
  left: 50%;
  top: 0;
  width: 1px;
  height: 100%;
}

/* 軌跡 */
.iss-trail {
  position: absolute;
  width: 4px;
  height: 4px;
  background: #00f5d4;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
  opacity: 0.6;
}

/* フットプリント（可視範囲） */
.iss-footprint {
  position: absolute;
  border: 1px dashed rgba(0,245,212,0.2);
  border-radius: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
  transition: all 1s linear;
}

/* レーダースキャン */
.radar-sweep {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent 0%, rgba(0,245,212,0.05) 50%, transparent 100%);
  animation: sweep 4s linear infinite;
  pointer-events: none;
}

@keyframes sweep {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

/* サイドパネル */
.side-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

/* データパネル */
.data-panel {
  background: rgba(0,15,25,0.9);
  border: 1px solid rgba(0,245,212,0.2);
  border-radius: 4px;
  overflow: hidden;
}

.panel-header {
  background: rgba(0,245,212,0.1);
  padding: 0.5rem 1rem;
  border-bottom: 1px solid rgba(0,245,212,0.2);
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: #00f5d4;
  letter-spacing: 0.2em;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.panel-status {
  width: 6px;
  height: 6px;
  background: #00f5d4;
  border-radius: 50%;
  animation: blink 1s infinite;
}

.panel-content {
  padding: 1rem;
}

/* データ行 */
.data-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 0.4rem 0;
  border-bottom: 1px solid rgba(0,245,212,0.05);
}

.data-row:last-child { border-bottom: none; }

.data-label {
  font-size: 0.65rem;
  color: rgba(255,255,255,0.4);
  font-family: "JetBrains Mono", monospace;
}

.data-value {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.85rem;
  color: #00f5d4;
  text-align: right;
}

.data-value.large {
  font-size: 1.3rem;
  font-weight: 700;
}

.data-unit {
  font-size: 0.6em;
  opacity: 0.6;
  margin-left: 0.2em;
}

/* スコアボード */
.score-section {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.score-box {
  background: rgba(0,15,25,0.9);
  border: 1px solid rgba(0,245,212,0.2);
  border-radius: 4px;
  padding: 1rem;
  text-align: center;
  position: relative;
  overflow: hidden;
}

.score-box.iss {
  border-color: rgba(0,245,212,0.4);
}

.score-box.iss::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 2px;
  background: linear-gradient(90deg, transparent, #00f5d4, transparent);
  animation: score-glow 2s infinite;
}

@keyframes score-glow {
  0%, 100% { opacity: 0.3; }
  50% { opacity: 1; }
}

.score-box.you {
  border-color: rgba(123,44,191,0.4);
}

.score-box.you::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 2px;
  background: linear-gradient(90deg, transparent, #7b2cbf, transparent);
}

.score-icon {
  font-size: 1.5rem;
  margin-bottom: 0.5rem;
}

.score-box.iss .score-icon { color: #00f5d4; }
.score-box.you .score-icon { color: #7b2cbf; }

.score-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: rgba(255,255,255,0.5);
  letter-spacing: 0.2em;
  margin-bottom: 0.3rem;
}

.score-number {
  font-family: "JetBrains Mono", monospace;
  font-size: 1.8rem;
  font-weight: 700;
}

.score-box.iss .score-number { color: #00f5d4; }
.score-box.you .score-number { color: #7b2cbf; }

.score-unit-label {
  font-size: 0.7rem;
  color: rgba(255,255,255,0.4);
}

/* 比較セクション */
.comparison-panel {
  background: rgba(0,15,25,0.9);
  border: 1px solid rgba(0,245,212,0.2);
  border-radius: 4px;
  padding: 1.5rem;
  position: relative;
  overflow: hidden;
}

.comparison-panel::before {
  content: "";
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(0,245,212,0.03), transparent);
  animation: comparison-sweep 3s infinite;
}

@keyframes comparison-sweep {
  0% { left: -100%; }
  100% { left: 100%; }
}

.comparison-text {
  font-size: 0.95rem;
  color: rgba(255,255,255,0.7);
  line-height: 1.8;
  text-align: center;
  position: relative;
  z-index: 1;
}

.comparison-text .highlight {
  color: #00f5d4;
  font-family: "JetBrains Mono", monospace;
  font-weight: 700;
  text-shadow: 0 0 10px rgba(0,245,212,0.5);
}

.comparison-text .dim {
  color: rgba(255,255,255,0.3);
  font-size: 0.85rem;
}

/* API ステータスバー */
.status-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 1rem;
  background: rgba(0,15,25,0.9);
  border: 1px solid rgba(0,245,212,0.1);
  border-radius: 4px;
  margin-top: 1rem;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.65rem;
}

.status-left {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}

.status-item {
  color: rgba(255,255,255,0.4);
}

.status-item span {
  color: #00f5d4;
}

.api-status {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: rgba(255,255,255,0.4);
}

.api-status.success { color: #00f5d4; }
.api-status.error { color: #ff6b6b; }

/* コーナーデコレーション */
.corner-deco {
  position: fixed;
  width: 100px;
  height: 100px;
  pointer-events: none;
  z-index: 100;
}

.corner-deco.tl { top: 0; left: 0; border-top: 2px solid rgba(0,245,212,0.3); border-left: 2px solid rgba(0,245,212,0.3); }
.corner-deco.tr { top: 0; right: 0; border-top: 2px solid rgba(0,245,212,0.3); border-right: 2px solid rgba(0,245,212,0.3); }
.corner-deco.bl { bottom: 0; left: 0; border-bottom: 2px solid rgba(0,245,212,0.3); border-left: 2px solid rgba(0,245,212,0.3); }
.corner-deco.br { bottom: 0; right: 0; border-bottom: 2px solid rgba(0,245,212,0.3); border-right: 2px solid rgba(0,245,212,0.3); }

@media (max-width: 600px) {
  .score-section { grid-template-columns: 1fr; }
  .header-bar { flex-direction: column; gap: 0.5rem; text-align: center; }
  .status-bar { flex-direction: column; gap: 0.5rem; }
  .status-left { flex-direction: column; gap: 0.3rem; }
  .corner-deco { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'watch'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
require_once __DIR__ . '/includes/share.php';
?>

<div class="corner-deco tl"></div>
<div class="corner-deco tr"></div>
<div class="corner-deco bl"></div>
<div class="corner-deco br"></div>

<div class="container">
  <!-- ヘッダーバー -->
  <div class="header-bar">
    <div class="system-title">
      <span class="status-indicator"></span>
      SATELLITE TRACKING SYSTEM // ISS RACE
    </div>
    <div class="system-time" id="systemTime">--:--:--</div>
  </div>

  <!-- メイン表示 -->
  <div class="main-display">
    <!-- 地図 -->
    <div class="map-wrapper">
      <div class="map-header">
        <span class="map-label">WORLD MAP // MERCATOR PROJECTION</span>
        <span class="map-label" id="mapCoords">LAT -- / LON --</span>
      </div>
      <div class="map-container">
        <svg class="world-map" viewBox="0 0 360 180" preserveAspectRatio="xMidYMid slice">
          <defs>
            <pattern id="grid" width="15" height="15" patternUnits="userSpaceOnUse">
              <path d="M 15 0 L 0 0 0 15" fill="none" stroke="rgba(0,245,212,0.08)" stroke-width="0.5"/>
            </pattern>
            <linearGradient id="equatorGrad" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="transparent"/>
              <stop offset="50%" stop-color="rgba(0,245,212,0.4)"/>
              <stop offset="100%" stop-color="transparent"/>
            </linearGradient>
          </defs>

          <!-- 背景グリッド -->
          <rect width="360" height="180" fill="#010408"/>
          <rect width="360" height="180" fill="url(#grid)"/>

          <!-- 緯度線（主要） -->
          <line x1="0" y1="23.5" x2="360" y2="23.5" stroke="rgba(0,245,212,0.1)" stroke-width="0.5" stroke-dasharray="2,2"/>
          <line x1="0" y1="66.5" x2="360" y2="66.5" stroke="rgba(0,245,212,0.1)" stroke-width="0.5" stroke-dasharray="2,2"/>
          <line x1="0" y1="90" x2="360" y2="90" stroke="url(#equatorGrad)" stroke-width="1"/>
          <line x1="0" y1="113.5" x2="360" y2="113.5" stroke="rgba(0,245,212,0.1)" stroke-width="0.5" stroke-dasharray="2,2"/>
          <line x1="0" y1="156.5" x2="360" y2="156.5" stroke="rgba(0,245,212,0.1)" stroke-width="0.5" stroke-dasharray="2,2"/>

          <!-- 経度線（主要） -->
          <line x1="180" y1="0" x2="180" y2="180" stroke="rgba(0,245,212,0.15)" stroke-width="0.5"/>
          <line x1="90" y1="0" x2="90" y2="180" stroke="rgba(0,245,212,0.08)" stroke-width="0.5"/>
          <line x1="270" y1="0" x2="270" y2="180" stroke="rgba(0,245,212,0.08)" stroke-width="0.5"/>

          <!-- 大陸のシルエット（簡易版） -->
          <g fill="rgba(0,245,212,0.05)" stroke="rgba(0,245,212,0.15)" stroke-width="0.5">
            <!-- 北米 -->
            <path d="M40,30 L80,25 L100,35 L110,50 L100,70 L80,75 L60,70 L40,55 Z"/>
            <!-- 南米 -->
            <path d="M80,85 L95,80 L100,95 L95,120 L80,140 L70,130 L75,100 Z"/>
            <!-- ヨーロッパ -->
            <path d="M165,35 L185,30 L195,40 L190,55 L175,55 L165,45 Z"/>
            <!-- アフリカ -->
            <path d="M165,70 L195,65 L200,90 L190,120 L170,120 L160,95 Z"/>
            <!-- アジア -->
            <path d="M200,25 L280,20 L300,40 L310,60 L290,75 L250,70 L220,60 L200,45 Z"/>
            <!-- オーストラリア -->
            <path d="M280,110 L310,105 L320,120 L310,135 L285,130 Z"/>
            <!-- 日本 -->
            <ellipse cx="305" cy="52" rx="8" ry="12" fill="rgba(123,44,191,0.2)" stroke="rgba(123,44,191,0.4)"/>
          </g>

          <!-- ラベル -->
          <text x="5" y="93" fill="rgba(0,245,212,0.3)" font-size="4" font-family="Orbitron">EQUATOR</text>
          <text x="305" y="42" fill="rgba(123,44,191,0.5)" font-size="4" font-family="Orbitron">JAPAN</text>
        </svg>

        <div class="radar-sweep"></div>
        <div class="iss-footprint" id="issFootprint"></div>

        <div class="iss-marker" id="issMarker">
          <div class="iss-crosshair"></div>
          <div class="iss-ring"></div>
          <div class="iss-ring"></div>
          <div class="iss-ring"></div>
          <div class="iss-core"></div>
        </div>
      </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel">
      <!-- テレメトリパネル -->
      <div class="data-panel">
        <div class="panel-header">
          TELEMETRY
          <span class="panel-status"></span>
        </div>
        <div class="panel-content">
          <div class="data-row">
            <span class="data-label">ELAPSED</span>
            <span class="data-value large" id="elapsedTime">00:00:00</span>
          </div>
          <div class="data-row">
            <span class="data-label">VELOCITY</span>
            <span class="data-value" id="issSpeed">--<span class="data-unit">km/h</span></span>
          </div>
          <div class="data-row">
            <span class="data-label">ALTITUDE</span>
            <span class="data-value" id="issAltitude">--<span class="data-unit">km</span></span>
          </div>
          <div class="data-row">
            <span class="data-label">LATITUDE</span>
            <span class="data-value" id="issLat">--°</span>
          </div>
          <div class="data-row">
            <span class="data-label">LONGITUDE</span>
            <span class="data-value" id="issLon">--°</span>
          </div>
          <div class="data-row">
            <span class="data-label">VISIBILITY</span>
            <span class="data-value" id="issVis">--</span>
          </div>
        </div>
      </div>

      <!-- 軌道情報 -->
      <div class="data-panel">
        <div class="panel-header">
          ORBIT INFO
          <span class="panel-status"></span>
        </div>
        <div class="panel-content">
          <div class="data-row">
            <span class="data-label">NORAD ID</span>
            <span class="data-value">25544</span>
          </div>
          <div class="data-row">
            <span class="data-label">PERIOD</span>
            <span class="data-value">~92<span class="data-unit">min</span></span>
          </div>
          <div class="data-row">
            <span class="data-label">ORBITS/DAY</span>
            <span class="data-value">~15.5</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- スコアセクション -->
  <div class="score-section">
    <div class="score-box iss">
      <div class="score-icon"><i class="fas fa-satellite"></i></div>
      <div class="score-title">ISS DISTANCE</div>
      <div class="score-number"><span id="issDistance">0</span></div>
      <div class="score-unit-label">kilometers traveled</div>
    </div>
    <div class="score-box you">
      <div class="score-icon"><i class="fas fa-couch"></i></div>
      <div class="score-title">YOUR DISTANCE</div>
      <div class="score-number"><span id="youDistance">0</span></div>
      <div class="score-unit-label">probably</div>
    </div>
  </div>

  <!-- 比較パネル -->
  <div class="comparison-panel">
    <p class="comparison-text" id="comparisonText">
      SYSTEM INITIALIZING...
    </p>
  </div>

  <!-- ステータスバー -->
  <div class="status-bar">
    <div class="status-left">
      <span class="status-item">SRC: <span>api.wheretheiss.at</span></span>
      <span class="status-item">REFRESH: <span>5s</span></span>
      <span class="status-item">FOOTPRINT: <span id="footprintSize">--</span></span>
    </div>
    <div class="api-status" id="apiStatus">
      <span class="status-indicator"></span>
      CONNECTING...
    </div>
  </div>
</div>

<script>
const ISS_API = 'https://api.wheretheiss.at/v1/satellites/25544';
const ISS_SPEED_KMH = 27580;
const ISS_SPEED_KMS = ISS_SPEED_KMH / 3600;

let startTime = Date.now();
let issData = null;
let trailPoints = [];

// DOM
const issMarker = document.getElementById('issMarker');
const issFootprint = document.getElementById('issFootprint');
const issDistanceEl = document.getElementById('issDistance');
const youDistanceEl = document.getElementById('youDistance');
const elapsedTimeEl = document.getElementById('elapsedTime');
const issSpeedEl = document.getElementById('issSpeed');
const issAltitudeEl = document.getElementById('issAltitude');
const issLatEl = document.getElementById('issLat');
const issLonEl = document.getElementById('issLon');
const issVisEl = document.getElementById('issVis');
const comparisonTextEl = document.getElementById('comparisonText');
const apiStatusEl = document.getElementById('apiStatus');
const mapContainer = document.querySelector('.map-container');
const mapCoordsEl = document.getElementById('mapCoords');
const systemTimeEl = document.getElementById('systemTime');
const footprintSizeEl = document.getElementById('footprintSize');

function coordsToPosition(lat, lon) {
  const x = ((lon + 180) / 360) * 100;
  const y = ((90 - lat) / 180) * 100;
  return { x, y };
}

function updateISSPosition(lat, lon, footprint) {
  const pos = coordsToPosition(lat, lon);
  issMarker.style.left = pos.x + '%';
  issMarker.style.top = pos.y + '%';

  // フットプリント（可視範囲）
  const fpSize = (footprint / 40075) * 360; // 地球の円周に対する割合
  const fpPercent = (fpSize / 360) * 100;
  issFootprint.style.left = pos.x + '%';
  issFootprint.style.top = pos.y + '%';
  issFootprint.style.width = fpPercent + '%';
  issFootprint.style.height = (fpPercent * 2) + '%';

  mapCoordsEl.textContent = `LAT ${lat.toFixed(4)} / LON ${lon.toFixed(4)}`;
  addTrailPoint(pos.x, pos.y);
}

function addTrailPoint(x, y) {
  const trail = document.createElement('div');
  trail.className = 'iss-trail';
  trail.style.left = x + '%';
  trail.style.top = y + '%';
  mapContainer.appendChild(trail);
  trailPoints.push(trail);

  if (trailPoints.length > 80) {
    const old = trailPoints.shift();
    old.remove();
  }

  setTimeout(() => {
    trail.style.opacity = '0';
    trail.style.transition = 'opacity 5s';
  }, 100);
}

function formatNumber(num) {
  return num.toLocaleString('ja-JP', { maximumFractionDigits: 1 });
}

function formatTime(ms) {
  const totalSec = Math.floor(ms / 1000);
  const hours = Math.floor(totalSec / 3600);
  const mins = Math.floor((totalSec % 3600) / 60);
  const secs = totalSec % 60;
  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function formatSystemTime() {
  const now = new Date();
  return now.toTimeString().split(' ')[0] + ' JST';
}

function generateComparison(distanceKm) {
  const comparisons = [
    { threshold: 1, text: `In the time it takes to sip coffee, <span class="highlight">${formatNumber(distanceKm)} km</span>` },
    { threshold: 10, text: `Tokyo–Yokohama (28 km) <span class="highlight">×${(distanceKm / 28).toFixed(1)}</span>` },
    { threshold: 50, text: `Shinkansen Nozomi (285 km/h) <span class="highlight">×${(ISS_SPEED_KMH / 285).toFixed(0)}</span>` },
    { threshold: 200, text: `Tokyo–Osaka (500 km) <span class="highlight">${((distanceKm / 500) * 100).toFixed(1)}%</span>` },
    { threshold: 500, text: `Tokyo–Osaka <span class="highlight">×${(distanceKm / 500).toFixed(2)}</span>` },
    { threshold: 2000, text: `Japan archipelago (3,000 km) <span class="highlight">×${(distanceKm / 3000).toFixed(2)}</span>` },
    { threshold: 10000, text: `Earth circumference <span class="highlight">${((distanceKm / 40075) * 100).toFixed(1)}%</span>` },
    { threshold: 40000, text: `orbited Earth <span class="highlight">×${(distanceKm / 40075).toFixed(2)}</span>` },
  ];

  let text = comparisons[0].text;
  for (const c of comparisons) {
    if (distanceKm >= c.threshold) text = c.text;
  }

  return text + `<br><span class="dim">…still on the couch?</span>`;
}

async function fetchISSData() {
  try {
    const response = await fetch(ISS_API);
    if (!response.ok) throw new Error('API error');

    issData = await response.json();

    updateISSPosition(issData.latitude, issData.longitude, issData.footprint);

    issSpeedEl.innerHTML = formatNumber(issData.velocity) + '<span class="data-unit">km/h</span>';
    issAltitudeEl.innerHTML = formatNumber(issData.altitude) + '<span class="data-unit">km</span>';
    issLatEl.textContent = issData.latitude.toFixed(4) + '°';
    issLonEl.textContent = issData.longitude.toFixed(4) + '°';
    issVisEl.textContent = issData.visibility.toUpperCase();
    footprintSizeEl.textContent = formatNumber(issData.footprint) + ' km';

    apiStatusEl.innerHTML = '<span class="status-indicator"></span> LIVE • ' + issData.visibility.toUpperCase();
    apiStatusEl.className = 'api-status success';

  } catch (error) {
    console.error('ISS API Error:', error);
    apiStatusEl.innerHTML = '<span class="status-indicator" style="background:#ff6b6b"></span> OFFLINE';
    apiStatusEl.className = 'api-status error';
  }
}

function update() {
  const elapsed = Date.now() - startTime;
  const elapsedSec = elapsed / 1000;

  elapsedTimeEl.textContent = formatTime(elapsed);
  systemTimeEl.textContent = formatSystemTime();

  const issDistance = ISS_SPEED_KMS * elapsedSec;
  issDistanceEl.textContent = formatNumber(issDistance);
  youDistanceEl.textContent = '0';

  comparisonTextEl.innerHTML = generateComparison(issDistance);

  requestAnimationFrame(update);
}

fetchISSData();
setInterval(fetchISSData, 5000);
update();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
