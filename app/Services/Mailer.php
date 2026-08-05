<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * Minimal SMTP client — enough to send HTML mail reliably from cPanel,
 * with no Composer dependency.
 *
 * Supports STARTTLS and implicit SSL, AUTH LOGIN and AUTH PLAIN.
 * PHP's mail() is deliberately not used: it cannot authenticate, and mail
 * sent through it from shared hosting is routinely dropped as spam.
 */
class Mailer
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    private array $transcript = [];

    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $replyTo;

    /**
     * Every argument is optional and falls back to the stored settings.
     * Pass values explicitly only when testing credentials that have not
     * been saved yet.
     */
    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $encryption = null,
        ?string $username = null,
        ?string $password = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
        private int $timeout = 20
    ) {
        $this->host       = $host       ?? (string) Settings::get('smtp_host', '');
        $this->port       = $port       ?? Settings::int('smtp_port', 587);
        $this->encryption = $encryption ?? (string) Settings::get('smtp_encryption', 'tls');
        $this->username   = $username   ?? (string) Settings::get('smtp_username', '');
        $this->password   = $password   ?? (string) Settings::get('smtp_password', '');
        $this->fromEmail  = $fromEmail  ?? (string) Settings::get('smtp_from_email', '');
        $this->fromName   = $fromName   ?? (string) Settings::get('smtp_from_name', 'Shanfix Technology');
        $this->replyTo    = $replyTo    ?? (string) Settings::get('smtp_reply_to', '');
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->fromEmail !== '';
    }

    /**
     * @param array<int,array{name:string,content:string,mime:string}> $attachments
     *
     * @return array{ok:bool, error?:string, transcript?:array}
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $attachments = []
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP is not configured. Add your mail server details in Settings.'];
        }

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email address: ' . $toEmail];
        }

        $this->transcript = [];

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $this->fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            $message = $this->buildMessage($toEmail, $toName, $subject, $htmlBody, $textBody, $attachments);

            // A leading dot on its own line terminates DATA, so it must be escaped.
            $message = preg_replace('/^\./m', '..', $message);

            $this->write($message . self::CRLF . '.' . self::CRLF);
            $this->expect([250]);

            $this->command('QUIT', [221, 250]);
            $this->disconnect();

            return ['ok' => true, 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            $this->disconnect();

            Logger::error('SMTP send failed: ' . $e->getMessage(), [
                'to'   => $toEmail,
                'host' => $this->host,
            ]);

            return [
                'ok'         => false,
                'error'      => $e->getMessage(),
                'transcript' => $this->transcript,
            ];
        }
    }

    /** Connect and authenticate without sending, to prove the settings work. */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP is not configured.'];
        }

        $this->transcript = [];

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->command('QUIT', [221, 250]);
            $this->disconnect();

            return ['ok' => true, 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            $this->disconnect();
            return ['ok' => false, 'error' => $e->getMessage(), 'transcript' => $this->transcript];
        }
    }

    // -- SMTP conversation ---------------------------------------------

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : '';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno  = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "Could not connect to {$this->host}:{$this->port} — " . ($errstr ?: 'connection refused')
                . '. Check the host and port, and that your firewall allows outbound SMTP.'
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        $this->expect([220]);
    }

    private function handshake(): void
    {
        $domain = $this->fromEmail !== '' && str_contains($this->fromEmail, '@')
            ? explode('@', $this->fromEmail)[1]
            : 'localhost';

        $this->command('EHLO ' . $domain, [250]);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );

            if (!$ok) {
                throw new \RuntimeException(
                    'STARTTLS failed — could not negotiate an encrypted connection. '
                    . 'Try port 465 with SSL, or 25 with no encryption if your host requires it.'
                );
            }

            // The server must be greeted again over the encrypted channel.
            $this->command('EHLO ' . $domain, [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }

        // AUTH LOGIN is the most widely supported; fall back to PLAIN.
        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '535') || str_contains($e->getMessage(), '534')) {
                throw new \RuntimeException(
                    'The mail server rejected those credentials. Check the username and password — '
                    . 'for cPanel mailboxes the username is usually the full email address.'
                );
            }

            $this->command(
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password),
                [235]
            );
        }
    }

    private function command(string $command, array $expected): string
    {
        // Never write a password into the transcript.
        $this->transcript[] = '> ' . (
            preg_match('/^(AUTH|[A-Za-z0-9+\/=]{16,}$)/', $command) ? '[credentials hidden]' : $command
        );

        $this->write($command . self::CRLF);

        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('Lost connection to the mail server while sending.');
        }
    }

    private function expect(array $codes): string
    {
        $response = '';

        while ($this->socket !== null && ($line = fgets($this->socket, 512)) !== false) {
            $response .= $line;

            // Multi-line replies use "250-"; the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $meta = $this->socket !== null ? stream_get_meta_data($this->socket) : ['timed_out' => true];

        if (!empty($meta['timed_out'])) {
            throw new \RuntimeException('The mail server did not respond in time.');
        }

        $this->transcript[] = '< ' . trim($response);

        $code = (int) substr(trim($response), 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('Mail server replied: ' . trim($response));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    // -- Message construction ------------------------------------------

    private function buildMessage(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments
    ): string {
        $boundaryMixed = 'mix_' . bin2hex(random_bytes(12));
        $boundaryAlt   = 'alt_' . bin2hex(random_bytes(12));

        if ($textBody === '') {
            $textBody = $this->htmlToText($htmlBody);
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>',
            'To: ' . ($toName !== '' ? $this->encodeHeader($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->senderDomain() . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Shanfix BMS',
        ];

        if ($this->replyTo !== '') {
            $headers[] = 'Reply-To: <' . $this->replyTo . '>';
        }

        $body = '';

        if ($attachments !== []) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';
            $body .= '--' . $boundaryMixed . self::CRLF;
        }

        // text/plain + text/html alternative, so every client renders something.
        $body .= 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . self::CRLF . self::CRLF;

        $body .= '--' . $boundaryAlt . self::CRLF;
        $body .= 'Content-Type: text/plain; charset=UTF-8' . self::CRLF;
        $body .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
        $body .= chunk_split(base64_encode($textBody)) . self::CRLF;

        $body .= '--' . $boundaryAlt . self::CRLF;
        $body .= 'Content-Type: text/html; charset=UTF-8' . self::CRLF;
        $body .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
        $body .= chunk_split(base64_encode($htmlBody)) . self::CRLF;

        $body .= '--' . $boundaryAlt . '--' . self::CRLF;

        foreach ($attachments as $file) {
            $body .= self::CRLF . '--' . $boundaryMixed . self::CRLF;
            $body .= 'Content-Type: ' . ($file['mime'] ?? 'application/octet-stream')
                   . '; name="' . $file['name'] . '"' . self::CRLF;
            $body .= 'Content-Transfer-Encoding: base64' . self::CRLF;
            $body .= 'Content-Disposition: attachment; filename="' . $file['name'] . '"' . self::CRLF . self::CRLF;
            $body .= chunk_split(base64_encode($file['content'])) . self::CRLF;
        }

        if ($attachments !== []) {
            $body .= '--' . $boundaryMixed . '--' . self::CRLF;
        }

        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $body;
    }

    private function senderDomain(): string
    {
        return str_contains($this->fromEmail, '@')
            ? explode('@', $this->fromEmail)[1]
            : 'shanfix.co.ke';
    }

    /** RFC 2047 encoding, so accented names and non-ASCII subjects survive. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return str_contains($value, ',') || str_contains($value, '"')
                ? '"' . str_replace('"', '\"', $value) . '"'
                : $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Readable plain-text fallback from the HTML body. */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $text);
        $text = preg_replace('/<\/(p|div|tr|h[1-6])>/i', "\n", (string) $text);
        $text = preg_replace('/<\/td>/i', "\t", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }
}
