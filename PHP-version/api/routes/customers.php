<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_customers($method, $id, $body) {
    require_auth();

    if ($method !== 'GET') { method_not_allowed(); return; }

    $pdo       = get_pdo();
    $customers = $pdo->query('SELECT id, full_name AS fullName, phone FROM customers ORDER BY full_name')->fetchAll();
    $entries   = $pdo->query('
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
        ORDER BY te.date_in DESC
    ')->fetchAll();

    $result = array_map(function ($c) use ($entries) {
        $c['phone']   = $c['phone'] ?? '';
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
