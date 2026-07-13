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
        case 'refresh':
            if ($method !== 'POST') { method_not_allowed(); return; }
            auth_refresh();
            break;
        case 'account':
            if ($method !== 'POST' && $method !== 'PUT') { method_not_allowed(); return; }
            auth_update_account($body);
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
        case 'impersonation':
            if ($method !== 'GET') { method_not_allowed(); return; }
            auth_impersonation();
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

    // Info o zaległości (okres karencji przed blokadą) — do baneru w aplikacji
    require_once __DIR__ . '/../helpers/billing.php';
    $bill = billing_state($pdo, $user['company_id']);
    $billingInfo = ($bill['overdue'] && !$bill['blocked'])
        ? ['overdue' => true, 'daysOverdue' => $bill['daysOverdue'],
           'blockInDays' => max(0, $bill['graceDays'] - $bill['daysOverdue']), 'nextBillingAt' => $bill['nextBillingAt']]
        : null;

    return [
        'id'          => $user['id'],
        'username'    => $user['username'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'company_id'  => $user['company_id'],
        'plan_id'     => $planRow['plan_id'] ?? 'free',
        'features'    => $features,
        'permissions' => $permissions,
        'billing'     => $billingInfo,
        'iat'         => time(),
        'exp'         => time() + 30 * 24 * 3600, // 30 dni — sesja trwała (localStorage)
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
        // suspend_reason pobierane defensywnie — kolumna może nie istnieć przed migracją
        $reason = '';
        try {
            $rs = $pdo->prepare('SELECT suspend_reason FROM companies WHERE id = ?');
            $rs->execute([$user['company_id']]);
            $reason = (string)($rs->fetchColumn() ?: '');
        } catch (Throwable $e) { $reason = ''; }
        $billingBlock = $reason === 'billing';
        http_response_code(403);
        echo json_encode([
            'message' => $billingBlock
                ? 'Konto zablokowane z powodu zaległej płatności. Ureguluj fakturę, aby odblokować dostęp.'
                : 'Konto firmy jest nieaktywne. Skontaktuj się z administratorem.',
            'billing_blocked' => $billingBlock,
        ]);
        return;
    }

    // Zaległość >10 dni po terminie i brak opłaconej faktury → automatyczna blokada.
    // Wszystko defensywnie: problem z rozliczeniami nie może zablokować logowania.
    require_once __DIR__ . '/../helpers/billing.php';
    $bill = billing_state($pdo, $user['company_id']);
    if ($bill['blocked']) {
        try { $pdo->prepare("UPDATE companies SET status='suspended', suspend_reason='billing' WHERE id=?")->execute([$user['company_id']]); } catch (Throwable $e) {}
        http_response_code(403);
        echo json_encode([
            'message' => 'Konto zablokowane z powodu zaległej płatności (ponad ' . $bill['graceDays'] . ' dni po terminie). Ureguluj fakturę, aby odblokować dostęp.',
            'billing_blocked' => true,
        ]);
        return;
    }

    $payload = build_jwt_payload($user, $pdo);
    echo json_encode(['token' => jwt_encode($payload, JWT_SECRET), 'user' => $payload]);
}

// Samodzielna edycja danych konta przez użytkownika (login + e-mail)
function auth_update_account($body) {
    $current  = require_auth();
    $username = trim($body['username'] ?? '');
    $email    = trim($body['email'] ?? '');

    if (!$username || !$email) {
        http_response_code(400); echo json_encode(['message' => 'Login i e-mail są wymagane.']); return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['message' => 'Nieprawidłowy adres e-mail.']); return;
    }

    $pdo = get_pdo();
    $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
    $dup->execute([$username, $current['id']]);
    if ($dup->fetch()) {
        http_response_code(409); echo json_encode(['message' => 'Nazwa użytkownika jest już zajęta.']); return;
    }

    $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?')
        ->execute([$username, $email, $current['id']]);
    echo json_encode(['success' => true, 'username' => $username, 'email' => $email]);
}

// Ponowne wydanie tokenu z aktualnego stanu bazy (plan/uprawnienia/rola mogły się zmienić)
function auth_refresh() {
    $current = require_auth();
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT u.*, c.status AS company_status FROM users u JOIN companies c ON u.company_id = c.id WHERE u.id = ?');
    $stmt->execute([$current['id']]);
    $user = $stmt->fetch();
    if (!$user) { http_response_code(404); echo json_encode(['message' => 'Użytkownik nie istnieje.']); return; }
    if ($user['status'] !== 'active' || $user['company_status'] !== 'active') {
        http_response_code(403); echo json_encode(['message' => 'Konto jest nieaktywne.']); return;
    }
    $payload = build_jwt_payload($user, $pdo);
    if (!empty($current['impersonated_by'])) $payload['impersonated_by'] = $current['impersonated_by'];
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

    $nipDigits = preg_replace('/[\s\-]/', '', $companyNip);
    if (!preg_match('/^\d{10}$/', $nipDigits)) {
        http_response_code(400);
        echo json_encode(['message' => 'Nieprawidłowy NIP — wymagane 10 cyfr.']);
        return;
    }
    $weights = [6,5,7,2,3,4,5,6,7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (int)$nipDigits[$i] * $weights[$i];
    if (($sum % 11) !== (int)$nipDigits[9]) {
        http_response_code(400);
        echo json_encode(['message' => 'Nieprawidłowy NIP — błędna suma kontrolna.']);
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
        // Główne konto firmy = administrator z pełnymi uprawnieniami
        $pdo->prepare('INSERT INTO users (id, username, email, password, role, company_id, status) VALUES (?,?,?,?,\'admin\',?,\'inactive\')')
            ->execute([$userId, $username, $companyEmail, $hash, $companyId]);

        $permStmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
        foreach (['manage_users','add_entries','edit_entries','delete_entries'] as $perm) {
            $permStmt->execute([$userId, $perm]);
        }

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

function auth_impersonation() {
    $token = trim($_GET['token'] ?? '');
    if (!$token) { http_response_code(400); echo json_encode(['message' => 'Brak tokenu.']); return; }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM impersonation_tokens WHERE token=? AND used=0 AND expires_at > NOW()');
    $stmt->execute([$token]);
    $imp = $stmt->fetch();
    if (!$imp) { http_response_code(400); echo json_encode(['message' => 'Token jest nieważny lub wygasł.']); return; }

    $pdo->prepare('UPDATE impersonation_tokens SET used=1 WHERE token=?')->execute([$token]);

    $uStmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $uStmt->execute([$imp['target_user_id']]);
    $targetUser = $uStmt->fetch();
    if (!$targetUser) { http_response_code(404); echo json_encode(['message' => 'Użytkownik nie znaleziony.']); return; }

    $payload = build_jwt_payload($targetUser, $pdo);
    $payload['impersonated_by'] = $imp['created_by'];
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
