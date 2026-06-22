<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function method_not_allowed() {
    http_response_code(405);
    echo json_encode(['message' => 'Metoda niedozwolona.']);
}

// REDIRECT_URL = oryginalna ścieżka przed rewrite'm Apache (OVH)
// REQUEST_URI  = fallback gdy brak rewrite'u (lokalne dev)
$uri    = parse_url($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = preg_replace('#^/?api/?#', '', $uri);
$path   = trim($path, '/');
$parts  = $path !== '' ? explode('/', $path) : [];

$method   = $_SERVER['REQUEST_METHOD'];
$resource = $parts[0] ?? '';
$id       = $parts[1] ?? null;
$sub      = $parts[2] ?? null;

$body = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($resource) {
    case 'auth':
        require_once __DIR__ . '/routes/auth.php';
        handle_auth($method, $id, $body);
        break;
    case 'users':
        require_once __DIR__ . '/routes/users.php';
        handle_users($method, $id, $sub, $body);
        break;
    case 'tires':
        require_once __DIR__ . '/routes/tires.php';
        handle_tires($method, $id, $body);
        break;
    case 'customers':
        require_once __DIR__ . '/routes/customers.php';
        handle_customers($method, $id, $body);
        break;
    case 'templates':
        require_once __DIR__ . '/routes/templates.php';
        handle_templates($method, $id, $body);
        break;
    case 'email-templates':
        require_once __DIR__ . '/routes/email-templates.php';
        handle_email_templates($method, $id, $body);
        break;
    case 'actions':
        require_once __DIR__ . '/routes/actions.php';
        handle_actions($method, $id, $sub, $body);
        break;
    default:
        http_response_code(404);
        echo json_encode(['message' => 'Nie znaleziono zasobu.']);
}
