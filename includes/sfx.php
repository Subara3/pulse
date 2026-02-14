<?php
/**
 * 効果音システム（Web Audio API）
 * 
 * 使い方:
 * require_once __DIR__ . '/includes/sfx.php';
 * 
 * JavaScript:
 * SFX.play('click');     // UIクリック音
 * SFX.play('toggle');    // モード切替
 * SFX.play('spawn');     // エンティティ生成（点追加等）
 * SFX.play('pop');       // エンティティ消滅
 * SFX.play('collide');   // 衝突・接触
 * SFX.play('ripple');    // 波紋・水滴
 * SFX.play('tick');      // 刻み音（ステップ進行等）
 * SFX.play('move');      // 移動音
 * SFX.play('success');   // 決定音・成功音
 * SFX.play('grow');      // 成長音・レベルアップ
 * SFX.play('fail');      // ゲームオーバー・失敗
 * SFX.play('correct');   // 正解音
 * SFX.play('complete');  // 完了音
 * SFX.play('type');      // タイプ音
 * SFX.toggle();          // オンオフ切り替え
 *
 * スロットル付き（連打防止）:
 * SFX.play('collide', 50);  // 50ms以内の再発音を抑制
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
  
  // スロットル管理（連打防止）
  const lastPlayed = {};

  // ノイズバースト生成（衝突音等）
  function playNoise(duration, volume = 0.1) {
    if (!enabled) return;
    initAudio();
    const bufferSize = audioCtx.sampleRate * duration;
    const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < bufferSize; i++) {
      data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / bufferSize, 3);
    }
    const source = audioCtx.createBufferSource();
    const gain = audioCtx.createGain();
    const filter = audioCtx.createBiquadFilter();
    source.buffer = buffer;
    filter.type = 'bandpass';
    filter.frequency.value = 2000;
    filter.Q.value = 0.5;
    gain.gain.setValueAtTime(volume, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
    source.connect(filter);
    filter.connect(gain);
    gain.connect(audioCtx.destination);
    source.start();
  }

  // 効果音定義
  const sounds = {
    // === ビジュアライゼーション向け ===

    // エンティティ生成（柔らかいポップ）
    spawn: function() {
      playTone(660, 0.08, 'sine', 0.1);
      playTone(990, 0.06, 'sine', 0.05, 0.01, 0.03);
    },

    // エンティティ消滅（下降ポップ）
    pop: function() {
      playSweep(600, 200, 0.1, 'sine', 0.08);
    },

    // 衝突・接触（短いパーカッシブノイズ）
    collide: function() {
      playNoise(0.06, 0.08);
      playTone(200 + Math.random() * 200, 0.04, 'triangle', 0.06);
    },

    // 波紋・水滴（澄んだ下降ピング）
    ripple: function() {
      playTone(1200 + Math.random() * 400, 0.15, 'sine', 0.08, 0.005, 0.05);
    },

    // 刻み音（メトロノーム的クリック）
    tick: function() {
      playTone(1000, 0.02, 'square', 0.04);
    },

    // モード切替（短いスウィープ）
    toggle: function() {
      playSweep(400, 800, 0.1, 'sine', 0.1);
    },

    // === ゲーム・UI向け ===

    // UIクリック音（短いポップ）
    click: function() {
      playTone(800, 0.05, 'sine', 0.12);
    },

    // 移動音（軽いスライド）
    move: function() {
      playSweep(300, 400, 0.08, 'sine', 0.08);
    },

    // 決定音・成功音（上昇する和音）
    success: function() {
      playTone(523.25, 0.15, 'sine', 0.15);
      setTimeout(() => playTone(659.25, 0.15, 'sine', 0.15), 50);
      setTimeout(() => playTone(783.99, 0.2, 'sine', 0.18), 100);
    },

    // 成長音・レベルアップ（キラキラ上昇）
    grow: function() {
      playSweep(400, 800, 0.2, 'sine', 0.15);
      setTimeout(() => playSweep(500, 1000, 0.2, 'sine', 0.1), 100);
      setTimeout(() => playSweep(600, 1200, 0.25, 'sine', 0.15), 200);
    },

    // ゲームオーバー・失敗（下降音）
    fail: function() {
      playSweep(400, 150, 0.4, 'sawtooth', 0.1);
      setTimeout(() => playTone(100, 0.3, 'sine', 0.15), 200);
    },

    // 正解音（ピコッ）
    correct: function() {
      playTone(880, 0.1, 'sine', 0.15);
      setTimeout(() => playTone(1108.73, 0.15, 'sine', 0.1), 50);
    },

    // タイプ音（キーボード風）
    type: function() {
      playTone(600 + Math.random() * 200, 0.03, 'square', 0.04);
    },

    // 完了音（ファンファーレ風）
    complete: function() {
      playTone(523.25, 0.1, 'sine', 0.15);
      setTimeout(() => playTone(659.25, 0.1, 'sine', 0.15), 80);
      setTimeout(() => playTone(783.99, 0.1, 'sine', 0.15), 160);
      setTimeout(() => playTone(1046.50, 0.3, 'sine', 0.18), 240);
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
    play: function(name, throttleMs) {
      if (!sounds[name]) return;
      if (throttleMs) {
        const now = performance.now();
        if (lastPlayed[name] && now - lastPlayed[name] < throttleMs) return;
        lastPlayed[name] = now;
      }
      sounds[name]();
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
