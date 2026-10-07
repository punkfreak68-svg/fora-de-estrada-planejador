<?php
// Perfil: ver perfil público, editar, foto, senha, excluir conta (LGPD).
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        $username = strtolower((string)($_GET['u'] ?? ''));
        $u = $username !== '' ? find_user_by('username', $username) : null;
        if (!$u) fail('Perfil não encontrado.', 404);
        json_out(['ok' => true, 'profile' => user_public($u)]);

    case 'update':
        require_post();
        $u = require_user();
        $in = input();
        $fields = [];
        $params = [];
        foreach (['display_name' => 60, 'bio' => 300, 'vehicle' => 80, 'city' => 80] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $fields[] = "$k = ?";
                $params[] = str_in($k, $max);
            }
        }
        if (array_key_exists('username', $in)) {
            $new = strtolower(str_in('username', 40));
            if ($new !== $u['username']) {
                if ($e = validate_username($new)) fail($e);
                $other = find_user_by('username', $new);
                if ($other && (int)$other['id'] !== (int)$u['id']) fail('Esse nome de usuário já está em uso.');
                $fields[] = 'username = ?';
                $params[] = $new;
            }
            $fields[] = 'needs_username = 0';
        }
        if ($fields) {
            $params[] = $u['id'];
            db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$u['id']))]);

    case 'password':
        require_post();
        $u = require_user();
        $new = (string)(input()['new_password'] ?? '');
        if ($u['password_hash'] !== null) {
            rate_limit('pwchange', (string)$u['id'], 6, 15, 'Muitas tentativas. Espere 15 minutos.');
            if (!password_verify((string)(input()['current_password'] ?? ''), $u['password_hash'])) {
                rate_hit('pwchange', (string)$u['id']);
                fail('Senha atual incorreta.');
            }
        }
        if ($e = validate_password($new)) fail($e);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        // encerra as outras sessões, mantém esta
        $t = $_COOKIE[FDE_SESSION_COOKIE] ?? '';
        db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?')->execute([$u['id'], hash('sha256', (string)$t)]);
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$u['id']))]);

    case 'avatar':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_FDE'] ?? '') !== '1') fail('Requisição inválida.', 403);
        $u = require_user();
        rate_limit('avatar', (string)$u['id'], 20, 60, 'Muitas trocas de foto. Tente mais tarde.');
        rate_hit('avatar', (string)$u['id']);
        $f = $_FILES['photo'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail('Envie uma imagem.');
        if ($f['size'] > 3 * 1024 * 1024) fail('Imagem grande demais (máx. 3 MB).');
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Formato não aceito. Use JPG, PNG ou WebP.');
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']),
        };
        if (!$src) fail('Não foi possível ler a imagem.');
        // recorta quadrado no centro e regrava em 256x256 (remove qualquer conteúdo escondido)
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(256, 256);
        imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 256, 256, $side, $side);
        $dir = avatars_dir();
        $name = $u['id'] . '_' . bin2hex(random_bytes(6)) . '.jpg';
        if (!imagejpeg($dst, $dir . '/' . $name, 82)) fail('Não foi possível salvar a imagem.', 500);
        imagedestroy($src);
        imagedestroy($dst);
        if ($u['avatar']) @unlink($dir . '/' . basename($u['avatar']));
        db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$name, $u['id']]);
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$u['id']))]);

    case 'delete':
        require_post();
        $u = require_user();
        if ($u['password_hash'] !== null) {
            rate_limit('delete', (string)$u['id'], 5, 15, 'Muitas tentativas. Espere 15 minutos.');
            if (!password_verify((string)(input()['password'] ?? ''), $u['password_hash'])) {
                rate_hit('delete', (string)$u['id']);
                fail('Senha incorreta.');
            }
        } elseif (strtoupper(str_in('confirm', 20)) !== 'EXCLUIR') {
            fail('Digite EXCLUIR para confirmar.');
        }
        if ($u['avatar']) @unlink(avatars_dir() . '/' . basename($u['avatar']));
        // ON DELETE CASCADE apaga sessões e tokens (e, nas próximas etapas, posts/curtidas/etc.)
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
        set_session_cookie('', time() - 3600);
        json_out(['ok' => true]);

    case 'export':
        // LGPD: a pessoa pode baixar os dados dela
        $u = require_user();
        header('Content-Disposition: attachment; filename="meus-dados-fora-de-estrada.json"');
        json_out(['ok' => true, 'exported_at' => date('c'), 'account' => user_private($u) + ['terms_accepted_at' => $u['terms_accepted_at'], 'last_login_at' => $u['last_login_at']]]);

    default:
        fail('Ação desconhecida.', 404);
}

function avatars_dir(): string {
    $dir = fde_config()['uploads_dir'] ?? (dirname(__DIR__) . '/uploads');
    $dir = rtrim($dir, '/') . '/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
