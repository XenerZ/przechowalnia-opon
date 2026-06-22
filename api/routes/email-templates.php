<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_email_templates($method, $id, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($id) {
        switch ($method) {
            case 'GET':    email_tpl_get($id);         break;
            case 'PUT':    email_tpl_update($id, $body); break;
            case 'DELETE': email_tpl_delete($id);      break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  email_tpl_list();          break;
            case 'POST': email_tpl_create($body);   break;
            default: method_not_allowed();
        }
    }
}

function email_tpl_list() {
    $pdo  = get_pdo();
    $rows = $pdo->query('SELECT id, name, subject, created_at AS createdAt, updated_at AS updatedAt FROM email_templates ORDER BY id')->fetchAll();
    echo json_encode(array_values($rows));
}

function email_tpl_get($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, subject, html_content AS htmlContent, created_at AS createdAt, updated_at AS updatedAt FROM email_templates WHERE id = ?');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono szablonu.']); return; }
    echo json_encode($row);
}

function email_tpl_create($body) {
    $name    = trim($body['name']        ?? '');
    $subject = trim($body['subject']     ?? '');
    $html    = trim($body['htmlContent'] ?? '');
    if (!$name || !$subject || !$html) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje wymaganych pól.']); return;
    }
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO email_templates (name, subject, html_content) VALUES (?, ?, ?)');
    $stmt->execute([$name, $subject, $html]);
    email_tpl_get($pdo->lastInsertId());
}

function email_tpl_update($id, $body) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM email_templates WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono szablonu.']); return; }

    $name    = trim($body['name']        ?? '');
    $subject = trim($body['subject']     ?? '');
    $html    = trim($body['htmlContent'] ?? '');
    if (!$name || !$subject || !$html) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje wymaganych pól.']); return;
    }
    $pdo->prepare('UPDATE email_templates SET name = ?, subject = ?, html_content = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$name, $subject, $html, $id]);
    email_tpl_get($id);
}

function email_tpl_delete($id) {
    $pdo = get_pdo();
    $pdo->prepare('UPDATE actions SET email_template_id = NULL WHERE email_template_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
}
