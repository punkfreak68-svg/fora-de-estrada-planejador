<?php
// Sincronização genérica de itens do app por conta (rotas salvas, expedições...).
// Regra igual à do app: para cada item vale a versão mais recente (savedAt / deletedAt);
// exclusões viram "lápide" mínima para propagar entre aparelhos.
declare(strict_types=1);

const SYNC_MAX_ITEMS = 3000;
const SYNC_MAX_ITEM_BYTES = 400 * 1024;
const SYNC_MAX_BODY = 6 * 1024 * 1024;
const SYNC_QUOTA_BYTES = 50 * 1024 * 1024;    // v8.3: espaço máximo por conta, em cada tipo (rotas, expedições, garagem)
const SYNC_DELETE_SKEW_MS = 10 * 60 * 1000;   // v8.3: exclusão "do futuro" (relógio adiantado) é recusada
const SYNC_TRASH_DAYS = 30;

// $table: 'routes' | 'expeditions' | 'garage'  (nomes fixos, nunca vindos do usuário)
// $valid: função que diz se um item não excluído tem o formato mínimo esperado
function sync_handle(string $table, string $field, callable $valid): never {
    if (!in_array($table, ['routes', 'expeditions', 'garage'], true)) throw new RuntimeException('tabela inválida');
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $u = require_user();
        json_out(['ok' => true, $field => sync_all($table, (int)$u['id'])]);
    }
    // v8.3 — lixeira: o que foi apagado nos últimos 30 dias
    if ($action === 'trash') {
        $u = require_user();
        $st = db()->prepare('SELECT id, item_id, data, deleted_at FROM sync_trash WHERE user_id = ? AND tbl = ? AND deleted_at > (NOW() - INTERVAL ' . SYNC_TRASH_DAYS . ' DAY) ORDER BY deleted_at DESC LIMIT 100');
        $st->execute([(int)$u['id'], $table]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $d = json_decode($r['data'], true);
            $items[] = ['id' => (int)$r['id'], 'item_id' => $r['item_id'], 'name' => is_array($d) ? mb_substr((string)($d['name'] ?? ''), 0, 100) : '',
                'points' => is_array($d) && is_array($d['points'] ?? null) ? count($d['points']) : 0, 'deleted_at' => $r['deleted_at']];
        }
        json_out(['ok' => true, 'items' => $items]);
    }
    if ($action === 'restore') {
        require_post();
        $u = require_user();
        $uid = (int)$u['id'];
        rate_limit('restore', (string)$uid, 60, 60, 'Muitas recuperações seguidas. Aguarde um pouco.');
        rate_hit('restore', (string)$uid);
        $tid = (int)(input()['id'] ?? 0);
        $st = db()->prepare('SELECT item_id, data FROM sync_trash WHERE id = ? AND user_id = ? AND tbl = ?');
        $st->execute([$tid, $uid, $table]);
        $row = $st->fetch();
        if (!$row) fail('Não encontrado na lixeira.', 404);
        $d = json_decode($row['data'], true);
        if (!is_array($d)) fail('Item corrompido.');
        $now = (int)floor(microtime(true) * 1000);
        unset($d['deleted'], $d['deletedAt']);
        $d['savedAt'] = $now;
        $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO $table (user_id, route_id, rev, deleted, data) VALUES (?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE rev = VALUES(rev), deleted = 0, data = VALUES(data)")->execute([$uid, $row['item_id'], $now, $json]);
        $pdo->prepare('DELETE FROM sync_trash WHERE id = ?')->execute([$tid]);
        $pdo->commit();
        json_out(['ok' => true, $field => sync_all($table, $uid)]);
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
    $curLen = [];
    $curDel = [];
    $st = $pdo->prepare("SELECT route_id, rev, deleted, LENGTH(data) AS len FROM $table WHERE user_id = ?");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) {
        $cur[$r['route_id']] = (int)$r['rev'];
        $curLen[$r['route_id']] = (int)$r['len'];
        $curDel[$r['route_id']] = (int)$r['deleted'] === 1;
    }
    $used = array_sum($curLen);
    $overQuota = false;
    // antes de virar "lápide", o conteúdo vai para a lixeira (dá para recuperar por 30 dias)
    $toTrash = $pdo->prepare("INSERT INTO sync_trash (user_id, tbl, item_id, data) SELECT user_id, ?, route_id, data FROM $table WHERE user_id = ? AND route_id = ? AND deleted = 0");

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
        if ($deleted && $rev > time() * 1000 + SYNC_DELETE_SKEW_MS) { $rejected++; continue; } // exclusão com relógio adiantado
        if (isset($cur[$id]) && $cur[$id] >= $rev) continue; // servidor já tem igual ou mais nova
        if (!$deleted && !$valid($r)) { $rejected++; continue; }
        if ($deleted) $r = ['id' => $id, 'deleted' => true, 'deletedAt' => $rev, 'savedAt' => (int)($r['savedAt'] ?? $rev)];
        $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > SYNC_MAX_ITEM_BYTES) { $rejected++; continue; }
        if (!isset($cur[$id]) && count($cur) >= SYNC_MAX_ITEMS) { $rejected++; continue; }
        $newLen = strlen($json);
        $oldLen = $curLen[$id] ?? 0;
        if (!$deleted && $newLen > $oldLen && $used - $oldLen + $newLen > SYNC_QUOTA_BYTES) { $rejected++; $overQuota = true; continue; }
        if ($deleted && isset($cur[$id]) && empty($curDel[$id])) $toTrash->execute([$table, $uid, $id]);
        $ins->execute([$uid, $id, $rev, $deleted ? 1 : 0, $json]);
        $cur[$id] = $rev;
        $used += $newLen - $oldLen;
        $curLen[$id] = $newLen;
        $curDel[$id] = $deleted;
        $accepted++;
    }
    $pdo->commit();
    if (random_int(1, 50) === 1) $pdo->exec('DELETE FROM sync_trash WHERE deleted_at < (NOW() - INTERVAL ' . SYNC_TRASH_DAYS . ' DAY)');
    json_out(['ok' => true, 'accepted' => $accepted, 'rejected' => $rejected, 'over_quota' => $overQuota, $field => sync_all($table, $uid)]);
}

function sync_all(string $table, int $uid, bool $onlyLive = false): array {
    if (!in_array($table, ['routes', 'expeditions', 'garage'], true)) throw new RuntimeException('tabela inválida');
    $st = db()->prepare("SELECT data FROM $table WHERE user_id = ?" . ($onlyLive ? ' AND deleted = 0 ORDER BY updated_at' : ''));
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode($r['data'], true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
