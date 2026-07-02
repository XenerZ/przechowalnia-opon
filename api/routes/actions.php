<?php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/mailer.php';

function handle_actions($method, $id, $sub, $body) {
    $user = require_auth();
    require_permission($user, 'manage_users');
    require_feature($user, 'actions');

    if ($id && $sub === 'run'  && $method === 'POST') { action_run($id);         return; }
    if ($id && $sub === 'logs' && $method === 'GET')  { action_logs($id);        return; }

    if ($id) {
        switch ($method) {
            case 'GET':    action_get($id);            break;
            case 'PUT':    action_update($id, $body);  break;
            case 'DELETE': action_delete($id);         break;
            default: method_not_allowed();
        }
    } else {
        switch ($method) {
            case 'GET':  action_list();         break;
            case 'POST': action_create($body);  break;
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
    $triggerType = trim($body['triggerType'] ?? '');
    if (!$triggerType) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje typu wyzwalacza.']); return;
    }
    $name        = trim($body['name']          ?? '') ?: null;
    $triggerVal  = trim($body['triggerValue']  ?? '') ?: null;
    $tplId       = $body['emailTemplateId']    ?? null;
    $recType     = $body['recipientType']      ?? 'customer_email';
    $recEmail    = trim($body['recipientEmail'] ?? '') ?: null;
    $active      = isset($body['active']) ? (int)(bool)$body['active'] : 1;

    $pdo  = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO actions (name, trigger_type, trigger_value, email_template_id, recipient_type, recipient_email, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $triggerType, $triggerVal, $tplId ?: null, $recType, $recEmail, $active]);
    http_response_code(201);
    echo json_encode(action_row($pdo->lastInsertId()));
}

function action_update($id, $body) {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM actions WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['message' => 'Nie znaleziono akcji.']); return; }

    $triggerType = trim($body['triggerType'] ?? '');
    if (!$triggerType) {
        http_response_code(400); echo json_encode(['message' => 'Brakuje typu wyzwalacza.']); return;
    }
    $name       = trim($body['name']          ?? '') ?: null;
    $triggerVal = trim($body['triggerValue']  ?? '') ?: null;
    $tplId      = $body['emailTemplateId']    ?? null;
    $recType    = $body['recipientType']      ?? 'customer_email';
    $recEmail   = trim($body['recipientEmail'] ?? '') ?: null;
    $active     = isset($body['active']) ? (int)(bool)$body['active'] : 1;

    $pdo->prepare('UPDATE actions SET name=?, trigger_type=?, trigger_value=?, email_template_id=?, recipient_type=?, recipient_email=?, active=? WHERE id=?')
        ->execute([$name, $triggerType, $triggerVal, $tplId ?: null, $recType, $recEmail, $active, $id]);
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
        SELECT al.id, al.tire_id AS tireId, c.full_name AS clientName, te.license_plate AS licensePlate,
               al.recipient_email AS recipientEmail, al.status, al.error, al.sent_at AS sentAt
        FROM action_logs al
        LEFT JOIN tire_entries te ON te.id = al.tire_id
        LEFT JOIN customers c ON c.id = te.customer_id
        WHERE al.action_id = ?
        ORDER BY al.sent_at DESC
        LIMIT 100
    ');
    $rows->execute([$id]);
    echo json_encode(array_values($rows->fetchAll()));
}

/* ─────────────────────── Runner ─────────────────────── */

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
    switch ($action['trigger_type']) {
        case 'days_in_storage':       return run_days_in_storage($pdo, $action);
        case 'days_before_next_change': return run_days_before_next_change($pdo, $action);
        default: return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
    }
}

function run_days_in_storage($pdo, $action) {
    $days  = max(1, (int)($action['trigger_value'] ?? 30));
    $tires = $pdo->prepare("
        SELECT te.id, c.full_name AS fullName, c.email, c.phone, te.license_plate AS licensePlate,
               te.tire_width AS tireWidth, te.tire_profile AS tireProfile, te.tire_diameter AS tireDiameter,
               te.location, te.date_in AS dateIn, te.status, te.notes,
               te.next_tire_change AS nextTireChange,
               DATEDIFF(NOW(), te.date_in) AS daysStored
        FROM tire_entries te
        JOIN customers c ON c.id = te.customer_id
        WHERE te.status = 'W przechowalni'
          AND DATEDIFF(NOW(), te.date_in) >= ?
    ");
    $tires->execute([$days]);
    return send_action_emails($pdo, $action, $tires->fetchAll());
}

function run_days_before_next_change($pdo, $action) {
    $days  = max(1, (int)($action['trigger_value'] ?? 7));
    $tires = $pdo->prepare("
        SELECT te.id, c.full_name AS fullName, c.email, c.phone, te.license_plate AS licensePlate,
               te.tire_width AS tireWidth, te.tire_profile AS tireProfile, te.tire_diameter AS tireDiameter,
               te.location, te.date_in AS dateIn, te.status, te.notes,
               te.next_tire_change AS nextTireChange,
               DATEDIFF(NOW(), te.date_in) AS daysStored,
               DATEDIFF(te.next_tire_change, CURDATE()) AS daysToChange
        FROM tire_entries te
        JOIN customers c ON c.id = te.customer_id
        WHERE te.status = 'W przechowalni'
          AND te.next_tire_change IS NOT NULL
          AND DATEDIFF(te.next_tire_change, CURDATE()) BETWEEN 0 AND ?
    ");
    $tires->execute([$days]);
    return send_action_emails($pdo, $action, $tires->fetchAll());
}

function send_action_emails($pdo, $action, array $tires) {
    $tplHtml = $tplSubject = null;
    if ($action['email_template_id']) {
        $tplStmt = $pdo->prepare('SELECT subject, html_content FROM email_templates WHERE id = ?');
        $tplStmt->execute([$action['email_template_id']]);
        $tpl = $tplStmt->fetch();
        if ($tpl) { $tplHtml = $tpl['html_content']; $tplSubject = $tpl['subject']; }
    }

    $sent = $failed = $skipped = 0;
    $details = [];
    $logStmt = $pdo->prepare('INSERT INTO action_logs (action_id, tire_id, recipient_email, status, error) VALUES (?, ?, ?, ?, ?)');

    foreach ($tires as $tire) {
        // Duplikat w ciągu 23h
        $dup = $pdo->prepare("SELECT id FROM action_logs WHERE action_id = ? AND tire_id = ? AND status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 23 HOUR)");
        $dup->execute([$action['id'], $tire['id']]);
        if ($dup->fetch()) { $skipped++; continue; }

        // Wyznacz adres odbiorcy
        $recType = $action['recipient_type'] ?? 'customer_email';
        if ($recType === 'customer_email') {
            $recipient = $tire['email'] ?? null;
        } elseif ($recType === 'admin') {
            $cfg       = get_smtp_config();
            $recipient = $cfg ? $cfg['from_email'] : (defined('MAIL_FROM') ? MAIL_FROM : null);
        } else {
            $recipient = $action['recipient_email'] ?? null;
        }

        if (!$recipient) { $skipped++; continue; }

        $subject = fill_placeholders($tplSubject ?? 'Opony {{nr_rejestracyjny}} — przypomnienie', $tire);
        $body    = fill_placeholders($tplHtml    ?? default_email_body(), $tire);

        try {
            send_mail($recipient, $subject, $body);
            $status = 'sent';
            $errMsg = null;
            $sent++;
        } catch (Exception $e) {
            $status = 'failed';
            $errMsg = $e->getMessage();
            $failed++;
        }

        $logStmt->execute([$action['id'], $tire['id'], $recipient, $status, $errMsg]);
        $details[] = [
            'tire'      => $tire['licensePlate'],
            'recipient' => $recipient,
            'status'    => $status,
            'days'      => $tire['daysStored'] ?? null,
        ];
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'details' => $details];
}

function fill_placeholders($text, $tire) {
    $size = ($tire['tireWidth'] ?? '') . '/' . ($tire['tireProfile'] ?? '') . ' R' . ($tire['tireDiameter'] ?? '');
    $ntc  = $tire['nextTireChange'] ?? null;
    if ($ntc) {
        try { $dt = new DateTime($ntc); $ntc = $dt->format('d.m.Y'); } catch (Exception $e) {}
    }
    $map = [
        '{{imie_nazwisko}}'      => $tire['fullName']     ?? '',
        '{{nr_rejestracyjny}}'   => $tire['licensePlate'] ?? '',
        '{{email_klienta}}'      => $tire['email']        ?? '',
        '{{telefon}}'            => $tire['phone']        ?? '',
        '{{rozmiar_kol}}'        => $size,
        '{{lokalizacja}}'        => $tire['location']     ?? '',
        '{{data_przyjecia}}'     => $tire['dateIn']       ?? '',
        '{{dni_w_przechowalni}}' => (string)($tire['daysStored']   ?? ''),
        '{{data_zmiany}}'        => $ntc ?? '—',
        '{{dni_do_zmiany}}'      => isset($tire['daysToChange']) ? (string)$tire['daysToChange'] : '',
        '{{status}}'             => $tire['status']       ?? '',
        '{{uwagi}}'              => $tire['notes']        ?? '',
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
