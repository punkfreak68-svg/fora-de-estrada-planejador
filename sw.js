// Service Worker for "Fora de Estrada - Navegador e Planejador".
// Caches the app shell (this HTML page + its core script/style/icon/manifest
// dependencies) so the app can open and function with zero network
// connectivity. Map tiles, Google Drive API calls and Google auth are
// deliberately left untouched here — those already have their own offline
// handling in app code (IndexedDB tile cache, graceful catch on sync failures).

var CACHE_NAME = "fora-de-estrada-shell-v2";

var SHELL_RELATIVE_PATHS = [
  "./",
  "./index.html",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./icons/icon-maskable-192.png",
  "./icons/icon-maskable-512.png",
  "./icons/apple-touch-icon.png",
  "./icons/favicon-32.png"
];

var EXTERNAL_SHELL_URLS = [
  "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css",
  "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js",
  "https://unpkg.com/leaflet-rotate@0.2.8/dist/leaflet-rotate-src.js"
];

// Resolve relative paths against the service worker's own location so this
// works correctly regardless of which origin the app is deployed to
// (localhost during development, the real Netlify domain in production).
var APP_SHELL_URLS = SHELL_RELATIVE_PATHS.map(function (p) {
  return new URL(p, self.location.href).href;
}).concat(EXTERNAL_SHELL_URLS);

var INDEX_URL = new URL("./index.html", self.location.href).href;

self.addEventListener("install", function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.all(
        APP_SHELL_URLS.map(function (url) {
          return fetch(url, { cache: "reload" })
            .then(function (resp) {
              if (resp && resp.ok) return cache.put(url, resp);
            })
            .catch(function () {
              /* this one resource failed to precache; fetch handler below will retry live */
            });
        })
      );
    })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches
      .keys()
      .then(function (names) {
        return Promise.all(
          names
            .filter(function (n) {
              return n !== CACHE_NAME;
            })
            .map(function (n) {
              return caches.delete(n);
            })
        );
      })
      .then(function () {
        return self.clients.claim();
      })
  );
});

self.addEventListener("fetch", function (event) {
  var req = event.request;
  if (req.method !== "GET") return;

  var isShellUrl = APP_SHELL_URLS.indexOf(req.url) !== -1 || req.mode === "navigate";
  if (!isShellUrl) return; // let map tiles, Drive API, Google auth, etc. go straight to network as usual

  event.respondWith(
    fetch(req)
      .then(function (resp) {
        if (resp && resp.ok) {
          var clone = resp.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(req, clone);
          });
        }
        return resp;
      })
      .catch(function () {
        return caches.match(req).then(function (cached) {
          return cached || caches.match(INDEX_URL);
        });
      })
  );
});
