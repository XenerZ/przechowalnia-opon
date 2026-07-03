<?php
require_once __DIR__ . '/../helpers/auth.php';

// Pobranie pliku faktury (PDF) — tylko dla właściciela (firmy zalogowanego użytkownika)
function handle_invoices($method, $id, $sub, $body) {
    $user = require_auth();

    if ($method === 'GET' && $id && $sub === 'file') {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT file_data, file_name, file_mime FROM invoices WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $user['company_id']]);
        $row = $stmt->fetch();
        if (!$row || $row['file_data'] === null) {
            http_response_code(404);
            echo json_encode(['message' => 'Faktura nie ma dołączonego pliku.']);
            return;
        }
        $name = $row['file_name'] ?: ('faktura-' . $id . '.pdf');
        // nadpisujemy nagłówek JSON ustawiony w index.php
        header('Content-Type: ' . ($row['file_mime'] ?: 'application/pdf'));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . strlen($row['file_data']));
        echo $row['file_data'];
        return;
    }

    http_response_code(404);
    echo json_encode(['message' => 'Nie znaleziono.']);
}
