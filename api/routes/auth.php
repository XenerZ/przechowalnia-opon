<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/auth.php';

function handle_auth($method, $action, $body) {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_login($body);
            break;
        case 'verify-password':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_verify_password($body);
            break;
        case 'change-password':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_change_password($body);
            break;
        default:
            http_response_code(404);
            echo json_encode(['message' => 'Nie znaleziono.']);
    }
}

function auth_login($body) {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['message' => 'Podaj login i hasło.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['message' => 'Nieprawidłowy login lub hasło.']);
        return;
    }

    $stmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $permissions = array_column($stmt->fetchAll(), 'permission');

    $payload = [
        'id'          => (int)$user['id'],
        'username'    => $user['username'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'permissions' => $permissions,
        'iat'         => time(),
        'exp'         => time() + 8 * 3600,
    ];

    echo json_encode(['token' => jwt_encode($payload, JWT_SECRET), 'user' => $payload]);
}

function auth_verify_password($body) {
    $user     = require_auth();
    $password = $body['password'] ?? '';

    if (!$password) { echo json_encode(['valid' => false]); return; }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row  = $stmt->fetch();

    echo json_encode(['valid' => (bool)($row && password_verify($password, $row['password']))]);
}

function auth_change_password($body) {
    $user        = require_auth();
    $currentPass = $body['currentPassword'] ?? '';
    $newPass     = $body['newPassword'] ?? '';

    if (!$currentPass || !$newPass) {
        http_response_code(400);
        echo json_encode(['message' => 'Brakuje wymaganych pól.']);
        return;
    }
    if (mb_strlen($newPass) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Nowe hasło musi mieć minimum 6 znaków.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row  = $stmt->fetch();

    if (!$row || !password_verify($currentPass, $row['password'])) {
        http_response_code(401);
        echo json_encode(['message' => 'Nieprawidłowe obecne hasło.']);
        return;
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user['id']]);

    echo json_encode(['success' => true]);
}
