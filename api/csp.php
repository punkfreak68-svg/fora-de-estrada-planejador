<?php
// v5.8.2: recebe os relatórios da Content-Security-Policy (modo observação).
// Guarda só a diretiva, a origem bloqueada (sem caminho/parâmetros) e a página — nada do usuário.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Método não permitido.', 405);
rate_limit('csp', client_ip(), 120, 60, 'Muitas requisições.');
rate_hit('csp', client_ip());

$raw = file_get_contents('php://input', false, null, 0, 20000) ?: '';
$data = json_decode($raw, true);
$items = [];
if (isset($data['csp-report']) && is_array($data['csp-report'])) {
    $items[] = $data['csp-report'];                       // formato report-uri
} elseif (is_array($data)) {
    foreach (array_slice($data, 0, 20) as $r) {           // formato Reporting API
        if (is_array($r) && isset($r['body']) && is_array($r['body'])) $items[] = $r['body'];
    }
}

function csp_origin(string $u): string {
    if ($u === '' ) return '(vazio)';
    if (!str_contains($u, '://')) return mb_substr($u, 0, 60);   // inline, eval, data, blob...
    $p = parse_url($u);
    return isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] : mb_substr($u, 0, 60);
}

$st = db()->prepare('INSERT INTO csp_reports (directive, blocked, page) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE n = n + 1, last_at = NOW(), page = VALUES(page)');
foreach ($items as $r) {
    $dir = (string)($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? '');
    $blocked = (string)($r['blocked-uri'] ?? $r['blockedURL'] ?? '');
    $page = (string)($r['document-uri'] ?? $r['documentURL'] ?? '');
    $page = parse_url($page, PHP_URL_PATH) ?: '';
    $dir = mb_substr(preg_replace('/[^a-z-]/', '', strtolower($dir)) ?: '?', 0, 40);
    $st->execute([$dir, mb_substr(csp_origin($blocked), 0, 190), mb_substr($page, 0, 190)]);
}
http_response_code(204);
exit;
