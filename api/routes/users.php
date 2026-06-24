<?php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/uuid.php';

function handle_users($method, $id, $sub, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    $company_id = $user['company_id'];

    if ($id && $sub === 'permissions' && $method === 'PUT') {
        users_update_permissions($id, $body, $company_id);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'PUT':    users_update($id, $body, $user, $company_id); break;
            case 'DELETE': users_delete($id, $user, $company_id);        break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  users_list($company_id);          break;
            case 'POST': users_create($body, $company_id); break;
            default: method_not_allowed();
        }
    }
}

function fetch_user_by_id($id, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, role, status, created_at AS createdAt FROM users WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $stmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$id]);
    $user['permissions'] = array_column($stmt->fetchAll(), 'permission');
    return $user;
}

function users_list($company_id) {
    $pdo   = get_pdo();
    $stmt  = $pdo->prepare('SELECT id, username, email, role, status, created_at AS createdAt FROM users WHERE company_id = ? ORDER BY createdAt');
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll();

    $perms = $pdo->query('SELECT user_id, permission FROM user_permissions')->fetchAll();
    foreach ($users as &$u) {
        $u['permissions'] = array_column(
            array_filter($perms, fn($p) => $p['user_id'] === $u['id']),
            'permission'
        );
    }
    echo json_encode(array_values($users));
}

function users_create($body, $company_id) {
    $username    = trim($body['username'] ?? '');
    $email       = trim($body['email'] ?? '');
    $password    = $body['password'] ?? '';
    $role        = $body['role'] ?? 'pracownik';
    $permissions = $body['permissions'] ?? [];

    if (!$username || !$email || !$password) {
        http_response_code(400);
        echo json_encode(['message' => 'Brakuje wymaganych pól.']);
        return;
    }

    $pdo = get_pdo();
    $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $dup->execute([$username, $email]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['message' => 'Nazwa użytkownika lub e-mail jest już zajęty.']);
        return;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->beginTransaction();
    try {
        $newId = generate_uuid();
        $stmt  = $pdo->prepare('INSERT INTO users (id, username, email, password, role, company_id, status) VALUES (?, ?, ?, ?, ?, ?, \'active\')');
        $stmt->execute([$newId, $username, $email, $hash, $role, $company_id]);

        $permStmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
        foreach ($permissions as $perm) {
            $permStmt->execute([$newId, $perm]);
        }
        $pdo->commit();
        http_response_code(201);
        echo json_encode(fetch_user_by_id($newId, $company_id));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function users_update($id, $body, $currentUser, $company_id) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?');
    $exists->execute([$id, $company_id]);
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Użytkownik nie istnieje.']);
        return;
    }

    $username = trim($body['username'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $role     = $body['role'] ?? '';
    $status   = $body['status'] ?? '';

    if ($username || $email) {
        $dup = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?');
        $dup->execute([$username, $email, $id]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Nazwa użytkownika lub e-mail jest już zajęty.']);
            return;
        }
    }

    $fields = [];
    $vals   = [];
    if ($username) { $fields[] = 'username = ?'; $vals[] = $username; }
    if ($email)    { $fields[] = 'email = ?';    $vals[] = $email; }
    if ($password) { $fields[] = 'password = ?'; $vals[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]); }
    if ($role)     { $fields[] = 'role = ?';     $vals[] = $role; }
    if ($status && in_array($status, ['active','inactive','suspended'])) {
        $fields[] = 'status = ?'; $vals[] = $status;
    }

    if ($fields) {
        $vals[] = $id;
        $vals[] = $company_id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ? AND company_id = ?')->execute($vals);
    }

    echo json_encode(fetch_user_by_id($id, $company_id));
}

function users_update_permissions($id, $body, $company_id) {
    $permissions = $body['permissions'] ?? [];
    $pdo         = get_pdo();

    $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?');
    $exists->execute([$id, $company_id]);
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Użytkownik nie istnieje.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
        foreach ($permissions as $perm) {
            $stmt->execute([$id, $perm]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'permissions' => $permissions]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function users_delete($id, $currentUser, $company_id) {
    if ($id === $currentUser['id']) {
        http_response_code(400);
        echo json_encode(['message' => 'Nie możesz usunąć własnego konta.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Użytkownik nie istnieje.']);
        return;
    }

    $pdo->prepare('DELETE FROM users WHERE id = ? AND company_id = ?')->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
}
