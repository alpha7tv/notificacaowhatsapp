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
  function placeholderCover(name) {
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) % 360;
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
      <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="hsl(${h},70%,55%)"/><stop offset="1" stop-color="hsl(${(h + 40) % 360},70%,35%)"/>
      </linearGradient></defs>
      <rect width="100" height="100" fill="url(#g)"/>
      <text x="50" y="50" dy=".35em" text-anchor="middle" font-family="system-ui,sans-serif" font-size="38" font-weight="700" fill="#fff">${escapeHtml(initials)}</text>
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
  function toast(msg) {
    const el = $('#toast');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 2600);
  }

  // ---------- Lista ----------

  function filtered() {
    const q = state.query.trim().toLowerCase();
    let list = state.stations;
    if (state.tab === 'fav') list = list.filter((s) => state.favorites.has(s.id));
    if (state.tab === 'recent') list = state.recent.map((id) => state.stations.find((s) => s.id === id)).filter(Boolean);
    if (state.genre) list = list.filter((s) => s.genre === state.genre);
    if (q) list = list.filter((s) => [s.name, s.genre, s.city, s.slogan].join(' ').toLowerCase().includes(q));
    return list;
  }

  function renderGenres() {
    const used = new Set(state.stations.map((s) => s.genre).filter(Boolean));
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
    if (!list.length) {
      const msg = state.tab === 'fav' ? 'Você ainda não favoritou nenhuma rádio. Toque no ♥ no player.'
        : state.tab === 'recent' ? 'Nenhuma rádio ouvida ainda.'
        : state.stations.length ? 'Nenhuma rádio encontrada.' : 'Nenhuma rádio no ar no momento. Volte mais tarde!';
      box.innerHTML = `<p class="empty">${msg}</p>`;
      return;
    }
    box.innerHTML = list.map((s) => {
      const cur = state.current && state.current.id === s.id;
      const sub = [s.genre, s.city].filter(Boolean).join(' · ') || s.slogan || '';
      return `<button class="station${cur ? ' current' : ''}${cur && state.playing ? ' is-playing' : ''}" data-id="${escapeHtml(s.id)}">
        <img class="cover sm" alt="" loading="lazy" data-cover="${escapeHtml(s.id)}">
        <span class="info"><strong>${escapeHtml(s.name)}</strong><small>${escapeHtml(sub)}</small></span>
        <span class="playing-ind" aria-hidden="true"><i></i><i></i><i></i></span>
        ${state.favorites.has(s.id) ? '<svg class="fav" aria-label="Favorita"><use href="#i-heart"/></svg>' : ''}
      </button>`;
    }).join('');
    box.querySelectorAll('img[data-cover]').forEach((img) => {
      setCover(img, state.stations.find((s) => s.id === img.dataset.cover));
    });
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
    $('#playerBg').style.backgroundImage = `url("${coverOf(st).replace(/"/g, '%22')}")`;
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
    clearTimeout(state.retryTimer);
    setStatus('loading');
    // Rádio ao vivo: sempre recarrega o stream para não tocar áudio "atrasado" do buffer.
    audio.src = st.stream + (st.stream.includes('?') ? '&' : '?') + '_=' + Date.now();
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
    $('#timerLabel').textContent = left > 0 ? `Desliga em ${Math.ceil(left / 60000)} min` : 'Desligar em…';
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
  window.addEventListener('popstate', () => closePlayer(true));

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

  // ---------- Eventos de interface ----------

  $('#list').addEventListener('click', (e) => {
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
    if (e.target.closest('input, dialog')) return;
    if (e.code === 'Space') { e.preventDefault(); toggle(); }
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'Escape') closePlayer();
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

  // ---------- Inicialização ----------

  function applyConfig(cfg) {
    state.config = cfg;
    state.title = cfg.title || 'Rádio Player';
    $('#appTitle').textContent = state.title;
    if (!state.current) document.title = state.title;
    if (cfg.accent) {
      document.documentElement.style.setProperty('--accent', cfg.accent);
      document.querySelectorAll('meta[name=theme-color]').forEach((m) => m.setAttribute('content', cfg.accent));
    }
    if (cfg.logo) $('#appLogo').src = cfg.logo;
    if (cfg.icon) $('#touchIcon').href = cfg.icon;

    const prevId = state.current && state.current.id;
    state.genreOrder = cfg.genres || [];
    state.stations = (cfg.stations || []).map((s, i) => ({ ...s, id: String(s.id || i) }));
    if (prevId) {
      const updated = state.stations.find((s) => s.id === prevId);
      if (updated) { state.current = updated; updateInfo(); }
    }
    renderGenres();
    renderList();
    renderAbout(cfg);
    renderSlot($('#adTop'), cfg.ads && cfg.ads.top);
    renderSlot($('#adPlayer'), cfg.ads && cfg.ads.player);
  }

  async function loadConfig() {
    const res = await fetch('api.php', { cache: 'no-cache' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const cfg = await res.json();
    state.loadedAt = Date.now();
    return cfg;
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
    applyConfig(cfg);

    // Link compartilhado (?r=id) ou última rádio ouvida — sem tocar sozinho (bloqueado pelos navegadores).
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
