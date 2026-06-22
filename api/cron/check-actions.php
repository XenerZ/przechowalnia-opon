<?php
/**
 * Cron script — uruchamiaj codziennie z panelu OVH:
 *   Komenda: php /home/[login]/www/api/cron/check-actions.php
 *   Częstotliwość: raz dziennie (np. 08:00)
 *
 * W panelu OVH: Hosting → Więcej → Zadania zaplanowane (Cron)
 */

define('CRON_MODE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../routes/actions.php';

$pdo = get_pdo();
$actions = $pdo->query("SELECT * FROM actions WHERE active = 1")->fetchAll();

$totalSent = 0; $totalFailed = 0; $totalSkipped = 0;

foreach ($actions as $action) {
    $result = run_action($pdo, $action);
    $pdo->prepare('UPDATE actions SET last_run = NOW() WHERE id = ?')->execute([$action['id']]);
    $totalSent    += $result['sent'];
    $totalFailed  += $result['failed'];
    $totalSkipped += $result['skipped'];

    if (!defined('CRON_MODE') || CRON_MODE) {
        echo '[' . date('Y-m-d H:i:s') . '] Akcja "' . $action['name'] . '": '
           . 'wysłano=' . $result['sent'] . ', błędy=' . $result['failed'] . ', pominięto=' . $result['skipped'] . "\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . "] RAZEM: wysłano=$totalSent, błędy=$totalFailed, pominięto=$totalSkipped\n";
