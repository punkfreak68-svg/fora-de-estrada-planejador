<?php
// Sincronização genérica de itens do app por conta (rotas salvas, expedições...).
// Regra igual à do app: para cada item vale a versão mais recente (savedAt / deletedAt);
// exclusões viram "lápide" mínima para propagar entre aparelhos.
declare(strict_types=1);

const SYNC_MAX_ITEMS = 3000;
const SYNC_MAX_ITEM_BYTES = 400 * 1024;
const SYNC_MAX_BODY = 6 * 1024 * 1024;

// $table: 'routes' | 'expeditions'  (nomes fixos, nunca vindos do usuário)
// $valid: função que diz se um item não excluído tem o formato mínimo esperado
function sync_handle(string $table, string $field, callable $valid): never {
    if (!in_array($table, ['routes', 'expeditions'], true)) throw new RuntimeException('tabela inválida');
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $u = require_user();
        json_out(['ok' => true, $field => sync_all($table, (int)$u['id'])]);
    }
    if ($action !== 'sync') fail('Ação desconhecida.', 404);

    require_post();
    $u = require_user();
    $uid = (int)$u['id'];
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > SYNC_MAX_BODY) fail('Dados demais de uma vez. Tente de novo.', 413);
    rate_limit('sync_' . $table, (string)$uid, 240, 60, 'Muitas sincronizações. Aguarde alguns minutos.');
    rate_hit('sync_' . $table, (string)$uid);
    $incoming = input()[$field] ?? [];
    if (!is_array($incoming)) fail('Formato inválido.');
    if (count($incoming) > SYNC_MAX_ITEMS) fail('Limite de ' . SYNC_MAX_ITEMS . ' itens por conta.');

    $pdo = db();
    $cur = [];
    $st = $pdo->prepare("SELECT route_id, rev FROM $table WHERE user_id = ?");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $cur[$r['route_id']] = (int)$r['rev'];

    $ins = $pdo->prepare("INSERT INTO $table (user_id, route_id, rev, deleted, data) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rev = VALUES(rev), deleted = VALUES(deleted), data = VALUES(data)");
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
        if (!$deleted && !$valid($r)) { $rejected++; continue; }
        if ($deleted) $r = ['id' => $id, 'deleted' => true, 'deletedAt' => $rev, 'savedAt' => (int)($r['savedAt'] ?? $rev)];
        $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > SYNC_MAX_ITEM_BYTES) { $rejected++; continue; }
        if (!isset($cur[$id]) && count($cur) >= SYNC_MAX_ITEMS) { $rejected++; continue; }
        $ins->execute([$uid, $id, $rev, $deleted ? 1 : 0, $json]);
        $cur[$id] = $rev;
        $accepted++;
    }
    $pdo->commit();
    json_out(['ok' => true, 'accepted' => $accepted, 'rejected' => $rejected, $field => sync_all($table, $uid)]);
}

function sync_all(string $table, int $uid, bool $onlyLive = false): array {
    if (!in_array($table, ['routes', 'expeditions'], true)) throw new RuntimeException('tabela inválida');
    $st = db()->prepare("SELECT data FROM $table WHERE user_id = ?" . ($onlyLive ? ' AND deleted = 0 ORDER BY updated_at' : ''));
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode($r['data'], true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
