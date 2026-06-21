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
        case 'forgot-password':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_forgot_password($body);
            break;
        case 'reset-password':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_reset_password($body);
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

function auth_forgot_password($body) {
    $email = trim($body['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['message' => 'Podaj poprawny adres e-mail.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Zawsze zwracamy sukces — nie ujawniamy czy email istnieje w systemie
    if (!$user) {
        echo json_encode(['success' => true]);
        return;
    }

    // Usuń stare tokeny dla tego emaila
    $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())')
        ->execute([$email, $token]);

    $resetUrl = APP_URL . '/reset-password.html?token=' . $token;
    $subject  = '=?UTF-8?B?' . base64_encode('Resetowanie hasła — Przechowalnia Opon') . '?=';
    $message  = "Cześć " . $user['username'] . ",\r\n\r\n"
              . "Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta.\r\n\r\n"
              . "Kliknij poniższy link, aby ustawić nowe hasło (link ważny 1 godzinę):\r\n"
              . $resetUrl . "\r\n\r\n"
              . "Jeśli to nie Ty wysłałeś(-aś) tę prośbę, zignoruj tę wiadomość.\r\n\r\n"
              . "Pozdrawiamy,\r\nZespół Przechowalnia Opon";

    $headers  = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
              . "Reply-To: " . MAIL_FROM . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "X-Mailer: PHP/" . phpversion();

    mail($email, $subject, $message, $headers);

    echo json_encode(['success' => true]);
}

function auth_reset_password($body) {
    $token   = trim($body['token'] ?? '');
    $newPass = $body['newPassword'] ?? '';

    if (!$token || !$newPass) {
        http_response_code(400);
        echo json_encode(['message' => 'Brakuje wymaganych pól.']);
        return;
    }
    if (mb_strlen($newPass) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Hasło musi mieć minimum 6 znaków.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM password_resets WHERE token = ? AND used = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        http_response_code(400);
        echo json_encode(['message' => 'Link jest nieważny lub wygasł. Wyślij nowy.']);
        return;
    }

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $userStmt->execute([$reset['email']]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(400);
        echo json_encode(['message' => 'Konto nie istnieje.']);
        return;
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user['id']]);
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);

    echo json_encode(['success' => true]);
}
