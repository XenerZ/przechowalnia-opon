<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_pools($method, $id, $sub, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($id && $sub === 'members' && $method === 'PUT') {
        pools_set_members($id, $body);
        return;
    }
    if ($id && $sub === 'features' && $method === 'PUT') {
        pools_set_features($id, $body);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'GET':    pools_get($id);            break;
            case 'PUT':    pools_update($id, $body);  break;
            case 'DELETE': pools_delete($id);         break;
            default:       method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  pools_list();          break;
            case 'POST': pools_create($body);   break;
            default:     method_not_allowed();
        }
    }
}

function pools_format($row, PDO $pdo) {
    $stmt = $pdo->prepare('SELECT feature_name FROM pool_features WHERE pool_id = ?');
    $stmt->execute([$row['id']]);
    $features = array_column($stmt->fetchAll(), 'feature_name');

    $stmt = $pdo->prepare('
        SELECT u.id, u.username, u.email, c.name AS companyName
        FROM pool_members pm
        JOIN users u ON pm.user_id = u.id
        JOIN companies c ON u.company_id = c.id
        WHERE pm.pool_id = ?
        ORDER BY u.username
    ');
    $stmt->execute([$row['id']]);
    $members = $stmt->fetchAll();

    return [
        'id'          => (int)$row['id'],
        'name'        => $row['name'],
        'description' => $row['description'],
        'features'    => $features,
        'members'     => $members,
        'createdAt'   => $row['created_at'] ? substr($row['created_at'], 0, 10) : null,
    ];
}

function pools_list() {
    $pdo  = get_pdo();
    $stmt = $pdo->query('SELECT * FROM pools ORDER BY id');
    $rows = $stmt->fetchAll();
    echo json_encode(array_map(fn($r) => pools_format($r, $pdo), $rows));
}

function pools_get($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM pools WHERE id = ?');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Pool nie istnieje.']); return; }
    echo json_encode(pools_format($row, $pdo));
}

function pools_create($body) {
    $name = trim($body['name'] ?? '');
    if (!$name) {
        http_response_code(400);
        echo json_encode(['message' => 'Nazwa poola jest wymagana.']);
        return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO pools (name, description) VALUES (?, ?)');
    $stmt->execute([$name, $body['description'] ?? null]);
    $newId = (int)$pdo->lastInsertId();

    if (!empty($body['features']) && is_array($body['features'])) {
        $fs = $pdo->prepare('INSERT INTO pool_features (pool_id, feature_name) VALUES (?, ?)');
        foreach ($body['features'] as $f) {
            $fs->execute([$newId, trim($f)]);
        }
    }

    http_response_code(201);
    pools_get($newId);
}

function pools_update($id, $body) {
    $pdo    = get_pdo();
    $exists = $pdo->prepare('SELECT id FROM pools WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) { http_response_code(404); echo json_encode(['message' => 'Pool nie istnieje.']); return; }

    $fields = [];
    $vals   = [];
    if (isset($body['name']))        { $fields[] = 'name = ?';        $vals[] = trim($body['name']); }
    if (isset($body['description'])) { $fields[] = 'description = ?'; $vals[] = $body['description']; }
    if ($fields) {
        $vals[] = $id;
        $pdo->prepare('UPDATE pools SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    }
    pools_get($id);
}

function pools_delete($id) {
    $pdo = get_pdo();
    $pdo->prepare('DELETE FROM pools WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
}

function pools_set_features($id, $body) {
    $features = $body['features'] ?? [];
    $pdo      = get_pdo();

    $exists = $pdo->prepare('SELECT id FROM pools WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) { http_response_code(404); echo json_encode(['message' => 'Pool nie istnieje.']); return; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM pool_features WHERE pool_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO pool_features (pool_id, feature_name) VALUES (?, ?)');
        foreach ($features as $f) {
            if ($f = trim($f)) $stmt->execute([$id, $f]);
        }
        $pdo->commit();
        pools_get($id);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}

function pools_set_members($id, $body) {
    $userIds = $body['user_ids'] ?? [];
    $pdo     = get_pdo();

    $exists = $pdo->prepare('SELECT id FROM pools WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) { http_response_code(404); echo json_encode(['message' => 'Pool nie istnieje.']); return; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM pool_members WHERE pool_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO pool_members (pool_id, user_id) VALUES (?, ?)');
        foreach ($userIds as $uid) {
            if ($uid = trim($uid)) $stmt->execute([$id, $uid]);
        }
        $pdo->commit();
        pools_get($id);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['message' => 'Błąd serwera.']);
    }
}
