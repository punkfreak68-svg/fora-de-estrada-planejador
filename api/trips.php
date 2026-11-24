<?php
// v10.0 — Passeios (caminho percorrido, tempo, velocidade) sincronizados pela conta,
// para o mapa inicial mostrar no PC o que foi rodado com o celular (e vice-versa).
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_sync.php';

sync_handle('trips', 'trips', fn(array $t) => isset($t['track']) && is_string($t['track']) && $t['track'] !== '' && isset($t['startedAt']) && is_numeric($t['startedAt']), true);
