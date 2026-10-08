<?php
// Expedições (planejamento: dias, equipe, veículos, checklist, segurança, gastos) sincronizadas pela conta.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_sync.php';

sync_handle('expeditions', 'expeditions', fn(array $e) => isset($e['name']) && is_string($e['name']) && isset($e['days']) && is_array($e['days']));
