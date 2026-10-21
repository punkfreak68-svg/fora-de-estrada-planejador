<?php
// v7.1 — intermediário de estradas para o "🆘 Estou perdido".
// O celular pergunta aqui; o servidor busca no Overpass (OpenStreetMap) com paciência,
// tenta servidores reserva e guarda o resultado por quadrado do mapa, para responder na hora da próxima vez.
//
// POST {mode:"near", lat, lng}        → estrada mais perto e asfalto mais perto (já calculados)
// POST {mode:"route", pts:[[lat,lng]…]} → estradas em volta da rota (o app guarda no aparelho)
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_post();
@ini_set('serialize_precision', '-1');   // números curtos no JSON (-22.79947, não -22.7994699999…)
@set_time_limit(170);
ignore_user_abort(true);   // se o celular desistir, o servidor termina e guarda para a próxima

const RD_ALL = 'motorway|trunk|primary|secondary|tertiary|unclassified|residential|track|service|motorway_link|trunk_link|primary_link|secondary_link|tertiary_link';
const RD_PAVED = 'motorway|trunk|primary|secondary|tertiary|motorway_link|trunk_link|primary_link|secondary_link|tertiary_link';
const RD_TILE = ['a' => 0.03, 'p' => 0.2];          // ~3,3 km e ~22 km
const RD_MAX_AGE_DAYS = 90;
const RD_MAX_TILES_PER_FETCH = ['a' => 8, 'p' => 4];
const RD_BUDGET_S = 140;

$T0 = microtime(true);
$ip = client_ip();
rate_limit('roads', $ip, 120, 60, 'Muitas buscas de estrada. Tente daqui a pouco.');
rate_hit('roads', $ip);

function rd_elapsed(): float { global $T0; return microtime(true) - $T0; }
$RD_DIAG = [];   // resumo das consultas ao Overpass (servidor, código, segundos, quadrados) — ajuda a entender lentidão

function rd_overpass_urls(): array {
    $c = fde_config();
    return $c['overpass_urls'] ?? [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];
}

function rd_valid(float $lat, float $lng): bool {
    return $lat >= -85 && $lat <= 85 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0);
}

/** Quadrados (tx,ty) que cobrem um círculo de raio $m metros em volta do ponto. */
function rd_tiles_around(string $layer, float $lat, float $lng, float $m): array {
    $sz = RD_TILE[$layer];
    $dLat = $m / 111320;
    $dLng = $m / (111320 * max(0.2, cos(deg2rad($lat))));
    $out = [];
    for ($tx = (int)floor(($lng - $dLng) / $sz); $tx <= (int)floor(($lng + $dLng) / $sz); $tx++) {
        for ($ty = (int)floor(($lat - $dLat) / $sz); $ty <= (int)floor(($lat + $dLat) / $sz); $ty++) {
            $out["$tx,$ty"] = [$tx, $ty];
        }
    }
    return $out;
}

function rd_polyline(array $pts): string {
    $out = ''; $pl = 0; $pg = 0;
    $enc = function (int $v): string {
        $v = $v < 0 ? ~($v << 1) : $v << 1;
        $s = '';
        while ($v >= 0x20) { $s .= chr((0x20 | ($v & 0x1f)) + 63); $v >>= 5; }
        return $s . chr($v + 63);
    };
    foreach ($pts as $p) {
        $la = (int)round($p[0] * 1e5); $lo = (int)round($p[1] * 1e5);
        $out .= $enc($la - $pl) . $enc($lo - $pg);
        $pl = $la; $pg = $lo;
    }
    return $out;
}

/** Busca no Overpass; tenta os servidores em ordem. Devolve elements ou null. */
function rd_fetch(string $query, int $timeout): ?array {
    foreach (rd_overpass_urls() as $idx => $url) {
        $left = RD_BUDGET_S - rd_elapsed();
        if ($left < 15) return null;
        $t1 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'data=' . rawurlencode($query),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int)min($idx === 0 ? $timeout : 40, $left - 5),   // reservas: no máximo 40 s
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'ForaDeEstrada/7.1 (+https://foradeestrada.sansoliveira.com.br)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_errno($ch);
        curl_close($ch);
        global $RD_DIAG;
        $RD_DIAG[] = [parse_url($url, PHP_URL_HOST), $code, round(microtime(true) - $t1, 1), $cerr, is_string($body) ? strlen($body) : 0];
        if ($code === 200 && is_string($body)) {
            $j = json_decode($body, true);
            if (is_array($j) && isset($j['elements']) && is_array($j['elements'])) {
                // resposta "pela metade" (tempo esgotado no Overpass) não serve
                if (!empty($j['remark']) && stripos((string)$j['remark'], 'runtime') !== false) continue;
                return $j['elements'];
            }
        }
        // falhou devagar = consulta pesada demais: não adianta repetir igual nos reservas (quem chamou tenta grupos menores)
        if (microtime(true) - $t1 > 35) return null;
        if ($code === 429 || $code === 504 || $code === 502 || $code === 0) usleep(1500000);
    }
    return null;
}

/** Garante que os quadrados estão guardados (busca os que faltam numa só consulta). Devolve quantos ficaram faltando. */
function rd_ensure(string $layer, array $tiles): int {
    if (!$tiles) return 0;
    $keys = array_values($tiles);
    $have = [];
    $conds = [];
    $args = [$layer];
    foreach ($keys as [$tx, $ty]) { $conds[] = '(tx = ? AND ty = ?)'; $args[] = $tx; $args[] = $ty; }
    $st = db()->prepare('SELECT tx, ty FROM road_tiles WHERE layer = ? AND fetched_at > (NOW() - INTERVAL ' . RD_MAX_AGE_DAYS . ' DAY) AND (' . implode(' OR ', $conds) . ')');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tx, $ty]) $have["$tx,$ty"] = true;
    $missing = [];
    foreach ($tiles as $k => $t) if (!isset($have[$k])) $missing[$k] = $t;
    if (!$missing) return 0;

    // limite global de consultas ao Overpass por hora (respeito ao serviço gratuito)
    if (rate_count('roads_fetch', 'all', 60) >= 400) return count($missing);

    // muitos quadrados de cidade numa consulta só estouram o tempo do Overpass: se falhar, tenta grupos menores
    $done = 0;
    foreach ([RD_MAX_TILES_PER_FETCH[$layer], 3, 1] as $size) {
        if (rd_elapsed() > RD_BUDGET_S - 25) break;
        $batch = array_slice($missing, $done, $size, true);
        if (!$batch) break;
        $ok = rd_fetch_tiles($layer, $batch);
        if ($ok) { $done += count($batch); if ($size === RD_MAX_TILES_PER_FETCH[$layer]) break; }
    }
    return count($missing) - $done;
}

function rd_fetch_tiles(string $layer, array $batch): bool {
    $sz = RD_TILE[$layer];
    $classes = $layer === 'p' ? RD_PAVED : RD_ALL;
    $parts = '';
    foreach ($batch as [$tx, $ty]) {
        $s = round($ty * $sz, 5); $w = round($tx * $sz, 5); $n = round(($ty + 1) * $sz, 5); $e = round(($tx + 1) * $sz, 5);
        $parts .= "way($s,$w,$n,$e)[highway~\"^($classes)$\"];";
    }
    rate_hit('roads_fetch', 'all');
    $els = rd_fetch('[out:json][timeout:60];(' . $parts . ');out tags geom;', 70);
    if ($els === null) return false;

    // reparte cada estrada pelos quadrados que ela atravessa
    $per = [];
    foreach ($batch as $k => $_) $per[$k] = [];
    foreach ($els as $w) {
        if (($w['type'] ?? '') !== 'way' || empty($w['geometry']) || count($w['geometry']) < 2) continue;
        $pts = [];
        $hit = [];
        foreach ($w['geometry'] as $g) {
            if (!isset($g['lat'], $g['lon'])) continue;
            $pts[] = [(float)$g['lat'], (float)$g['lon']];
            $k = (int)floor($g['lon'] / $sz) . ',' . (int)floor($g['lat'] / $sz);
            if (isset($per[$k])) $hit[$k] = true;
        }
        if (count($pts) < 2 || !$hit) continue;
        $t = $w['tags'] ?? [];
        $road = [
            'i' => (int)($w['id'] ?? 0),
            'h' => (string)($t['highway'] ?? ''),
            's' => (string)($t['surface'] ?? ''),
            'n' => mb_substr((string)($t['name'] ?? $t['ref'] ?? ''), 0, 80),
            'p' => rd_polyline($pts),
        ];
        foreach ($hit as $k => $_) $per[$k][] = $road;
    }
    $ins = db()->prepare('REPLACE INTO road_tiles (layer, tx, ty, n, data, fetched_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($batch as $k => [$tx, $ty]) {
        $ins->execute([$layer, $tx, $ty, count($per[$k]), gzcompress(json_encode($per[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6)]);
    }
    return true;
}

/** Estradas guardadas nos quadrados (sem repetir a mesma estrada). */
function rd_load(string $layer, array $tiles): array {
    if (!$tiles) return [];
    $conds = []; $args = [$layer];
    foreach ($tiles as [$tx, $ty]) { $conds[] = '(tx = ? AND ty = ?)'; $args[] = $tx; $args[] = $ty; }
    $st = db()->prepare('SELECT data FROM road_tiles WHERE layer = ? AND (' . implode(' OR ', $conds) . ')');
    $st->execute($args);
    $seen = []; $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $blob) {
        $list = json_decode((string)@gzuncompress((string)$blob), true) ?: [];
        foreach ($list as $r) {
            if (isset($seen[$r['i']])) continue;
            $seen[$r['i']] = true;
            $out[] = $r;
        }
    }
    return $out;
}

function rd_decode(string $s): array {
    $pts = []; $i = 0; $lat = 0; $lng = 0; $len = strlen($s);
    while ($i < $len) {
        foreach ([0, 1] as $which) {
            $r = 0; $sh = 0;
            do { $b = ord($s[$i++]) - 63; $r |= ($b & 0x1f) << $sh; $sh += 5; } while ($b >= 0x20 && $i < $len);
            $d = ($r & 1) ? ~($r >> 1) : ($r >> 1);
            if ($which === 0) $lat += $d; else $lng += $d;
        }
        $pts[] = [$lat / 1e5, $lng / 1e5];
    }
    return $pts;
}

function rd_is_paved(array $r): bool {
    $sf = strtolower($r['s'] ?? '');
    if (preg_match('/unpaved|dirt|ground|gravel|earth|sand|grass|compacted|mud|fine_gravel/', $sf)) return false;
    if (preg_match('/asphalt|paved|concrete|paving_stones|sett/', $sf)) return true;
    return in_array($r['h'] ?? '', ['residential', 'motorway', 'trunk', 'primary', 'secondary', 'tertiary', 'motorway_link', 'trunk_link', 'primary_link', 'secondary_link', 'tertiary_link'], true);
}

/** Ponto mais perto (aproximação plana local — boa para alguns km). */
function rd_closest(float $lat, float $lng, array $roads, bool $onlyPaved): ?array {
    $kx = 111320 * cos(deg2rad($lat)); $ky = 110540;
    $best = null;
    foreach ($roads as $r) {
        if ($onlyPaved && !rd_is_paved($r)) continue;
        $pts = rd_decode($r['p']);
        for ($j = 0; $j < count($pts) - 1; $j++) {
            $ax = ($pts[$j][1] - $lng) * $kx; $ay = ($pts[$j][0] - $lat) * $ky;
            $bx = ($pts[$j + 1][1] - $lng) * $kx; $by = ($pts[$j + 1][0] - $lat) * $ky;
            $dx = $bx - $ax; $dy = $by - $ay;
            $l2 = $dx * $dx + $dy * $dy;
            $t = $l2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $l2)) : 0;
            $px = $ax + $t * $dx; $py = $ay + $t * $dy;
            $d = sqrt($px * $px + $py * $py);
            if (!$best || $d < $best['d']) {
                $best = ['d' => round($d), 'lat' => round($lat + $py / $ky, 6), 'lng' => round($lng + $px / $kx, 6), 'h' => $r['h'], 's' => $r['s'], 'n' => $r['n']];
            }
        }
    }
    return $best;
}

$in = input();
$mode = (string)($in['mode'] ?? '');

if ($mode === 'near') {
    $lat = (float)($in['lat'] ?? 0); $lng = (float)($in['lng'] ?? 0);
    if (!rd_valid($lat, $lng)) fail('Posição inválida.');
    $pending = 0;
    $any = null; $paved = null;
    foreach ([1500, 4000] as $r) {
        $tiles = rd_tiles_around('a', $lat, $lng, $r);
        $pending = rd_ensure('a', $tiles);
        $roads = rd_load('a', $tiles);
        $any = rd_closest($lat, $lng, $roads, false);
        $paved = rd_closest($lat, $lng, $roads, true);
        if ($any && $any['d'] <= $r) break;
        if (rd_elapsed() > RD_BUDGET_S - 20) break;
    }
    if (!$any && $pending) fail('O serviço de estradas está ocupado. Tente de novo em instantes.', 503, ['busy' => true]);
    if ((!$paved || $paved['d'] > 3000) && rd_elapsed() < RD_BUDGET_S - 20) {
        $tp = rd_tiles_around('p', $lat, $lng, 8000);
        rd_ensure('p', $tp);
        $pp = rd_closest($lat, $lng, rd_load('p', $tp), true);
        if ($pp && (!$paved || $pp['d'] < $paved['d'])) $paved = $pp;
    }
    json_out(['ok' => true, 'any' => $any, 'paved' => $paved, 'partial' => $pending > 0, 'diag' => $RD_DIAG]);
}

if ($mode === 'route') {
    $pts = $in['pts'] ?? null;
    if (!is_array($pts) || !$pts) fail('Rota inválida.');
    $tiles = [];
    foreach (array_slice($pts, 0, 40) as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $la = (float)$p[0]; $lo = (float)$p[1];
        if (!rd_valid($la, $lo)) continue;
        $tiles += rd_tiles_around('a', $la, $lo, 3000);
    }
    if (!$tiles) fail('Rota inválida.');
    if (count($tiles) > 120) $tiles = array_slice($tiles, 0, 120, true);
    $pending = rd_ensure('a', $tiles);
    if ($pending && rd_elapsed() < RD_BUDGET_S - 40) $pending = rd_ensure('a', $tiles);
    $roads = rd_load('a', $tiles);
    if ($pending && !$roads) fail('O serviço de estradas está ocupado. Tente de novo em instantes.', 503, ['busy' => true]);
    if (function_exists('ob_gzhandler') && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) ob_start('ob_gzhandler');
    json_out(['ok' => true, 'roads' => $roads, 'pending' => $pending, 'diag' => $RD_DIAG]);
}

fail('Modo inválido.');
