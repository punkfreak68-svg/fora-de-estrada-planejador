<?php
// Contas: cadastro, login (usuário/e-mail + senha ou Google), sair, "quem sou eu",
// confirmação de e-mail e recuperação de senha.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_google.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'me':
        $u = current_user();
        json_out(['ok' => true, 'user' => $u ? user_private($u) : null, 'signup_open' => signup_open()]);

    case 'register':
        require_post();
        $ip = client_ip();
        rate_limit('register', $ip, 5, 60, 'Muitos cadastros deste endereço. Tente de novo mais tarde.');
        $username = strtolower(str_in('username', 40));
        $email = strtolower(str_in('email', 190));
        $password = (string)(input()['password'] ?? '');
        $name = str_in('display_name', 60);
        if (empty(input()['accept_terms'])) fail('É preciso aceitar os Termos de Uso e a Política de Privacidade.');
        if ($e = validate_username($username)) fail($e);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('E-mail inválido.');
        if ($e = validate_password($password)) fail($e);
        if (!signup_allowed($email)) fail(FDE_CLOSED_MSG, 403);
        rate_hit('register', $ip);
        if (find_user_by('username', $username)) fail('Esse nome de usuário já está em uso.');
        if (find_user_by('email', $email)) fail('Já existe uma conta com esse e-mail. Use "Entrar" ou "Esqueci a senha".');
        $role = 'user'; // admin só depois de confirmar o e-mail (ou entrar com Google)
        db()->prepare('INSERT INTO users (username, email, password_hash, display_name, role, terms_accepted_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $name, $role]);
        $id = (int)db()->lastInsertId();
        send_verification($id, $email);
        start_session($id);
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$id))]);

    case 'login':
        require_post();
        $ip = client_ip();
        $login = strtolower(str_in('login', 190));
        $password = (string)(input()['password'] ?? '');
        rate_limit('login_ip', $ip, 20, 15, 'Muitas tentativas. Espere 15 minutos.');
        rate_limit('login_user', $login, 8, 15, 'Muitas tentativas para essa conta. Espere 15 minutos.');
        $u = str_contains($login, '@') ? find_user_by('email', $login) : find_user_by('username', $login);
        // mesmo custo de tempo com ou sem usuário (não revela quem existe)
        $hash = $u['password_hash'] ?? '$2y$10$9E0VI6UL9cPpQ3NJ7F5LXuK7SJtOC2P4MBSW3XL2l/Lda1Os/nk9i';
        $okPass = password_verify($password, $hash);
        if (!$u || !$okPass || $u['password_hash'] === null) {
            rate_hit('login_ip', $ip);
            rate_hit('login_user', $login);
            if ($u && $u['password_hash'] === null) fail('Essa conta entra com o Google. Use o botão "Entrar com Google" (e, se quiser, crie uma senha no seu perfil).');
            fail('Usuário/e-mail ou senha incorretos.');
        }
        rate_clear('login_user', $login);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        start_session((int)$u['id']);
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$u['id']))]);

    case 'google':
        require_post();
        $r = google_login(str_in('credential', 4096), !empty(input()['accept_terms']));
        json_out($r, $r['ok'] ? 200 : ($r['status'] ?? 400));

    case 'logout':
        require_post();
        end_session();
        json_out(['ok' => true]);

    case 'resend_verification':
        require_post();
        $u = require_user();
        if ((int)$u['email_verified']) json_out(['ok' => true, 'already' => true]);
        rate_limit('verify', (string)$u['id'], 3, 60, 'Já enviamos alguns e-mails. Confira a caixa de entrada e o spam.');
        rate_hit('verify', (string)$u['id']);
        send_verification((int)$u['id'], $u['email']);
        json_out(['ok' => true]);

    case 'verify':
        // vem do link do e-mail (GET) — responde com uma página simples
        header('Content-Type: text/html; charset=utf-8');
        $uid = use_token((string)($_GET['t'] ?? ''), 'verify');
        if ($uid) {
            db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$uid]);
            $u = find_user_by('id', (string)$uid);
            if ($u && is_admin_email($u['email'])) db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$uid]);
        }
        echo simple_page($uid ? 'E-mail confirmado ✅' : 'Link inválido ou vencido', $uid ? 'Pode voltar para o app.' : 'Peça um novo link no seu perfil.');
        exit;

    case 'forgot':
        require_post();
        $ip = client_ip();
        $email = strtolower(str_in('email', 190));
        rate_limit('forgot_ip', $ip, 5, 60, 'Muitos pedidos. Tente de novo mais tarde.');
        rate_hit('forgot_ip', $ip);
        $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by('email', $email) : null;
        if ($u && rate_count('forgot_user', (string)$u['id'], 60) < 3) {
            rate_hit('forgot_user', (string)$u['id']);
            $t = make_token((int)$u['id'], 'reset', 60);
            send_mail($u['email'], 'Fora de Estrada — redefinir senha',
                "Olá, {$u['username']}!\n\nPara criar uma nova senha, abra o link abaixo (vale por 1 hora):\n\n" .
                app_url('/#senha=' . $t) . "\n\nSe não foi você que pediu, ignore este e-mail — sua senha continua a mesma.\n\nFora de Estrada");
        }
        // mesma resposta sempre (não revela se o e-mail existe)
        json_out(['ok' => true]);

    case 'reset':
        require_post();
        $ip = client_ip();
        rate_limit('reset_ip', $ip, 10, 60, 'Muitas tentativas. Tente mais tarde.');
        rate_hit('reset_ip', $ip);
        $password = (string)(input()['password'] ?? '');
        if ($e = validate_password($password)) fail($e);
        $uid = use_token(str_in('token', 100), 'reset');
        if (!$uid) fail('Link inválido ou vencido. Peça outro em "Esqueci a senha".');
        db()->prepare('UPDATE users SET password_hash = ?, email_verified = 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]); // derruba outros aparelhos
        start_session($uid);
        json_out(['ok' => true, 'user' => user_private(find_user_by('id', (string)$uid))]);

    default:
        fail('Ação desconhecida.', 404);
}

// ---------- helpers ----------
function send_verification(int $id, string $email): void {
    $t = make_token($id, 'verify', 60 * 48);
    send_mail($email, 'Fora de Estrada — confirme seu e-mail',
        "Bem-vindo(a) ao Fora de Estrada!\n\nConfirme seu e-mail abrindo o link abaixo (vale por 48 horas):\n\n" .
        app_url('/api/auth.php?action=verify&t=' . $t) . "\n\nSe você não criou esta conta, ignore este e-mail.\n\nFora de Estrada");
}

function simple_page(string $title, string $msg): string {
    $t = htmlspecialchars($title);
    $m = htmlspecialchars($msg);
    $home = htmlspecialchars(app_url('/'));
    return "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>$t</title>" .
        "<style>body{font-family:system-ui,Arial;background:#1b1f16;color:#eee;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}div{background:#22261b;border:1px solid #4a5a34;border-radius:12px;padding:28px;max-width:360px;text-align:center}a{color:#c9d9a8}</style></head>" .
        "<body><div><h1>$t</h1><p>$m</p><p><a href=\"$home\">Abrir o Fora de Estrada</a></p></div></body></html>";
}
