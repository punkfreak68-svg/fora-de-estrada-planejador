// v8.8 — gera android-app/www/offline.html: uma cópia COMPLETA do app, num arquivo só,
// que vai dentro do APK. Quando o celular não consegue abrir o site, o Android abre esta
// cópia no mesmo endereço do site (capacitor.config.json: server.hostname + errorPath),
// e ela enxerga as mesmas rotas salvas e mapas baixados.
// Uso: node build-offline.js  — rodar (e commitar www/offline.html) antes de gerar um APK novo
const fs = require("fs");
const path = require("path");
const ROOT = path.join(__dirname, "..");
const rd = (p, enc) => fs.readFileSync(path.join(ROOT, p), enc === undefined ? "utf8" : enc);
const dataUri = (p, mime) => "data:" + mime + ";base64," + rd(p, null).toString("base64");
const safeScript = (js) => js.replace(/<\/script/gi, "<\\/script");

let html = rd("index.html");
const n0 = html.length;
function swap(re, to, what) {
  const before = html;
  html = html.replace(re, typeof to === "function" ? to : () => to);
  if (html === before) throw new Error("não encontrei: " + what);
}

// Leaflet CSS com as imagens embutidas
let css = rd("vendor/leaflet/leaflet.css").replace(/url\(images\/([\w.-]+\.png)\)/g, (m, f) => "url(" + dataUri("vendor/leaflet/images/" + f, "image/png") + ")");
swap(/<link rel="stylesheet" href="vendor\/leaflet\/leaflet\.css"[^>]*>/, "<style>\n" + css + "\n</style>", "leaflet.css");
swap(/<script src="vendor\/leaflet\/leaflet\.js"[^>]*><\/script>/, "<script>\n" + safeScript(rd("vendor/leaflet/leaflet.js")) + "\n</script>", "leaflet.js");
swap(/<script src="vendor\/leaflet-rotate\/leaflet-rotate\.js"[^>]*><\/script>/, "<script>\n" + safeScript(rd("vendor/leaflet-rotate/leaflet-rotate.js")) + "\n</script>", "leaflet-rotate.js");
// ícones e QR code embutidos; sem manifest, fontes e login Google (precisam de internet)
swap(/href="icons\/favicon-32\.png"/, 'href="' + dataUri("icons/favicon-32.png", "image/png") + '"', "favicon");
const ICON192 = dataUri("icons/icon-192.png", "image/png");
html = html.split('"icons/icon-192.png"').join('"' + ICON192 + '"');
html = html.split('"icons/apple-touch-icon.png"').join('"' + dataUri("icons/apple-touch-icon.png", "image/png") + '"');
html = html.replace(/<link rel="manifest"[^>]*>\s*/, "");
html = html.replace(/<link rel="(?:preconnect|stylesheet)" href="https:\/\/fonts\.g[^"]*"[^>]*>\s*/g, "");
html = html.replace(/<script src="https:\/\/accounts\.google\.com\/gsi\/client"[^>]*><\/script>\s*/, "");
swap(/<head>/, '<head>\n<script>window.__fdeOfflineCopy = true;</script>\n<script>\n' + safeScript(rd("vendor/qrcode/qrcode.js")) + "\n</script>", "<head>");

if (!/var APP_VERSION = "[\d.]+";/.test(html)) throw new Error("APP_VERSION não encontrado");
if (/(src|href)="(vendor|icons)\//.test(html)) throw new Error("ainda há arquivo de vendor/ ou icons/ fora da cópia");
const out = path.join(__dirname, "www", "offline.html");
fs.writeFileSync(out, html);
console.log("offline.html v" + html.match(/var APP_VERSION = "([\d.]+)"/)[1] + ": " + Math.round(html.length / 1024) + " KB (index.html tinha " + Math.round(n0 / 1024) + " KB)");
