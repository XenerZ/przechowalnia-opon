<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_customers($method, $id, $body) {
    $user = require_auth();
    require_feature($user, 'customers');

    $company_id = $user['company_id'];
    $pdo        = get_pdo();

    // Usuwanie klienta — tylko gdy nie ma powiązanych wpisów (ochrona danych/FK)
    if ($method === 'DELETE' && $id) {
        $chk = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND company_id = ?');
        $chk->execute([$id, $company_id]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['message' => 'Klient nie istnieje.']); return; }

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM tire_entries WHERE customer_id = ? AND company_id = ?');
        $cnt->execute([$id, $company_id]);
        if ((int)$cnt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['message' => 'Nie można usunąć klienta z powiązanymi wpisami. Usuń najpierw jego wpisy.']);
            return;
        }
        $pdo->prepare('DELETE FROM customers WHERE id = ? AND company_id = ?')->execute([$id, $company_id]);
        echo json_encode(['success' => true]);
        return;
    }

    if ($method !== 'GET') { method_not_allowed(); return; }

    $stmt = $pdo->prepare('SELECT id, full_name AS fullName, phone, email FROM customers WHERE company_id = ? ORDER BY full_name');
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            te.customer_id  AS customerId,
            te.id,
            te.license_plate AS licensePlate,
            te.tire_width    AS tireWidth,
            te.tire_profile  AS tireProfile,
            te.tire_diameter AS tireDiameter,
            te.tire_year     AS tireYear,
            te.location,
            te.date_in       AS dateIn,
            te.status,
            te.date_out      AS dateOut,
            te.notes
        FROM tire_entries te
        WHERE te.company_id = ?
        ORDER BY te.date_in DESC
    ');
    $stmt->execute([$company_id]);
    $entries = $stmt->fetchAll();

    $result = array_map(function ($c) use ($entries) {
        $c['phone']   = $c['phone'] ?? '';
        $c['email']   = $c['email'] ?? '';
        $c['entries'] = array_values(array_map(
            fn($e) => [
                'id'           => (int)$e['id'],
                'licensePlate' => $e['licensePlate'],
                'tireWidth'    => (int)$e['tireWidth'],
                'tireProfile'  => (int)$e['tireProfile'],
                'tireDiameter' => (int)$e['tireDiameter'],
                'tireYear'     => isset($e['tireYear']) && $e['tireYear'] !== null ? (int)$e['tireYear'] : null,
                'location'     => $e['location'],
                'dateIn'       => $e['dateIn']  ? substr($e['dateIn'],  0, 10) : '',
                'status'       => $e['status'],
                'dateOut'      => $e['dateOut'] ? substr($e['dateOut'], 0, 10) : '',
                'notes'        => $e['notes'] ?? '',
            ],
            array_filter($entries, fn($e) => $e['customerId'] == $c['id'])
        ));
        return $c;
    }, $customers);

    echo json_encode($result);
}
