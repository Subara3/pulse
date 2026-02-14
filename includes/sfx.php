<?php
/**
 * 効果音システム（Web Audio API）
 * 
 * 使い方:
 * require_once __DIR__ . '/includes/sfx.php';
 * 
 * JavaScript:
 * SFX.play('success');  // 決定音・成功音
 * SFX.play('grow');     // 成長音・レベルアップ
 * SFX.play('fail');     // ゲームオーバー・失敗
 * SFX.play('click');    // クリック音
 * SFX.play('move');     // 移動音
 * SFX.toggle();         // オンオフ切り替え
 */
?>

<style>
/* 効果音トグルボタン */
.sfx-toggle {
  position: fixed;
  top: 1.5rem;
  right: 5rem;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.4);
  padding: 0.4rem 0.8rem;
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
  z-index: 100;
}

.sfx-toggle:hover {
  border-color: rgba(255,255,255,0.3);
  color: rgba(255,255,255,0.6);
}

.sfx-toggle.on {
  border-color: rgba(0, 245, 212, 0.4);
  color: rgba(0, 245, 212, 0.7);
}

.sfx-toggle.on::before {
  content: '🔊 ';
}

.sfx-toggle.off::before {
  content: '🔇 ';
}

.hide-ui .sfx-toggle {
  opacity: 0 !important;
  pointer-events: none;
}
</style>

<button class="sfx-toggle on" id="sfxToggle">SFX</button>

<script>
const SFX = (function() {
  let audioCtx = null;
  let enabled = true;
  
  // 遅延初期化（ユーザー操作後に初期化）
  function initAudio() {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
  }
  
  // 基本的なトーン生成
  function playTone(freq, duration, type = 'sine', volume = 0.3, attack = 0.01, decay = 0.1) {
    if (!enabled) return;
    initAudio();
    
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    
    osc.type = type;
    osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
    
    gain.gain.setValueAtTime(0, audioCtx.currentTime);
    gain.gain.linearRampToValueAtTime(volume, audioCtx.currentTime + attack);
    gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + duration);
    
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    
    osc.start();
    osc.stop(audioCtx.currentTime + duration);
  }
  
  // 周波数スイープ
  function playSweep(startFreq, endFreq, duration, type = 'sine', volume = 0.3) {
    if (!enabled) return;
    initAudio();
    
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    
    osc.type = type;
    osc.frequency.setValueAtTime(startFreq, audioCtx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(endFreq, audioCtx.currentTime + duration);
    
    gain.gain.setValueAtTime(volume, audioCtx.currentTime);
    gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + duration);
    
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    
    osc.start();
    osc.stop(audioCtx.currentTime + duration);
  }
  
  // 効果音定義
  const sounds = {
    // 決定音・成功音（上昇する和音）
    success: function() {
      playTone(523.25, 0.15, 'sine', 0.2);  // C5
      setTimeout(() => playTone(659.25, 0.15, 'sine', 0.2), 50);  // E5
      setTimeout(() => playTone(783.99, 0.2, 'sine', 0.25), 100); // G5
    },
    
    // 成長音・レベルアップ（キラキラ上昇）
    grow: function() {
      playSweep(400, 800, 0.2, 'sine', 0.2);
      setTimeout(() => playSweep(500, 1000, 0.2, 'sine', 0.15), 100);
      setTimeout(() => playSweep(600, 1200, 0.25, 'sine', 0.2), 200);
    },
    
    // ゲームオーバー・失敗（下降音）
    fail: function() {
      playSweep(400, 150, 0.4, 'sawtooth', 0.15);
      setTimeout(() => playTone(100, 0.3, 'sine', 0.2), 200);
    },
    
    // クリック音（短いポップ）
    click: function() {
      playTone(800, 0.05, 'sine', 0.15);
    },
    
    // 移動音（軽いスライド）
    move: function() {
      playSweep(300, 400, 0.08, 'sine', 0.1);
    },
    
    // 正解音（ピコッ）
    correct: function() {
      playTone(880, 0.1, 'sine', 0.2);
      setTimeout(() => playTone(1108.73, 0.15, 'sine', 0.15), 50);
    },
    
    // タイプ音（キーボード風）
    type: function() {
      playTone(600 + Math.random() * 200, 0.03, 'square', 0.05);
    },
    
    // 完了音（ファンファーレ風）
    complete: function() {
      playTone(523.25, 0.1, 'sine', 0.2);
      setTimeout(() => playTone(659.25, 0.1, 'sine', 0.2), 80);
      setTimeout(() => playTone(783.99, 0.1, 'sine', 0.2), 160);
      setTimeout(() => playTone(1046.50, 0.3, 'sine', 0.25), 240);
    }
  };
  
  // トグルボタン
  const toggleBtn = document.getElementById('sfxToggle');
  if (toggleBtn) {
    // ローカルストレージから設定を復元
    const saved = localStorage.getItem('pulse_sfx_enabled');
    if (saved !== null) {
      enabled = saved === 'true';
      toggleBtn.className = 'sfx-toggle ' + (enabled ? 'on' : 'off');
    }
    
    toggleBtn.addEventListener('click', function() {
      enabled = !enabled;
      this.className = 'sfx-toggle ' + (enabled ? 'on' : 'off');
      localStorage.setItem('pulse_sfx_enabled', enabled);
      
      // フィードバック音
      if (enabled) {
        sounds.click();
      }
    });
  }
  
  return {
    play: function(name) {
      if (sounds[name]) {
        sounds[name]();
      }
    },
    toggle: function() {
      enabled = !enabled;
      if (toggleBtn) {
        toggleBtn.className = 'sfx-toggle ' + (enabled ? 'on' : 'off');
      }
      localStorage.setItem('pulse_sfx_enabled', enabled);
    },
    isEnabled: function() {
      return enabled;
    }
  };
})();
</script>
