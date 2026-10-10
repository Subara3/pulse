/**
 * PULSE kit — 全ページ共通の小さな仕組み。
 *
 *  PULSE.fav     お気に入り（ブラウザに保存）
 *  PULSE.share() 作品ページの操作状態を URL（#s=...）にしてコピーする
 *  PULSE.tiles() 作品タイル（.work）のお気に入り表示と、プレビュー動画の再生
 *
 * 保存はすべて localStorage。使えない環境（プライベートモード等）でも落ちないようにする。
 */
(function () {
  'use strict';
  var P = window.PULSE = window.PULSE || {};

  /* ------------------------------------------------------------ 保存 */
  function load(key, fallback) {
    try { var v = JSON.parse(localStorage.getItem(key)); return v == null ? fallback : v; }
    catch (e) { return fallback; }
  }
  function save(key, v) {
    try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {}
  }
  function emit(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
  }

  /* ------------------------------------------------------ お気に入り */
  var FAV = 'pulse_fav';
  P.fav = {
    list: function () { var a = load(FAV, []); return Array.isArray(a) ? a : []; },
    has: function (p) { return P.fav.list().indexOf(p) !== -1; },
    toggle: function (p) {
      var a = P.fav.list(), i = a.indexOf(p);
      if (i === -1) a.unshift(p); else a.splice(i, 1);
      save(FAV, a);
      emit('pulse:fav', { page: p, on: i === -1 });
      return i === -1;
    }
  };

  /* -------------------------------------------------------- トースト */
  var toastEl = null, toastTimer = 0;
  P.toast = function (msg, cls) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'pulse-toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.className = 'pulse-toast' + (cls ? ' ' + cls : '');
    toastEl.textContent = msg;
    void toastEl.offsetWidth;
    toastEl.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 2600);
  };

  /* ------------------------------------------------------ 状態の共有 */
  /* 作品ページの #controls の中にあるスライダーの値と、選ばれているボタン（.active）だけを残す。
     どのページも操作部品はこの中に置いているので、ページ側を書き換えずに共有できる。
     乱数で決まる配置までは再現しない。 */
  function controlsRoot() { return document.getElementById('controls'); }
  function buttons(root) {
    return Array.prototype.filter.call(root.querySelectorAll('button'), function (b) {
      return b.id !== 'drawer-toggle';
    });
  }
  function isOn(b) { return b.classList.contains('active') || b.getAttribute('aria-pressed') === 'true'; }

  P.readState = function () {
    var root = controlsRoot();
    if (!root) return null;
    var s = { r: {}, b: [] };
    root.querySelectorAll('input[type="range"]').forEach(function (el, i) {
      s.r[el.id || ('#' + i)] = el.value;
    });
    buttons(root).forEach(function (b, i) { if (isOn(b)) s.b.push(i); });
    return s;
  };

  P.applyState = function (s) {
    var root = controlsRoot();
    if (!root || !s) return;
    var bs = buttons(root), want = s.b || [];
    // 選ばれていないものを選ぶ → 余計に選ばれているものを外す、の順。
    // モード切替は前者だけで揃い、ON/OFF ボタンは後者で揃う。
    want.forEach(function (i) { if (bs[i] && !isOn(bs[i])) bs[i].click(); });
    bs.forEach(function (b, i) { if (want.indexOf(i) === -1 && isOn(b)) b.click(); });
    var ranges = root.querySelectorAll('input[type="range"]');
    ranges.forEach(function (el, i) {
      var v = s.r && s.r[el.id || ('#' + i)];
      if (v == null || el.value === String(v)) return;
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  };

  function encode(o) {
    var json = JSON.stringify(o);
    return btoa(unescape(encodeURIComponent(json))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function decode(str) {
    try {
      str = str.replace(/-/g, '+').replace(/_/g, '/');
      while (str.length % 4) str += '=';
      return JSON.parse(decodeURIComponent(escape(atob(str))));
    } catch (e) { return null; }
  }

  P.shareUrl = function () {
    var s = P.readState();
    var base = location.origin + location.pathname;
    return s && (Object.keys(s.r).length || s.b.length) ? base + '#s=' + encode(s) : base;
  };

  P.share = function () {
    var url = P.shareUrl();
    try { history.replaceState(null, '', url.replace(location.origin, '')); } catch (e) {}
    var coarse = matchMedia('(pointer: coarse)').matches;
    if (coarse && navigator.share) {
      navigator.share({ title: document.title, url: url }).catch(function () {});
      return;
    }
    function done() { P.toast('この状態のリンクをコピーしました'); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url); done(); });
    } else { fallbackCopy(url); done(); }
  };
  function fallbackCopy(text) {
    var t = document.createElement('textarea');
    t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } catch (e) {}
    t.remove();
  }

  /* URL に状態が付いていれば、ページの初期化が終わってから当てる */
  function restoreFromHash() {
    var m = /[#&]s=([A-Za-z0-9_-]+)/.exec(location.hash);
    if (!m) return;
    var s = decode(m[1]);
    if (!s) return;
    setTimeout(function () { P.applyState(s); }, 450);
  }

  /* ------------------------------------------------------ 作品タイル */
  var hoverPlay = matchMedia('(hover: hover) and (pointer: fine)').matches;
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var saveData = navigator.connection && navigator.connection.saveData;

  function attach(v) {
    if (v.dataset.loaded || !v.dataset.mp4) return;
    v.dataset.loaded = '1';
    var s = document.createElement('source');
    s.src = v.dataset.mp4; s.type = 'video/mp4';
    v.appendChild(s);
    v.load();
  }
  function play(v) { attach(v); var p = v.play(); if (p && p.catch) p.catch(function () {}); }

  var io = null;
  function watch(v) {
    if (reduce || saveData) return;
    if (v.hasAttribute('data-autoplay') || !hoverPlay) {
      // 指で見る端末と、常に動かしたい大きなタイルは「画面に十分入っている間」だけ動かす
      if (!io && 'IntersectionObserver' in window) {
        io = new IntersectionObserver(function (es) {
          es.forEach(function (e) {
            if (e.isIntersecting) play(e.target); else if (!e.target.paused) e.target.pause();
          });
        }, { threshold: 0.6 });
      }
      if (io) io.observe(v);
    }
    if (hoverPlay) {
      var host = v.closest('.work') || v;
      host.addEventListener('mouseenter', function () { play(v); });
      if (!v.hasAttribute('data-autoplay')) {
        host.addEventListener('mouseleave', function () { v.pause(); });
      }
    }
  }

  function paintTile(t) {
    var p = t.dataset.page;
    var fav = P.fav.has(p);
    t.classList.toggle('is-fav', fav);
    var b = t.querySelector('[data-fav]');
    if (b) {
      b.setAttribute('aria-pressed', fav ? 'true' : 'false');
      b.setAttribute('aria-label', fav ? 'お気に入りから外す' : 'お気に入りに入れる');
    }
  }

  P.tiles = function (root) {
    (root || document).querySelectorAll('.work').forEach(function (t) {
      if (t.dataset.bound) { paintTile(t); return; }
      t.dataset.bound = '1';
      paintTile(t);
      var b = t.querySelector('[data-fav]');
      if (b) b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        P.fav.toggle(t.dataset.page);
      });
      var v = t.querySelector('video[data-mp4]');
      if (v) watch(v);
    });
  };
  P.playVideo = play;

  document.addEventListener('pulse:fav', function () { P.tiles(); });
  // 別タブでの変更も反映する
  window.addEventListener('storage', function (e) {
    if (e.key === FAV) { P.tiles(); emit('pulse:fav', {}); }
  });

  function boot() {
    P.tiles();
    if (P.page) restoreFromHash();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
