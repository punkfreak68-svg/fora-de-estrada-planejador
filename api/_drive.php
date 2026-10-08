<?php
// Google Drive com renovação pelo servidor: o Google entrega um "refresh token" (permissão permanente,
// só para os arquivos que o próprio app cria — escopo drive.file). Ele fica CIFRADO no banco e o servidor
// troca por chaves de 1 hora sempre que o app pede. Assim o backup não desconecta a cada hora.
declare(strict_types=1);

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

function drive_cfg(): array {
    $c = fde_config();
    if (!drive_server_ready() || empty($c['google_client_id'])) fail('Renovação do Drive pelo servidor ainda não configurada.', 503);
    return [
        'id' => $c['google_client_id'],
        'secret' => $c['google_client_secret'],
        'auth_url' => $c['google_auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => $c['google_token_url'] ?? 'https://oauth2.googleapis.com/token',
        'revoke_url' => $c['google_revoke_url'] ?? 'https://oauth2.googleapis.com/revoke',
        'redirect' => app_url('/api/drive_callback.php'),
    ];
}

// chave de cifra derivada dos segredos do config (nunca sai do servidor)
function drive_key(): string {
    $c = fde_config();
    return hash('sha256', 'fde-drive-v1|' . ($c['db_pass'] ?? '') . '|' . ($c['google_client_secret'] ?? ''), true);
}

function drive_encrypt(string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', drive_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) fail('Falha ao proteger a autorização do Drive.', 500);
    return base64_encode($iv . $tag . $ct);
}

function drive_decrypt(string $enc): ?string {
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', drive_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

function drive_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return [$code, is_array($data) ? $data : []];
}

function drive_save_refresh(int $uid, string $refresh): void {
    db()->prepare('REPLACE INTO drive_links (user_id, refresh_enc) VALUES (?, ?)')->execute([$uid, drive_encrypt($refresh)]);
}

function drive_get_refresh(int $uid): ?string {
    $st = db()->prepare('SELECT refresh_enc FROM drive_links WHERE user_id = ?');
    $st->execute([$uid]);
    $enc = $st->fetchColumn();
    return $enc ? drive_decrypt((string)$enc) : null;
}

function drive_forget(int $uid): void {
    db()->prepare('DELETE FROM drive_links WHERE user_id = ?')->execute([$uid]);
}
