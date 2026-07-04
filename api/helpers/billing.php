<?php
// Wspólna logika zaległości rozliczeniowych (login, budowa JWT, cron).
// Zasada: okres kończy się w next_billing_at. Jeśli minął i nie ma opłaconej
// faktury za bieżący okres → zaległość; po BILLING_GRACE_DAYS dniach → blokada.

if (!defined('BILLING_GRACE_DAYS')) define('BILLING_GRACE_DAYS', 10);

function billing_state(PDO $pdo, string $company_id): array {
    $out = ['overdue' => false, 'daysOverdue' => 0, 'blocked' => false,
            'graceDays' => BILLING_GRACE_DAYS, 'nextBillingAt' => null];

    $c = $pdo->prepare('SELECT billing_date, next_billing_at FROM companies WHERE id = ?');
    $c->execute([$company_id]);
    $row = $c->fetch();
    if (!$row || empty($row['next_billing_at'])) return $out;

    $out['nextBillingAt'] = substr($row['next_billing_at'], 0, 10);
    $today = new DateTime('today');
    $end   = new DateTime($row['next_billing_at']);
    if ($today <= $end) return $out; // okres jeszcze trwa

    // opłacona faktura za bieżący okres?
    $paid = $pdo->prepare("SELECT 1 FROM invoices WHERE company_id = ? AND status = 'paid'
                           AND (period_end IS NULL OR period_end >= ?) LIMIT 1");
    $paid->execute([$company_id, $row['billing_date'] ?: '1970-01-01']);
    if ($paid->fetch()) return $out;

    $out['daysOverdue'] = (int)$end->diff($today)->days;
    $out['overdue']     = true;
    $out['blocked']     = $out['daysOverdue'] > BILLING_GRACE_DAYS;
    return $out;
}
