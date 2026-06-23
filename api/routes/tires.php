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
    $user = require_auth();

    if ($id === 'stats' && $method === 'GET') {
        tires_stats();
        return;
    }

    if ($id) {
        switch ($method) {
            case 'PUT':    require_permission($user, 'edit_entries');   tires_update($id, $body); break;
            case 'DELETE': require_permission($user, 'delete_entries'); tires_delete($id);        break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  tires_list();                                               break;
            case 'POST': require_permission($user, 'add_entries'); tires_create($body); break;
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

function find_or_create_customer($pdo, $fullName, $phone, $email = null) {
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE full_name = ? AND COALESCE(phone,'') = COALESCE(?,'')");
    $stmt->execute([$fullName, $phone ?: '']);
    $row = $stmt->fetch();
    if ($row) {
        if ($email !== null) {
            $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute([$email ?: null, $row['id']]);
        }
        return $row['id'];
    }
    $stmt = $pdo->prepare('INSERT INTO customers (full_name, phone, email) VALUES (?, ?, ?)');
    $stmt->execute([$fullName, $phone ?: null, $email ?: null]);
    return $pdo->lastInsertId();
}

function tires_list() {
    try {
        $pdo  = get_pdo();
        $rows = $pdo->query(TIRE_SELECT . ' ORDER BY te.id')->fetchAll();
        echo json_encode(array_map('format_tire', $rows));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'tires_list: ' . $e->getMessage()]);
    }
}

function tires_stats() {
    try {
        $pdo      = get_pdo();
        $total    = $pdo->query('SELECT COUNT(*) AS n FROM tire_entries')->fetch()['n'];
        $inStore  = $pdo->query("SELECT COUNT(*) AS n FROM tire_entries WHERE status = 'W przechowalni'")->fetch()['n'];
        $released = $pdo->query("SELECT COUNT(*) AS n FROM tire_entries WHERE status = 'Wydane'")->fetch()['n'];

        $weekStart = 'DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
        $weekEnd   = "DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";

        $relWeek = $pdo->query("SELECT COUNT(*) AS n FROM tire_entries WHERE date_out >= $weekStart AND date_out < $weekEnd AND status = 'Wydane'")->fetch()['n'];
        $recWeek = $pdo->query("SELECT COUNT(*) AS n FROM tire_entries WHERE date_in  >= $weekStart AND date_in  < $weekEnd")->fetch()['n'];

        echo json_encode([
            'total'            => (int)$total,
            'inStorage'        => (int)$inStore,
            'released'         => (int)$released,
            'releasedThisWeek' => (int)$relWeek,
            'receivedThisWeek' => (int)$recWeek,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'tires_stats: ' . $e->getMessage()]);
    }
}

function tires_create($body) {
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
        $customerId = find_or_create_customer($pdo, $fullName, $phone, $email);
        $stmt = $pdo->prepare('INSERT INTO tire_entries (customer_id, license_plate, tire_width, tire_profile, tire_diameter, tire_year, location, date_in, status, date_out, next_tire_change, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $customerId, $licensePlate,
            (int)$tireWidth, (int)$tireProfile, (int)$tireDiameter,
            $tireYear ? (int)$tireYear : null,
            $location, $dateIn, $status, $dateOut, $nextTireChange, $notes,
        ]);
        $newId = $pdo->lastInsertId();
        $pdo->commit();

        $row = $pdo->prepare(TIRE_SELECT . ' WHERE te.id = ?');
        $row->execute([$newId]);
        http_response_code(201);
        echo json_encode(format_tire($row->fetch()));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function tires_update($id, $body) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT customer_id FROM tire_entries WHERE id = ?');
    $exists->execute([$id]);
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
            'licensePlate'   => ['license_plate',    'str'],
            'tireWidth'      => ['tire_width',        'int'],
            'tireProfile'    => ['tire_profile',      'int'],
            'tireDiameter'   => ['tire_diameter',     'int'],
            'tireYear'       => ['tire_year',         'int_null'],
            'location'       => ['location',          'str'],
            'dateIn'         => ['date_in',           'str'],
            'status'         => ['status',            'str'],
            'dateOut'        => ['date_out',          'null_str'],
            'nextTireChange' => ['next_tire_change',  'null_str'],
            'notes'          => ['notes',             'null_str'],
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
            $pdo->prepare('UPDATE tire_entries SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
        }

        $pdo->commit();
        $row = $pdo->prepare(TIRE_SELECT . ' WHERE te.id = ?');
        $row->execute([$id]);
        echo json_encode(format_tire($row->fetch()));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function tires_delete($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM tire_entries WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Wpis nie istnieje.']);
        return;
    }
    $pdo->prepare('DELETE FROM tire_entries WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
}
