<?php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/uuid.php';
require_once __DIR__ . '/../helpers/jwt.php';

function require_support_role() {
    $user = require_auth();
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['message' => 'Brak dostępu — wymagana rola super_admin.']);
        exit;
    }
    return $user;
}

function handle_support($method, $section, $resourceId, $action, $body) {
    switch ($section) {
        case 'companies': support_companies($method, $resourceId, $action, $body); break;
        case 'users':     support_users($method, $resourceId, $action, $body);     break;
        case 'tickets':   support_tickets($method, $resourceId, $action, $body);   break;
        case 'pools':     support_pools($method, $resourceId, $action, $body);     break;
        default:          support_dashboard($method);
    }
}

// ── Dashboard ────────────────────────────────────────────────────
function support_dashboard($method) {
    require_support_role();
    if ($method !== 'GET') { method_not_allowed(); return; }
    $pdo = get_pdo();
    echo json_encode([
        'companies'         => (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
        'companies_pending' => (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status='pending'")->fetchColumn(),
        'users'             => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role != \'super_admin\'')->fetchColumn(),
        'tickets_open'      => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('open','in_progress')")->fetchColumn(),
        'tickets_total'     => (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
    ]);
}

// ── Companies ────────────────────────────────────────────────────
function support_companies($method, $id, $action, $body) {
    require_support_role();
    $pdo = get_pdo();

    if (!$id) {
        if ($method !== 'GET') { method_not_allowed(); return; }
        $stmt = $pdo->query('
            SELECT c.id, c.name, c.nip, c.email, c.phone, c.plan_id, c.status,
                   c.billing_date, c.next_billing_at, c.created_at,
                   p.name AS plan_name,
                   COUNT(DISTINCT u.id) AS user_count,
                   COUNT(DISTINCT t.id) AS ticket_count
            FROM companies c
            LEFT JOIN plans p ON c.plan_id = p.id
            LEFT JOIN users u ON u.company_id = c.id
            LEFT JOIN tickets t ON t.company_id = c.id
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ');
        echo json_encode($stmt->fetchAll());
        return;
    }

    $cStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $cStmt->execute([$id]);
    $company = $cStmt->fetch();
    if (!$company) { http_response_code(404); echo json_encode(['message' => 'Firma nie znaleziona.']); return; }

    if (!$action && $method === 'GET') {
        echo json_encode($company);
        return;
    }

    switch ($action) {
        case 'approve':
            if ($method !== 'PUT') { method_not_allowed(); return; }
            $pdo->prepare("UPDATE companies SET status='active' WHERE id=?")->execute([$id]);
            $pdo->prepare("UPDATE users SET status='active' WHERE company_id=? AND role != 'super_admin'")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        case 'suspend':
            if ($method !== 'PUT') { method_not_allowed(); return; }
            $pdo->prepare("UPDATE companies SET status='suspended' WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        case 'billing':
            if ($method !== 'PUT') { method_not_allowed(); return; }
            $pdo->prepare('UPDATE companies SET billing_date=?, next_billing_at=? WHERE id=?')
                ->execute([$body['billing_date'] ?: null, $body['next_billing_at'] ?: null, $id]);
            echo json_encode(['success' => true]);
            break;
        case 'plan':
            if ($method !== 'PUT') { method_not_allowed(); return; }
            $planId = trim($body['plan_id'] ?? '');
            if (!$planId) { http_response_code(400); echo json_encode(['message' => 'Brak plan_id.']); return; }
            $pdo->prepare('UPDATE companies SET plan_id=? WHERE id=?')->execute([$planId, $id]);
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
    }
}

// ── Users ────────────────────────────────────────────────────────
function support_users($method, $id, $action, $body) {
    $actor = require_support_role();
    $pdo   = get_pdo();

    if (!$id) {
        if ($method !== 'GET') { method_not_allowed(); return; }
        $companyId = $_GET['company_id'] ?? null;
        if ($companyId) {
            $stmt = $pdo->prepare('
                SELECT u.id, u.username, u.email, u.role, u.status, u.company_id,
                       c.name AS company_name
                FROM users u JOIN companies c ON u.company_id = c.id
                WHERE u.company_id = ?
                ORDER BY u.username
            ');
            $stmt->execute([$companyId]);
        } else {
            $stmt = $pdo->query('
                SELECT u.id, u.username, u.email, u.role, u.status, u.company_id,
                       c.name AS company_name
                FROM users u JOIN companies c ON u.company_id = c.id
                WHERE u.role != \'super_admin\'
                ORDER BY c.name, u.username
            ');
        }
        echo json_encode($stmt->fetchAll());
        return;
    }

    if ($action === 'impersonate') {
        if ($method !== 'POST') { method_not_allowed(); return; }
        $uStmt = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $uStmt->execute([$id]);
        if (!$uStmt->fetch()) { http_response_code(404); echo json_encode(['message' => 'Użytkownik nie znaleziony.']); return; }

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 900);
        $pdo->prepare('INSERT INTO impersonation_tokens (token, target_user_id, created_by, expires_at) VALUES (?,?,?,?)')
            ->execute([$token, $id, $actor['id'], $expires]);
        echo json_encode(['token' => $token]);
        return;
    }

    http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
}

// ── Tickets ──────────────────────────────────────────────────────
function support_tickets($method, $id, $action, $body) {
    $actor = require_support_role();
    $pdo   = get_pdo();

    if (!$id) {
        if ($method !== 'GET') { method_not_allowed(); return; }
        $where  = '';
        $params = [];
        if (!empty($_GET['status'])) {
            $where = 'WHERE t.status = ?';
            $params[] = $_GET['status'];
        }
        $stmt = $pdo->prepare("
            SELECT t.*, c.name AS company_name, u.username AS user_username,
                   (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) AS message_count
            FROM tickets t
            JOIN companies c ON t.company_id = c.id
            JOIN users u ON t.user_id = u.id
            $where
            ORDER BY t.updated_at DESC
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        return;
    }

    if (!$action && $method === 'GET') {
        $stmt = $pdo->prepare('
            SELECT t.*, c.name AS company_name, u.username AS user_username
            FROM tickets t
            JOIN companies c ON t.company_id = c.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) { http_response_code(404); echo json_encode(['message' => 'Ticket nie znaleziony.']); return; }

        $mStmt = $pdo->prepare('
            SELECT tm.*, u.username AS author_username
            FROM ticket_messages tm JOIN users u ON tm.author_id = u.id
            WHERE tm.ticket_id = ?
            ORDER BY tm.created_at ASC
        ');
        $mStmt->execute([$id]);
        $ticket['messages'] = $mStmt->fetchAll();
        echo json_encode($ticket);
        return;
    }

    switch ($action) {
        case 'status':
            if ($method !== 'PUT') { method_not_allowed(); return; }
            $allowed = ['open','in_progress','resolved','closed'];
            $status  = $body['status'] ?? '';
            if (!in_array($status, $allowed)) { http_response_code(400); echo json_encode(['message' => 'Nieprawidłowy status.']); return; }
            $pdo->prepare('UPDATE tickets SET status=? WHERE id=?')->execute([$status, $id]);
            echo json_encode(['success' => true]);
            break;
        case 'reply':
            if ($method !== 'POST') { method_not_allowed(); return; }
            $message = trim($body['message'] ?? '');
            if (!$message) { http_response_code(400); echo json_encode(['message' => 'Wiadomość nie może być pusta.']); return; }
            $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author_id, is_support, message) VALUES (?,?,1,?)')
                ->execute([$id, $actor['id'], $message]);
            $pdo->prepare("UPDATE tickets SET status=IF(status='open','in_progress',status) WHERE id=?")
                ->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
    }
}

// ── Pools ────────────────────────────────────────────────────────
function support_pools($method, $id, $action, $body) {
    require_support_role();
    $pdo = get_pdo();

    if (!$id) {
        if ($method !== 'GET') { method_not_allowed(); return; }
        $stmt = $pdo->query('
            SELECT p.id, p.name, p.description, p.created_at,
                   GROUP_CONCAT(DISTINCT pf.feature_name ORDER BY pf.feature_name SEPARATOR \',\') AS features,
                   COUNT(DISTINCT pm.user_id) AS member_count
            FROM pools p
            LEFT JOIN pool_features pf ON p.id = pf.pool_id
            LEFT JOIN pool_members pm  ON p.id = pm.pool_id
            GROUP BY p.id ORDER BY p.name
        ');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) $row['features'] = $row['features'] ? explode(',', $row['features']) : [];
        echo json_encode($rows);
        return;
    }

    if ($action === 'members-remove') {
        if ($method !== 'POST') { method_not_allowed(); return; }
        $userId = trim($body['user_id'] ?? '');
        if (!$userId) { http_response_code(400); echo json_encode(['message' => 'Brak user_id.']); return; }
        $pdo->prepare('DELETE FROM pool_members WHERE pool_id=? AND user_id=?')->execute([$id, $userId]);
        echo json_encode(['success' => true]);
        return;
    }

    if ($action === 'members') {
        switch ($method) {
            case 'GET':
                $stmt = $pdo->prepare('
                    SELECT u.id, u.username, u.email, u.company_id,
                           c.name AS company_name, pm.added_at
                    FROM pool_members pm
                    JOIN users u ON pm.user_id = u.id
                    JOIN companies c ON u.company_id = c.id
                    WHERE pm.pool_id = ?
                    ORDER BY c.name, u.username
                ');
                $stmt->execute([$id]);
                echo json_encode($stmt->fetchAll());
                break;
            case 'POST':
                $userId = trim($body['user_id'] ?? '');
                if (!$userId) { http_response_code(400); echo json_encode(['message' => 'Brak user_id.']); return; }
                $pdo->prepare('INSERT IGNORE INTO pool_members (pool_id, user_id) VALUES (?,?)')->execute([$id, $userId]);
                echo json_encode(['success' => true]);
                break;
            case 'DELETE':
                $userId = $body['user_id'] ?? ($_GET['user_id'] ?? '');
                $pdo->prepare('DELETE FROM pool_members WHERE pool_id=? AND user_id=?')->execute([$id, $userId]);
                echo json_encode(['success' => true]);
                break;
            default: method_not_allowed();
        }
        return;
    }

    http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
}
