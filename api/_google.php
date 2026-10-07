<?php
// Login com Google (botão no app e modo "redirect" do app instalado).
declare(strict_types=1);

function google_login(string $credential, bool $acceptTerms): array {
    $ip = client_ip();
    rate_limit('google_ip', $ip, 30, 15, 'Muitas tentativas. Espere alguns minutos.');
    rate_hit('google_ip', $ip);
    $info = verify_google_id_token($credential);
    if (!$info) return ['ok' => false, 'error' => 'Não foi possível confirmar o login do Google.', 'status' => 401];
    $sub = $info['sub'];
    $email = strtolower($info['email']);
    $u = find_user_by('google_sub', $sub);
    if (!$u) {
        $byEmail = find_user_by('email', $email);
        if ($byEmail) {
            // O Google provou que a pessoa é dona do e-mail.
            if (!(int)$byEmail['email_verified']) {
                // conta criada com esse e-mail sem confirmação: o dono real assume e a senha antiga é anulada
                db()->prepare('UPDATE users SET password_hash = NULL WHERE id = ?')->execute([$byEmail['id']]);
                db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$byEmail['id']]);
            }
            db()->prepare('UPDATE users SET google_sub = ?, email_verified = 1 WHERE id = ?')->execute([$sub, $byEmail['id']]);
            $u = find_user_by('id', (string)$byEmail['id']);
        } else {
            if (!signup_allowed($email)) return ['ok' => false, 'error' => FDE_CLOSED_MSG, 'status' => 403];
            if (!$acceptTerms) return ['ok' => false, 'error' => 'terms_required', 'need_terms' => true, 'email' => $email];
            $username = suggest_username($email);
            db()->prepare('INSERT INTO users (username, email, email_verified, google_sub, display_name, needs_username, terms_accepted_at) VALUES (?, ?, 1, ?, ?, 1, NOW())')
                ->execute([$username, $email, $sub, mb_substr((string)($info['name'] ?? ''), 0, 60)]);
            $u = find_user_by('id', (string)db()->lastInsertId());
        }
    }
    if (is_admin_email($email) && $u['role'] !== 'admin') {
        db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$u['id']]);
    }
    start_session((int)$u['id']);
    return ['ok' => true, 'user' => user_private(find_user_by('id', (string)$u['id']))];
}

function suggest_username(string $email): string {
    $base = strtolower(preg_replace('/[^a-z0-9_.]/i', '', explode('@', $email)[0]));
    $base = substr($base, 0, 14);
    if (strlen($base) < 3) $base = 'jipeiro';
    if (in_array($base, RESERVED_USERNAMES, true)) $base .= '_';
    $candidate = $base;
    for ($i = 0; $i < 50 && find_user_by('username', $candidate); $i++) {
        $candidate = $base . random_int(10, 9999);
    }
    return $candidate;
}

function verify_google_id_token(string $jwt): ?array {
    if ($jwt === '' || substr_count($jwt, '.') !== 2) return null;
    $cfg = fde_config();
    $url = ($cfg['google_tokeninfo_url'] ?? 'https://oauth2.googleapis.com/tokeninfo') . '?id_token=' . urlencode($jwt);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $d = json_decode($body, true);
    if (!is_array($d)) return null;
    if (($d['aud'] ?? '') !== ($cfg['google_client_id'] ?? '')) return null;
    if (!in_array($d['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
    if ((int)($d['exp'] ?? 0) < time()) return null;
    if (($d['email_verified'] ?? '') !== 'true' && ($d['email_verified'] ?? false) !== true) return null;
    if (empty($d['sub']) || empty($d['email'])) return null;
    return $d;
}

