<?php
class SimpleSmtp {
    private array $cfg;
    private $fp;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    public function send(string $to, string $subject, string $htmlBody): bool {
        $enc  = strtolower($this->cfg['encryption'] ?? 'tls');
        $host = $this->cfg['host'];
        $port = (int)($this->cfg['port'] ?? 587);

        $transport = ($enc === 'ssl') ? "ssl://$host" : "tcp://$host";
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]]);

        $this->fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) throw new Exception("Błąd połączenia z $host:$port — $errstr ($errno)");
        stream_set_timeout($this->fp, 15);

        $this->expect(220);
        $helo = parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?? 'localhost';
        $this->cmd("EHLO $helo", 250);

        if ($enc === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('STARTTLS nie powiodło się');
            }
            $this->cmd("EHLO $helo", 250);
        }

        $user = $this->cfg['user'] ?? '';
        $pass = $this->cfg['pass'] ?? '';
        if ($user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($user), 334);
            $this->cmd(base64_encode($pass), 235);
        }

        $fromEmail = $this->cfg['from_email'];
        $fromName  = $this->cfg['from_name'] ?? $fromEmail;

        $this->cmd("MAIL FROM:<$fromEmail>", 250);
        $this->cmd("RCPT TO:<$to>", [250, 251]);
        $this->cmd('DATA', 354);

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $msg  = "From: $encFrom <$fromEmail>\r\n";
        $msg .= "To: <$to>\r\n";
        $msg .= "Subject: $encSubject\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $msg .= "\r\n";
        $msg .= str_replace("\n.", "\n..", $htmlBody);
        $msg .= "\r\n.";

        fwrite($this->fp, "$msg\r\n");
        $this->expect(250);

        $this->write('QUIT');
        fclose($this->fp);
        return true;
    }

    private function cmd(string $c, $expected): string {
        $this->write($c);
        return $this->expect($expected);
    }

    private function write(string $data): void {
        fwrite($this->fp, "$data\r\n");
    }

    private function expect($codes): string {
        $codes = (array)$codes;
        $line  = '';
        do {
            $line = fgets($this->fp, 1024);
            if ($line === false) throw new Exception('Serwer SMTP przerwał połączenie');
        } while (substr($line, 3, 1) === '-');

        $code = (int)substr($line, 0, 3);
        if (!in_array($code, $codes)) {
            throw new Exception('SMTP ' . $code . ': ' . trim(substr($line, 4)));
        }
        return $line;
    }
}
