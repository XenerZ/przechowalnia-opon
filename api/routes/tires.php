<?php
require_once __DIR__ . '/../helpers/auth.php';

define('TIRE_SELECT', '
  SELECT
    te.id,
    c.full_name          AS fullName,
    c.phone,
    c.email,
    te.license_plate     AS licensePlate,
    te.tire_width        AS tireWidth,
    te.tire_profile      AS tireProfile,
    te.tire_diameter     AS tireDiameter,
    te.tire_year         AS tireYear,
    te.location,
    te.date_in           AS dateIn,
    te.status,
    te.date_out          AS dateOut,
    te.next_tire_change  AS nextTireChange,
    te.notes,
    te.customer_id       AS customerId
  FROM tire_entries te
  JOIN customers c ON te.customer_id = c.id
');

function handle_tires($method, $id, $body) {
    $user       = require_auth();
    $company_id = $user['company_id'];

    if ($id === 'stats' && $method === 'GET') {
        tires_stats($company_id);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'PUT':    require_permission($user, 'edit_entries');   tires_update($id, $body, $company_id); break;
            case 'DELETE': require_permission($user, 'delete_entries'); tires_delete($id, $company_id);        break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  tires_list($company_id);                                               break;
            case 'POST': require_permission($user, 'add_entries'); tires_create($body, $company_id); break;
            default: method_not_allowed();
        }
    }
}

function format_tire($row) {
    return [
        'id'             => (int)$row['id'],
        'fullName'       => $row['fullName'],
        'phone'          => $row['phone'] ?? '',
        'email'          => $row['email'] ?? '',
        'licensePlate'   => $row['licensePlate'],
        'tireWidth'      => (int)$row['tireWidth'],
        'tireProfile'    => (int)$row['tireProfile'],
        'tireDiameter'   => (int)$row['tireDiameter'],
        'tireYear'       => isset($row['tireYear']) && $row['tireYear'] !== null ? (int)$row['tireYear'] : null,
        'location'       => $row['location'],
        'dateIn'         => $row['dateIn']  ? substr($row['dateIn'],  0, 10) : '',
        'status'         => $row['status'],
        'dateOut'        => $row['dateOut'] ? substr($row['dateOut'], 0, 10) : '',
        'nextTireChange' => $row['nextTireChange'] ? substr($row['nextTireChange'], 0, 10) : '',
        'notes'          => $row['notes'] ?? '',
        'customerId'     => (int)$row['customerId'],
    ];
}

function find_or_create_customer($pdo, $fullName, $phone, $email, $company_id) {
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE full_name = ? AND COALESCE(phone,'') = COALESCE(?,'') AND company_id = ?");
    $stmt->execute([$fullName, $phone ?: '', $company_id]);
    $row = $stmt->fetch();
    if ($row) {
        if ($email !== null) {
            $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute([$email ?: null, $row['id']]);
        }
        return $row['id'];
    }
    $stmt = $pdo->prepare('INSERT INTO customers (full_name, phone, email, company_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$fullName, $phone ?: null, $email ?: null, $company_id]);
    return $pdo->lastInsertId();
}

function tires_list($company_id) {
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare(TIRE_SELECT . ' WHERE te.company_id = ? ORDER BY te.id');
        $stmt->execute([$company_id]);
        echo json_encode(array_map('format_tire', $stmt->fetchAll()));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'tires_list: ' . $e->getMessage()]);
    }
}

function tires_stats($company_id) {
    try {
        $pdo = get_pdo();

        $q = fn($sql) => $pdo->prepare($sql)->execute([$company_id]) ?: null;

        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM tire_entries WHERE company_id = ?');
        $stmt->execute([$company_id]); $total = (int)$stmt->fetch()['n'];

        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM tire_entries WHERE company_id = ? AND status = 'W przechowalni'");
        $stmt->execute([$company_id]); $inStore = (int)$stmt->fetch()['n'];

        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM tire_entries WHERE company_id = ? AND status = 'Wydane'");
        $stmt->execute([$company_id]); $released = (int)$stmt->fetch()['n'];

        $wS = 'DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
        $wE = "DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";

        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM tire_entries WHERE company_id = ? AND date_out >= $wS AND date_out < $wE AND status = 'Wydane'");
        $stmt->execute([$company_id]); $relWeek = (int)$stmt->fetch()['n'];

        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM tire_entries WHERE company_id = ? AND date_in >= $wS AND date_in < $wE");
        $stmt->execute([$company_id]); $recWeek = (int)$stmt->fetch()['n'];

        // Limit planu
        $planStmt = $pdo->prepare('SELECT p.max_tires FROM companies c JOIN plans p ON c.plan_id = p.id WHERE c.id = ?');
        $planStmt->execute([$company_id]);
        $maxTires = $planStmt->fetch()['max_tires'];

        echo json_encode([
            'total'            => $total,
            'inStorage'        => $inStore,
            'released'         => $released,
            'releasedThisWeek' => $relWeek,
            'receivedThisWeek' => $recWeek,
            'planLimit'        => [
                'max'      => $maxTires !== null ? (int)$maxTires : null,
                'current'  => $total,
                'exceeded' => $maxTires !== null && $total >= (int)$maxTires,
            ],
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'tires_stats: ' . $e->getMessage()]);
    }
}

function tires_create($body, $company_id) {
    $fullName       = trim($body['fullName'] ?? '');
    $phone          = $body['phone'] ?? '';
    $email          = $body['email'] ?? null;
    $licensePlate   = trim($body['licensePlate'] ?? '');
    $tireWidth      = $body['tireWidth']    ?? null;
    $tireProfile    = $body['tireProfile']  ?? null;
    $tireDiameter   = $body['tireDiameter'] ?? null;
    $tireYear       = $body['tireYear']     ?? null;
    $location       = trim($body['location'] ?? '');
    $dateIn         = $body['dateIn']  ?? '';
    $status         = $body['status']  ?? 'W przechowalni';
    $dateOut        = $body['dateOut'] ?: null;
    $nextTireChange = $body['nextTireChange'] ?: null;
    $notes          = $body['notes']   ?: null;

    if (!$fullName || !$licensePlate || !$tireWidth || !$tireProfile || !$tireDiameter || !$location || !$dateIn) {
        http_response_code(400);
        echo json_encode(['message' => 'Brakuje wymaganych pól.']);
        return;
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        $customerId = find_or_create_customer($pdo, $fullName, $phone, $email, $company_id);
        $stmt = $pdo->prepare('INSERT INTO tire_entries (customer_id, license_plate, tire_width, tire_profile, tire_diameter, tire_year, location, date_in, status, date_out, next_tire_change, notes, company_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $customerId, $licensePlate,
            (int)$tireWidth, (int)$tireProfile, (int)$tireDiameter,
            $tireYear ? (int)$tireYear : null,
            $location, $dateIn, $status, $dateOut, $nextTireChange, $notes,
            $company_id,
        ]);
        $newId = $pdo->lastInsertId();
        $pdo->commit();

        $row = $pdo->prepare(TIRE_SELECT . ' WHERE te.id = ? AND te.company_id = ?');
        $row->execute([$newId, $company_id]);
        http_response_code(201);
        echo json_encode(format_tire($row->fetch()));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function tires_update($id, $body, $company_id) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT customer_id FROM tire_entries WHERE id = ? AND company_id = ?');
    $exists->execute([$id, $company_id]);
    $entry = $exists->fetch();
    if (!$entry) {
        http_response_code(404);
        echo json_encode(['message' => 'Wpis nie istnieje.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        if (isset($body['fullName'])) {
            $pdo->prepare('UPDATE customers SET full_name = ?, phone = ?, email = ? WHERE id = ?')
                ->execute([$body['fullName'], $body['phone'] ?: null, $body['email'] ?: null, $entry['customer_id']]);
        }

        $map = [
            'licensePlate'   => ['license_plate',   'str'],
            'tireWidth'      => ['tire_width',       'int'],
            'tireProfile'    => ['tire_profile',     'int'],
            'tireDiameter'   => ['tire_diameter',    'int'],
            'tireYear'       => ['tire_year',        'int_null'],
            'location'       => ['location',         'str'],
            'dateIn'         => ['date_in',          'str'],
            'status'         => ['status',           'str'],
            'dateOut'        => ['date_out',         'null_str'],
            'nextTireChange' => ['next_tire_change', 'null_str'],
            'notes'          => ['notes',            'null_str'],
        ];

        $fields = [];
        $vals   = [];
        foreach ($map as $jsKey => [$col, $type]) {
            if (!array_key_exists($jsKey, $body)) continue;
            $val = $body[$jsKey];
            $val = match($type) {
                'int'      => (int)$val,
                'int_null' => $val ? (int)$val : null,
                'null_str' => $val ?: null,
                default    => $val,
            };
            $fields[] = "$col = ?";
            $vals[]   = $val;
        }

        if ($fields) {
            $vals[] = $id;
            $vals[] = $company_id;
            $pdo->prepare('UPDATE tire_entries SET ' . implode(', ', $fields) . ' WHERE id = ? AND company_id = ?')->execute($vals);
        }

        $pdo->commit();
        $row = $pdo->prepare(TIRE_SELECT . ' WHERE te.id = ? AND te.company_id = ?');
        $row->execute([$id, $company_id]);
        echo json_encode(format_tire($row->fetch()));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function tires_delete($id, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM tire_entries WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Wpis nie istnieje.']);
        return;
    }
    $pdo->prepare('DELETE FROM tire_entries WHERE id = ? AND company_id = ?')->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
}
