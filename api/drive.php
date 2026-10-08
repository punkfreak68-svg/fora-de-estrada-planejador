<?php
// Drive pela conta: iniciar a ligação, pegar chave de acesso nova, status e desligar.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_drive.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'start':
        // navegação normal (GET) a partir do app: manda para a tela de permissão do Google
        $u = current_user();
        if (!$u) {
            header('Location: ' . app_url('/#drive=login'), true, 303);
            exit;
        }
        $cfg = drive_cfg();
        $state = make_token((int)$u['id'], 'drive_state', 15);
        $q = http_build_query([
            'client_id' => $cfg['id'],
            'redirect_uri' => $cfg['redirect'],
            'response_type' => 'code',
            'scope' => DRIVE_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => $u['email'],
            'state' => $state,
        ]);
        header('Location: ' . $cfg['auth_url'] . '?' . $q, true, 303);
        exit;

    case 'token':
        require_post();
        $u = require_user();
        $uid = (int)$u['id'];
        rate_limit('drive_token', (string)$uid, 120, 60, 'Muitos pedidos ao Drive. Aguarde um pouco.');
        rate_hit('drive_token', (string)$uid);
        $cfg = drive_cfg();
        $refresh = drive_get_refresh($uid);
        if (!$refresh) json_out(['ok' => true, 'linked' => false]);
        [$code, $data] = drive_post($cfg['token_url'], [
            'client_id' => $cfg['id'],
            'client_secret' => $cfg['secret'],
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if ($code === 200 && !empty($data['access_token'])) {
            json_out(['ok' => true, 'linked' => true, 'access_token' => $data['access_token'], 'expires_in' => (int)($data['expires_in'] ?? 3600)]);
        }
        if (($data['error'] ?? '') === 'invalid_grant') {
            // a pessoa tirou a permissão no Google (ou ela venceu): desliga para pedir de novo
            drive_forget($uid);
            json_out(['ok' => true, 'linked' => false, 'revoked' => true]);
        }
        fail('O Google não respondeu agora. Tente de novo em instantes.', 502);

    case 'unlink':
        require_post();
        $u = require_user();
        $uid = (int)$u['id'];
        $cfg = drive_cfg();
        $refresh = drive_get_refresh($uid);
        if ($refresh) drive_post($cfg['revoke_url'], ['token' => $refresh]);
        drive_forget($uid);
        json_out(['ok' => true]);

    default:
        fail('Ação desconhecida.', 404);
}
