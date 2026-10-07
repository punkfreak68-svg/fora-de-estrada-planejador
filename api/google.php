<?php
// Retorno do "Entrar com Google" no modo redirect (app instalado no celular).
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_google.php';

$cookie = (string)($_COOKIE['g_csrf_token'] ?? '');
$posted = (string)($_POST['g_csrf_token'] ?? '');
if ($cookie === '' || !hash_equals($cookie, $posted)) {
    header('Location: ' . app_url('/#comunidade=erro'), true, 303);
    exit;
}
$r = google_login((string)($_POST['credential'] ?? ''), true); // o botão mostra "ao continuar você aceita os Termos"
$code = $r['ok'] ? 'ok' : ((($r['status'] ?? 0) === 403) ? 'fechado' : 'erro');
header('Location: ' . app_url('/#comunidade=' . $code), true, 303);
exit;
