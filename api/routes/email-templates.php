<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_email_templates($method, $id, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');
    require_feature($user, 'actions');

    $company_id = $user['company_id'];

    if ($id) {
        switch ($method) {
            case 'GET':    email_tpl_get($id, $company_id);          break;
            case 'PUT':    email_tpl_update($id, $body, $company_id); break;
            case 'DELETE': email_tpl_delete($id, $company_id);       break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  email_tpl_list($company_id);          break;
            case 'POST': email_tpl_create($body, $company_id); break;
            default: method_not_allowed();
        }
    }
}

function email_tpl_list($company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, subject, created_at AS createdAt, updated_at AS updatedAt FROM email_templates WHERE company_id = ? ORDER BY id');
    $stmt->execute([$company_id]);
    echo json_encode(array_values($stmt->fetchAll()));
}

function email_tpl_get($id, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, subject, html_content AS htmlContent, created_at AS createdAt, updated_at AS updatedAt FROM email_templates WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    $row  = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono szablonu.']); return; }
    echo json_encode($row);
}

function email_tpl_create($body, $company_id) {
    $name    = trim($body['name']        ?? '');
    $subject = trim($body['subject']     ?? '');
    $html    = trim($body['htmlContent'] ?? '');
    if (!$name || !$subject || !$html) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje wymaganych pól.']); return;
    }
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO email_templates (name, subject, html_content, company_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $subject, $html, $company_id]);
    email_tpl_get($pdo->lastInsertId(), $company_id);
}

function email_tpl_update($id, $body, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM email_templates WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono szablonu.']); return; }

    $name    = trim($body['name']        ?? '');
    $subject = trim($body['subject']     ?? '');
    $html    = trim($body['htmlContent'] ?? '');
    if (!$name || !$subject || !$html) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje wymaganych pól.']); return;
    }
    $pdo->prepare('UPDATE email_templates SET name = ?, subject = ?, html_content = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
        ->execute([$name, $subject, $html, $id, $company_id]);
    email_tpl_get($id, $company_id);
}

function email_tpl_delete($id, $company_id) {
    $pdo = get_pdo();
    $pdo->prepare('UPDATE actions SET email_template_id = NULL WHERE email_template_id = ? AND company_id = ?')->execute([$id, $company_id]);
    $pdo->prepare('DELETE FROM email_templates WHERE id = ? AND company_id = ?')->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
}
