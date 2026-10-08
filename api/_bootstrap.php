<?php
// Fora de Estrada — base da API (PHP 8.1+). Nada aqui é acessível direto:
// o .htaccess da pasta bloqueia arquivos que começam com "_".
declare(strict_types=1);

const FDE_SCHEMA_VERSION = 4;
const FDE_SESSION_COOKIE = 'fde_sess';
const FDE_SESSION_DAYS = 60;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e) {
    error_log('[fde] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno. Tente de novo em instantes.']);
    exit;
});

function fde_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    // config fica FORA da pasta pública: /home/<conta>/fde-config/config.php
    $path = getenv('FDE_CONFIG') ?: dirname(__DIR__, 2) . '/fde-config/config.php';
    if (!is_file($path)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Servidor ainda não configurado.']);
        exit;
    }
    $cfg = require $path;
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = fde_config();
    $pdo = new PDO(
        'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
        $c['db_user'],
        $c['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    fde_migrate($pdo);
    return $pdo;
}

function fde_migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS fde_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(200) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $v = (int)($pdo->query("SELECT v FROM fde_meta WHERE k='schema'")->fetchColumn() ?: 0);
    if ($v >= FDE_SCHEMA_VERSION) return;
    if ($v < 1) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(20) NOT NULL UNIQUE,
            email VARCHAR(190) NOT NULL UNIQUE,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            password_hash VARCHAR(255) NULL,
            google_sub VARCHAR(64) NULL UNIQUE,
            display_name VARCHAR(60) NOT NULL DEFAULT '',
            bio VARCHAR(300) NOT NULL DEFAULT '',
            vehicle VARCHAR(80) NOT NULL DEFAULT '',
            city VARCHAR(80) NOT NULL DEFAULT '',
            avatar VARCHAR(120) NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'user',
            needs_username TINYINT(1) NOT NULL DEFAULT 0,
            terms_accepted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(200) NOT NULL DEFAULT '',
            INDEX (user_id),
            CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (kind, key_hash, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            purpose VARCHAR(20) NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX (user_id),
            CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($v < 2) {
        // rotas salvas sincronizadas pela conta (cada rota é o mesmo JSON que o app guarda no aparelho)
        $pdo->exec("CREATE TABLE IF NOT EXISTS routes (
            user_id INT UNSIGNED NOT NULL,
            route_id VARCHAR(64) NOT NULL,
            rev BIGINT NOT NULL DEFAULT 0,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            data MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, route_id),
            CONSTRAINT fk_route_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($v < 3) {
        // expedições (mesmo formato de sincronização das rotas; route_id = id da expedição)
        $pdo->exec("CREATE TABLE IF NOT EXISTS expeditions (
            user_id INT UNSIGNED NOT NULL,
            route_id VARCHAR(64) NOT NULL,
            rev BIGINT NOT NULL DEFAULT 0,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            data MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, route_id),
            CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($v < 4) {
        // autorização permanente do Google Drive (refresh token cifrado), para o backup não desconectar
        $pdo->exec("CREATE TABLE IF NOT EXISTS drive_links (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            refresh_enc TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_drive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $pdo->prepare("REPLACE INTO fde_meta (k, v) VALUES ('schema', ?)")->execute([(string)FDE_SCHEMA_VERSION]);
}

// ---- entrada/saída ----
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): never {
    json_out(['ok' => false, 'error' => $msg] + $extra, $code);
}

function input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input') ?: '';
    $in = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($in)) $in = [];
    return $in;
}

function str_in(string $k, int $max = 500): string {
    $v = input()[$k] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

// Toda escrita exige POST + cabeçalho próprio do app (bloqueia formulários de outros sites)
function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Método não permitido.', 405);
    if (($_SERVER['HTTP_X_FDE'] ?? '') !== '1') fail('Requisição inválida.', 403);
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---- limite de tentativas ----
function rate_hit(string $kind, string $key): void {
    db()->prepare('INSERT INTO attempts (kind, key_hash) VALUES (?, ?)')->execute([$kind, hash('sha256', $key)]);
    if (random_int(1, 100) === 1) {
        // faxina de vez em quando: tentativas velhas, sessões e links vencidos
        db()->exec('DELETE FROM attempts WHERE at < (NOW() - INTERVAL 2 DAY)');
        db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
        db()->exec('DELETE FROM tokens WHERE expires_at < NOW()');
    }
}

function rate_count(string $kind, string $key, int $minutes): int {
    $st = db()->prepare('SELECT COUNT(*) FROM attempts WHERE kind = ? AND key_hash = ? AND at > (NOW() - INTERVAL ? MINUTE)');
    $st->execute([$kind, hash('sha256', $key), $minutes]);
    return (int)$st->fetchColumn();
}

function rate_limit(string $kind, string $key, int $max, int $minutes, string $msg): void {
    if (rate_count($kind, $key, $minutes) >= $max) fail($msg, 429);
}

function rate_clear(string $kind, string $key): void {
    db()->prepare('DELETE FROM attempts WHERE kind = ? AND key_hash = ?')->execute([$kind, hash('sha256', $key)]);
}

// ---- sessão ----
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function set_session_cookie(string $value, int $expires): void {
    setcookie(FDE_SESSION_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function start_session(int $userId): void {
    $token = bin2hex(random_bytes(32));
    $expires = time() + FDE_SESSION_DAYS * 86400;
    db()->prepare('INSERT INTO sessions (token_hash, user_id, expires_at, user_agent) VALUES (?, ?, FROM_UNIXTIME(?), ?)')
        ->execute([hash('sha256', $token), $userId, $expires, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    set_session_cookie($token, $expires);
    // limpeza ocasional
    if (random_int(1, 20) === 1) {
        db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
        db()->exec('DELETE FROM attempts WHERE at < (NOW() - INTERVAL 2 DAY)');
        db()->exec('DELETE FROM tokens WHERE expires_at < NOW()');
    }
}

function end_session(): void {
    $t = $_COOKIE[FDE_SESSION_COOKIE] ?? '';
    if (is_string($t) && $t !== '') {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    set_session_cookie('', time() - 3600);
}

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $t = $_COOKIE[FDE_SESSION_COOKIE] ?? '';
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    $st = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > NOW()');
    $st->execute([hash('sha256', $t)]);
    $row = $st->fetch();
    if ($row) $u = $row;
    return $u;
}

function require_user(): array {
    $u = current_user();
    if (!$u) fail('Faça login para continuar.', 401);
    return $u;
}

// ---- formato público do usuário ----
function avatar_url(?string $file): ?string {
    return $file ? '/uploads/avatars/' . rawurlencode($file) : null;
}

function user_public(array $u): array {
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'display_name' => $u['display_name'] !== '' ? $u['display_name'] : $u['username'],
        'bio' => $u['bio'],
        'vehicle' => $u['vehicle'],
        'city' => $u['city'],
        'avatar_url' => avatar_url($u['avatar']),
        'role' => $u['role'],
        'created_at' => $u['created_at'],
    ];
}

function user_private(array $u): array {
    return user_public($u) + [
        'email' => $u['email'],
        'email_verified' => (bool)$u['email_verified'],
        'has_password' => $u['password_hash'] !== null,
        'has_google' => $u['google_sub'] !== null,
        'needs_username' => (bool)$u['needs_username'],
        'drive_linked' => drive_linked((int)$u['id']),
    ];
}

// segredo do cliente Google preenchido de verdade (não o texto "COLE_..." do modelo)
function drive_server_ready(): bool {
    $s = (string)(fde_config()['google_client_secret'] ?? '');
    return $s !== '' && !str_starts_with($s, 'COLE_');
}

function drive_linked(int $uid): bool {
    if (!drive_server_ready()) return false;
    $st = db()->prepare('SELECT 1 FROM drive_links WHERE user_id = ?');
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

function find_user_by(string $col, string $val): ?array {
    if (!in_array($col, ['id', 'username', 'email', 'google_sub'], true)) throw new RuntimeException('coluna inválida');
    $st = db()->prepare("SELECT * FROM users WHERE $col = ? LIMIT 1");
    $st->execute([$val]);
    $r = $st->fetch();
    return $r ?: null;
}

// ---- validações ----
const RESERVED_USERNAMES = ['admin', 'administrador', 'root', 'suporte', 'support', 'foradeestrada', 'fora_de_estrada', 'moderador', 'sistema', 'api', 'null', 'undefined'];

function validate_username(string $u): ?string {
    if (!preg_match('/^[a-z0-9_.]{3,20}$/', $u)) return 'Nome de usuário: 3 a 20 caracteres, só letras minúsculas, números, "_" e ".".';
    if (in_array($u, RESERVED_USERNAMES, true)) return 'Esse nome de usuário é reservado.';
    return null;
}

function validate_password(string $p): ?string {
    if (mb_strlen($p) < 8) return 'A senha precisa ter pelo menos 8 caracteres.';
    if (mb_strlen($p) > 200) return 'Senha longa demais.';
    if (preg_match('/^(.)\1+$/', $p) || in_array(strtolower($p), ['12345678', '123456789', 'password', 'senha123', 'qwerty123', '11111111'], true)) return 'Essa senha é fácil demais de adivinhar.';
    return null;
}

function is_admin_email(string $email): bool {
    $list = array_map('strtolower', fde_config()['admin_emails'] ?? []);
    return in_array(strtolower($email), $list, true);
}

// Teste fechado: enquanto 'closed_beta' estiver ligado, só e-mails da lista criam conta.
function signup_allowed(string $email): bool {
    $c = fde_config();
    if (empty($c['closed_beta'])) return true;
    $list = array_map('strtolower', array_merge($c['admin_emails'] ?? [], $c['beta_emails'] ?? []));
    return in_array(strtolower($email), $list, true);
}
function signup_open(): bool {
    return empty(fde_config()['closed_beta']);
}
const FDE_CLOSED_MSG = 'A comunidade está em teste fechado. Em breve abre para todos!';

// ---- e-mail ----
function send_mail(string $to, string $subject, string $text): bool {
    $c = fde_config();
    $from = $c['mail_from'] ?? 'no-reply@localhost';
    $headers = 'From: "Fora de Estrada" <' . $from . ">\r\n" .
        'Reply-To: ' . $from . "\r\n" .
        "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subj, $text, $headers, '-f' . $from);
}

function make_token(int $userId, string $purpose, int $minutes): string {
    $t = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM tokens WHERE user_id = ? AND purpose = ?')->execute([$userId, $purpose]);
    db()->prepare('INSERT INTO tokens (token_hash, user_id, purpose, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)')
        ->execute([hash('sha256', $t), $userId, $purpose, $minutes]);
    return $t;
}

function use_token(string $t, string $purpose): ?int {
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
    $st = db()->prepare('SELECT user_id FROM tokens WHERE token_hash = ? AND purpose = ? AND expires_at > NOW()');
    $st->execute([hash('sha256', $t), $purpose]);
    $uid = $st->fetchColumn();
    if (!$uid) return null;
    db()->prepare('DELETE FROM tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    return (int)$uid;
}

function app_url(string $path = ''): string {
    return rtrim(fde_config()['app_url'] ?? '', '/') . $path;
}
