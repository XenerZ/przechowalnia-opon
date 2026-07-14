<?php
// Wspólna logika zaległości rozliczeniowych (login, budowa JWT, cron).
// Zasada: blokada następuje dopiero po BILLING_GRACE_DAYS dniach od minięcia
// terminu płatności (due_date) nieopłaconej faktury.

if (!defined('BILLING_GRACE_DAYS')) define('BILLING_GRACE_DAYS', 2);

function billing_state(PDO $pdo, string $company_id): array {
    $out = ['overdue' => false, 'daysOverdue' => 0, 'blocked' => false,
            'graceDays' => BILLING_GRACE_DAYS, 'dueDate' => null];

    // Rozliczenia nie mogą blokować logowania — przy braku tabel/kolumn (np. przed
    // migracją) lub dowolnym błędzie zwracamy stan „bez zaległości".
    try {
        // najbardziej zaległa nieopłacona faktura z ustawionym terminem płatności
        $q = $pdo->prepare("SELECT due_date FROM invoices
                            WHERE company_id = ? AND status = 'unpaid' AND due_date IS NOT NULL
                            ORDER BY due_date ASC LIMIT 1");
        $q->execute([$company_id]);
        $due = $q->fetchColumn();
        if (!$due) return $out;

        $out['dueDate'] = substr($due, 0, 10);
        $today = new DateTime('today');
        $dueD  = new DateTime($due);
        if ($today <= $dueD) return $out; // termin jeszcze nie minął

        $out['daysOverdue'] = (int)$dueD->diff($today)->days;
        $out['overdue']     = true;
        $out['blocked']     = $out['daysOverdue'] > BILLING_GRACE_DAYS;
    } catch (Throwable $e) {
        return ['overdue' => false, 'daysOverdue' => 0, 'blocked' => false,
                'graceDays' => BILLING_GRACE_DAYS, 'dueDate' => null];
    }
    return $out;
}

// Wartość abonamentu za bieżący okres — proporcjonalnie do segmentów stawek
// (część okresu w niższym, część w wyższym pakiecie). Brak segmentów → period_rate.
function billing_period_amount(PDO $pdo, string $company_id): ?float {
    try {
        $c = $pdo->prepare('SELECT billing_date, next_billing_at, period_rate FROM companies WHERE id = ?');
        $c->execute([$company_id]);
        $row = $c->fetch();
        if (!$row) return null;
        $rate = $row['period_rate'] !== null ? (float)$row['period_rate'] : null;
        if (empty($row['billing_date']) || empty($row['next_billing_at'])) return $rate;

        $B = new DateTime($row['billing_date']);
        $N = new DateTime($row['next_billing_at']);
        $totalDays = (int)$B->diff($N)->days;
        if ($totalDays <= 0) return $rate;

        $seg = $pdo->prepare('SELECT seg_start, rate FROM billing_rate_segments WHERE company_id = ? AND period_start = ? ORDER BY seg_start, id');
        $seg->execute([$company_id, $row['billing_date']]);
        $segs = $seg->fetchAll();
        if (!$segs) return $rate;

        $amount = 0.0;
        $n = count($segs);
        for ($i = 0; $i < $n; $i++) {
            $start = new DateTime($segs[$i]['seg_start']);
            if ($start < $B) $start = clone $B;
            $end = ($i + 1 < $n) ? new DateTime($segs[$i + 1]['seg_start']) : clone $N;
            if ($end > $N) $end = clone $N;
            $days = (int)$start->diff($end)->days;
            if ($days < 0) $days = 0;
            $amount += (float)$segs[$i]['rate'] * ($days / $totalDays);
        }
        return round($amount, 2);
    } catch (Throwable $e) { return null; }
}

// Zapis zmiany stawki w trakcie okresu (upgrade / zmiana planu). Tworzy segment
// bazowy (dotychczasowa stawka od początku okresu) jeśli go brak, potem segment
// z nową stawką od dziś.
function billing_record_rate_change(PDO $pdo, string $company_id, float $newRate): void {
    try {
        $c = $pdo->prepare('SELECT billing_date, period_rate FROM companies WHERE id = ?');
        $c->execute([$company_id]);
        $row = $c->fetch();
        if (!$row || empty($row['billing_date'])) return;
        $B     = $row['billing_date'];
        $today = date('Y-m-d');

        $has = $pdo->prepare('SELECT 1 FROM billing_rate_segments WHERE company_id = ? AND period_start = ? LIMIT 1');
        $has->execute([$company_id, $B]);
        if (!$has->fetch()) {
            $oldRate = $row['period_rate'] !== null ? (float)$row['period_rate'] : $newRate;
            $pdo->prepare('INSERT INTO billing_rate_segments (company_id, period_start, seg_start, rate) VALUES (?,?,?,?)')
                ->execute([$company_id, $B, $B, $oldRate]);
        }
        $upd = $pdo->prepare('UPDATE billing_rate_segments SET rate = ? WHERE company_id = ? AND period_start = ? AND seg_start = ?');
        $upd->execute([$newRate, $company_id, $B, $today]);
        if ($upd->rowCount() === 0) {
            $pdo->prepare('INSERT INTO billing_rate_segments (company_id, period_start, seg_start, rate) VALUES (?,?,?,?)')
                ->execute([$company_id, $B, $today, $newRate]);
        }
    } catch (Throwable $e) {}
}
