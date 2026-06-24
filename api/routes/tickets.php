<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_tickets($method, $id, $sub, $body) {
    $user = require_auth();
    $pdo  = get_pdo();

    if (!$id) {
        if ($method === 'GET') {
            $stmt = $pdo->prepare('
                SELECT t.*,
                       (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) AS message_count,
                       (SELECT MAX(created_at) FROM ticket_messages WHERE ticket_id = t.id) AS last_message_at
                FROM tickets t
                WHERE t.company_id = ?
                ORDER BY t.updated_at DESC
            ');
            $stmt->execute([$user['company_id']]);
            echo json_encode($stmt->fetchAll());
            return;
        }
        if ($method === 'POST') {
            $subject  = trim($body['subject']  ?? '');
            $message  = trim($body['message']  ?? '');
            $priority = $body['priority'] ?? 'normal';
            if (!$subject || !$message) {
                http_response_code(400);
                echo json_encode(['message' => 'Temat i wiadomość są wymagane.']);
                return;
            }
            if (!in_array($priority, ['low','normal','high','urgent'])) $priority = 'normal';

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO tickets (company_id, user_id, subject, priority) VALUES (?,?,?,?)')
                    ->execute([$user['company_id'], $user['id'], $subject, $priority]);
                $ticketId = $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author_id, is_support, message) VALUES (?,?,0,?)')
                    ->execute([$ticketId, $user['id'], $message]);
                $pdo->commit();
                http_response_code(201);
                echo json_encode(['success' => true, 'id' => (int)$ticketId]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['message' => 'Błąd serwera.']);
            }
            return;
        }
        method_not_allowed(); return;
    }

    $tStmt = $pdo->prepare('SELECT * FROM tickets WHERE id=? AND company_id=?');
    $tStmt->execute([$id, $user['company_id']]);
    $ticket = $tStmt->fetch();
    if (!$ticket) { http_response_code(404); echo json_encode(['message' => 'Ticket nie znaleziony.']); return; }

    if (!$sub && $method === 'GET') {
        $mStmt = $pdo->prepare('
            SELECT tm.*, u.username AS author_username
            FROM ticket_messages tm JOIN users u ON tm.author_id = u.id
            WHERE tm.ticket_id = ?
            ORDER BY tm.created_at ASC
        ');
        $mStmt->execute([$id]);
        $ticket['messages'] = $mStmt->fetchAll();
        echo json_encode($ticket);
        return;
    }

    if ($sub === 'reply' && $method === 'POST') {
        if (in_array($ticket['status'], ['resolved','closed'])) {
            $pdo->prepare("UPDATE tickets SET status='open' WHERE id=?")->execute([$id]);
        }
        $message = trim($body['message'] ?? '');
        if (!$message) { http_response_code(400); echo json_encode(['message' => 'Wiadomość nie może być pusta.']); return; }
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author_id, is_support, message) VALUES (?,?,0,?)')
            ->execute([$id, $user['id'], $message]);
        $pdo->prepare("UPDATE tickets SET status='open' WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        return;
    }

    http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
}
