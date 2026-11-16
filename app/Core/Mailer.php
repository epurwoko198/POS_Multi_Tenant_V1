<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Small SMTP client using PHP streams only (no Composer dependency).
 * Supports implicit SSL (465), STARTTLS (587), AUTH LOGIN and AUTH PLAIN.
 */
final class Mailer
{
    /** @var resource|null */
    private $socket = null;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Alamat email penerima tidak valid.');
        }
        $missing = MailSettings::missing($this->cfg);
        if ($missing !== []) {
            throw new RuntimeException('Konfigurasi SMTP belum lengkap: ' . implode(', ', $missing) . '.');
        }
        if (trim((string) ($this->cfg['host'] ?? '')) === '') {
            throw new RuntimeException('SMTP host belum diatur.');
        }
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));
        $fromEmail = (string) $this->cfg['from_email'];
        $fromName = trim((string) preg_replace('/[\r\n"]+/', ' ', (string) ($this->cfg['from_name'] ?? '')));
        $fromHeader = $fromName !== ''
            ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>'
            : $fromEmail;
        $domain = (string) (parse_url((string) Config::get('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $domain) . '>';

        $boundary = '=_pos_' . bin2hex(random_bytes(12));
        if ($text === '') {
            $text = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
        }
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        $this->connect();
        try {
            $this->command('MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
            $this->command('RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $this->command('DATA', [354], 'DATA');
            $payload = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
            $payload = str_replace("\n", "\r\n", (string) $payload);
            $this->write($payload . "\r\n.\r\n");
            $this->expect([250], 'Pengiriman pesan');
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $encryption = (string) $this->cfg['encryption'];
        $timeout = (int) ($this->cfg['timeout'] ?? 15);
        $scheme = $encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Tidak dapat terhubung ke SMTP ' . $host . ':' . $port . ' — ' . ($errstr ?: 'koneksi ditolak') . '.');
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);
        $this->expect([220], 'Sapaan SMTP');

        $ehloHost = (string) (parse_url((string) Config::get('APP_URL', ''), PHP_URL_HOST) ?: gethostname() ?: 'localhost');
        $this->command('EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $ehloHost), [250], 'EHLO');

        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220], 'STARTTLS');
            $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) {
                throw new RuntimeException('Negosiasi TLS dengan server SMTP gagal.');
            }
            $this->command('EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $ehloHost), [250], 'EHLO setelah TLS');
        }

        $username = (string) ($this->cfg['username'] ?? '');
        $password = (string) ($this->cfg['password'] ?? '');
        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('Password SMTP belum diatur.');
            }
            if (($this->cfg['auth'] ?? 'login') === 'plain') {
                $this->command('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), [235], 'AUTH PLAIN');
            } else {
                $this->command('AUTH LOGIN', [334], 'AUTH LOGIN');
                $this->command(base64_encode($username), [334], 'Username SMTP');
                $this->command(base64_encode($password), [235], 'Password SMTP');
            }
        }
    }

    private function command(string $command, array $expected, string $label): string
    {
        $this->write($command . "\r\n");
        return $this->expect($expected, $label);
    }

    private function expect(array $expected, string $label): string
    {
        $response = $this->readResponse();
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException($label . ' gagal: ' . trim($response));
        }
        return $response;
    }

    private function readResponse(): string
    {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException(!empty($meta['timed_out'])
                    ? 'Server SMTP tidak merespons (timeout).'
                    : 'Koneksi SMTP terputus.');
            }
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $response;
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Gagal mengirim data ke server SMTP.');
            }
            $offset += $written;
        }
    }

    private function close(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        try {
            $this->write("QUIT\r\n");
            $this->readResponse();
        } catch (\Throwable) {
            // Server may close immediately after DATA; delivery already succeeded.
        }
        @fclose($this->socket);
        $this->socket = null;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
