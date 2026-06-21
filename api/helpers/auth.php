<?php
require_once __DIR__ . '/jwt.php';

function get_auth_user() {
    $headers = getallheaders();
    // OVH Apache stripuje Authorization — sprawdź też $_SERVER jako fallback
    $authHeader = $headers['Authorization']
               ?? $headers['authorization']
               ?? $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) return null;

    return jwt_decode($matches[1], JWT_SECRET);
}

function require_auth() {
    $user = get_auth_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['message' => 'Brak autoryzacji.']);
        exit;
    }
    return $user;
}

function require_permission($user, $permission) {
    $perms = $user['permissions'] ?? [];
    if (!in_array($permission, $perms)) {
        http_response_code(403);
        echo json_encode(['message' => 'Brak uprawnień.']);
        exit;
    }
}
