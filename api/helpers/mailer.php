<?php
require_once __DIR__ . '/smtp.php';

function send_mail(string $to, string $subject, string $htmlBody, ?string $company_id = null): bool {
    $cfg = get_smtp_config($company_id);
    if ($cfg !== null) {
        $smtp = new SimpleSmtp($cfg);
        return $smtp->send($to, $subject, $htmlBody);
    }
    $fromEmail = defined('MAIL_FROM')      ? MAIL_FROM      : '';
    $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '';
    if (!$fromEmail) {
        throw new Exception('Brak konfiguracji e-mail — ustaw SMTP lub MAIL_FROM w config.php');
    }
    $headers    = "From: $fromName <$fromEmail>\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "X-Mailer: PHP/" . phpversion();
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $ok = @mail($to, $encSubject, $htmlBody, $headers);
    if (!$ok) throw new Exception('mail() zwróciło false — sprawdź konfigurację e-mail na serwerze');
    return true;
}

function get_smtp_config(?string $company_id = null): ?array {
    try {
        $pdo = get_pdo();
        if ($company_id !== null) {
            $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE company_id = ? AND `key` LIKE 'smtp_%'");
            $stmt->execute([$company_id]);
        } else {
            $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%' LIMIT 50");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($rows['smtp_host'])) return null;
        return [
            'host'       => $rows['smtp_host'],
            'port'       => (int)($rows['smtp_port'] ?? 587),
            'encryption' => $rows['smtp_encryption'] ?? 'tls',
            'user'       => $rows['smtp_user'] ?? '',
            'pass'       => $rows['smtp_pass'] ?? '',
            'from_email' => $rows['smtp_from_email'] ?? (defined('MAIL_FROM')      ? MAIL_FROM      : ''),
            'from_name'  => $rows['smtp_from_name']  ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : ''),
        ];
    } catch (Exception $e) {
        return null;
    }
}
