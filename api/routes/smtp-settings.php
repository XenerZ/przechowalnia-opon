<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_smtp_settings($method, $id, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($method === 'GET')                        { smtp_get();          return; }
    if ($method === 'PUT')                        { smtp_save($body);    return; }
    if ($method === 'POST' && $id === 'test')     { smtp_test($body);    return; }
    if ($method === 'DELETE')                     { smtp_clear();        return; }
    method_not_allowed();
}

function smtp_get() {
    $pdo  = get_pdo();
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows['smtp_pass_set'] = !empty($rows['smtp_pass']) ? '1' : '0';
    unset($rows['smtp_pass']);
    echo json_encode($rows);
}

function smtp_save($body) {
    $pdo    = get_pdo();
    $fields = ['smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','smtp_from_email','smtp_from_name'];
    $stmt   = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?");
    foreach ($fields as $key) {
        if (!array_key_exists($key, $body)) continue;
        $val = trim((string)$body[$key]);
        if ($key === 'smtp_pass' && $val === '') continue;
        $stmt->execute([$key, $val, $val]);
    }
    smtp_get();
}

function smtp_clear() {
    $pdo = get_pdo();
    $pdo->exec("DELETE FROM settings WHERE `key` LIKE 'smtp_%'");
    echo json_encode(['success' => true]);
}

function smtp_test($body) {
    $email = trim($body['email'] ?? '');
    if (!$email) {
        http_response_code(400);
        echo json_encode(['message' => 'Podaj adres e-mail do testu.']);
        return;
    }
    require_once __DIR__ . '/../helpers/mailer.php';
    try {
        send_mail(
            $email,
            'Test połączenia — Przechowalnia Opon',
            '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">'
          . '<h2 style="color:#1a56db">Test SMTP</h2>'
          . '<p>Jeśli widzisz tę wiadomość, konfiguracja e-mail działa poprawnie.</p>'
          . '<p style="color:#888;font-size:.85rem">Wysłano: ' . date('Y-m-d H:i:s') . '</p>'
          . '</div>'
        );
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => $e->getMessage()]);
    }
}
