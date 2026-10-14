<?php
// v5.7: números do app — aparelhos ativos (anônimo) e contas ativas. O resumo é só para o administrador.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ping':
        // o app manda 1x por dia um id aleatório do aparelho; guardamos só o hash dele
        require_post();
        $device = str_in('device', 64);
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $device)) fail('Requisição inválida.');
        rate_limit('ping', client_ip(), 60, 60, 'Muitas requisições.');
        rate_hit('ping', client_ip());
        $u = current_user();
        db()->prepare('INSERT IGNORE INTO app_days (day, device, user_id) VALUES (CURDATE(), ?, ?)')
            ->execute([hash('sha256', 'fde-device:' . $device), $u ? (int)$u['id'] : null]);
        if (random_int(1, 200) === 1) db()->exec('DELETE FROM app_days WHERE day < (CURDATE() - INTERVAL 400 DAY)');
        json_out(['ok' => true]);

    case 'summary':
        $me = require_user();
        if ($me['role'] !== 'admin') fail('Só o administrador vê os números.', 403);
        $pdo = db();
        $one = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
        $devices = [
            'today' => $one('SELECT COUNT(DISTINCT device) FROM app_days WHERE day = CURDATE()'),
            'd7' => $one('SELECT COUNT(DISTINCT device) FROM app_days WHERE day > (CURDATE() - INTERVAL 7 DAY)'),
            'd30' => $one('SELECT COUNT(DISTINCT device) FROM app_days WHERE day > (CURDATE() - INTERVAL 30 DAY)'),
            'total' => $one('SELECT COUNT(DISTINCT device) FROM app_days'),
            'since' => (string)($pdo->query('SELECT MIN(day) FROM app_days')->fetchColumn() ?: ''),
        ];
        $accounts = [
            'total' => $one('SELECT COUNT(*) FROM users'),
            'new7' => $one('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 7 DAY)'),
            'new30' => $one('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 30 DAY)'),
            'active1' => $one('SELECT COUNT(*) FROM users WHERE last_seen_at > (NOW() - INTERVAL 1 DAY)'),
            'active7' => $one('SELECT COUNT(*) FROM users WHERE last_seen_at > (NOW() - INTERVAL 7 DAY)'),
            'active30' => $one('SELECT COUNT(*) FROM users WHERE last_seen_at > (NOW() - INTERVAL 30 DAY)'),
        ];
        $content = [
            'routes' => $one('SELECT COUNT(*) FROM routes WHERE deleted = 0'),
            'expeditions' => $one('SELECT COUNT(*) FROM expeditions WHERE deleted = 0'),
            'posts' => $one('SELECT COUNT(*) FROM posts'),
            'comments' => $one('SELECT COUNT(*) FROM comments'),
            'likes' => $one('SELECT COUNT(*) FROM post_likes'),
        ];
        $series = [];
        $st = $pdo->query('SELECT day, COUNT(*) n FROM app_days WHERE day > (CURDATE() - INTERVAL 30 DAY) GROUP BY day ORDER BY day');
        foreach ($st as $r) $series[] = ['day' => (string)$r['day'], 'n' => (int)$r['n']];
        $latest = [];
        $st = $pdo->query('SELECT username, display_name, created_at FROM users ORDER BY id DESC LIMIT 10');
        foreach ($st as $r) $latest[] = ['username' => $r['username'], 'display_name' => $r['display_name'], 'created_at' => (string)$r['created_at']];
        json_out(['ok' => true, 'devices' => $devices, 'accounts' => $accounts, 'content' => $content, 'series' => $series, 'latest' => $latest]);

    default:
        fail('Ação desconhecida.', 404);
}
