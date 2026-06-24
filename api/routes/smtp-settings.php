<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_smtp_settings($method, $id, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    $company_id = $user['company_id'];

    if ($method === 'GET')                     { smtp_get($company_id);          return; }
    if ($method === 'PUT')                     { smtp_save($body, $company_id);  return; }
    if ($method === 'POST' && $id === 'test')  { smtp_test($body, $company_id);  return; }
    if ($method === 'DELETE')                  { smtp_clear($company_id);        return; }
    method_not_allowed();
}

function smtp_get($company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE company_id = ? AND `key` LIKE 'smtp_%'");
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows['smtp_pass_set'] = !empty($rows['smtp_pass']) ? '1' : '0';
    unset($rows['smtp_pass']);
    echo json_encode($rows);
}

function smtp_save($body, $company_id) {
    $pdo    = get_pdo();
    $fields = ['smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','smtp_from_email','smtp_from_name'];
    $stmt   = $pdo->prepare("INSERT INTO settings (company_id, `key`, `value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=?");
    foreach ($fields as $key) {
        if (!array_key_exists($key, $body)) continue;
        $val = trim((string)$body[$key]);
        if ($key === 'smtp_pass' && $val === '') continue;
        $stmt->execute([$company_id, $key, $val, $val]);
    }
    smtp_get($company_id);
}

function smtp_clear($company_id) {
    $pdo = get_pdo();
    $pdo->prepare("DELETE FROM settings WHERE company_id = ? AND `key` LIKE 'smtp_%'")->execute([$company_id]);
    echo json_encode(['success' => true]);
}

function smtp_test($body, $company_id) {
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
          . '</div>',
            $company_id
        );
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => $e->getMessage()]);
    }
}
