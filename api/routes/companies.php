<?php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/uuid.php';

function handle_companies($method, $id, $sub, $body) {
    // Własna firma („Moje konto") — self-service dla zalogowanego użytkownika
    if ($id === 'mine') {
        $user = require_auth();
        if ($sub === 'invoices' && $method === 'GET') { companies_invoices($user['company_id']); return; }
        if ($method === 'GET') { companies_get_mine($user['company_id']); return; }
        if ($method === 'PUT') {
            // edycja danych do faktury — tylko administrator konta
            require_permission($user, 'manage_users');
            companies_update_mine($user['company_id'], $body);
            return;
        }
        method_not_allowed();
        return;
    }

    // Reszta tylko dla super-adminów (manage_users + specjalny flag)
    // Na razie: manage_users wystarczy, dopracujemy gdy będzie panel
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($id && $sub === 'approve' && $method === 'PUT') {
        companies_approve($id);
        return;
    }
    if ($id && $sub === 'suspend' && $method === 'PUT') {
        companies_suspend($id);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'GET': companies_get($id);            break;
            case 'PUT': companies_update($id, $body);  break;
            default:    method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  companies_list();          break;
            case 'POST': companies_create($body);   break;
            default:     method_not_allowed();
        }
    }
}

function companies_format($row) {
    return [
        'id'          => $row['id'],
        'name'        => $row['name'],
        'nip'         => $row['nip'],
        'address'     => $row['address'],
        'city'        => $row['city'],
        'postalCode'  => $row['postal_code'],
        'phone'       => $row['phone'],
        'email'       => $row['email'],
        'planId'      => $row['plan_id'],
        'planName'    => $row['plan_name'] ?? null,
        'trialEndsAt' => $row['trial_ends_at'] ? substr($row['trial_ends_at'], 0, 10) : null,
        'status'      => $row['status'],
        'notes'       => $row['notes'],
        'billingDate'   => $row['billing_date']    ?? null,
        'nextBillingAt' => $row['next_billing_at'] ?? null,
        'createdAt'   => $row['created_at'] ? substr($row['created_at'], 0, 10) : null,
        'userCount'   => isset($row['user_count']) ? (int)$row['user_count'] : null,
    ];
}

function companies_list() {
    $pdo  = get_pdo();
    $stmt = $pdo->query('
        SELECT c.*, p.name AS plan_name,
               (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count
        FROM companies c
        LEFT JOIN plans p ON c.plan_id = p.id
        ORDER BY c.created_at DESC
    ');
    echo json_encode(array_map('companies_format', $stmt->fetchAll()));
}

function companies_get($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('
        SELECT c.*, p.name AS plan_name,
               (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count
        FROM companies c
        LEFT JOIN plans p ON c.plan_id = p.id
        WHERE c.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Firma nie istnieje.']); return; }
    echo json_encode(companies_format($row));
}

function companies_get_mine($company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('
        SELECT c.*, p.name AS plan_name, p.max_tires, p.has_customers, p.has_actions, p.price_monthly
        FROM companies c
        LEFT JOIN plans p ON c.plan_id = p.id
        WHERE c.id = ?
    ');
    $stmt->execute([$company_id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono firmy.']); return; }

    $result = companies_format($row);
    $result['planPrice'] = isset($row['price_monthly']) && $row['price_monthly'] !== null ? (float)$row['price_monthly'] : null;
    $result['planLimits'] = [
        'maxTires'    => $row['max_tires'] !== null ? (int)$row['max_tires'] : null,
        'hasCustomers' => (bool)$row['has_customers'],
        'hasActions'   => (bool)$row['has_actions'],
    ];
    echo json_encode($result);
}

// Edycja danych do faktury własnej firmy (nie zmienia planu/statusu — to po stronie supportu)
function companies_update_mine($company_id, $body) {
    $pdo = get_pdo();
    $allowed = ['name','nip','address','city','postal_code','phone','email'];
    $fields  = [];
    $vals    = [];
    foreach ($allowed as $col) {
        $jsKey = lcfirst(str_replace('_', '', ucwords($col, '_'))); // postal_code -> postalCode
        $key   = array_key_exists($jsKey, $body) ? $jsKey : (array_key_exists($col, $body) ? $col : null);
        if (!$key) continue;
        $val = trim((string)$body[$key]);
        if ($col === 'name' && $val === '') { http_response_code(400); echo json_encode(['message' => 'Nazwa firmy jest wymagana.']); return; }
        if ($col === 'email') {
            if ($val === '' || !filter_var($val, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['message' => 'Nieprawidłowy adres e-mail.']); return; }
        }
        $fields[] = "`$col` = ?";
        $vals[]   = ($col === 'name' || $col === 'email') ? $val : ($val !== '' ? $val : null);
    }
    if ($fields) {
        $vals[] = $company_id;
        $pdo->prepare('UPDATE companies SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    }
    companies_get_mine($company_id);
}

// Historia rozliczeń / faktury danej firmy
function companies_invoices($company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('
        SELECT id, number, issued_at AS issuedAt, period_start AS periodStart, period_end AS periodEnd,
               amount, currency, status, file_url AS fileUrl
        FROM invoices
        WHERE company_id = ?
        ORDER BY issued_at DESC, id DESC
    ');
    $stmt->execute([$company_id]);
    $rows = array_map(function ($r) {
        $r['id']     = (int)$r['id'];
        $r['amount'] = $r['amount'] !== null ? (float)$r['amount'] : null;
        return $r;
    }, $stmt->fetchAll());
    echo json_encode(array_values($rows));
}

function companies_create($body) {
    require_once __DIR__ . '/../helpers/uuid.php';
    $name       = trim($body['name']        ?? '');
    $email      = trim($body['email']       ?? '');
    $planId     = trim($body['plan_id']     ?? 'free');
    $nip        = trim($body['nip']         ?? '');
    $address    = trim($body['address']     ?? '');
    $city       = trim($body['city']        ?? '');
    $postalCode = trim($body['postal_code'] ?? '');
    $phone      = trim($body['phone']       ?? '');
    $notes      = trim($body['notes']       ?? '');
    $status     = in_array($body['status'] ?? '', ['pending','active','suspended']) ? $body['status'] : 'active';

    if (!$name || !$email) {
        http_response_code(400);
        echo json_encode(['message' => 'Nazwa i e-mail firmy są wymagane.']);
        return;
    }

    $pdo  = get_pdo();
    $id   = generate_uuid();
    $pdo->prepare('INSERT INTO companies (id, name, nip, address, city, postal_code, phone, email, plan_id, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $name, $nip ?: null, $address ?: null, $city ?: null, $postalCode ?: null, $phone ?: null, $email, $planId, $status, $notes ?: null]);

    http_response_code(201);
    companies_get($id);
}

function companies_update($id, $body) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Firma nie istnieje.']);
        return;
    }

    $allowed = ['name','nip','address','city','postal_code','phone','email','plan_id','notes','trial_ends_at'];
    $fields  = [];
    $vals    = [];
    foreach ($allowed as $col) {
        $jsKey = lcfirst(str_replace('_', '', ucwords($col, '_')));
        $key   = array_key_exists($jsKey, $body) ? $jsKey : (array_key_exists($col, $body) ? $col : null);
        if (!$key) continue;
        $fields[] = "`$col` = ?";
        $vals[]   = $body[$key] ?: null;
    }
    if (isset($body['status']) && in_array($body['status'], ['pending','active','suspended'])) {
        $fields[] = 'status = ?';
        $vals[]   = $body['status'];
    }

    if ($fields) {
        $vals[] = $id;
        $pdo->prepare('UPDATE companies SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    }

    companies_get($id);
}

function companies_approve($id) {
    $pdo = get_pdo();
    $pdo->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE users SET status = 'active' WHERE company_id = ? AND status = 'inactive'")->execute([$id]);
    echo json_encode(['success' => true]);
}

function companies_suspend($id) {
    $pdo = get_pdo();
    $pdo->prepare("UPDATE companies SET status = 'suspended' WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
}
