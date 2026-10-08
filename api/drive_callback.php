<?php
// Volta da tela de permissão do Google: troca o código pela autorização permanente e guarda cifrada.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_drive.php';

function back(string $code): never {
    header('Location: ' . app_url('/#drive=' . $code), true, 303);
    exit;
}

$u = current_user();
$state = (string)($_GET['state'] ?? '');
$uidFromState = use_token($state, 'drive_state');
if (!$u || !$uidFromState || $uidFromState !== (int)$u['id']) back('erro'); // proteção CSRF: o pedido tem que ter saído desta conta
if (!empty($_GET['error'])) back('cancelado');
$authCode = (string)($_GET['code'] ?? '');
if ($authCode === '' || strlen($authCode) > 512) back('erro');

$cfg = drive_cfg();
[$status, $data] = drive_post($cfg['token_url'], [
    'client_id' => $cfg['id'],
    'client_secret' => $cfg['secret'],
    'code' => $authCode,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $cfg['redirect'],
]);
if ($status !== 200 || empty($data['refresh_token'])) back('erro');
$scopes = explode(' ', (string)($data['scope'] ?? ''));
if (!in_array(DRIVE_SCOPE, $scopes, true)) back('sem_permissao'); // a pessoa desmarcou o acesso ao Drive na tela do Google
drive_save_refresh((int)$u['id'], (string)$data['refresh_token']);
back('ok');
