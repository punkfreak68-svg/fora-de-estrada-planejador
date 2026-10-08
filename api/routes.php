<?php
// Rotas salvas sincronizadas pela conta (substitui a necessidade do Google Drive para as rotas).
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_sync.php';

sync_handle('routes', 'routes', fn(array $r) => isset($r['points']) && is_array($r['points']));
