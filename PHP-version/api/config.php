<?php
// ── Konfiguracja bazy danych i JWT ────────────────────────────────────────────
// Uzupełnij danymi z panelu OVH → Bazy danych

define('DB_HOST', 'ZMIEN_HOST_MYSQL_OVH');       // np. abcd1234.mysql.db
define('DB_NAME', 'ZMIEN_NAZWE_BAZY');
define('DB_USER', 'ZMIEN_UZYTKOWNIKA');
define('DB_PASS', 'ZMIEN_HASLO');

// Wygeneruj losowy klucz: https://randomkeygen.com/ (min. 32 znaki)
define('JWT_SECRET', 'ZMIEN_NA_SWOJ_SEKRETNY_KLUCZ_MIN_32_ZNAKI');

function get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
