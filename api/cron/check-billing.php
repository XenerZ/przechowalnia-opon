<?php
/**
 * Cron script — automatyczna blokada kont zalegających z płatnością.
 * Uruchamiaj codziennie z panelu OVH:
 *   Komenda: php /home/[login]/www/api/cron/check-billing.php
 *   Częstotliwość: raz dziennie
 *
 * Blokuje (status='suspended', suspend_reason='billing') aktywne firmy, których
 * okres rozliczeniowy (next_billing_at) minął o ponad BILLING_GRACE_DAYS dni
 * i które nie mają opłaconej faktury za bieżący okres.
 */

define('CRON_MODE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/billing.php';

$pdo = get_pdo();

// Kandydaci: aktywne firmy z nieopłaconą fakturą, której termin płatności minął
// o więcej niż BILLING_GRACE_DAYS dni.
$stmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM companies c
    WHERE c.status = 'active'
      AND EXISTS (
          SELECT 1 FROM invoices i
          WHERE i.company_id = c.id AND i.status = 'unpaid'
            AND i.due_date IS NOT NULL
            AND i.due_date < (CURDATE() - INTERVAL :grace DAY)
      )
");
$stmt->bindValue(':grace', BILLING_GRACE_DAYS, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$blocked = 0;
$upd = $pdo->prepare("UPDATE companies SET status='suspended', suspend_reason='billing' WHERE id = ? AND status='active'");
foreach ($rows as $c) {
    $upd->execute([$c['id']]);
    $blocked++;
    echo '[' . date('Y-m-d H:i:s') . '] Zablokowano firmę "' . $c['name'] . '" (zaległa płatność).' . "\n";
}
echo '[' . date('Y-m-d H:i:s') . '] Zablokowano kont: ' . $blocked . "\n";
