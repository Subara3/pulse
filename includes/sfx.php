<?php
/**
 * PULSE Chill Audio Engine（Web Audio API / 音源はサイン波のみ）
 *
 * ---------------------------------------------------------------------------
 * 考え方
 * ---------------------------------------------------------------------------
 * 各ページの 1200 箇所を超える SFX.play() 呼び出しを「効果音」ではなく
 * 「1つの曲の中の音符」として鳴らす。
 *
 *  - 音階はマイナー・ペンタトニック。どの音が同時に鳴っても濁らない。
 *  - コードは 4 つを約 7 秒ずつ巡回する。鳴る音は必ずその時のコード構成音。
 *  - 発音時刻は 1/16 グリッドにスナップする。連打してもリズムになる。
 *  - キー（基音）はページ名から決まる。ページごとに響きが違い、再訪しても同じ。
 *  - 常時鳴り続ける低音のドローンは置かない（重低音のうなりが不快だったので 2026-11 に廃止）。
 *    代わりに、遠くで鳴る単音がときどき入る。
 *  - 低すぎる音は 1 オクターブずつ上げて鳴らす（FLOOR_HZ）。
 *
 * ---------------------------------------------------------------------------
 * 既存ページ向け API（変更なし・そのまま動く）
 * ---------------------------------------------------------------------------
 *   SFX.play('click');            // UI クリック
 *   SFX.play('toggle');           // モード切替
 *   SFX.play('spawn');            // エンティティ生成
 *   SFX.play('pop');              // エンティティ消滅
 *   SFX.play('collide');          // 衝突・接触
 *   SFX.play('ripple');           // 波紋・水滴
 *   SFX.play('tick');             // 刻み音
 *   SFX.play('move');             // 移動
 *   SFX.play('success');          // 決定・成功
 *   SFX.play('grow');             // 成長
 *   SFX.play('fail');             // 失敗
 *   SFX.play('correct');          // 正解
 *   SFX.play('complete');         // 完了
 *   SFX.play('type');             // タイプ
 *   SFX.play('collide', 50);      // 第2引数はスロットル(ms)
 *
 * ---------------------------------------------------------------------------
 * 追加 API
 * ---------------------------------------------------------------------------
 *   PulseAudio.enable() / disable() / toggle()   // 音のオンオフ
 *   PulseAudio.state()                           // 'on' | 'off' | null(未選択)
 *   PulseAudio.onChange(fn)                      // 状態変化の購読
 *   PulseAudio.note(semitone, opts)              // 基音からの半音で 1 音
 *   PulseAudio.chordNote(index, octave, opts)    // 今のコードの構成音
 *   PulseAudio.arp(list, spacing, opts)          // アルペジオ
 *   PulseAudio.sweep(from, to, steps, opts)      // スケール上のグリッサンド
 */

// プレビュー撮影（?preview=1）ではオーディオを一切積まない。
// 各ページ末尾からも直接 require されるので、header.php 側だけでなくここでも弾く。
// SFX の呼び出し自体は header.php の no-op スタブが受ける。
if (isset($_GET['preview']) && $_GET['preview'] === '1') return;
?>

<script>
/* PulseAudio: 音の状態管理と合成。header.php から読み込まれ、ページ本体より先に立つ。 */
window.PulseAudio = (function () {
  'use strict';

  var STORE_KEY = 'pulse_audio';

  /* ---------------------------------------------------------------- 状態 */
  var choice = null;                  // 'on' | 'off' | null（未選択）
  try { choice = localStorage.getItem(STORE_KEY); } catch (e) { choice = null; }
  if (choice !== 'on' && choice !== 'off') choice = null;

  var listeners = [];
  var reduceMotion = false;
  try {
    reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch (e) {}

  /* ------------------------------------------------------------ 音楽設定 */
  /* ページ名からキーを決める。同じページなら毎回同じ響きになる。 */
  var slug = (location.pathname.split('/').pop() || 'index').replace(/\.php$/, '') || 'index';
  function hashOf(s) {
    var h = 2166136261;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h * 16777619) >>> 0; }
    return h >>> 0;
  }
  var H = hashOf(slug);

  /* 基音は A1 付近の 7 通り。低めに置いて、上に積んでいく。 */
  var ROOT_TABLE = [55.00, 58.27, 61.74, 65.41, 69.30, 73.42, 82.41];
  var ROOT = ROOT_TABLE[H % ROOT_TABLE.length];

  /* マイナー・ペンタトニック */
  var SCALE = [0, 3, 5, 7, 10];

  /* コード進行（基音からの度数 + 構成音）。7.2 秒 = 1 コード。 */
  var PROG = [
    { deg: 0,  tones: [0, 3, 7, 10, 14] },   // i7
    { deg: 8,  tones: [0, 4, 7, 11, 14] },   // VI maj7
    { deg: 3,  tones: [0, 4, 7, 11, 14] },   // III maj7
    { deg: 10, tones: [0, 4, 7, 10, 14] }    // VII 7
  ];
  var BAR = 7.2;          // 1 コードの長さ（秒）
  var GRID = 0.15;        // 発音のクオンタイズ幅（1/16 相当）

  /* ------------------------------------------------------ オーディオ資源 */
  var ctx = null;
  var master = null, tone = null, dryBus = null, fxSend = null;
  var delayNode = null, delayFb = null, delayLp = null, convolver = null;
  var padGain = null, padOscs = [], padGains = [], padLfo = null;
  var built = false, padRunning = false;

  var MASTER_VOL = 0.72;   /* 全体音量 */
  var MAX_VOICES = 20;
  /* これより低い音は、同じ音名のままオクターブを上げる。低い帯域のうなりを出さない */
  var FLOOR_HZ = 165;
  var voices = 0;
  var recentSlots = [];   // 同一グリッド・同一音程の重複を潰す

  function ready() { return ctx && built && choice === 'on'; }

  /* リバーブ用のインパルス応答をその場で作る（外部ファイルを持たない方針） */
  function makeImpulse(seconds, decay) {
    var rate = ctx.sampleRate;
    var len = Math.floor(rate * seconds);
    var buf = ctx.createBuffer(2, len, rate);
    var l = buf.getChannelData(0), r = buf.getChannelData(1);
    var inv = 1 / len;
    for (var i = 0; i < len; i++) {
      var env = Math.pow(1 - i * inv, decay);
      l[i] = (Math.random() * 2 - 1) * env;
      r[i] = (Math.random() * 2 - 1) * env;
    }
    return buf;
  }

  function build() {
    if (built) return;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    try { ctx = new AC(); } catch (e) { return; }

    master = ctx.createGain();
    master.gain.value = 0.0001;
    master.connect(ctx.destination);

    /* 同時発音が増えても割れないように均す */
    var comp = ctx.createDynamicsCompressor();
    comp.threshold.value = -20;
    comp.knee.value = 14;
    comp.ratio.value = 5;
    comp.attack.value = 0.006;
    comp.release.value = 0.28;
    comp.connect(master);

    /* チルに寄せるための全体ローパス */
    tone = ctx.createBiquadFilter();
    tone.type = 'lowpass';
    tone.frequency.value = 2600;
    tone.Q.value = 0.6;
    tone.connect(comp);

    dryBus = ctx.createGain();
    dryBus.gain.value = 0.9;
    dryBus.connect(tone);

    /* ディレイ（付点8分あたり）。音符の隙間を埋める。 */
    fxSend = ctx.createGain();
    fxSend.gain.value = 0.5;

    delayNode = ctx.createDelay(1.5);
    delayNode.delayTime.value = 0.36;
    delayFb = ctx.createGain();
    delayFb.gain.value = 0.34;
    delayLp = ctx.createBiquadFilter();
    delayLp.type = 'lowpass';
    delayLp.frequency.value = 1700;

    fxSend.connect(delayNode);
    delayNode.connect(delayLp);
    delayLp.connect(delayFb);
    delayFb.connect(delayNode);
    delayLp.connect(tone);

    /* リバーブ。インパルス応答の生成は数十 ms かかり、そのままだと
       最初のクリックのフレームを落とす（描画ループを持つページに響く）。
       ここでは繋がず、次のタスクで作って後から挿す。 */
    setTimeout(buildReverb, 0);

    /* アンビエントパッド */
    padGain = ctx.createGain();
    padGain.gain.value = 0.0001;
    padGain.connect(tone);
    padGain.connect(fxSend);

    built = true;
  }

  function buildReverb() {
    if (!ctx || convolver) return;
    convolver = ctx.createConvolver();
    convolver.buffer = makeImpulse(2.2, 2.4);
    var revGain = ctx.createGain();
    revGain.gain.value = 0.55;
    fxSend.connect(convolver);
    convolver.connect(revGain);
    revGain.connect(tone);
  }

  /* -------------------------------------------------------------- 音楽時間 */
  function barIndex() {
    if (!ctx) return 0;
    return Math.floor(ctx.currentTime / BAR) % PROG.length;
  }
  function chord() { return PROG[barIndex()]; }

  /* 現在のコードの i 番目の構成音（基音からの半音） */
  function chordSemi(i, octave) {
    var c = chord();
    var t = c.tones[((i % c.tones.length) + c.tones.length) % c.tones.length];
    return c.deg + t + (octave || 0) * 12;
  }
  /* スケール上の i 番目（基音からの半音） */
  function scaleSemi(i, octave) {
    var n = SCALE.length;
    var idx = ((i % n) + n) % n;
    var oct = Math.floor(i / n) + (octave || 0);
    return SCALE[idx] + oct * 12;
  }

  function freqOf(semi) { return ROOT * Math.pow(2, semi / 12); }

  function snap(t) { return Math.ceil(t / GRID) * GRID; }

  /* 同じ瞬間に同じ音程が重ならないようにする（連打を 1 音にまとめる） */
  function slotFree(at, semi) {
    var key = Math.round(at / GRID) + ':' + semi;
    if (recentSlots.indexOf(key) !== -1) return false;
    recentSlots.push(key);
    if (recentSlots.length > 64) recentSlots.shift();
    return true;
  }

  /* ------------------------------------------------------------------ 発音 */
  /**
   * サイン波 1 音。opts:
   *   vol      音量（既定 0.07）
   *   dur      減衰までの秒数（既定 1.3）
   *   attack   立ち上がり秒（既定 0.012）
   *   at       発音時刻のオフセット秒（既定 0）
   *   shimmer  オクターブ上を薄く重ねる（既定 false）
   *   glide    終端の周波数比（例 0.5 で 1 オクターブ下へ）
   *   quantize false でグリッドにスナップしない
   *   pan      -1..1
   */
  function note(semi, opts) {
    if (!ready()) return;
    opts = opts || {};
    if (voices >= MAX_VOICES) return;

    var now = ctx.currentTime;
    var at = now + (opts.at || 0);
    if (opts.quantize !== false) at = snap(at) + (opts.at || 0);
    if (at < now) at = now;
    if (!slotFree(at, semi)) return;

    var dur = opts.dur == null ? 1.3 : opts.dur;
    var vol = opts.vol == null ? 0.07 : opts.vol;
    var atk = opts.attack == null ? 0.012 : opts.attack;
    var f = freqOf(semi);
    while (f < FLOOR_HZ) f *= 2;
    if (f > 12000) return;

    var osc = ctx.createOscillator();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(f, at);
    if (opts.glide) {
      osc.frequency.exponentialRampToValueAtTime(Math.max(20, f * opts.glide), at + dur * 0.85);
    }

    var g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, at);
    g.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol), at + atk);
    g.gain.exponentialRampToValueAtTime(0.0001, at + dur);

    osc.connect(g);

    var out = g;
    if (opts.pan != null && ctx.createStereoPanner) {
      var p = ctx.createStereoPanner();
      p.pan.value = Math.max(-1, Math.min(1, opts.pan));
      g.connect(p);
      out = p;
    }
    out.connect(dryBus);
    out.connect(fxSend);

    voices++;
    osc.start(at);
    osc.stop(at + dur + 0.06);
    osc.onended = function () {
      voices--;
      try { osc.disconnect(); g.disconnect(); if (out !== g) out.disconnect(); } catch (e) {}
    };

    /* きらめき（オクターブ上を薄く）。ベル系だけに付ける。 */
    if (opts.shimmer && voices < MAX_VOICES) {
      note(semi + 12, {
        vol: vol * 0.33, dur: dur * 0.8, attack: atk,
        at: opts.at || 0, quantize: false, pan: opts.pan
      });
    }
  }

  function arp(semis, spacing, opts) {
    opts = opts || {};
    var sp = spacing == null ? 0.11 : spacing;
    for (var i = 0; i < semis.length; i++) {
      var o = {};
      for (var k in opts) if (Object.prototype.hasOwnProperty.call(opts, k)) o[k] = opts[k];
      o.at = (opts.at || 0) + i * sp;
      o.quantize = (i === 0 && opts.quantize !== false);
      note(semis[i], o);
    }
  }

  /* --------------------------------------------------------------- パッド */
  function startPad() {
    if (!ready() || padRunning || reduceMotion) return;
    padRunning = true;

    /* 以前はここでサイン波 4 本のドローンを常時鳴らしていた。重低音のうなりになるので廃止した。 */

    /* 遠くで鳴る単音。何も操作していなくても部屋が静かにならない。 */
    (function chime() {
      var wait = 7000 + Math.random() * 11000;
      setTimeout(function () {
        if (ready() && padRunning && document.visibilityState === 'visible') {
          note(chordSemi(2 + Math.floor(Math.random() * 3), 2), {
            vol: 0.05, dur: 3.4, attack: 0.08, shimmer: true,
            pan: (Math.random() * 2 - 1) * 0.6
          });
        }
        chime();
      }, wait);
    })();
  }

  function stopPad() {
    if (!padRunning) return;
    padRunning = false;
    try { padGain.gain.setTargetAtTime(0.0001, ctx.currentTime, 0.6); } catch (e) {}
    var oscs = padOscs.slice(), lfo = padLfo;
    padOscs = []; padGains = []; padLfo = null;
    setTimeout(function () {
      for (var i = 0; i < oscs.length; i++) { try { oscs[i].stop(); oscs[i].disconnect(); } catch (e) {} }
      if (lfo) { try { lfo.stop(); lfo.disconnect(); } catch (e) {} }
    }, 2500);
  }

  /* ------------------------------------------------------------ オンオフ */
  function fadeMasterTo(v) {
    if (!ctx) return;
    try { master.gain.setTargetAtTime(Math.max(0.0001, v), ctx.currentTime, 0.35); } catch (e) {}
  }

  function emit() {
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i](choice); } catch (e) {}
    }
  }

  function persist() {
    try { localStorage.setItem(STORE_KEY, choice); } catch (e) {}
  }

  function enable() {
    choice = 'on';
    persist();
    build();
    if (ctx && ctx.state === 'suspended') ctx.resume();
    fadeMasterTo(document.visibilityState === 'hidden' ? 0.0001 : MASTER_VOL);
    startPad();
    emit();
  }

  function disable() {
    choice = 'off';
    persist();
    stopPad();
    fadeMasterTo(0.0001);
    emit();
  }

  /* タブが隠れている間は鳴らさない */
  document.addEventListener('visibilitychange', function () {
    if (!ctx || choice !== 'on') return;
    if (document.visibilityState === 'hidden') fadeMasterTo(0.0001);
    else { if (ctx.state === 'suspended') ctx.resume(); fadeMasterTo(MASTER_VOL); }
  });

  /* 「音あり」を選んだ状態で再訪した場合。自動再生は許可されないので、
     最初の操作で AudioContext を起こす。
     ただし AudioContext の生成そのものに数十 ms かかり、それを最初のクリックで
     やると描画ループを持つページがフレームを落とす。生成は読み込み後の暇な時間に
     済ませておき、操作時は resume() だけにする。 */
  if (choice === 'on') {
    var boot = function () { build(); };
    if (window.requestIdleCallback) window.requestIdleCallback(boot, { timeout: 2500 });
    else setTimeout(boot, 700);

    var wake = function () {
      build();
      if (ctx && ctx.state === 'suspended') ctx.resume();
      fadeMasterTo(document.visibilityState === 'hidden' ? 0.0001 : MASTER_VOL);
      startPad();
      document.removeEventListener('pointerdown', wake);
      document.removeEventListener('keydown', wake);
      document.removeEventListener('touchstart', wake);
      document.removeEventListener('wheel', wake);
    };
    document.addEventListener('pointerdown', wake, { passive: true });
    document.addEventListener('keydown', wake);
    document.addEventListener('touchstart', wake, { passive: true });
    document.addEventListener('wheel', wake, { passive: true });
  }

  return {
    state: function () { return choice; },
    isEnabled: function () { return choice === 'on'; },
    enable: enable,
    disable: disable,
    toggle: function () { if (choice === 'on') disable(); else enable(); },
    onChange: function (fn) { if (typeof fn === 'function') listeners.push(fn); },
    note: note,
    arp: arp,
    chordSemi: chordSemi,
    scaleSemi: scaleSemi,
    chordNote: function (i, oct, opts) { note(chordSemi(i, oct), opts); },
    bar: barIndex,
    ready: ready,
    /* 調整・検証用 */
    context: function () { return ctx; },
    buses: function () { return { master: master, dry: dryBus, fx: fxSend, pad: padGain }; },
    setVolume: function (v) { if (ctx) master.gain.setTargetAtTime(Math.max(0.0001, v), ctx.currentTime, 0.2); }
  };
})();

/* SFX: 既存 1200 箇所の呼び出し口。中身は PulseAudio の音符に置き換わっている。
   const にすると TDZ が生まれ、これより前に走る初期化コードの bare な SFX 参照が
   ReferenceError でページ全体を殺す。window への代入のままにしておくこと。 */
window.SFX = (function () {
  'use strict';

  var PA = window.PulseAudio;
  var lastPlayed = {};
  var lastAny = 0;
  var lastDegree = 2;

  /* 直前の音から近い構成音を選ぶ。跳躍が減って旋律らしく聞こえる。 */
  function nextDegree(spread) {
    var d = lastDegree + (Math.random() < 0.5 ? -1 : 1) * (1 + Math.floor(Math.random() * (spread || 2)));
    if (d < 0) d += 5;
    lastDegree = d % 5;
    return lastDegree;
  }

  var sounds = {

    /* --- ビジュアライゼーション向け --- */

    /* 生成。上の方で澄んだ音が 1 つ灯る */
    spawn: function () {
      PA.note(PA.chordSemi(nextDegree(2), 2), { vol: 0.09, dur: 2.0, attack: 0.008, shimmer: true, pan: (Math.random() * 2 - 1) * 0.5 });
    },

    /* 消滅。5 度から根音へ落ちる */
    pop: function () {
      PA.note(PA.chordSemi(2, 1), { vol: 0.085, dur: 0.9, glide: 0.66, attack: 0.006 });
    },

    /* 衝突。低い位置で短く鳴る */
    collide: function () {
      PA.note(PA.chordSemi(0, 0), { vol: 0.095, dur: 0.55, attack: 0.004 });
    },

    /* 波紋。高い鈴。ディレイに長く残る */
    ripple: function () {
      PA.note(PA.chordSemi(nextDegree(3), 2), { vol: 0.085, dur: 2.6, attack: 0.006, shimmer: true, pan: (Math.random() * 2 - 1) * 0.7 });
    },

    /* 刻み。音楽の中の点。うるさくしない */
    tick: function () {
      PA.note(PA.chordSemi(4, 2), { vol: 0.038, dur: 0.42, attack: 0.003 });
    },

    /* 切替。根音から 5 度へ */
    toggle: function () {
      PA.arp([PA.chordSemi(0, 1), PA.chordSemi(2, 1)], 0.1, { vol: 0.085, dur: 1.2, attack: 0.008 });
    },

    /* --- UI 向け --- */

    click: function () {
      PA.note(PA.chordSemi(nextDegree(2), 1), { vol: 0.1, dur: 1.1, attack: 0.006, shimmer: true });
    },

    move: function () {
      PA.note(PA.chordSemi(nextDegree(1), 0), { vol: 0.058, dur: 0.9, attack: 0.05 });
    },

    /* 成功。三和音を駆け上がる */
    success: function () {
      PA.arp([PA.chordSemi(0, 1), PA.chordSemi(1, 1), PA.chordSemi(2, 1)], 0.1,
        { vol: 0.095, dur: 1.6, attack: 0.008, shimmer: true });
    },

    /* 成長。スケールを 5 段のぼる */
    grow: function () {
      var s = [];
      for (var i = 0; i < 5; i++) s.push(PA.scaleSemi(i + 5, 0));
      PA.arp(s, 0.09, { vol: 0.075, dur: 1.4, attack: 0.008 });
    },

    /* 失敗。同じ音階のまま降りる。刺さない */
    fail: function () {
      PA.arp([PA.chordSemi(2, 1), PA.chordSemi(1, 0), PA.chordSemi(0, 0)], 0.14,
        { vol: 0.09, dur: 1.8, attack: 0.01 });
    },

    correct: function () {
      PA.arp([PA.chordSemi(2, 1), PA.chordSemi(0, 2)], 0.09, { vol: 0.09, dur: 1.5, shimmer: true });
    },

    type: function () {
      PA.note(PA.chordSemi(3 + Math.floor(Math.random() * 2), 2), { vol: 0.034, dur: 0.35, attack: 0.003 });
    },

    /* 完了。四和音 + 低音の支え */
    complete: function () {
      PA.arp([PA.chordSemi(0, 1), PA.chordSemi(1, 1), PA.chordSemi(2, 1), PA.chordSemi(3, 2)], 0.11,
        { vol: 0.095, dur: 2.2, attack: 0.01, shimmer: true });
      PA.note(PA.chordSemi(0, -1), { vol: 0.07, dur: 3.0, attack: 0.05 });
    }
  };

  return {
    play: function (name, throttleMs) {
      if (!PA || !PA.ready()) return;
      var fn = sounds[name];
      if (!fn) return;

      var now = performance.now();
      /* 呼び出しが集中してもリズムが潰れないよう、全体にも下限を置く */
      if (now - lastAny < 26) return;
      if (throttleMs) {
        if (lastPlayed[name] && now - lastPlayed[name] < throttleMs) return;
      }
      lastPlayed[name] = now;
      lastAny = now;
      window.__pulseSfxAt = now;   // 汎用インタラクション層がページの沈黙を判定するのに使う
      fn();
    },
    /* 旧 API 互換 */
    toggle: function () { if (PA) PA.toggle(); },
    isEnabled: function () { return PA ? PA.isEnabled() : false; }
  };
})();

/* ---------------------------------------------------------------------------
   汎用インタラクション層
   SFX を呼んでいないページ（一覧・ログ・about など、および音の薄いページ）でも
   触れば音が返るようにする。ページ自身が鳴らしている間は黙る。
   --------------------------------------------------------------------------- */
(function () {
  'use strict';
  var PA = window.PulseAudio;
  if (!PA) return;

  var lastTouch = 0;
  var lastMoveNote = 0;

  function pageIsSpeaking() {
    return window.__pulseSfxAt && (performance.now() - window.__pulseSfxAt) < 1200;
  }

  /* 画面上の位置を音程に変換する。横 = 音階、縦 = 高さ。 */
  function noteAt(x, y, opts) {
    var w = window.innerWidth || 1, h = window.innerHeight || 1;
    var step = Math.floor((x / w) * 10);              // 2 オクターブ分のスケール
    var oct = y < h * 0.45 ? 2 : 1;
    PA.note(PA.scaleSemi(step, oct), opts);
  }

  document.addEventListener('pointerdown', function (e) {
    if (!PA.ready() || pageIsSpeaking()) return;
    var t = performance.now();
    if (t - lastTouch < 60) return;
    lastTouch = t;
    noteAt(e.clientX, e.clientY, {
      vol: 0.09, dur: 1.8, attack: 0.008, shimmer: true,
      pan: (e.clientX / (window.innerWidth || 1)) * 1.4 - 0.7
    });
  }, { passive: true });

  document.addEventListener('pointermove', function (e) {
    if (!PA.ready() || pageIsSpeaking()) return;
    if (e.buttons !== 1 && e.pointerType !== 'touch') return;
    var t = performance.now();
    if (t - lastMoveNote < 150) return;
    lastMoveNote = t;
    noteAt(e.clientX, e.clientY, {
      vol: 0.05, dur: 1.2, attack: 0.02,
      pan: (e.clientX / (window.innerWidth || 1)) * 1.4 - 0.7
    });
  }, { passive: true });

  /* リンクやボタンの上を通ったときの小さな返事 */
  var lastHover = 0;
  document.addEventListener('pointerover', function (e) {
    if (!PA.ready() || pageIsSpeaking()) return;
    var el = e.target;
    if (!el || !el.closest) return;
    if (!el.closest('a, button, .control-btn, .card, .fp-item')) return;
    var t = performance.now();
    if (t - lastHover < 90) return;
    lastHover = t;
    PA.note(PA.chordSemi(1 + Math.floor(Math.random() * 3), 2), { vol: 0.036, dur: 0.9, attack: 0.006 });
  }, { passive: true });
})();
</script>
