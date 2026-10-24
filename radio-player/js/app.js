(() => {
  'use strict';

  const $ = (sel) => document.querySelector(sel);
  const audio = $('#audio');

  const store = {
    get(key, fallback) {
      try {
        const v = localStorage.getItem('radio:' + key);
        return v === null ? fallback : JSON.parse(v);
      } catch { return fallback; }
    },
    set(key, value) {
      try { localStorage.setItem('radio:' + key, JSON.stringify(value)); } catch { /* modo privado */ }
    },
  };

  const state = {
    stations: [],
    current: null,
    tab: 'all',
    mode: store.get('mode', 'radio'),
    tvs: [],
    tvCurrent: null,
    hls: null,
    genre: '',
    query: '',
    favorites: new Set(store.get('favorites', [])),
    recent: store.get('recent', []),
    playing: false,
    retries: 0,
    retryTimer: null,
    metaTimer: null,
    sleepTimer: null,
    sleepAt: 0,
    nowPlaying: '',
    genreOrder: [],
    config: null,
    loadedAt: 0,
  };

  const MAX_RETRIES = 5;
  const META_INTERVAL = 15000;

  // ---------- Utilidades ----------

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Capa gerada (iniciais + cor) quando a rádio não tem logo.
  function placeholderCover(name, wide = false) {
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) % 360;
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    const w = wide ? 178 : 100;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} 100">
      <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="hsl(${h},70%,55%)"/><stop offset="1" stop-color="hsl(${(h + 40) % 360},70%,35%)"/>
      </linearGradient></defs>
      <rect width="${w}" height="100" fill="url(#g)"/>
      <text x="${w / 2}" y="50" dy=".35em" text-anchor="middle" font-family="system-ui,sans-serif" font-size="38" font-weight="700" fill="#fff">${escapeHtml(initials)}</text>
    </svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }

  const brokenLogos = new Set();
  const coverOf = (st) => (st.logo && !brokenLogos.has(st.logo) ? st.logo : placeholderCover(st.name));

  function setCover(img, st) {
    img.onerror = () => { img.onerror = null; brokenLogos.add(st.logo); img.src = placeholderCover(st.name); };
    img.src = coverOf(st);
  }

  let toastTimer;
  function toast(msg, ms = 2600) {
    const el = $('#toast');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  // ---------- Lista ----------

  const items = () => (state.mode === 'tv' ? state.tvs : state.stations);

  function filtered() {
    const q = state.query.trim().toLowerCase();
    const all = items();
    let list = all;
    if (state.tab === 'fav') list = list.filter((s) => state.favorites.has(s.id));
    if (state.tab === 'recent') list = state.recent.map((id) => all.find((s) => s.id === id)).filter(Boolean);
    if (state.genre) list = list.filter((s) => s.genre === state.genre);
    if (q) list = list.filter((s) => [s.name, s.genre, s.city, s.slogan, s.description].join(' ').toLowerCase().includes(q));
    return list;
  }

  // Rádios | TV ao vivo: o seletor só aparece quando o app tem os dois.
  function renderModes() {
    const hasRadio = state.stations.length > 0;
    const hasTv = state.tvs.length > 0;
    if (!hasTv) state.mode = 'radio';
    else if (!hasRadio) state.mode = 'tv';
    $('#modes').hidden = !(hasRadio && hasTv);
    document.querySelectorAll('.mode').forEach((b) => {
      const on = b.dataset.mode === state.mode;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', String(on));
    });
    $('#search').placeholder = state.mode === 'tv' ? 'Buscar canal ou estilo' : 'Buscar rádio, cidade ou gênero';
  }

  function renderGenres() {
    const used = new Set(items().map((s) => s.genre).filter(Boolean));
    const genres = state.genreOrder.filter((g) => used.has(g));
    if (state.genre && !genres.includes(state.genre)) state.genre = '';
    const box = $('#genres');
    box.hidden = genres.length < 2;
    box.innerHTML = ['', ...genres].map((g) =>
      `<button class="chip${g === state.genre ? ' active' : ''}" data-genre="${escapeHtml(g)}">${g ? escapeHtml(g) : 'Todos'}</button>`
    ).join('');
  }

  function renderList() {
    const list = filtered();
    const box = $('#list');
    const tv = state.mode === 'tv';
    box.classList.toggle('tv-grid', tv);
    if (!list.length) {
      const what = tv ? 'canal' : 'rádio';
      const msg = state.tab === 'fav' ? `Você ainda não favoritou nenhum ${tv ? 'canal' : 'rádio'}. Toque no ♥ no player.`
        : state.tab === 'recent' ? (tv ? 'Nenhum canal assistido ainda.' : 'Nenhuma rádio ouvida ainda.')
        : items().length ? `Nenhum ${what} encontrado.` : `Nenhum ${what} no ar no momento. Volte mais tarde!`;
      box.innerHTML = `<p class="empty">${msg}</p>`;
      return;
    }
    if (tv) {
      box.innerHTML = list.map(tvCardHtml).join('');
      box.querySelectorAll('img[data-tvcover]').forEach((img) => setTvCover(img, state.tvs.find((t) => t.id === img.dataset.tvcover)));
      return;
    }
    box.innerHTML = list.map((s) => {
      const cur = state.current && state.current.id === s.id;
      const sub = [s.genre, s.city].filter(Boolean).join(' · ') || s.slogan || '';
      return `<button class="station${cur ? ' current' : ''}${cur && state.playing ? ' is-playing' : ''}" data-id="${escapeHtml(s.id)}">
        <span class="tile-art">
          <img class="cover" alt="" loading="lazy" data-cover="${escapeHtml(s.id)}">
          <span class="playing-ind" aria-hidden="true"><i></i><i></i><i></i></span>
        </span>
        <span class="info"><strong>${escapeHtml(s.name)}</strong><small>${escapeHtml(sub)}</small></span>
        ${state.favorites.has(s.id) ? '<svg class="fav" aria-label="Favorita"><use href="#i-heart"/></svg>' : ''}
      </button>`;
    }).join('');
    box.querySelectorAll('img[data-cover]').forEach((img) => {
      setCover(img, state.stations.find((s) => s.id === img.dataset.cover));
    });
  }

  function tvCardHtml(t) {
    const cur = state.tvCurrent && state.tvCurrent.id === t.id;
    const sub = [t.genre, t.description].filter(Boolean).join(' · ');
    return `<button class="tv-card${cur ? ' current' : ''}" data-tv="${escapeHtml(t.id)}">
      <span class="tv-thumb">
        <img alt="" loading="lazy" data-tvcover="${escapeHtml(t.id)}">
        <span class="live-badge">Ao vivo</span>
        <span class="tv-play" aria-hidden="true"><svg><use href="#i-play"/></svg></span>
      </span>
      <span class="info"><strong>${escapeHtml(t.name)}</strong><small>${escapeHtml(sub)}</small></span>
      ${state.favorites.has(t.id) ? '<svg class="fav" aria-label="Favorito"><use href="#i-heart"/></svg>' : ''}
    </button>`;
  }

  function setTvCover(img, t) {
    if (!t) return;
    const fallback = placeholderCover(t.name, true);
    img.onerror = () => { img.onerror = null; img.src = fallback; };
    img.src = t.logo || fallback;
    img.classList.toggle('logo', !!t.logo);
  }

  // ---------- Player ----------

  function setStatus(kind) {
    const el = $('#status');
    const labels = { idle: 'Parado', loading: 'Conectando…', live: 'Ao vivo', error: 'Sem sinal', paused: 'Pausado' };
    el.textContent = labels[kind];
    el.classList.toggle('live', kind === 'live');

    const loading = kind === 'loading';
    const playing = kind === 'live' || kind === 'loading';
    state.playing = kind === 'live';
    for (const btn of [$('#playBtn'), $('#miniPlay')]) {
      btn.classList.toggle('loading', loading);
      btn.querySelector('use').setAttribute('href', playing ? '#i-pause' : '#i-play');
      btn.setAttribute('aria-label', playing ? 'Pausar' : 'Tocar');
    }
    $('#eq').classList.toggle('on', kind === 'live');
    document.body.classList.toggle('is-live', kind === 'live');
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = playing ? 'playing' : (state.current ? 'paused' : 'none');
    markCurrent();
  }

  // Atualiza só o destaque da rádio atual, sem redesenhar a lista.
  function markCurrent() {
    document.querySelectorAll('.station').forEach((el) => {
      const cur = !!state.current && el.dataset.id === state.current.id;
      el.classList.toggle('current', cur);
      el.classList.toggle('is-playing', cur && state.playing);
    });
  }

  function subtitle() {
    const st = state.current;
    if (!st) return 'Escolha uma rádio';
    return state.nowPlaying || st.slogan || [st.genre, st.city].filter(Boolean).join(' · ') || 'Ao vivo';
  }

  function updateInfo() {
    const st = state.current;
    if (!st) return;
    $('#mini').hidden = false;
    $('#stationName').textContent = st.name;
    $('#miniName').textContent = st.name;
    $('#nowPlaying').textContent = subtitle();
    $('#miniNow').textContent = subtitle();
    setCover($('#cover'), st);
    setCover($('#miniCover'), st);
    const bgImg = (state.config && state.config.background) || coverOf(st);
    $('#playerBg').style.backgroundImage = `url("${bgImg.replace(/"/g, '%22')}")`;
    const fav = state.favorites.has(st.id);
    $('#favBtn').setAttribute('aria-pressed', String(fav));
    $('#favBtn').setAttribute('aria-label', fav ? 'Remover dos favoritos' : 'Favoritar');
    document.title = `${st.name} · ${state.title}`;
    updateMediaSession();
  }

  function updateMediaSession() {
    if (!('mediaSession' in navigator) || !state.current) return;
    const st = state.current;
    const art = coverOf(st);
    navigator.mediaSession.metadata = new MediaMetadata({
      title: state.nowPlaying || st.name,
      artist: state.nowPlaying ? st.name : (st.slogan || 'Ao vivo'),
      album: state.title,
      artwork: art.startsWith('data:') ? [] : [{ src: new URL(art, location.href).href, sizes: '512x512' }],
    });
  }

  function selectStation(st, autoplay = true) {
    if (!st) return;
    const changed = !state.current || state.current.id !== st.id;
    state.current = st;
    state.nowPlaying = '';
    store.set('last', st.id);
    if (changed) {
      state.recent = [st.id, ...state.recent.filter((id) => id !== st.id)].slice(0, 20);
      store.set('recent', state.recent);
    }
    updateInfo();
    markCurrent();
    if (autoplay) play(); else setStatus('idle');
    startMeta();
  }

  function play() {
    const st = state.current;
    if (!st) return;
    if (state.tvCurrent) closeTv();
    clearTimeout(state.retryTimer);
    setStatus('loading');
    // Rádio ao vivo: sempre recarrega o stream para não tocar áudio "atrasado" do buffer.
    audio.src = /^(blob|data):/.test(st.stream) ? st.stream : st.stream + (st.stream.includes('?') ? '&' : '?') + '_=' + Date.now();
    const p = audio.play();
    if (p) p.catch((err) => {
      if (err.name === 'NotAllowedError') { setStatus('paused'); toast('Toque em ▶ para ouvir'); }
      else if (err.name !== 'AbortError') onStreamError();
    });
  }

  function stop(kind = 'paused') {
    clearTimeout(state.retryTimer);
    state.retries = 0;
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    setStatus(kind);
  }

  function toggle() {
    if (!state.current) {
      const first = filtered()[0] || state.stations[0];
      if (first) selectStation(first);
      return;
    }
    if (state.playing || $('#playBtn').classList.contains('loading')) stop();
    else { state.retries = 0; play(); }
  }

  function onStreamError() {
    if (!state.current || !audio.getAttribute('src')) return;
    if (state.retries >= MAX_RETRIES) {
      stop('error');
      toast('Não foi possível conectar à rádio.');
      return;
    }
    state.retries++;
    setStatus('loading');
    const delay = Math.min(1000 * 2 ** (state.retries - 1), 10000);
    clearTimeout(state.retryTimer);
    state.retryTimer = setTimeout(play, delay);
  }

  function step(dir) {
    const list = filtered().length ? filtered() : state.stations;
    if (!list.length) return;
    const idx = state.current ? list.findIndex((s) => s.id === state.current.id) : -1;
    selectStation(list[(idx + dir + list.length) % list.length]);
  }

  audio.addEventListener('playing', () => { state.retries = 0; setStatus('live'); });
  audio.addEventListener('waiting', () => { if (audio.getAttribute('src')) setStatus('loading'); });
  audio.addEventListener('error', onStreamError);
  audio.addEventListener('ended', onStreamError); // stream ao vivo não deveria terminar: reconecta
  audio.addEventListener('stalled', () => {
    // Se ficar 8s travado, força reconexão.
    const t = audio.currentTime;
    setTimeout(() => { if (audio.getAttribute('src') && !audio.paused && audio.currentTime === t) onStreamError(); }, 8000);
  });
  // Pausa vinda do sistema (fone desconectado, outra mídia, controle da tela de bloqueio).
  audio.addEventListener('pause', () => { if (audio.getAttribute('src') && state.playing) stop(); });

  if ('mediaSession' in navigator) {
    const ms = navigator.mediaSession;
    const handlers = {
      play: () => { state.retries = 0; play(); },
      pause: () => stop(),
      stop: () => stop(),
      previoustrack: () => step(-1),
      nexttrack: () => step(1),
    };
    for (const [action, fn] of Object.entries(handlers)) {
      try { ms.setActionHandler(action, fn); } catch { /* ação não suportada */ }
    }
  }

  // ---------- Metadados (música tocando) ----------
  // Suporta servidores Icecast (status-json.xsl) e endpoints JSON simples
  // ({"title": "..."} ou {"artist": "...", "title": "..."}). Requer CORS liberado no servidor.

  function parseMeta(data, st) {
    if (data && data.icestats) {
      let src = data.icestats.source;
      if (Array.isArray(src)) {
        const path = (() => { try { return new URL(st.stream).pathname; } catch { return ''; } })();
        src = src.find((s) => s.listenurl && path && s.listenurl.endsWith(path)) || src[0];
      }
      if (!src) return '';
      return src.title || [src.artist, src.title].filter(Boolean).join(' - ') || '';
    }
    if (data && typeof data === 'object') {
      const d = data.now_playing?.song || data.nowplaying || data.current || data;
      if (d.text) return d.text;
      return [d.artist, d.title].filter(Boolean).join(' - ');
    }
    return '';
  }

  async function fetchMeta() {
    const st = state.current;
    if (!st || !st.metadata || document.hidden && !state.playing) return;
    try {
      const res = await fetch(st.metadata, { cache: 'no-store' });
      if (!res.ok) return;
      const text = parseMeta(await res.json(), st).trim();
      if (state.current !== st || text === state.nowPlaying) return;
      state.nowPlaying = text;
      updateInfo();
    } catch { /* sem CORS ou offline: mantém o slogan */ }
  }

  function startMeta() {
    clearInterval(state.metaTimer);
    if (!state.current || !state.current.metadata) return;
    fetchMeta();
    state.metaTimer = setInterval(fetchMeta, META_INTERVAL);
  }

  // ---------- Timer para desligar ----------

  function setSleep(minutes) {
    clearTimeout(state.sleepTimer);
    state.sleepAt = 0;
    if (minutes > 0) {
      state.sleepAt = Date.now() + minutes * 60000;
      state.sleepTimer = setTimeout(() => { stop(); state.sleepAt = 0; updateSleepLabel(); toast('Timer encerrado. Boa noite!'); }, minutes * 60000);
      toast(`O rádio desliga em ${minutes} min`);
    }
    updateSleepLabel();
  }

  function updateSleepLabel() {
    const left = state.sleepAt - Date.now();
    $('#timerLabel').textContent = left > 0 ? `${Math.ceil(left / 60000)}m` : '';
    $('#timerBtn').setAttribute('aria-label', left > 0 ? `Desliga em ${Math.ceil(left / 60000)} minutos` : 'Desligar automaticamente');
  }
  setInterval(updateSleepLabel, 30000);

  // ---------- Tela do player ----------

  function openPlayer() {
    const p = $('#player');
    p.classList.add('open');
    p.setAttribute('aria-hidden', 'false');
    if (history.state?.player !== true) history.pushState({ player: true }, '');
    trackPlayerAd();
  }
  function closePlayer(fromHistory = false) {
    const p = $('#player');
    if (!p.classList.contains('open')) return;
    p.classList.remove('open');
    p.setAttribute('aria-hidden', 'true');
    if (!fromHistory && history.state?.player) history.back();
  }
  // Botão "voltar" do Android fecha o player em vez de sair do app.
  window.addEventListener('popstate', () => { closePlayer(true); closeTv(true); closeChat(true); });

  // Arrastar para baixo fecha o player.
  (() => {
    const p = $('#player');
    let y0 = null;
    p.addEventListener('touchstart', (e) => { if (!e.target.closest('input')) y0 = e.touches[0].clientY; }, { passive: true });
    p.addEventListener('touchend', (e) => {
      if (y0 !== null && e.changedTouches[0].clientY - y0 > 90) closePlayer();
      y0 = null;
    });
  })();

  // ---------- Web TV ----------

  const HLS_SRC = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js';
  let hlsLoading = null;
  function loadHls() {
    if (window.Hls) return Promise.resolve(window.Hls);
    hlsLoading ??= new Promise((resolve, reject) => {
      const sc = document.createElement('script');
      sc.src = HLS_SRC;
      sc.onload = () => resolve(window.Hls);
      sc.onerror = reject;
      document.head.appendChild(sc);
    });
    return hlsLoading;
  }

  function destroyTv() {
    if (state.hls) { try { state.hls.destroy(); } catch { /* já destruído */ } state.hls = null; }
    const v = $('#tvFrame video');
    if (v) { v.pause(); v.removeAttribute('src'); v.load(); }
    $('#tvFrame').innerHTML = '';
  }

  function tvError() {
    const t = state.tvCurrent;
    destroyTv();
    $('#tvFrame').innerHTML = `<div class="tv-msg"><p>Não foi possível carregar este canal agora.</p><button class="btn-primary" type="button" id="tvRetry">Tentar de novo</button></div>`;
    $('#tvRetry').addEventListener('click', () => mountTv(t));
  }

  function mountTv(t) {
    destroyTv();
    const frame = $('#tvFrame');
    if (t.type === 'youtube' || t.type === 'embed') {
      // Sem allow-top-navigation: o player do canal não consegue tirar o ouvinte do app.
      frame.innerHTML = `<iframe src="${escapeHtml(t.src)}" title="${escapeHtml(t.name)}"
        allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
        sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox allow-forms"></iframe>`;
      return;
    }
    frame.innerHTML = '<video controls playsinline autoplay preload="auto"></video>';
    const v = frame.querySelector('video');
    v.volume = audio.volume;
    v.addEventListener('error', () => { if (!state.hls) tvError(); });
    const start = () => v.play().catch(() => { /* o navegador pode exigir um toque no play */ });
    if (t.type === 'hls' && !v.canPlayType('application/vnd.apple.mpegurl')) {
      loadHls().then((Hls) => {
        if (state.tvCurrent !== t) return;
        if (!Hls || !Hls.isSupported()) { v.src = t.src; start(); return; }
        const hls = new Hls({ lowLatencyMode: true, backBufferLength: 30 });
        let fails = 0;
        hls.on(Hls.Events.ERROR, (_, d) => {
          if (!d.fatal) return;
          if (++fails > 3) { tvError(); return; }
          if (d.type === Hls.ErrorTypes.NETWORK_ERROR) setTimeout(() => hls.startLoad(), 1500 * fails);
          else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
          else tvError();
        });
        hls.on(Hls.Events.MANIFEST_PARSED, start);
        hls.loadSource(t.src);
        hls.attachMedia(v);
        state.hls = hls;
      }).catch(tvError);
    } else {
      v.src = t.src;
      start();
    }
  }

  function updateTvFav() {
    const t = state.tvCurrent;
    if (!t) return;
    const fav = state.favorites.has(t.id);
    $('#tvFav').setAttribute('aria-pressed', String(fav));
    $('#tvFav').setAttribute('aria-label', fav ? 'Remover dos favoritos' : 'Favoritar canal');
  }

  function openTv(t) {
    if (!t) return;
    if (state.playing || audio.getAttribute('src')) stop();
    closePlayer();
    state.tvCurrent = t;
    state.recent = [t.id, ...state.recent.filter((id) => id !== t.id)].slice(0, 30);
    store.set('recent', state.recent);
    $('#tvName').textContent = t.name;
    $('#tvDesc').textContent = [t.genre, t.description].filter(Boolean).join(' · ');
    $('#tvDesc').hidden = !$('#tvDesc').textContent;
    updateTvFav();
    const others = state.tvs.filter((x) => x.id !== t.id);
    $('#tvMoreWrap').hidden = !others.length;
    $('#tvMore').innerHTML = others.map(tvCardHtml).join('');
    $('#tvMore').querySelectorAll('img[data-tvcover]').forEach((img) => setTvCover(img, state.tvs.find((x) => x.id === img.dataset.tvcover)));
    const scr = $('#tvScreen');
    scr.classList.add('open');
    scr.setAttribute('aria-hidden', 'false');
    scr.scrollTop = 0;
    if (history.state?.tv !== true) history.pushState({ tv: true }, '');
    document.title = `${t.name} · ${state.title}`;
    mountTv(t);
    const slot = slots.adTv;
    if (slot && slot.ads.length) {
      const ad = slot.ads[slot.idx];
      if (!viewed.has(ad.id)) { viewed.add(ad.id); track(ad.id, 'view'); }
    }
    if (state.mode === 'tv') renderList();
  }

  function closeTv(fromHistory = false) {
    const scr = $('#tvScreen');
    if (!scr.classList.contains('open')) return;
    destroyTv();
    state.tvCurrent = null;
    scr.classList.remove('open');
    scr.setAttribute('aria-hidden', 'true');
    document.title = state.title;
    if (!fromHistory && history.state?.tv) history.back();
    if (state.mode === 'tv') renderList();
  }

  $('#closeTv').addEventListener('click', () => closeTv());
  $('#tvMore').addEventListener('click', (e) => {
    const card = e.target.closest('.tv-card');
    if (card) openTv(state.tvs.find((t) => t.id === card.dataset.tv));
  });
  $('#tvFav').addEventListener('click', () => {
    const t = state.tvCurrent;
    if (!t) return;
    if (state.favorites.has(t.id)) { state.favorites.delete(t.id); toast('Removido dos favoritos'); }
    else { state.favorites.add(t.id); toast('Adicionado aos favoritos'); }
    store.set('favorites', [...state.favorites]);
    updateTvFav();
  });
  $('#shareTv').addEventListener('click', async () => {
    const t = state.tvCurrent;
    if (!t) return;
    const url = new URL(location.href);
    url.search = '?tv=' + encodeURIComponent(t.id);
    url.hash = '';
    try {
      if (navigator.share) await navigator.share({ title: t.name, text: `Assista ${t.name} ao vivo`, url: url.href });
      else { await navigator.clipboard.writeText(url.href); toast('Link copiado!'); }
    } catch { /* cancelado */ }
  });

  document.querySelectorAll('.mode').forEach((b) => b.addEventListener('click', () => {
    state.mode = b.dataset.mode;
    store.set('mode', state.mode);
    state.genre = '';
    renderModes();
    renderGenres();
    renderList();
  }));

  // ---------- Eventos de interface ----------

  $('#list').addEventListener('click', (e) => {
    const card = e.target.closest('.tv-card');
    if (card) { openTv(state.tvs.find((t) => t.id === card.dataset.tv)); return; }
    const btn = e.target.closest('.station');
    if (!btn) return;
    const st = state.stations.find((s) => s.id === btn.dataset.id);
    if (state.current && state.current.id === st.id) { if (!state.playing) { state.retries = 0; play(); } }
    else selectStation(st);
    openPlayer();
  });

  $('#genres').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-genre]');
    if (!btn) return;
    state.genre = btn.dataset.genre;
    renderGenres();
    renderList();
  });

  document.querySelectorAll('.tab').forEach((tab) => tab.addEventListener('click', () => {
    state.tab = tab.dataset.tab;
    document.querySelectorAll('.tab').forEach((t) => {
      t.classList.toggle('active', t === tab);
      t.setAttribute('aria-selected', String(t === tab));
    });
    renderList();
  }));

  $('#search').addEventListener('input', (e) => { state.query = e.target.value; renderList(); });

  $('#playBtn').addEventListener('click', toggle);
  $('#miniPlay').addEventListener('click', toggle);
  $('#prevBtn').addEventListener('click', () => step(-1));
  $('#nextBtn').addEventListener('click', () => step(1));
  $('#miniOpen').addEventListener('click', openPlayer);
  $('#closePlayer').addEventListener('click', () => closePlayer());

  $('#favBtn').addEventListener('click', () => {
    const id = state.current?.id;
    if (!id) return;
    if (state.favorites.has(id)) { state.favorites.delete(id); toast('Removida dos favoritos'); }
    else { state.favorites.add(id); toast('Adicionada aos favoritos'); }
    store.set('favorites', [...state.favorites]);
    updateInfo();
    renderList();
  });

  // Volume (no iOS o volume é controlado só pelos botões do aparelho).
  const vol = $('#volume');
  function applyVolume(v, muted) {
    audio.volume = v;
    audio.muted = muted;
    vol.value = muted ? 0 : v;
    $('#muteBtn use').setAttribute('href', muted || v === 0 ? '#i-mute' : '#i-vol');
    store.set('volume', { v, muted });
  }
  const savedVol = store.get('volume', { v: 1, muted: false });
  applyVolume(savedVol.v, savedVol.muted);
  vol.addEventListener('input', () => applyVolume(Number(vol.value), false));
  $('#muteBtn').addEventListener('click', () => applyVolume(audio.volume || 1, !audio.muted));

  $('#timerBtn').addEventListener('click', () => $('#timerDialog').showModal());
  $('#timerDialog').addEventListener('close', () => {
    const v = $('#timerDialog').returnValue;
    if (v !== '') setSleep(Number(v));
  });
  $('#timerDialog').addEventListener('click', (e) => { if (e.target === e.currentTarget) e.currentTarget.close(''); });

  $('#shareBtn').addEventListener('click', async () => {
    const st = state.current;
    if (!st) return;
    const url = new URL(location.href);
    url.search = '?r=' + encodeURIComponent(st.id);
    url.hash = '';
    const data = { title: st.name, text: `Ouça ${st.name}${state.nowPlaying ? ' — ' + state.nowPlaying : ''}`, url: url.href };
    try {
      if (navigator.share) await navigator.share(data);
      else { await navigator.clipboard.writeText(url.href); toast('Link copiado!'); }
    } catch { /* compartilhamento cancelado */ }
  });

  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, dialog') || state.tvCurrent || $('#chatScreen').classList.contains('open')) {
      if (e.key === 'Escape') { closeTv(); closeChat(); }
      return;
    }
    if (e.code === 'Space') { e.preventDefault(); toggle(); }
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'Escape') { closePlayer(); closeTv(); }
  });

  // Ao voltar a conexão, retoma se estava tentando tocar.
  window.addEventListener('online', () => { if (state.current && audio.getAttribute('src') && !state.playing) { state.retries = 0; play(); } });

  // ---------- Publicidade ----------
  // Espaços: "top" (banner acima da lista), "player" (banner na tela do player) e "popup" (ao abrir).

  const viewed = new Set();
  const slots = {};

  function track(id, type) {
    const body = new URLSearchParams({ id, type });
    try {
      if (!(navigator.sendBeacon && navigator.sendBeacon('api.php?action=track', body))) {
        fetch('api.php?action=track', { method: 'POST', body, keepalive: true }).catch(() => {});
      }
    } catch { /* estatística é opcional */ }
  }

  function adHtml(ad) {
    const inner = ad.image
      ? `<img src="${escapeHtml(ad.image)}" alt="${escapeHtml(ad.title || 'Publicidade')}">`
      : `<span class="ad-text"><div><strong>${escapeHtml(ad.title)}</strong>${ad.text ? `<span>${escapeHtml(ad.text)}</span>` : ''}</div>${ad.link ? '<b class="ad-cta">Saiba mais ›</b>' : ''}</span>`;
    const label = '<small class="ad-label">Publicidade</small>';
    return ad.link
      ? `<a class="fade" href="${escapeHtml(ad.link)}" target="_blank" rel="noopener sponsored" data-ad="${escapeHtml(ad.id)}">${inner}${label}</a>`
      : `<div class="ad-box fade">${inner}${label}</div>`;
  }

  function showAd(el, ad) {
    el.innerHTML = adHtml(ad);
    const visible = !document.hidden && (el.id !== 'adPlayer' || $('#player').classList.contains('open'));
    if (visible && !viewed.has(ad.id)) { viewed.add(ad.id); track(ad.id, 'view'); }
  }

  function renderSlot(el, ads) {
    const slot = slots[el.id] || (slots[el.id] = { timer: null, idx: 0, ads: [] });
    clearInterval(slot.timer);
    slot.ads = ads || [];
    el.hidden = !slot.ads.length;
    if (!slot.ads.length) { el.innerHTML = ''; return; }
    slot.idx = Math.floor(Math.random() * slot.ads.length);
    showAd(el, slot.ads[slot.idx]);
    if (slot.ads.length > 1) {
      slot.timer = setInterval(() => {
        if (document.hidden) return;
        slot.idx = (slot.idx + 1) % slot.ads.length;
        showAd(el, slot.ads[slot.idx]);
      }, state.config.ad_rotate * 1000);
    }
  }

  // Conta a exibição do banner do player quando a tela do player é aberta.
  function trackPlayerAd() {
    const slot = slots.adPlayer;
    if (slot && slot.ads.length) {
      const ad = slot.ads[slot.idx];
      if (!viewed.has(ad.id)) { viewed.add(ad.id); track(ad.id, 'view'); }
    }
  }

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-ad]');
    if (a) track(a.dataset.ad, 'click');
  });

  function maybePopup(ads) {
    if (!ads || !ads.length) return;
    let shown = false;
    try { shown = sessionStorage.getItem('radio:popup') === '1'; } catch { /* sem storage */ }
    if (shown) return;
    setTimeout(() => {
      const dlg = $('#adPopup');
      if (dlg.open || document.querySelector('dialog[open]')) return;
      const ad = ads[Math.floor(Math.random() * ads.length)];
      $('#adPopupBody').innerHTML = adHtml(ad);
      $('#adPopupBody .ad-label')?.remove();
      dlg.showModal();
      track(ad.id, 'view');
      try { sessionStorage.setItem('radio:popup', '1'); } catch { /* sem storage */ }
    }, state.config.popup_delay * 1000);
  }
  $('#adPopupClose').addEventListener('click', () => $('#adPopup').close());
  $('#adPopup').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) e.currentTarget.close();
    else if (e.target.closest('a[data-ad]')) e.currentTarget.close();
  });

  // ---------- Sobre e contatos ----------

  const CONTACTS = { whatsapp: 'WhatsApp', instagram: 'Instagram', facebook: 'Facebook', youtube: 'YouTube', site: 'Site' };

  function renderAbout(cfg) {
    const links = Object.entries(CONTACTS).filter(([k]) => cfg.contacts && cfg.contacts[k]);
    $('#aboutText').textContent = cfg.about || '';
    $('#aboutText').hidden = !cfg.about;
    $('#contacts').innerHTML = links.map(([k, label]) =>
      `<a href="${escapeHtml(cfg.contacts[k])}" target="_blank" rel="noopener" aria-label="${label}" title="${label}"><svg><use href="#i-${k}"/></svg></a>`
    ).join('');
    $('#about').hidden = !cfg.about && !links.length;
    $('#playerSocial').innerHTML = $('#contacts').innerHTML;
    $('#playerSocial').hidden = !links.length;
  }

  // ---------- Instalação (multiplataforma) ----------
  // Android/Chrome/Edge (celular e computador): prompt nativo.
  // iPhone/iPad e navegadores sem prompt: instruções passo a passo.

  const ua = navigator.userAgent;
  const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  let installEvt = null;

  if (!isStandalone() && location.protocol !== 'file:') $('#installBtn').hidden = false;

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    installEvt = e;
    $('#installBtn').hidden = false;
  });

  function installSteps() {
    const share = '<svg><use href="#i-share"/></svg>';
    if (isIOS) {
      return `<ol><li>Abra este site no <b>Safari</b>.</li><li>Toque em <b>Compartilhar</b> ${share} na barra do navegador.</li><li>Escolha <b>Adicionar à Tela de Início</b> e confirme.</li></ol>`;
    }
    if (/Android/i.test(ua)) {
      return '<ol><li>Toque no menu <b>⋮</b> do navegador.</li><li>Escolha <b>Instalar app</b> ou <b>Adicionar à tela inicial</b>.</li></ol>';
    }
    if (/Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) {
      return `<ol><li>No Safari, clique em <b>Compartilhar</b> ${share} ou no menu <b>Arquivo</b>.</li><li>Escolha <b>Adicionar ao Dock</b>.</li></ol>`;
    }
    if (/Firefox/.test(ua)) {
      return '<ol><li>O Firefox para computador não instala aplicativos.</li><li>Abra este site no <b>Chrome</b> ou no <b>Edge</b> e clique em <b>Instalar</b> na barra de endereço.</li></ol>';
    }
    return '<ol><li>Clique no ícone de <b>instalar</b> na barra de endereço do navegador (ou no menu <b>⋮</b>).</li><li>Confirme em <b>Instalar</b>.</li></ol>';
  }

  $('#installBtn').addEventListener('click', async () => {
    if (installEvt) {
      installEvt.prompt();
      const { outcome } = await installEvt.userChoice;
      installEvt = null;
      if (outcome === 'accepted') $('#installBtn').hidden = true;
      return;
    }
    $('#installSteps').innerHTML = installSteps();
    $('#installDialog').showModal();
  });
  window.addEventListener('appinstalled', () => { $('#installBtn').hidden = true; toast('Aplicativo instalado!'); });

  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
  }

  // ---------- Pedidos de música / oração ----------

  // Identificador anônimo deste aparelho (para receber as respostas do locutor).
  const device = (() => {
    let d = store.get('device', '');
    if (!/^[a-f0-9]{24}$/.test(d)) {
      const a = new Uint8Array(12);
      crypto.getRandomValues(a);
      d = [...a].map((b) => b.toString(16).padStart(2, '0')).join('');
      store.set('device', d);
    }
    return d;
  })();
  const profile = store.get('profile', { name: '', city: '', phone: '' });

  const INTERACT = {
    music: { label: 'Pedir música', icon: 'i-music' },
    prayer: { label: 'Pedido de oração', icon: 'i-pray' },
    chat: { label: 'Chat', icon: 'i-chat' },
  };

  function renderInteract(cfg) {
    const on = Object.keys(INTERACT).filter((k) => cfg.interact && cfg.interact[k]);
    const html = on.map((k) => `<button type="button" class="pill" data-interact="${k}"><svg><use href="#${INTERACT[k].icon}"/></svg><span>${INTERACT[k].label}</span></button>`).join('');
    for (const id of ['homeInteract', 'playerInteract']) {
      $('#' + id).innerHTML = html;
      $('#' + id).hidden = !on.length;
    }
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-interact]');
    if (!b) return;
    if (b.dataset.interact === 'chat') openChat();
    else openRequest(b.dataset.interact);
  });

  const api = (action, data, method = 'POST') => {
    const body = new URLSearchParams({ device, ...data });
    const opts = method === 'POST' ? { method, body } : { cache: 'no-store' };
    const url = 'api.php?action=' + action + (method === 'GET' ? '&' + body : '');
    return fetch(url, opts).then(async (r) => ({ ok: r.ok, ...(await r.json().catch(() => ({ error: 'Sem conexão.' }))) }));
  };

  let reqType = 'music';
  function openRequest(type) {
    reqType = type;
    const music = type === 'music';
    $('#reqTitle').textContent = music ? 'Pedir música' : 'Pedido de oração';
    $('#reqSongWrap').hidden = !music;
    $('#reqTextLabel').textContent = music ? 'Recado (opcional)' : 'Seu pedido de oração';
    $('#reqText').placeholder = music ? 'Mande um abraço, um recado…' : 'Escreva pelo que devemos orar';
    $('#reqName').value = profile.name || '';
    $('#reqCity').value = profile.city || '';
    $('#reqPhone').value = profile.phone || '';
    $('#reqSong').value = '';
    $('#reqText').value = '';
    $('#reqMsg').textContent = '';
    $('#reqDialog').showModal();
    loadMyRequests();
  }
  $('#reqCancel').addEventListener('click', () => $('#reqDialog').close());
  $('#reqDialog').addEventListener('click', (e) => { if (e.target === e.currentTarget) e.currentTarget.close(); });

  $('#reqForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('#reqName').value.trim();
    const song = $('#reqSong').value.trim();
    const text = $('#reqText').value.trim();
    if (!name) { $('#reqMsg').textContent = 'Escreva seu nome.'; return; }
    if (reqType === 'music' && !song && !text) { $('#reqMsg').textContent = 'Escreva a música que você quer ouvir.'; return; }
    if (reqType === 'prayer' && !text) { $('#reqMsg').textContent = 'Escreva seu pedido de oração.'; return; }
    Object.assign(profile, { name, city: $('#reqCity').value.trim(), phone: $('#reqPhone').value.trim() });
    store.set('profile', profile);
    $('#reqSubmit').disabled = true;
    const r = await api('request', { type: reqType, name, song, text, city: profile.city, phone: profile.phone });
    $('#reqSubmit').disabled = false;
    if (!r.ok) { $('#reqMsg').textContent = r.error || 'Não foi possível enviar agora.'; return; }
    $('#reqDialog').close();
    toast(reqType === 'music' ? 'Pedido enviado! O locutor já recebeu.' : 'Pedido de oração enviado. Estamos com você!');
    store.set('waitingReply', true);
  });

  const fmtTime = (t) => new Date(t * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

  async function loadMyRequests() {
    const r = await api('my_requests', {}, 'GET').catch(() => null);
    const box = $('#myReqs');
    if (!r || !r.items || !r.items.length) { box.hidden = true; return; }
    box.hidden = false;
    box.innerHTML = '<h4>Seus pedidos</h4>' + r.items.slice(0, 5).map((q) => `
      <div class="my-req">
        <small>${q.type === 'prayer' ? 'Oração' : 'Música'} · ${fmtTime(q.at)}</small>
        <p>${escapeHtml(q.song || q.text)}</p>
        ${q.reply ? `<p class="answer"><b>Resposta:</b> ${escapeHtml(q.reply)}</p>` : '<p class="waiting">Aguardando resposta</p>'}
      </div>`).join('');
  }

  // Avisa quando o locutor responder (enquanto o app está aberto).
  async function checkReplies() {
    if (document.hidden || !state.config || !store.get('waitingReply', false)) return;
    const r = await api('my_requests', {}, 'GET').catch(() => null);
    if (!r || !r.items) return;
    const seen = store.get('seenReply', 0);
    const fresh = r.items.filter((q) => q.reply && q.reply_at > seen);
    if (fresh.length) {
      store.set('seenReply', Math.max(...fresh.map((q) => q.reply_at)));
      toast('O locutor respondeu: ' + fresh[0].reply, 7000);
    }
    if (!r.items.some((q) => !q.reply)) store.set('waitingReply', false);
  }
  setInterval(checkReplies, 20000);

  // ---------- Chat dos ouvintes ----------

  const chat = { since: 0, timer: null, nick: store.get('nick', '') };

  function openChat() {
    const scr = $('#chatScreen');
    scr.classList.add('open');
    scr.setAttribute('aria-hidden', 'false');
    if (history.state?.chat !== true) history.pushState({ chat: true }, '');
    $('#chatTitle').textContent = 'Chat · ' + (state.title || '');
    showChatForm();
    chat.since = 0;
    $('#chatLog').innerHTML = '<p class="chat-empty">Carregando conversa…</p>';
    loadChat();
    clearInterval(chat.timer);
    chat.timer = setInterval(loadChat, 3000);
  }

  function closeChat(fromHistory = false) {
    const scr = $('#chatScreen');
    if (!scr.classList.contains('open')) return;
    clearInterval(chat.timer);
    scr.classList.remove('open');
    scr.setAttribute('aria-hidden', 'true');
    if (!fromHistory && history.state?.chat) history.back();
  }

  function showChatForm() {
    $('#chatJoin').hidden = !!chat.nick;
    $('#chatForm').hidden = !chat.nick;
    if (chat.nick) setTimeout(() => $('#chatInput').focus({ preventScroll: true }), 300);
  }

  function chatMsgHtml(m) {
    const t = new Date(m.at * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (m.kind === 'system') return `<div class="cmsg system" data-id="${m.id}"><svg><use href="#i-music"/></svg><span><b>${escapeHtml(m.nick)}</b> ${escapeHtml(m.text)}</span></div>`;
    return `<div class="cmsg ${m.kind}${m.mine ? ' mine' : ''}" data-id="${m.id}">
      ${m.mine ? '' : `<b>${escapeHtml(m.nick)}${m.kind === 'host' ? ' <span class="host-tag">Locutor</span>' : ''}</b>`}
      <p>${escapeHtml(m.text)}</p><time>${t}</time></div>`;
  }

  async function loadChat() {
    if (document.hidden) return;
    const r = await api('chat', { since: chat.since, nick: chat.nick }, 'GET').catch(() => null);
    const log = $('#chatLog');
    if (!r || !r.ok) {
      if (r && r.error && chat.since === 0) log.innerHTML = `<p class="chat-empty">${escapeHtml(r.error)}</p>`;
      return;
    }
    (r.deleted || []).forEach((id) => log.querySelector(`[data-id="${id}"]`)?.remove());
    if (chat.since === 0) log.innerHTML = r.messages.length ? '' : '<p class="chat-empty">Seja o primeiro a dizer olá! 👋</p>';
    const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
    if (r.messages.length) {
      log.querySelector('.chat-empty')?.remove();
      log.insertAdjacentHTML('beforeend', r.messages.map(chatMsgHtml).join(''));
      if (atBottom || chat.since === 0) log.scrollTop = log.scrollHeight;
    }
    chat.since = r.last;
    $('#chatOnline').textContent = `${r.online.length} online`;
    $('#onlineList').innerHTML = r.online.slice(0, 30).map((n) => `<span class="who${n === chat.nick ? ' me' : ''}">${escapeHtml(n)}</span>`).join('');
    if (r.banned) { $('#chatForm').hidden = true; $('#chatJoin').hidden = true; }
  }

  $('#chatJoin').addEventListener('submit', (e) => {
    e.preventDefault();
    const n = $('#chatNick').value.trim();
    if (!n) return;
    chat.nick = n;
    store.set('nick', n);
    showChatForm();
    loadChat();
  });
  $('#chatForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = $('#chatInput');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    const r = await api('chat_send', { nick: chat.nick, text });
    if (!r.ok) { toast(r.error || 'Mensagem não enviada.'); input.value = text; return; }
    loadChat();
  });
  $('#closeChat').addEventListener('click', () => closeChat());

  // ---------- Temas visuais ----------

  const SKIN_FONTS = {
    gospel: 'family=Cinzel:wght@600;700&family=Nunito+Sans:wght@400;600;700;800',
    sertanejo: 'family=Oswald:wght@500;600;700&family=Nunito+Sans:wght@400;600;700;800',
    poprock: 'family=Unbounded:wght@600;700&family=Nunito+Sans:wght@400;600;700;800',
    raiz: 'family=Rye&family=Lora:wght@400;600;700',
    forro: 'family=Baloo+2:wght@500;600;700;800',
  };

  function applySkin(skin) {
    const root = document.documentElement;
    root.dataset.skin = skin in SKIN_FONTS ? skin : 'classico';
    let link = document.getElementById('skinFonts');
    const fonts = SKIN_FONTS[root.dataset.skin];
    if (fonts) {
      if (!link) {
        link = document.createElement('link');
        link.id = 'skinFonts';
        link.rel = 'stylesheet';
        document.head.appendChild(link);
      }
      link.href = `https://fonts.googleapis.com/css2?${fonts}&display=swap`;
    } else if (link) {
      link.remove();
    }
    // Cor da barra do navegador acompanha o fundo do tema.
    const bg = getComputedStyle(root).getPropertyValue('--bg').trim();
    if (bg) document.querySelectorAll('meta[name=theme-color]').forEach((m) => m.setAttribute('content', bg));
  }

  // Equalizador: barras com ritmos diferentes para parecer música de verdade.
  (() => {
    const box = $('#eq');
    const n = 36;
    let html = '';
    for (let i = 0; i < n; i++) {
      const center = 1 - Math.abs(i - n / 2) / (n / 2);
      const h = (0.35 + center * 0.55 + Math.random() * 0.25).toFixed(2);
      const d = (0.45 + Math.random() * 0.7).toFixed(2);
      const delay = (-Math.random() * 1.2).toFixed(2);
      html += `<i style="--h:${Math.min(1, h)};--d:${d}s;--delay:${delay}s"></i>`;
    }
    box.innerHTML = html;
  })();

  // ---------- Inicialização ----------

  function applyConfig(cfg) {
    state.config = cfg;
    state.title = cfg.title || 'Rádio Player';
    $('#appTitle').textContent = state.title;
    if (!state.current) document.title = state.title;
    applySkin(cfg.theme || 'classico');
    const root = document.documentElement;
    if (cfg.accent) root.style.setProperty('--accent', cfg.accent);
    else root.style.removeProperty('--accent');
    root.classList.toggle('has-photo', !!cfg.background);
    if (cfg.background) root.style.setProperty('--bg-photo', `url("${cfg.background.replace(/"/g, '%22')}")`);
    else root.style.removeProperty('--bg-photo');
    if (cfg.logo) $('#appLogo').src = cfg.logo;
    if (cfg.icon) $('#touchIcon').href = cfg.icon;

    const prevId = state.current && state.current.id;
    state.genreOrder = cfg.genres || [];
    state.stations = (cfg.stations || []).map((s, i) => ({ ...s, id: String(s.id || i) }));
    state.tvs = (cfg.tvs || []).map((t, i) => ({ ...t, id: String(t.id || 'tv' + i) }));
    renderModes();
    if (prevId) {
      const updated = state.stations.find((s) => s.id === prevId);
      if (updated) { state.current = updated; updateInfo(); }
    }
    renderGenres();
    renderList();
    renderAbout(cfg);
    renderInteract(cfg);
    renderSlot($('#adTop'), cfg.ads && cfg.ads.top);
    renderSlot($('#adPlayer'), cfg.ads && cfg.ads.player);
    renderSlot($('#adTv'), cfg.ads && cfg.ads.player);
  }

  async function loadConfig() {
    const res = await fetch('api.php', { cache: 'no-cache' });
    const cfg = await res.json().catch(() => null);
    if (!cfg || (!res.ok && !cfg.error)) throw new Error('HTTP ' + res.status);
    state.loadedAt = Date.now();
    return cfg;
  }

  function showUnavailable(cfg) {
    applySkin('classico');
    $('#appTitle').textContent = cfg.title || 'Rádio Player';
    document.title = cfg.title || 'Rádio Player';
    $('#installBtn').hidden = true;
    $('#mini').hidden = true;
    document.querySelectorAll('.search, .tabs, .genres, .ad-slot, #about').forEach((el) => { el.hidden = true; });
    $('#list').innerHTML = `<div class="unavailable">
      <svg aria-hidden="true"><use href="#i-radio"/></svg>
      <h2>${escapeHtml(cfg.message || 'Aplicativo indisponível.')}</h2>
      ${cfg.support ? `<a class="btn-primary" href="${escapeHtml(cfg.support)}" target="_blank" rel="noopener">Falar com o suporte</a>` : ''}
    </div>`;
  }

  async function init() {
    let cfg;
    try {
      cfg = await loadConfig();
    } catch {
      $('#list').innerHTML = '<p class="empty">Não foi possível carregar as rádios. Verifique sua conexão.</p>';
      setTimeout(init, 10000);
      return;
    }
    // Endereço principal do site: vai para o app padrão da plataforma.
    if (cfg.redirect) { location.replace(cfg.redirect); return; }
    // App não encontrado, suspenso ou vencido.
    if (cfg.error) { showUnavailable(cfg); return; }

    applyConfig(cfg);

    // Link compartilhado (?r=id) ou última rádio ouvida — sem tocar sozinho (bloqueado pelos navegadores).
    const wantTv = new URLSearchParams(location.search).get('tv');
    const tvLink = wantTv && state.tvs.find((t) => t.id === wantTv);
    if (tvLink) {
      state.mode = 'tv';
      renderModes(); renderGenres(); renderList();
      openTv(tvLink);
    }
    const wanted = new URLSearchParams(location.search).get('r') || store.get('last', null);
    const st = state.stations.find((s) => s.id === wanted);
    if (st) selectStation(st, false);
    else if (state.stations.length === 1) selectStation(state.stations[0], false);

    maybePopup(cfg.ads && cfg.ads.popup);
  }

  // App aberto por muito tempo: busca novidades do painel (rádios, publicidade) ao voltar para ele.
  document.addEventListener('visibilitychange', async () => {
    if (document.hidden || !state.config || Date.now() - state.loadedAt < 5 * 60000) return;
    try { applyConfig(await loadConfig()); } catch { /* mantém o que já tem */ }
  });

  state.title = 'Rádio Player';
  init();
})();
