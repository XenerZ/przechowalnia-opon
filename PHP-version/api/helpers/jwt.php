<?php
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode($payload, $secret) {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

function jwt_decode($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$headerB64, $payloadB64, $sigB64] = $parts;

    $expectedSig = base64url_encode(hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true));
    if (!hash_equals($expectedSig, $sigB64)) return null;

    $payload = json_decode(base64url_decode($payloadB64), true);
    if (!$payload) return null;

    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}
