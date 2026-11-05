// Service Worker for "Fora de Estrada - Navegador e Planejador".
// v8.0 — "Abrir sempre":
//  * Leaflet, leaflet-rotate e o gerador de QR agora vêm do próprio site (/vendor/),
//    então o app não depende de CDN nenhuma para abrir.
//  * Instalação atômica: se algum arquivo essencial não baixar (sinal ruim no meio
//    da atualização), a versão nova NÃO é instalada e a antiga continua valendo.
//    Antes, a versão nova era ativada incompleta e o cache antigo apagado — o app
//    podia abrir sem mapa no meio do mato.
//  * Cache primeiro: a página abre na hora com a cópia guardada e a cópia é
//    atualizada em segundo plano. Antes o app esperava a internet sem prazo e,
//    com "1 risquinho" de sinal, ficava em tela branca.
//  * Fontes num cache de nome fixo (não precisam ser baixadas de novo a cada versão).
// Blocos de mapa, API, Drive e login Google continuam fora daqui (têm tratamento próprio).

var CACHE_NAME = "fora-de-estrada-shell-v53";
var FONT_CACHE = "fde-fonts-v1";

// sem estes o app não funciona: se um falhar, a instalação inteira falha
var ESSENTIAL_PATHS = [
  "./",
  "./index.html",
  "./manifest.json",
  "./vendor/leaflet/leaflet.css",
  "./vendor/leaflet/leaflet.js",
  "./vendor/leaflet-rotate/leaflet-rotate.js"
];

// bons de ter: se falhar, tenta aproveitar a cópia de uma versão anterior
var OPTIONAL_PATHS = [
  "./vendor/qrcode/qrcode.js",
  "./vendor/leaflet/images/layers.png",
  "./vendor/leaflet/images/layers-2x.png",
  "./vendor/leaflet/images/marker-icon.png",
  "./vendor/leaflet/images/marker-icon-2x.png",
  "./vendor/leaflet/images/marker-shadow.png",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./icons/icon-maskable-192.png",
  "./icons/icon-maskable-512.png",
  "./icons/apple-touch-icon.png",
  "./icons/favicon-32.png"
];

function abs(p) {
  return new URL(p, self.location.href).href;
}
var ESSENTIAL_URLS = ESSENTIAL_PATHS.map(abs);
var OPTIONAL_URLS = OPTIONAL_PATHS.map(abs);
var APP_SHELL_URLS = ESSENTIAL_URLS.concat(OPTIONAL_URLS);
var INDEX_URL = abs("./index.html");
var ROOT_URL = abs("./");

function fetchFresh(url) {
  return fetch(url, { cache: "reload" }).then(function (resp) {
    if (!resp || !resp.ok) throw new Error("falhou: " + url);
    return resp;
  });
}

self.addEventListener("install", function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // 1) essenciais: tudo ou nada
      return Promise.all(
        ESSENTIAL_URLS.map(function (url) {
          return fetchFresh(url).then(function (resp) {
            return { url: url, resp: resp };
          });
        })
      )
        .then(function (list) {
          return Promise.all(
            list.map(function (it) {
              return cache.put(it.url, it.resp);
            })
          );
        })
        .then(function () {
          // 2) opcionais: rede, senão a cópia de uma versão anterior
          return Promise.all(
            OPTIONAL_URLS.map(function (url) {
              return fetchFresh(url)
                .then(function (resp) {
                  return cache.put(url, resp);
                })
                .catch(function () {
                  return caches.match(url).then(function (old) {
                    if (old) return cache.put(url, old);
                  });
                });
            })
          );
        })
        .then(function () {
          return self.skipWaiting();
        })
        .catch(function (err) {
          // instalação incompleta: apaga o cache novo pela metade e mantém a versão antiga
          return caches.delete(CACHE_NAME).then(function () {
            throw err;
          });
        });
    })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then(function (cache) {
        // só apaga as versões antigas se a nova está completa
        return Promise.all(
          ESSENTIAL_URLS.map(function (url) {
            return cache.match(url);
          })
        );
      })
      .then(function (hits) {
        var complete = hits.every(function (h) {
          return !!h;
        });
        if (!complete) return;
        return caches.keys().then(function (names) {
          return Promise.all(
            names
              .filter(function (n) {
                return n !== CACHE_NAME && n !== FONT_CACHE;
              })
              .map(function (n) {
                return caches.delete(n);
              })
          );
        });
      })
      .then(function () {
        return self.clients.claim();
      })
  );
});

// guarda a resposta nova no cache (em segundo plano)
function refresh(req, cacheKey) {
  return fetch(req).then(function (resp) {
    if (resp && resp.ok && resp.type !== "opaque") {
      var clone = resp.clone();
      caches.open(CACHE_NAME).then(function (cache) {
        cache.put(cacheKey || req, clone);
      });
    }
    return resp;
  });
}

self.addEventListener("fetch", function (event) {
  var req = event.request;
  if (req.method !== "GET") return;
  var url = new URL(req.url);

  // a API nunca passa pelo cache (sessão/dados sempre ao vivo)
  if (url.pathname.indexOf("/api/") !== -1) return;

  // fontes: guarda na primeira vez, cache de nome fixo
  if (url.hostname === "fonts.googleapis.com" || url.hostname === "fonts.gstatic.com") {
    event.respondWith(
      caches.open(FONT_CACHE).then(function (cache) {
        return cache.match(req).then(function (hit) {
          if (hit) return hit;
          return fetch(req).then(function (resp) {
            if (resp && (resp.ok || resp.type === "opaque")) cache.put(req, resp.clone());
            return resp;
          });
        });
      })
    );
    return;
  }

  var isNavigate = req.mode === "navigate";
  var isShell = APP_SHELL_URLS.indexOf(url.origin + url.pathname) !== -1;
  if (!isNavigate && !isShell) return; // blocos de mapa, Drive, login Google etc. vão direto à rede

  // páginas do app (/, /index.html, /?rota=...) usam a mesma cópia guardada
  var isAppPage =
    isNavigate &&
    url.origin === self.location.origin &&
    (url.pathname === new URL(ROOT_URL).pathname || url.pathname === new URL(INDEX_URL).pathname);
  var cacheKey = isAppPage ? INDEX_URL : null;

  event.respondWith(
    caches
      .match(cacheKey || req, { ignoreSearch: true })
      .then(function (hit) {
        if (!hit && isAppPage) return caches.match(ROOT_URL);
        return hit;
      })
      .then(function (cached) {
        var net = refresh(req, cacheKey);
        if (cached) {
          // abre na hora; a atualização segue em segundo plano
          event.waitUntil(net.catch(function () {}));
          return cached;
        }
        // sem cópia guardada: rede; se falhar, a página do app (outras páginas do site abrem o app)
        return net.catch(function () {
          return caches.match(INDEX_URL).then(function (idx) {
            return idx || caches.match(ROOT_URL);
          });
        });
      })
  );
});
