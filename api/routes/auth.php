<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/uuid.php';

function handle_auth($method, $action, $body) {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_login($body);
            break;
        case 'register':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_register($body);
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

function build_jwt_payload(array $user, PDO $pdo): array {
    // Uprawnienia
    $stmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $permissions = array_column($stmt->fetchAll(), 'permission');

    // Firma + plan
    $stmt = $pdo->prepare('
        SELECT c.plan_id, p.has_customers, p.has_actions, p.max_tires
        FROM companies c
        JOIN plans p ON c.plan_id = p.id
        WHERE c.id = ?
    ');
    $stmt->execute([$user['company_id']]);
    $planRow = $stmt->fetch();

    // Funkcjonalności z planu
    $features = [];
    if ($planRow && $planRow['has_customers']) $features[] = 'customers';
    if ($planRow && $planRow['has_actions'])   $features[] = 'actions';

    // Funkcjonalności z poolów
    $stmt = $pdo->prepare('
        SELECT DISTINCT pf.feature_name
        FROM pool_members pm
        JOIN pool_features pf ON pm.pool_id = pf.pool_id
        WHERE pm.user_id = ?
    ');
    $stmt->execute([$user['id']]);
    $poolFeatures = array_column($stmt->fetchAll(), 'feature_name');
    $features = array_values(array_unique(array_merge($features, $poolFeatures)));

    return [
        'id'          => $user['id'],
        'username'    => $user['username'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'company_id'  => $user['company_id'],
        'plan_id'     => $planRow['plan_id'] ?? 'free',
        'features'    => $features,
        'permissions' => $permissions,
        'iat'         => time(),
        'exp'         => time() + 8 * 3600,
    ];
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
    $stmt = $pdo->prepare('SELECT u.*, c.status AS company_status FROM users u JOIN companies c ON u.company_id = c.id WHERE u.username = ? OR u.email = ?');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['message' => 'Nieprawidłowy login lub hasło.']);
        return;
    }

    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['message' => 'Konto oczekuje na aktywację przez administratora.']);
        return;
    }

    if ($user['company_status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['message' => 'Konto firmy jest nieaktywne. Skontaktuj się z administratorem.']);
        return;
    }

    $payload = build_jwt_payload($user, $pdo);
    echo json_encode(['token' => jwt_encode($payload, JWT_SECRET), 'user' => $payload]);
}

function auth_register($body) {
    $company = $body['company'] ?? [];
    $userData = $body['user']   ?? [];
    $planId   = trim($body['plan_id'] ?? 'free');

    $companyName = trim($company['name']        ?? '');
    $companyNip  = trim($company['nip']         ?? '');
    $address     = trim($company['address']     ?? '');
    $city        = trim($company['city']        ?? '');
    $postalCode  = trim($company['postal_code'] ?? '');
    $phone       = trim($company['phone']       ?? '');
    $companyEmail = trim($company['email']      ?? '');

    $username = trim($userData['username'] ?? '');
    $password = $userData['password'] ?? '';

    if (!$companyName || !$companyNip || !$phone || !$address || !$city || !$postalCode || !$companyEmail || !$username || !$password) {
        http_response_code(400);
        echo json_encode(['message' => 'Wszystkie pola są wymagane.']);
        return;
    }

    if (!filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['message' => 'Podaj poprawny adres e-mail firmy.']);
        return;
    }

    if (mb_strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Hasło musi mieć minimum 6 znaków.']);
        return;
    }

    $pdo = get_pdo();

    // Sprawdź dostępność planu
    $planStmt = $pdo->prepare('SELECT id FROM plans WHERE id = ? AND is_active = 1');
    $planStmt->execute([$planId]);
    if (!$planStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['message' => 'Wybrany pakiet nie istnieje.']);
        return;
    }

    // Sprawdź unikalność loginu
    $dup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $dup->execute([$username]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['message' => 'Nazwa użytkownika jest już zajęta.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $companyId = generate_uuid();
        $pdo->prepare('INSERT INTO companies (id, name, nip, address, city, postal_code, phone, email, plan_id, status) VALUES (?,?,?,?,?,?,?,?,?,\'pending\')')
            ->execute([$companyId, $companyName, $companyNip ?: null, $address ?: null, $city ?: null, $postalCode ?: null, $phone ?: null, $companyEmail, $planId]);

        $userId   = generate_uuid();
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('INSERT INTO users (id, username, email, password, role, company_id, status) VALUES (?,?,?,?,\'pracownik\',?,\'inactive\')')
            ->execute([$userId, $username, $companyEmail, $hash, $companyId]);

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Rejestracja przyjęta. Konto zostanie aktywowane po weryfikacji przez administratora.',
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera podczas rejestracji.']);
    }
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

    if (!defined('APP_URL') || !defined('MAIL_FROM') || !defined('MAIL_FROM_NAME')) {
        http_response_code(500);
        echo json_encode(['message' => 'Błąd konfiguracji serwera: brakuje APP_URL, MAIL_FROM lub MAIL_FROM_NAME w config.php.']);
        return;
    }

    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT id, username, company_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => true]);
            return;
        }

        $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())')
            ->execute([$email, $token]);

        $resetUrl = rtrim(APP_URL, '/') . '/reset-password.html?token=' . $token;
        $subject  = '=?UTF-8?B?' . base64_encode('Resetowanie hasła — Przechowalnia Opon') . '?=';
        $message  = "Cześć " . $user['username'] . ",\r\n\r\n"
                  . "Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta.\r\n\r\n"
                  . "Kliknij poniższy link, aby ustawić nowe hasło (link ważny 1 godzinę):\r\n"
                  . $resetUrl . "\r\n\r\n"
                  . "Jeśli to nie Ty wysłałeś(-aś) tę prośbę, zignoruj tę wiadomość.\r\n\r\n"
                  . "Pozdrawiamy,\r\nZespół Przechowalnia Opon";

        $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
                 . "Reply-To: " . MAIL_FROM . "\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "X-Mailer: PHP/" . phpversion();

        $sent = mail($email, $subject, $message, $headers);
        if (!$sent) {
            http_response_code(500);
            echo json_encode(['message' => 'Nie udało się wysłać e-maila.']);
            return;
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera: ' . $e->getMessage()]);
    }
}

function auth_reset_password($body) {
    $token   = trim($body['token'] ?? '');
    $newPass = $body['newPassword'] ?? '';

    if (!$token) {
        http_response_code(400);
        echo json_encode(['message' => 'Brak tokenu resetowania.']);
        return;
    }

    try {
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

        if (!$newPass || mb_strlen($newPass) < 6) {
            http_response_code(400);
            echo json_encode(['message' => 'Hasło musi mieć minimum 6 znaków.']);
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
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera: ' . $e->getMessage()]);
    }
}
