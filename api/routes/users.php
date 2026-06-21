<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_users($method, $id, $sub, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($id && $sub === 'permissions' && $method === 'PUT') {
        users_update_permissions($id, $body);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'PUT':    users_update($id, $body, $user); break;
            case 'DELETE': users_delete($id, $user);        break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  users_list();          break;
            case 'POST': users_create($body);   break;
            default: method_not_allowed();
        }
    }
}

function fetch_user($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, role, created_at AS createdAt FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $stmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$id]);
    $user['permissions'] = array_column($stmt->fetchAll(), 'permission');
    return $user;
}

function users_list() {
    $pdo   = get_pdo();
    $users = $pdo->query('SELECT id, username, email, role, created_at AS createdAt FROM users ORDER BY id')->fetchAll();
    $perms = $pdo->query('SELECT user_id, permission FROM user_permissions')->fetchAll();

    foreach ($users as &$u) {
        $u['permissions'] = array_column(
            array_filter($perms, fn($p) => $p['user_id'] == $u['id']),
            'permission'
        );
    }
    echo json_encode(array_values($users));
}

function users_create($body) {
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
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $role]);
        $newId = $pdo->lastInsertId();

        $permStmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
        foreach ($permissions as $perm) {
            $permStmt->execute([$newId, $perm]);
        }
        $pdo->commit();
        http_response_code(201);
        echo json_encode(fetch_user($newId));
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function users_update($id, $body, $currentUser) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Użytkownik nie istnieje.']);
        return;
    }

    $username = trim($body['username'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $role     = $body['role'] ?? '';

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

    if ($fields) {
        $vals[] = $id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    }

    echo json_encode(fetch_user($id));
}

function users_update_permissions($id, $body) {
    $permissions = $body['permissions'] ?? [];
    $pdo         = get_pdo();

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

function users_delete($id, $currentUser) {
    if ($id == $currentUser['id']) {
        http_response_code(400);
        echo json_encode(['message' => 'Nie możesz usunąć własnego konta.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Użytkownik nie istnieje.']);
        return;
    }

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
}
