<?php
// Comunidade etapa 2: feed de trilhas — publicar, ver, curtir, comentar, seguir, baixar, denunciar, moderar.
// Tudo exige conta (só quem está logado vê o feed).
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

const FEED_PAGE = 15;
const POST_MAX_PHOTOS = 4;
const REPORTS_TO_HIDE = 3;

$action = $_GET['action'] ?? '';
$me = require_user();
$meId = (int)$me['id'];
$isAdmin = $me['role'] === 'admin';

switch ($action) {
    case 'list':
        $scope = $_GET['scope'] ?? 'all';
        $before = (int)($_GET['before'] ?? 0);
        $where = ['(p.hidden = 0 OR p.user_id = ?)'];
        $params = [$meId];
        if ($scope === 'following') {
            $where[] = 'p.user_id IN (SELECT followee_id FROM follows WHERE follower_id = ?)';
            $params[] = $meId;
        }
        if (!empty($_GET['u'])) {
            $who = find_user_by('username', strtolower((string)$_GET['u']));
            if (!$who) fail('Perfil não encontrado.', 404);
            $where[] = 'p.user_id = ?';
            $params[] = (int)$who['id'];
        }
        if ($before > 0) {
            $where[] = 'p.id < ?';
            $params[] = $before;
        }
        $sql = 'SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.bio, u.vehicle AS u_vehicle, u.city, u.created_at AS u_created,
                   EXISTS(SELECT 1 FROM post_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS liked
            FROM posts p JOIN users u ON u.id = p.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT ' . (FEED_PAGE + 1);
        $st = db()->prepare($sql);
        $st->execute(array_merge([$meId], $params));
        $rows = $st->fetchAll();
        $more = count($rows) > FEED_PAGE;
        $rows = array_slice($rows, 0, FEED_PAGE);
        json_out(['ok' => true, 'posts' => array_map(fn($r) => post_out($r, $meId, $isAdmin), $rows), 'more' => $more]);

    case 'get':
        $p = load_post((int)($_GET['id'] ?? 0), $meId, $isAdmin);
        $out = post_out($p, $meId, $isAdmin);
        $out['route'] = json_decode($p['route_json'], true);
        $st = db()->prepare('SELECT c.*, u.username, u.display_name, u.avatar, u.role, u.bio, u.vehicle AS u_vehicle, u.city, u.created_at AS u_created
            FROM comments c JOIN users u ON u.id = c.user_id WHERE c.post_id = ? AND (c.hidden = 0 OR c.user_id = ? OR ?) ORDER BY c.id LIMIT 300');
        $st->execute([$p['id'], $meId, $isAdmin ? 1 : 0]);
        $out['comment_list'] = array_map(fn($c) => [
            'id' => (int)$c['id'],
            'body' => $c['body'],
            'created_at' => $c['created_at'],
            'hidden' => (bool)$c['hidden'],
            'mine' => (int)$c['user_id'] === $meId,
            'can_delete' => (int)$c['user_id'] === $meId || (int)$p['user_id'] === $meId || $isAdmin,
            'author' => author_out($c),
        ], $st->fetchAll());
        $out['following_author'] = is_following($meId, (int)$p['user_id']);
        json_out(['ok' => true, 'post' => $out]);

    case 'create':
        require_post();
        rate_limit('post', (string)$meId, 15, 60 * 24, 'Limite de 15 publicações por dia.');
        $data = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($data)) fail('Dados inválidos.');
        $title = trim(mb_substr((string)($data['title'] ?? ''), 0, 100));
        $body = trim(mb_substr((string)($data['body'] ?? ''), 0, 1500));
        if (mb_strlen($title) < 3) fail('Dê um título para a trilha (pelo menos 3 letras).');
        $route = clean_route($data['route'] ?? null);
        $km = route_km($route['points']);
        $diff = max(0, min(5, (int)($route['meta']['difficulty'] ?? 0)));
        $vehicle = mb_substr((string)($route['meta']['vehicle'] ?? ''), 0, 40);
        $files = normalize_files($_FILES['photos'] ?? null);
        if (count($files) > POST_MAX_PHOTOS) fail('No máximo ' . POST_MAX_PHOTOS . ' fotos por publicação.');
        rate_hit('post', (string)$meId);
        $saved = [];
        foreach ($files as $f) {
            $name = save_post_photo($f, $meId);
            if ($name === null) {
                foreach ($saved as $s) @unlink(posts_dir() . '/' . $s);
                fail('Uma das fotos não pôde ser lida. Use JPG, PNG ou WebP de até 10 MB.');
            }
            $saved[] = $name;
        }
        db()->prepare('INSERT INTO posts (user_id, title, body, km, difficulty, vehicle, route_json, preview_json, photos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$meId, $title, $body, round($km, 2), $diff, $vehicle,
                json_encode($route, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode(preview_points($route['points'])), json_encode($saved)]);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);

    case 'delete':
        require_post();
        $p = load_post((int)(input()['id'] ?? 0), $meId, $isAdmin);
        if ((int)$p['user_id'] !== $meId && !$isAdmin) fail('Só quem publicou pode apagar.', 403);
        delete_post($p);
        json_out(['ok' => true]);

    case 'like':
    case 'unlike':
        require_post();
        rate_limit('like', (string)$meId, 400, 60, 'Calma! Muitas curtidas seguidas.');
        $p = load_post((int)(input()['id'] ?? 0), $meId, $isAdmin);
        if ($action === 'like') {
            $ins = db()->prepare('INSERT IGNORE INTO post_likes (post_id, user_id) VALUES (?, ?)');
            $ins->execute([$p['id'], $meId]);
            if ($ins->rowCount()) rate_hit('like', (string)$meId);
        } else {
            db()->prepare('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?')->execute([$p['id'], $meId]);
        }
        $n = recount_likes((int)$p['id']);
        json_out(['ok' => true, 'likes' => $n, 'liked' => $action === 'like']);

    case 'comment':
        require_post();
        rate_limit('comment', (string)$meId, 60, 60, 'Muitos comentários seguidos. Espere um pouco.');
        $p = load_post((int)(input()['id'] ?? 0), $meId, $isAdmin);
        $body = trim(mb_substr(str_in('body', 1000), 0, 500));
        if ($body === '') fail('Escreva o comentário.');
        rate_hit('comment', (string)$meId);
        db()->prepare('INSERT INTO comments (post_id, user_id, body) VALUES (?, ?, ?)')->execute([$p['id'], $meId, $body]);
        recount_comments((int)$p['id']);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);

    case 'comment_delete':
        require_post();
        $st = db()->prepare('SELECT c.*, p.user_id AS post_owner FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?');
        $st->execute([(int)(input()['id'] ?? 0)]);
        $c = $st->fetch();
        if (!$c) fail('Comentário não encontrado.', 404);
        if ((int)$c['user_id'] !== $meId && (int)$c['post_owner'] !== $meId && !$isAdmin) fail('Sem permissão.', 403);
        db()->prepare('DELETE FROM comments WHERE id = ?')->execute([$c['id']]);
        db()->prepare("DELETE FROM reports WHERE kind = 'comment' AND target_id = ?")->execute([$c['id']]);
        recount_comments((int)$c['post_id']);
        json_out(['ok' => true]);

    case 'download':
        require_post();
        $p = load_post((int)(input()['id'] ?? 0), $meId, $isAdmin);
        if ((int)$p['user_id'] !== $meId) db()->prepare('UPDATE posts SET downloads = downloads + 1 WHERE id = ?')->execute([$p['id']]);
        json_out(['ok' => true, 'route' => json_decode($p['route_json'], true), 'title' => $p['title'], 'author' => $p['username']]);

    case 'follow':
    case 'unfollow':
        require_post();
        rate_limit('follow', (string)$meId, 100, 60, 'Muitas ações seguidas. Espere um pouco.');
        $who = find_user_by('username', strtolower(str_in('username', 40)));
        if (!$who) fail('Perfil não encontrado.', 404);
        if ((int)$who['id'] === $meId) fail('Você não pode seguir a si mesmo.');
        rate_hit('follow', (string)$meId);
        if ($action === 'follow') db()->prepare('INSERT IGNORE INTO follows (follower_id, followee_id) VALUES (?, ?)')->execute([$meId, $who['id']]);
        else db()->prepare('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?')->execute([$meId, $who['id']]);
        json_out(['ok' => true, 'following' => $action === 'follow', 'profile' => profile_out($who, $meId)]);

    case 'profile':
        $who = find_user_by('username', strtolower((string)($_GET['u'] ?? '')));
        if (!$who) fail('Perfil não encontrado.', 404);
        json_out(['ok' => true, 'profile' => profile_out($who, $meId)]);

    case 'report':
        require_post();
        rate_limit('report', (string)$meId, 20, 60 * 24, 'Limite de denúncias por dia atingido.');
        $kind = input()['kind'] ?? 'post';
        if (!in_array($kind, ['post', 'comment'], true)) fail('Tipo inválido.');
        $id = (int)(input()['id'] ?? 0);
        $reason = mb_substr(str_in('reason', 400), 0, 200);
        $table = $kind === 'post' ? 'posts' : 'comments';
        $st = db()->prepare("SELECT user_id FROM $table WHERE id = ?");
        $st->execute([$id]);
        $owner = $st->fetchColumn();
        if ($owner === false) fail('Não encontrado.', 404);
        if ((int)$owner === $meId) fail('Você não pode denunciar o que você mesmo publicou.');
        $ins = db()->prepare('INSERT IGNORE INTO reports (kind, target_id, user_id, reason) VALUES (?, ?, ?, ?)');
        $ins->execute([$kind, $id, $meId, $reason]);
        if ($ins->rowCount()) {
            rate_hit('report', (string)$meId);
            db()->prepare("UPDATE $table SET reports = (SELECT COUNT(*) FROM reports WHERE kind = ? AND target_id = ?), hidden = IF(reports >= ?, 1, hidden) WHERE id = ?")
                ->execute([$kind, $id, REPORTS_TO_HIDE, $id]);
            // segunda passada para o IF enxergar o valor novo de reports
            db()->prepare("UPDATE $table SET hidden = 1 WHERE id = ? AND reports >= ?")->execute([$id, REPORTS_TO_HIDE]);
        }
        json_out(['ok' => true]);

    case 'mod_list':
        if (!$isAdmin) fail('Só administradores.', 403);
        $st = db()->query('SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.bio, u.vehicle AS u_vehicle, u.city, u.created_at AS u_created, 0 AS liked
            FROM posts p JOIN users u ON u.id = p.user_id WHERE p.hidden = 1 OR p.reports > 0 ORDER BY p.hidden DESC, p.reports DESC, p.id DESC LIMIT 100');
        $posts = array_map(fn($r) => post_out($r, $meId, true) + ['reasons' => reasons('post', (int)$r['id'])], $st->fetchAll());
        $st = db()->query('SELECT c.*, u.username, u.display_name, u.avatar, u.role, u.bio, u.vehicle AS u_vehicle, u.city, u.created_at AS u_created
            FROM comments c JOIN users u ON u.id = c.user_id WHERE c.hidden = 1 OR c.reports > 0 ORDER BY c.hidden DESC, c.reports DESC, c.id DESC LIMIT 100');
        $comments = array_map(fn($c) => ['id' => (int)$c['id'], 'post_id' => (int)$c['post_id'], 'body' => $c['body'], 'reports' => (int)$c['reports'],
            'hidden' => (bool)$c['hidden'], 'author' => author_out($c), 'reasons' => reasons('comment', (int)$c['id'])], $st->fetchAll());
        json_out(['ok' => true, 'posts' => $posts, 'comments' => $comments]);

    case 'mod':
        require_post();
        if (!$isAdmin) fail('Só administradores.', 403);
        $kind = input()['kind'] ?? '';
        $id = (int)(input()['id'] ?? 0);
        $do = input()['do'] ?? '';
        if (!in_array($kind, ['post', 'comment'], true) || !in_array($do, ['approve', 'delete'], true)) fail('Ação inválida.');
        if ($do === 'approve') {
            $table = $kind === 'post' ? 'posts' : 'comments';
            db()->prepare("UPDATE $table SET hidden = 0, reports = 0 WHERE id = ?")->execute([$id]);
            db()->prepare('DELETE FROM reports WHERE kind = ? AND target_id = ?')->execute([$kind, $id]);
        } elseif ($kind === 'post') {
            $st = db()->prepare('SELECT * FROM posts WHERE id = ?');
            $st->execute([$id]);
            if ($p = $st->fetch()) delete_post($p);
        } else {
            $st = db()->prepare('SELECT post_id FROM comments WHERE id = ?');
            $st->execute([$id]);
            $pid = $st->fetchColumn();
            db()->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
            db()->prepare("DELETE FROM reports WHERE kind = 'comment' AND target_id = ?")->execute([$id]);
            if ($pid) recount_comments((int)$pid);
        }
        json_out(['ok' => true]);

    default:
        fail('Ação desconhecida.', 404);
}

// ---------- helpers ----------
function posts_dir(): string {
    $dir = rtrim(fde_config()['uploads_dir'] ?? (dirname(__DIR__) . '/uploads'), '/') . '/posts';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function author_out(array $r): array {
    return [
        'username' => $r['username'],
        'display_name' => $r['display_name'] !== '' ? $r['display_name'] : $r['username'],
        'avatar_url' => avatar_url($r['avatar']),
        'role' => $r['role'],
    ];
}

function post_out(array $r, int $meId, bool $isAdmin): array {
    $photos = json_decode((string)$r['photos'], true) ?: [];
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'body' => $r['body'],
        'km' => (float)$r['km'],
        'difficulty' => (int)$r['difficulty'],
        'vehicle' => $r['vehicle'],
        'preview' => json_decode((string)$r['preview_json'], true) ?: [],
        'photos' => array_map(fn($f) => '/uploads/posts/' . rawurlencode($f), $photos),
        'likes' => (int)$r['likes'],
        'comments' => (int)$r['comments'],
        'downloads' => (int)$r['downloads'],
        'liked' => (bool)$r['liked'],
        'mine' => (int)$r['user_id'] === $meId,
        'can_delete' => (int)$r['user_id'] === $meId || $isAdmin,
        'hidden' => (bool)$r['hidden'],
        'reports' => $isAdmin ? (int)$r['reports'] : null,
        'created_at' => $r['created_at'],
        'author' => author_out($r),
    ];
}

function load_post(int $id, int $meId, bool $isAdmin): array {
    $st = db()->prepare('SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.bio, u.vehicle AS u_vehicle, u.city, u.created_at AS u_created,
            EXISTS(SELECT 1 FROM post_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS liked
        FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
    $st->execute([$meId, $id]);
    $p = $st->fetch();
    if (!$p || ((int)$p['hidden'] && (int)$p['user_id'] !== $meId && !$isAdmin)) fail('Publicação não encontrada.', 404);
    return $p;
}

function delete_post(array $p): void {
    foreach (json_decode((string)$p['photos'], true) ?: [] as $f) @unlink(posts_dir() . '/' . basename($f));
    db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$p['id']]);
    db()->prepare("DELETE FROM reports WHERE kind = 'post' AND target_id = ?")->execute([$p['id']]);
}

function recount_likes(int $id): int {
    db()->prepare('UPDATE posts SET likes = (SELECT COUNT(*) FROM post_likes WHERE post_id = ?) WHERE id = ?')->execute([$id, $id]);
    $st = db()->prepare('SELECT likes FROM posts WHERE id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

function recount_comments(int $id): void {
    db()->prepare('UPDATE posts SET comments = (SELECT COUNT(*) FROM comments WHERE post_id = ? AND hidden = 0) WHERE id = ?')->execute([$id, $id]);
}

function is_following(int $a, int $b): bool {
    $st = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?');
    $st->execute([$a, $b]);
    return (bool)$st->fetchColumn();
}

function profile_out(array $u, int $meId): array {
    $id = (int)$u['id'];
    $c = fn(string $sql) => (function () use ($sql, $id) { $st = db()->prepare($sql); $st->execute([$id]); return (int)$st->fetchColumn(); })();
    return user_public($u) + [
        'posts' => $c('SELECT COUNT(*) FROM posts WHERE user_id = ? AND hidden = 0'),
        'followers' => $c('SELECT COUNT(*) FROM follows WHERE followee_id = ?'),
        'following' => $c('SELECT COUNT(*) FROM follows WHERE follower_id = ?'),
        'is_following' => is_following($meId, $id),
        'is_me' => $id === $meId,
    ];
}

function reasons(string $kind, int $id): array {
    $st = db()->prepare('SELECT reason FROM reports WHERE kind = ? AND target_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$kind, $id]);
    return array_values(array_filter(array_map(fn($r) => $r['reason'], $st->fetchAll())));
}

function num_ok($v, float $min, float $max): bool {
    return (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $min && $v <= $max;
}

// guarda só o que a trilha precisa (nada de ids de fotos locais, pastas etc.)
function clean_route($r): array {
    if (!is_array($r) || !isset($r['points']) || !is_array($r['points'])) fail('Rota inválida.');
    $pts = [];
    foreach ($r['points'] as $p) {
        if (!is_array($p) || !num_ok($p['lat'] ?? null, -90, 90) || !num_ok($p['lng'] ?? null, -180, 180)) fail('Rota com ponto inválido.');
        $pts[] = ['lat' => round((float)$p['lat'], 6), 'lng' => round((float)$p['lng'], 6)];
    }
    if (count($pts) < 2) fail('A rota precisa de pelo menos 2 pontos.');
    if (count($pts) > 5000) fail('Rota grande demais (máx. 5000 pontos).');
    $wps = [];
    foreach (array_slice(is_array($r['waypoints'] ?? null) ? $r['waypoints'] : [], 0, 200) as $w) {
        if (!is_array($w) || !num_ok($w['lat'] ?? null, -90, 90) || !num_ok($w['lng'] ?? null, -180, 180)) continue;
        $wps[] = ['lat' => round((float)$w['lat'], 6), 'lng' => round((float)$w['lng'], 6),
            'type' => preg_replace('/[^a-z_]/', '', (string)($w['type'] ?? 'outro')) ?: 'outro',
            'note' => mb_substr((string)($w['note'] ?? ''), 0, 300)];
    }
    $m = is_array($r['meta'] ?? null) ? $r['meta'] : [];
    $meta = ['difficulty' => max(0, min(5, (int)($m['difficulty'] ?? 0))), 'vehicle' => mb_substr((string)($m['vehicle'] ?? ''), 0, 40),
        'season' => mb_substr((string)($m['season'] ?? ''), 0, 40), 'notes' => mb_substr((string)($m['notes'] ?? ''), 0, 500)];
    return ['name' => mb_substr((string)($r['name'] ?? ''), 0, 100), 'points' => $pts, 'waypoints' => $wps, 'meta' => $meta];
}

function route_km(array $pts): float {
    $m = 0.0;
    for ($i = 1; $i < count($pts); $i++) {
        $a = $pts[$i - 1];
        $b = $pts[$i];
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;
        $m += 2 * 6371000 * asin(min(1, sqrt($h)));
    }
    return $m / 1000;
}

function preview_points(array $pts): array {
    $n = count($pts);
    $step = max(1, (int)ceil($n / 120));
    $out = [];
    for ($i = 0; $i < $n; $i += $step) $out[] = [round($pts[$i]['lat'], 5), round($pts[$i]['lng'], 5)];
    $last = $pts[$n - 1];
    $out[] = [round($last['lat'], 5), round($last['lng'], 5)];
    return $out;
}

function normalize_files($f): array {
    if (!$f || !isset($f['tmp_name'])) return [];
    if (!is_array($f['tmp_name'])) return [$f];
    $out = [];
    foreach ($f['tmp_name'] as $i => $tmp) {
        $out[] = ['tmp_name' => $tmp, 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

// regrava a foto (máx. 1600 px, JPEG): some com qualquer dado escondido, inclusive a localização GPS (EXIF)
function save_post_photo(array $f, int $uid): ?string {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024) return null;
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']),
    };
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = $uid . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $ok = imagejpeg($dst, posts_dir() . '/' . $name, 78);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? $name : null;
}
