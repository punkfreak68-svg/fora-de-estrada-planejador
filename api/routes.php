<?php
// Rotas salvas sincronizadas pela conta (substitui a necessidade do Google Drive para as rotas).
// Regra igual à do app: para cada rota vale a versão mais recente (savedAt / deletedAt);
// exclusões viram "lápide" (deleted) para propagar entre aparelhos.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

const ROUTES_MAX_PER_USER = 3000;
const ROUTE_MAX_BYTES = 400 * 1024;      // uma rota (pontos, notas, waypoints sem fotos)
const SYNC_MAX_BODY = 6 * 1024 * 1024;   // pedido inteiro

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync':
        require_post();
        $u = require_user();
        $uid = (int)$u['id'];
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > SYNC_MAX_BODY) fail('Rotas demais de uma vez. Tente de novo.', 413);
        rate_limit('sync', (string)$uid, 240, 60, 'Muitas sincronizações. Aguarde alguns minutos.');
        rate_hit('sync', (string)$uid);
        $incoming = input()['routes'] ?? [];
        if (!is_array($incoming)) fail('Formato inválido.');
        if (count($incoming) > ROUTES_MAX_PER_USER) fail('Limite de ' . ROUTES_MAX_PER_USER . ' rotas por conta.');

        $pdo = db();
        $cur = [];
        $st = $pdo->prepare('SELECT route_id, rev FROM routes WHERE user_id = ?');
        $st->execute([$uid]);
        foreach ($st->fetchAll() as $r) $cur[$r['route_id']] = (int)$r['rev'];

        $ins = $pdo->prepare('INSERT INTO routes (user_id, route_id, rev, deleted, data) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rev = VALUES(rev), deleted = VALUES(deleted), data = VALUES(data)');
        $accepted = 0;
        $rejected = 0;
        $pdo->beginTransaction();
        foreach ($incoming as $r) {
            if (!is_array($r)) { $rejected++; continue; }
            $id = (string)($r['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $id)) { $rejected++; continue; }
            $deleted = !empty($r['deleted']);
            $rev = (int)($deleted ? ($r['deletedAt'] ?? $r['savedAt'] ?? 0) : ($r['savedAt'] ?? 0));
            if ($rev <= 0 || $rev > (time() + 86400) * 1000) { $rejected++; continue; } // relógio absurdo
            if (isset($cur[$id]) && $cur[$id] >= $rev) continue; // servidor já tem igual ou mais nova
            if (!$deleted && (!isset($r['points']) || !is_array($r['points']))) { $rejected++; continue; }
            if ($deleted) {
                // lápide: guarda só o necessário
                $r = ['id' => $id, 'deleted' => true, 'deletedAt' => $rev, 'savedAt' => (int)($r['savedAt'] ?? $rev)];
            }
            $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > ROUTE_MAX_BYTES) { $rejected++; continue; }
            if (!isset($cur[$id]) && count($cur) >= ROUTES_MAX_PER_USER) { $rejected++; continue; }
            $ins->execute([$uid, $id, $rev, $deleted ? 1 : 0, $json]);
            $cur[$id] = $rev;
            $accepted++;
        }
        $pdo->commit();
        json_out(['ok' => true, 'accepted' => $accepted, 'rejected' => $rejected, 'routes' => all_routes($uid)]);

    case 'list':
        $u = require_user();
        json_out(['ok' => true, 'routes' => all_routes((int)$u['id'])]);

    default:
        fail('Ação desconhecida.', 404);
}

function all_routes(int $uid): array {
    $st = db()->prepare('SELECT data FROM routes WHERE user_id = ?');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode($r['data'], true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
