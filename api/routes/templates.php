<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_templates($method, $id, $body) {
    $user       = require_auth();
    $company_id = $user['company_id'];

    if ($id) {
        switch ($method) {
            case 'GET':    templates_get($id, $company_id);             break;
            case 'PUT':    require_permission($user, 'manage_users'); templates_update($id, $body, $company_id); break;
            case 'DELETE': require_permission($user, 'manage_users'); templates_delete($id, $company_id);        break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  templates_list($company_id);                                                     break;
            case 'POST': require_permission($user, 'manage_users'); templates_create($body, $company_id); break;
            default: method_not_allowed();
        }
    }
}

function templates_list($company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, page_size AS pageSize, created_at AS createdAt, updated_at AS updatedAt FROM templates WHERE company_id = ? ORDER BY id');
    $stmt->execute([$company_id]);
    echo json_encode($stmt->fetchAll());
}

function templates_get($id, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, html_content AS htmlContent, page_size AS pageSize, created_at AS createdAt, updated_at AS updatedAt FROM templates WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    $row  = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['message' => 'Szablon nie istnieje.']);
        return;
    }
    echo json_encode($row);
}

function templates_create($body, $company_id) {
    $name        = trim($body['name'] ?? '');
    $htmlContent = $body['htmlContent'] ?? '';
    $pageSize    = $body['pageSize']    ?? 'A4';

    if (!$name) {
        http_response_code(400);
        echo json_encode(['message' => 'Nazwa szablonu jest wymagana.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO templates (name, html_content, page_size, company_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $htmlContent, $pageSize, $company_id]);
    $newId = $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT id, name, page_size AS pageSize, created_at AS createdAt FROM templates WHERE id = ?');
    $row->execute([$newId]);
    http_response_code(201);
    echo json_encode($row->fetch());
}

function templates_update($id, $body, $company_id) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT id FROM templates WHERE id = ? AND company_id = ?');
    $exists->execute([$id, $company_id]);
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['message' => 'Szablon nie istnieje.']);
        return;
    }

    $fields = [];
    $vals   = [];
    if (isset($body['name']))        { $fields[] = 'name = ?';         $vals[] = trim($body['name']); }
    if (isset($body['htmlContent'])) { $fields[] = 'html_content = ?'; $vals[] = $body['htmlContent']; }
    if (isset($body['pageSize']))    { $fields[] = 'page_size = ?';    $vals[] = $body['pageSize']; }
    $fields[] = 'updated_at = NOW()';

    if (count($fields) === 1) {
        http_response_code(400);
        echo json_encode(['message' => 'Brak pól do aktualizacji.']);
        return;
    }

    $vals[] = $id;
    $vals[] = $company_id;
    $pdo->prepare('UPDATE templates SET ' . implode(', ', $fields) . ' WHERE id = ? AND company_id = ?')->execute($vals);

    $row = $pdo->prepare('SELECT id, name, updated_at AS updatedAt FROM templates WHERE id = ?');
    $row->execute([$id]);
    echo json_encode($row->fetch());
}

function templates_delete($id, $company_id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM templates WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $company_id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Szablon nie istnieje.']);
        return;
    }
    echo json_encode(['success' => true]);
}
