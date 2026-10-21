<?php
// Garagem (veículos: abastecimento, plano preventivo, serviços com peças, medições) sincronizada pela conta.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_sync.php';

sync_handle('garage', 'vehicles', fn(array $v) => isset($v['name']) && is_string($v['name']) && $v['name'] !== '');
