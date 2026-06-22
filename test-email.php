<?php
/**
 * Tester wysyłki e-mail — wgraj na OVH, uruchom przez przeglądarkę, potem USUŃ.
 * Dostęp: https://twoja-domena.pl/test-email.php
 */

// ── Zabezpieczenie: tylko lokalnie lub z hasłem ───────────────────────────────
define('TESTER_PASS', 'test1234'); // zmień przed wgraniem jeśli chcesz
$given = $_GET['pass'] ?? $_POST['pass'] ?? '';
if ($given !== TESTER_PASS) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">'
       . '<h2>Tester e-mail</h2>'
       . '<form method="get"><input name="pass" type="password" placeholder="Hasło testera" style="padding:.4rem;font-size:1rem">'
       . ' <button type="submit" style="padding:.4rem .8rem">Wejdź</button></form></body></html>';
    exit;
}

require_once __DIR__ . '/api/config.php';

$step  = $_POST['step']  ?? '';
$email = trim($_POST['email'] ?? '');

?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Tester e-mail</title>
<style>
  body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
  h2   { margin-bottom: .25rem; }
  .card { border: 1px solid #ddd; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; }
  .ok  { color: #22863a; font-weight: 600; }
  .err { color: #cb2431; font-weight: 600; }
  .warn{ color: #735c0f; font-weight: 600; }
  pre  { background: #f6f8fa; padding: .75rem; border-radius: 6px; font-size: .8rem; overflow: auto; }
  input[type=email], input[type=text] { width: 100%; padding: .4rem .6rem; font-size: 1rem; box-sizing: border-box; margin-bottom: .75rem; border: 1px solid #ccc; border-radius: 4px; }
  button { padding: .45rem 1.1rem; font-size: 1rem; cursor: pointer; background: #0366d6; color: #fff; border: none; border-radius: 4px; }
  .tag  { display: inline-block; font-size: .75rem; padding: .1rem .45rem; border-radius: 3px; }
  .tag-ok  { background: #dcffe4; color: #22863a; }
  .tag-err { background: #ffeef0; color: #cb2431; }
</style>
</head>
<body>
<h2>Tester e-mail — Przechowalnia Opon</h2>
<p style="color:#666;font-size:.875rem">Pamiętaj żeby <strong>usunąć ten plik</strong> po zakończeniu testów.</p>

<?php
// ── 1. Konfiguracja ────────────────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin-top:0">1. Konfiguracja</h3>';
$cfgOk = true;
$checks = [
    'MAIL_FROM'      => defined('MAIL_FROM')      ? MAIL_FROM      : null,
    'MAIL_FROM_NAME' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : null,
    'APP_URL'        => defined('APP_URL')         ? APP_URL        : null,
];
foreach ($checks as $name => $val) {
    if ($val === null || strpos($val, 'TWOJA') !== false || strpos($val, 'ZMIEN') !== false) {
        echo '<div class="err">❌ ' . $name . ' — nie ustawione lub zawiera placeholder: <code>' . htmlspecialchars((string)$val) . '</code></div>';
        $cfgOk = false;
    } else {
        echo '<div class="ok">✅ ' . $name . ' = <code>' . htmlspecialchars($val) . '</code></div>';
    }
}
echo '</div>';

// ── 2. Tabela password_resets ──────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin-top:0">2. Tabela <code>password_resets</code></h3>';
try {
    $pdo = get_pdo();
    $pdo->query('SELECT 1 FROM password_resets LIMIT 1');
    $cnt = $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
    echo '<div class="ok">✅ Tabela istnieje (' . $cnt . ' rekordów)</div>';

    // Pokaż ostatnie tokeny
    $rows = $pdo->query('SELECT email, LEFT(token,12) AS token_prefix, created_at, used FROM password_resets ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<table style="width:100%;font-size:.8rem;margin-top:.75rem;border-collapse:collapse">';
        echo '<tr style="background:#f6f8fa"><th style="text-align:left;padding:.25rem .4rem">Email</th><th>Token (prefix)</th><th>Data</th><th>Użyty</th></tr>';
        foreach ($rows as $r) {
            $usedTag = $r['used'] ? '<span class="tag tag-err">TAK</span>' : '<span class="tag tag-ok">NIE</span>';
            echo '<tr><td style="padding:.25rem .4rem">' . htmlspecialchars($r['email']) . '</td>'
               . '<td style="padding:.25rem .4rem"><code>' . $r['token_prefix'] . '…</code></td>'
               . '<td style="padding:.25rem .4rem">' . $r['created_at'] . '</td>'
               . '<td style="padding:.25rem .4rem;text-align:center">' . $usedTag . '</td></tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="err">❌ Błąd: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<pre>Utwórz tabelę:\nCREATE TABLE password_resets (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  email VARCHAR(255) NOT NULL,\n  token VARCHAR(64) NOT NULL,\n  created_at DATETIME NOT NULL,\n  used TINYINT(1) NOT NULL DEFAULT 0,\n  INDEX idx_token (token),\n  INDEX idx_email (email)\n);</pre>';
}
echo '</div>';

// ── 3. Test wysyłki e-mail ─────────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin-top:0">3. Test wysyłki e-mail</h3>';

$mailResult = null;
if ($step === 'send_mail' && $email) {
    $subject = '=?UTF-8?B?' . base64_encode('Test wysyłki — Przechowalnia Opon') . '?=';
    $message = "To jest testowa wiadomość z systemu Przechowalnia Opon.\r\n\r\n"
             . "Jeśli ją widzisz, wysyłka e-mail działa poprawnie.\r\n\r\n"
             . "Data: " . date('Y-m-d H:i:s') . "\r\n"
             . "Serwer: " . ($_SERVER['HTTP_HOST'] ?? 'nieznany');
    $headers = "From: " . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Test') . " <" . (defined('MAIL_FROM') ? MAIL_FROM : 'test@test.pl') . ">\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "X-Mailer: PHP/" . phpversion();
    $sent = mail($email, $subject, $message, $headers);
    $mailResult = $sent;
    if ($sent) {
        echo '<div class="ok">✅ mail() zwróciło true — wiadomość przekazana do serwera pocztowego OVH.</div>';
        echo '<p style="font-size:.875rem">Sprawdź skrzynkę <strong>' . htmlspecialchars($email) . '</strong> (i folder SPAM).</p>';
        echo '<p style="font-size:.8rem;color:#666">⚠️ mail() = true oznacza tylko że serwer przyjął wiadomość, nie że dotarła. Jeśli nie ma jej po minucie — sprawdź folder SPAM lub logi OVH.</p>';
    } else {
        echo '<div class="err">❌ mail() zwróciło false — serwer odmówił wysyłki.</div>';
        echo '<p style="font-size:.875rem">Upewnij się, że <code>MAIL_FROM</code> to skrzynka istniejąca na OVH.</p>';
    }
}

if ($mailResult === null) {
    echo '<form method="post">
      <input type="hidden" name="pass" value="' . htmlspecialchars($given) . '">
      <input type="hidden" name="step" value="send_mail">
      <label style="font-size:.875rem">Adres e-mail odbiorcy:</label>
      <input type="email" name="email" placeholder="adres@email.pl" required>
      <button type="submit">Wyślij testowego e-maila</button>
    </form>';
}
echo '</div>';

// ── 4. Test endpointu forgot-password ─────────────────────────────────────────
echo '<div class="card"><h3 style="margin-top:0">4. Test endpointu <code>/auth/forgot-password</code></h3>';

if ($step === 'test_endpoint' && $email) {
    $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/api/auth/forgot-password';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo '<div class="err">❌ cURL błąd: ' . htmlspecialchars($err) . '</div>';
    } else {
        $json = json_decode($resp, true);
        if ($status === 200 && ($json['success'] ?? false)) {
            echo '<div class="ok">✅ HTTP ' . $status . ' — endpoint zwrócił success=true</div>';
            echo '<p style="font-size:.875rem">Sprawdź skrzynkę <strong>' . htmlspecialchars($email) . '</strong> oraz tabelę <code>password_resets</code> powyżej (odśwież stronę).</p>';
        } else {
            echo '<div class="err">❌ HTTP ' . $status . '</div>';
            echo '<pre>' . htmlspecialchars($resp) . '</pre>';
        }
    }
}

if ($step !== 'test_endpoint') {
    echo '<p style="font-size:.875rem">Wywołuje endpoint tak jak robi to strona logowania.</p>';
    echo '<form method="post">
      <input type="hidden" name="pass" value="' . htmlspecialchars($given) . '">
      <input type="hidden" name="step" value="test_endpoint">
      <label style="font-size:.875rem">E-mail konta w systemie (musi istnieć w tabeli users):</label>
      <input type="email" name="email" placeholder="adres@email.pl" required>
      <button type="submit">Wywołaj forgot-password</button>
    </form>';
}
echo '</div>';
?>

<p style="font-size:.8rem;color:#999;margin-top:2rem">
  ⚠️ Usuń ten plik z serwera po zakończeniu testów: <code>test-email.php</code>
</p>
</body>
</html>
