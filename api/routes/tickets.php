<?php
require_once __DIR__ . '/../helpers/auth.php';

function handle_tickets($method, $id, $sub, $body) {
    $user = require_auth();
    $pdo  = get_pdo();

    if (!$id) {
        if ($method === 'GET') {
            // komentarze wewnętrzne (is_internal=1) są niewidoczne dla klienta —
            // wykluczone z licznika, daty i znacznika ostatniej odpowiedzi
            $stmt = $pdo->prepare('
                SELECT t.*,
                       (SELECT COUNT(*)        FROM ticket_messages WHERE ticket_id = t.id AND is_internal = 0) AS message_count,
                       (SELECT MAX(created_at) FROM ticket_messages WHERE ticket_id = t.id AND is_internal = 0) AS last_message_at,
                       (SELECT m.is_support FROM ticket_messages m
                          WHERE m.ticket_id = t.id AND m.is_internal = 0
                          ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_is_support
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
        // LEFT JOIN — wiadomości supportu mają author_id z tabeli support_users,
        // więc INNER JOIN users gubiłby odpowiedzi supportera
        $mStmt = $pdo->prepare('
            SELECT tm.id, tm.ticket_id, tm.is_support, tm.message, tm.created_at,
                   CASE WHEN tm.is_support=1 THEN su.username ELSE u.username END AS author_username
            FROM ticket_messages tm
            LEFT JOIN users u          ON tm.is_support=0 AND tm.author_id = u.id
            LEFT JOIN support_users su ON tm.is_support=1 AND tm.author_id = su.id
            WHERE tm.ticket_id = ? AND tm.is_internal = 0
            ORDER BY tm.created_at ASC
        ');
        $mStmt->execute([$id]);
        $ticket['messages'] = $mStmt->fetchAll();
        echo json_encode($ticket);
        return;
    }

    if ($sub === 'reply' && $method === 'POST') {
        if ((int)$ticket['is_closed'] === 1) {
            http_response_code(403);
            echo json_encode(['message' => 'Zgłoszenie jest zamknięte — nie można dodać odpowiedzi.']);
            return;
        }
        $message = trim($body['message'] ?? '');
        if (!$message) { http_response_code(400); echo json_encode(['message' => 'Wiadomość nie może być pusta.']); return; }
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author_id, is_support, message) VALUES (?,?,0,?)')
            ->execute([$id, $user['id'], $message]);
        // odpowiedź klienta przywraca ticket na listę aktywnych supportu (etykieta statusu bez zmian)
        $pdo->prepare("UPDATE tickets SET is_active=1 WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        return;
    }

    if ($sub === 'close' && $method === 'POST') {
        // zamknięcie to osobny mechanizm — nie zmienia etykiety statusu
        $pdo->prepare("UPDATE tickets SET is_closed=1, is_active=0 WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        return;
    }

    http_response_code(404); echo json_encode(['message' => 'Nieznana akcja.']);
}
