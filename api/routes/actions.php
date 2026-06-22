<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_actions($method, $id, $sub, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');

    if ($id && $sub === 'run' && $method === 'POST') {
        action_run($id); return;
    }
    if ($id && $sub === 'logs' && $method === 'GET') {
        action_logs($id); return;
    }

    if ($id) {
        switch ($method) {
            case 'GET':    action_get($id);         break;
            case 'PUT':    action_update($id, $body); break;
            case 'DELETE': action_delete($id);      break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  action_list();          break;
            case 'POST': action_create($body);   break;
            default: method_not_allowed();
        }
    }
}

function action_row($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('
        SELECT a.id, a.name, a.trigger_type AS triggerType, a.trigger_value AS triggerValue,
               a.email_template_id AS emailTemplateId, et.name AS emailTemplateName,
               a.recipient_type AS recipientType, a.recipient_email AS recipientEmail,
               a.active, a.last_run AS lastRun, a.created_at AS createdAt
        FROM actions a
        LEFT JOIN email_templates et ON et.id = a.email_template_id
        WHERE a.id = ?
    ');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function action_list() {
    $pdo  = get_pdo();
    $rows = $pdo->query('
        SELECT a.id, a.name, a.trigger_type AS triggerType, a.trigger_value AS triggerValue,
               a.email_template_id AS emailTemplateId, et.name AS emailTemplateName,
               a.recipient_type AS recipientType, a.recipient_email AS recipientEmail,
               a.active, a.last_run AS lastRun, a.created_at AS createdAt
        FROM actions a
        LEFT JOIN email_templates et ON et.id = a.email_template_id
        ORDER BY a.id
    ')->fetchAll();
    echo json_encode(array_values($rows));
}

function action_get($id) {
    $row = action_row($id);
    if (!$row) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono akcji.']); return; }
    echo json_encode($row);
}

function action_create($body) {
    $name        = trim($body['name']           ?? '');
    $triggerType = trim($body['triggerType']    ?? '');
    $triggerVal  = trim($body['triggerValue']   ?? '');
    $tplId       = $body['emailTemplateId']      ?? null;
    $recType     = $body['recipientType']        ?? 'custom';
    $recEmail    = trim($body['recipientEmail']  ?? '');
    $active      = isset($body['active']) ? (int)(bool)$body['active'] : 1;

    if (!$name || !$triggerType) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje wymaganych pól.']); return;
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO actions (name, trigger_type, trigger_value, email_template_id, recipient_type, recipient_email, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $triggerType, $triggerVal ?: null, $tplId ?: null, $recType, $recEmail ?: null, $active]);
    http_response_code(201);
    echo json_encode(action_row($pdo->lastInsertId()));
}

function action_update($id, $body) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM actions WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono akcji.']); return; }

    $name        = trim($body['name']           ?? '');
    $triggerType = trim($body['triggerType']    ?? '');
    $triggerVal  = trim($body['triggerValue']   ?? '');
    $tplId       = $body['emailTemplateId']      ?? null;
    $recType     = $body['recipientType']        ?? 'custom';
    $recEmail    = trim($body['recipientEmail']  ?? '');
    $active      = isset($body['active']) ? (int)(bool)$body['active'] : 1;

    $pdo->prepare('UPDATE actions SET name=?, trigger_type=?, trigger_value=?, email_template_id=?, recipient_type=?, recipient_email=?, active=? WHERE id=?')
        ->execute([$name, $triggerType, $triggerVal ?: null, $tplId ?: null, $recType, $recEmail ?: null, $active, $id]);
    echo json_encode(action_row($id));
}

function action_delete($id) {
    $pdo = get_pdo();
    $pdo->prepare('DELETE FROM action_logs WHERE action_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM actions WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
}

function action_logs($id) {
    $pdo  = get_pdo();
    $rows = $pdo->prepare('
        SELECT al.id, al.tire_id AS tireId, t.full_name AS clientName, t.license_plate AS licensePlate,
               al.recipient_email AS recipientEmail, al.status, al.error, al.sent_at AS sentAt
        FROM action_logs al
        LEFT JOIN tires t ON t.id = al.tire_id
        WHERE al.action_id = ?
        ORDER BY al.sent_at DESC
        LIMIT 100
    ');
    $rows->execute([$id]);
    echo json_encode(array_values($rows->fetchAll()));
}

/* ── Runner — wywołany ręcznie lub przez cron ────────────────────────────────── */
function action_run($id) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM actions WHERE id = ? AND active = 1');
    $stmt->execute([$id]);
    $action = $stmt->fetch();

    if (!$action) { http_response_code(404); echo json_encode(['message' => 'Akcja nie istnieje lub jest nieaktywna.']); return; }

    $result = run_action($pdo, $action);

    $pdo->prepare('UPDATE actions SET last_run = NOW() WHERE id = ?')->execute([$id]);
    echo json_encode($result);
}

function run_action($pdo, $action) {
    if ($action['trigger_type'] === 'days_in_storage') {
        return run_days_in_storage($pdo, $action);
    }
    return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
}

function run_days_in_storage($pdo, $action) {
    $days = (int)($action['trigger_value'] ?? 30);

    // Opony w przechowalni X lub więcej dni
    $tires = $pdo->prepare("
        SELECT t.id, t.full_name AS fullName, t.phone, t.license_plate AS licensePlate,
               t.tire_width AS tireWidth, t.tire_profile AS tireProfile, t.tire_diameter AS tireDiameter,
               t.location, t.date_in AS dateIn, t.status, t.notes,
               DATEDIFF(NOW(), t.date_in) AS daysStored
        FROM tires t
        WHERE t.status = 'W przechowalni'
          AND DATEDIFF(NOW(), t.date_in) >= ?
    ");
    $tires->execute([$days]);
    $tires = $tires->fetchAll();

    // Pobierz szablon e-mail
    $tplHtml    = null;
    $tplSubject = null;
    if ($action['email_template_id']) {
        $tplStmt = $pdo->prepare('SELECT subject, html_content FROM email_templates WHERE id = ?');
        $tplStmt->execute([$action['email_template_id']]);
        $tpl = $tplStmt->fetch();
        if ($tpl) { $tplHtml = $tpl['html_content']; $tplSubject = $tpl['subject']; }
    }

    $sent = 0; $failed = 0; $skipped = 0;
    $details = [];
    $logStmt = $pdo->prepare('INSERT INTO action_logs (action_id, tire_id, recipient_email, status, error) VALUES (?, ?, ?, ?, ?)');

    foreach ($tires as $tire) {
        // Czy już wysłano w ciągu ostatnich 23 godzin?
        $dup = $pdo->prepare("SELECT id FROM action_logs WHERE action_id = ? AND tire_id = ? AND status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 23 HOUR)");
        $dup->execute([$action['id'], $tire['id']]);
        if ($dup->fetch()) { $skipped++; continue; }

        $recipient = $action['recipient_email'] ?? null;
        if (!$recipient) { $skipped++; continue; }

        $subject = fill_placeholders($tplSubject ?? 'Opony w przechowalni — {{nr_rejestracyjny}}', $tire);
        $body    = fill_placeholders($tplHtml    ?? default_email_body(), $tire);

        $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n"
                 . "X-Mailer: PHP/" . phpversion();

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail($recipient, $encSubject, $body, $headers);

        $status = $ok ? 'sent' : 'failed';
        $ok ? $sent++ : $failed++;
        $logStmt->execute([$action['id'], $tire['id'], $recipient, $status, $ok ? null : 'mail() zwróciło false']);
        $details[] = ['tire' => $tire['licensePlate'], 'recipient' => $recipient, 'status' => $status, 'days' => $tire['daysStored']];
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'details' => $details];
}

function fill_placeholders($text, $tire) {
    $size  = ($tire['tireWidth'] ?? '') . '/' . ($tire['tireProfile'] ?? '') . ' R' . ($tire['tireDiameter'] ?? '');
    $map   = [
        '{{imie_nazwisko}}'    => $tire['fullName']    ?? '',
        '{{nr_rejestracyjny}}' => $tire['licensePlate'] ?? '',
        '{{telefon}}'          => $tire['phone']       ?? '',
        '{{rozmiar_kol}}'      => $size,
        '{{lokalizacja}}'      => $tire['location']    ?? '',
        '{{data_przyjecia}}'   => $tire['dateIn']      ?? '',
        '{{dni_w_przechowalni}}' => (string)($tire['daysStored'] ?? ''),
        '{{status}}'           => $tire['status']      ?? '',
        '{{uwagi}}'            => $tire['notes']       ?? '',
    ];
    return str_replace(array_keys($map), array_values($map), $text);
}

function default_email_body() {
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px">
  <h2 style="color:#1a56db">Przypomnienie o oponach w przechowalni</h2>
  <p>Opony klienta <strong>{{imie_nazwisko}}</strong> przebywają w przechowalni od <strong>{{dni_w_przechowalni}} dni</strong>.</p>
  <table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:16px">
    <tr><td style="padding:6px 0;font-weight:bold;width:45%">Nr rejestracyjny:</td><td>{{nr_rejestracyjny}}</td></tr>
    <tr><td style="padding:6px 0;font-weight:bold">Telefon:</td><td>{{telefon}}</td></tr>
    <tr><td style="padding:6px 0;font-weight:bold">Rozmiar:</td><td>{{rozmiar_kol}}</td></tr>
    <tr><td style="padding:6px 0;font-weight:bold">Lokalizacja:</td><td>{{lokalizacja}}</td></tr>
    <tr><td style="padding:6px 0;font-weight:bold">Data przyjęcia:</td><td>{{data_przyjecia}}</td></tr>
  </table>
</div>';
}
