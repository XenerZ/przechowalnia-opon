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

// Kandydaci: aktywne firmy po terminie, bez opłaconej faktury za bieżący okres
$stmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM companies c
    WHERE c.status = 'active'
      AND c.next_billing_at IS NOT NULL
      AND c.next_billing_at < (CURDATE() - INTERVAL :grace DAY)
      AND NOT EXISTS (
          SELECT 1 FROM invoices i
          WHERE i.company_id = c.id AND i.status = 'paid'
            AND (i.period_end IS NULL OR i.period_end >= c.billing_date)
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
