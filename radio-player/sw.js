// Service worker: guarda a "casca" do app para abrir rápido e funcionar como aplicativo.
// Os streams de áudio NUNCA passam pelo cache.
const CACHE = 'radio-player-v1';
const SHELL = [
  './',
  'index.html',
  'css/app.css',
  'js/app.js',
  'manifest.webmanifest',
  'icons/icon.svg',
  'icons/icon-192.png',
  'icons/icon-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  // Só arquivos do próprio app; áudio, metadados e logos externos vão direto para a rede.
  if (req.method !== 'GET' || url.origin !== location.origin || req.headers.has('range')) return;

  // Rede primeiro (para pegar atualizações), cache como reserva quando offline.
  e.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req, { ignoreSearch: true }).then((r) => r || caches.match('index.html')))
  );
});
