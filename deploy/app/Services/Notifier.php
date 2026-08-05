<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\View;

/**
 * Decides what to send, renders it, queues it, and dispatches it.
 *
 * Everything goes through the queue so a slow or unreachable mail server
 * never blocks the person clicking "send", and every attempt is on record.
 */
class Notifier
{
    public const EVENTS = [
        'quotation_sent'   => 'Quotation sent to client',
        'invoice_sent'     => 'Invoice sent to client',
        'payment_received' => 'Payment received confirmation',
        'invoice_overdue'  => 'Overdue invoice reminder',
        'job_ready'        => 'Job ready for collection',
    ];

    // -- Queueing ------------------------------------------------------

    /**
     * Queue a message on each requested channel.
     *
     * @param array      $context  Placeholder values plus recipient details
     * @param bool       $force    Ignore the per-event toggles (an operator
     *                             pressing "send" overrides the automation)
     * @param array|null $channels Restrict to these channels; null = both
     *
     * @return array{queued:int, skipped:array<int,string>}
     */
    public static function dispatch(
        string $event,
        array $context,
        bool $force = false,
        ?array $channels = null
    ): array {
        $queued  = 0;
        $skipped = [];

        foreach (['email', 'sms'] as $channel) {
            if ($channels !== null && !in_array($channel, $channels, true)) {
                continue;
            }

            $enabled = Settings::bool("notify_{$event}_{$channel}", false);

            if (!$enabled && !$force) {
                continue;
            }

            $result = $channel === 'email'
                ? self::queueEmail($event, $context)
                : self::queueSms($event, $context);

            if ($result === true) {
                $queued++;
            } elseif (is_string($result)) {
                $skipped[] = $result;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private static function queueEmail(string $event, array $context): true|string
    {
        if (!Settings::bool('smtp_enabled')) {
            return 'Email is not enabled in Settings.';
        }

        $to = trim((string) ($context['email'] ?? ''));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'No valid email address on file for ' . ($context['client_name'] ?? 'this client') . '.';
        }

        $subject = self::render(Settings::get("tpl_{$event}_subject", ucfirst($event)), $context);
        $intro   = self::render(Settings::get("tpl_{$event}_intro", ''), $context);

        $html = View::capture('emails/document', [
            'event'   => $event,
            'intro'   => $intro,
            'context' => $context,
            'company' => Settings::company(),
            'footer'  => Settings::get('email_footer_note', ''),
        ], null);

        Database::insert('notifications', [
            'channel'        => 'email',
            'event'          => $event,
            'recipient'      => $to,
            'recipient_name' => $context['contact_name'] ?? ($context['client_name'] ?? null),
            'subject'        => mb_substr($subject, 0, 255),
            'body'           => $html,
            'entity_type'    => $context['entity_type'] ?? null,
            'entity_id'      => $context['entity_id'] ?? null,
            'client_id'      => $context['client_id'] ?? null,
            'created_by'     => Auth::id(),
        ]);

        return true;
    }

    private static function queueSms(string $event, array $context): true|string
    {
        if (!Settings::bool('sms_enabled')) {
            return 'SMS is not enabled in Settings.';
        }

        $template = Settings::get("tpl_sms_{$event}", '');

        if (trim((string) $template) === '') {
            return 'No SMS template set for this event.';
        }

        $phone = normalize_phone((string) ($context['phone'] ?? ''));

        if ($phone === null) {
            return 'No valid phone number on file for ' . ($context['client_name'] ?? 'this client') . '.';
        }

        // SMS links must be short enough to leave room for the message.
        $smsContext = $context;
        $smsContext['link'] = $context['short_link'] ?? ($context['link'] ?? '');

        Database::insert('notifications', [
            'channel'        => 'sms',
            'event'          => $event,
            'recipient'      => $phone,
            'recipient_name' => $context['contact_name'] ?? ($context['client_name'] ?? null),
            'body'           => self::render($template, $smsContext),
            'entity_type'    => $context['entity_type'] ?? null,
            'entity_id'      => $context['entity_id'] ?? null,
            'client_id'      => $context['client_id'] ?? null,
            'created_by'     => Auth::id(),
        ]);

        return true;
    }

    // -- Sending -------------------------------------------------------

    /**
     * Send queued messages. Called by cron.php, or directly after a user
     * action so they see the result immediately.
     *
     * @return array{sent:int, failed:int, processed:int}
     */
    public static function processQueue(int $limit = 25, ?int $onlyId = null): array
    {
        $maxAttempts = Settings::int('notify_max_attempts', 3);

        $where  = "status = 'queued' AND attempts < :max";
        $params = ['max' => $maxAttempts];

        if ($onlyId !== null) {
            $where .= ' AND id = :id';
            $params['id'] = $onlyId;
        } else {
            $where .= ' AND (scheduled_at IS NULL OR scheduled_at <= NOW())';
        }

        $rows = Database::all(
            "SELECT * FROM notifications WHERE {$where} ORDER BY id ASC LIMIT " . max(1, $limit),
            $params
        );

        $sent = 0;
        $failed = 0;

        $mailer = new Mailer();
        $sms    = new Sms();

        foreach ($rows as $row) {
            // Claim the row so two overlapping cron runs cannot double-send.
            $claimed = Database::run(
                "UPDATE notifications SET status = 'sending', attempts = attempts + 1
                  WHERE id = :id AND status = 'queued'",
                ['id' => $row['id']]
            )->rowCount();

            if ($claimed === 0) {
                continue;
            }

            $result = $row['channel'] === 'email'
                ? $mailer->send(
                    $row['recipient'],
                    (string) ($row['recipient_name'] ?? ''),
                    (string) $row['subject'],
                    (string) $row['body']
                  )
                : $sms->send($row['recipient'], (string) $row['body']);

            if ($result['ok']) {
                Database::update('notifications', [
                    'status'       => 'sent',
                    'sent_at'      => date('Y-m-d H:i:s'),
                    'provider_ref' => $result['ref'] ?? null,
                    'cost'         => $result['cost'] ?? null,
                    'last_error'   => null,
                ], ['id' => $row['id']]);

                $sent++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $giveUp   = $attempts >= $maxAttempts;

                Database::update('notifications', [
                    'status'     => $giveUp ? 'failed' : 'queued',
                    'last_error' => mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 500),
                ], ['id' => $row['id']]);

                $failed++;

                Logger::warning('Notification send failed', [
                    'id'      => $row['id'],
                    'channel' => $row['channel'],
                    'error'   => $result['error'] ?? '',
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'processed' => count($rows)];
    }

    // -- Building context ----------------------------------------------

    /**
     * Everything a template might need about a document, including the
     * public share link.
     */
    public static function documentContext(array $doc): array
    {
        $token = self::ensureToken((int) $doc['id'], $doc['public_token'] ?? null);
        $link  = self::publicUrl($token);

        return [
            'entity_type'  => 'document',
            'entity_id'    => (int) $doc['id'],
            'client_id'    => (int) $doc['client_id'],
            'client_name'  => $doc['client_name'] ?? '',
            'contact_name' => self::firstName($doc['client_contact'] ?? ($doc['client_name'] ?? '')),
            'email'        => $doc['client_email'] ?? '',
            'phone'        => $doc['client_phone'] ?? '',
            'company_name' => Settings::get('company_name', 'Shanfix Technology'),
            'doc_number'   => $doc['doc_number'] ?? '',
            'doc_type'     => ucfirst((string) ($doc['doc_type'] ?? 'document')),
            'title'        => $doc['title'] ?? '',
            'amount'       => money($doc['total'] ?? 0),
            'balance'      => money($doc['balance'] ?? 0),
            'total_raw'    => (float) ($doc['total'] ?? 0),
            'issue_date'   => fdate($doc['issue_date'] ?? null),
            'due_date'     => fdate($doc['due_date'] ?? null),
            'valid_until'  => fdate($doc['valid_until'] ?? null),
            'link'         => $link,
            'short_link'   => $link,
            'document'     => $doc,
        ];
    }

    public static function jobContext(array $job): array
    {
        return [
            'entity_type'  => 'job',
            'entity_id'    => (int) $job['id'],
            'client_id'    => (int) $job['client_id'],
            'client_name'  => $job['client_name'] ?? '',
            'contact_name' => self::firstName($job['client_contact'] ?? ($job['client_name'] ?? '')),
            'email'        => $job['client_email'] ?? '',
            'phone'        => $job['client_phone'] ?? '',
            'company_name' => Settings::get('company_name', 'Shanfix Technology'),
            'job_number'   => $job['job_number'] ?? '',
            'job_title'    => $job['title'] ?? '',
            'doc_number'   => $job['doc_number'] ?? '',
            'link'         => '',
            'job'          => $job,
        ];
    }

    /** Mint a share token the first time a document needs a public link. */
    public static function ensureToken(int $documentId, ?string $existing = null): string
    {
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(24));   // 48 hex chars

        Database::update('documents', ['public_token' => $token], ['id' => $documentId]);

        return $token;
    }

    public static function publicUrl(string $token): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            return $base . url('/view/' . $token);
        }

        return $base . base_path() . '/view/' . $token;
    }

    /**
     * Replace {placeholders}. Unknown ones are stripped rather than left
     * visible, so a stray token never reaches a client.
     */
    public static function render(?string $template, array $context): string
    {
        $template = (string) $template;

        if ($template === '') {
            return '';
        }

        $out = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use ($context): string {
                $key = strtolower($m[1]);
                $val = $context[$key] ?? '';
                return is_scalar($val) ? (string) $val : '';
            },
            $template
        );

        // Tidy up the gaps left by empty placeholders.
        $out = preg_replace('/ {2,}/', ' ', (string) $out);

        return trim((string) $out);
    }

    private static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'there';
        }
        return explode(' ', $name)[0];
    }

    // -- Automatic reminders -------------------------------------------

    /**
     * Chase overdue invoices on the configured day offsets.
     * A lock row per invoice-per-offset stops repeat chasing.
     *
     * @return array{queued:int, checked:int}
     */
    public static function queueOverdueReminders(): array
    {
        if (!Settings::bool('notify_invoice_overdue_email') && !Settings::bool('notify_invoice_overdue_sms')) {
            return ['queued' => 0, 'checked' => 0];
        }

        $offsets = array_values(array_filter(array_map(
            static fn($d) => (int) trim($d),
            explode(',', (string) Settings::get('notify_overdue_days', '1,7,14'))
        ), static fn($d) => $d > 0));

        if ($offsets === []) {
            return ['queued' => 0, 'checked' => 0];
        }

        $queued  = 0;
        $checked = 0;

        foreach ($offsets as $days) {
            $invoices = Database::all(
                "SELECT d.*, c.name AS client_name, c.email AS client_email,
                        c.phone AS client_phone, c.contact_person AS client_contact
                   FROM documents d
                   JOIN clients c ON c.id = d.client_id
                  WHERE d.doc_type = 'invoice'
                    AND d.status NOT IN ('cancelled','paid','draft')
                    AND d.balance > 0
                    AND d.due_date IS NOT NULL
                    AND DATEDIFF(CURDATE(), d.due_date) = :days",
                ['days' => $days]
            );

            foreach ($invoices as $invoice) {
                $checked++;
                $lockKey = 'overdue:' . $invoice['id'] . ':' . $days;

                // INSERT on a unique key is our idempotency guard.
                try {
                    Database::run(
                        'INSERT INTO notification_locks (lock_key) VALUES (:k)',
                        ['k' => $lockKey]
                    );
                } catch (\Throwable) {
                    continue;   // already chased at this offset
                }

                $context = self::documentContext($invoice);
                $context['days_overdue'] = (string) $days;

                $result = self::dispatch('invoice_overdue', $context);
                $queued += $result['queued'];
            }
        }

        if ($queued > 0) {
            ActivityLog::record('overdue_reminders_queued', 'notification', null, $queued . ' overdue reminder(s) queued');
        }

        return ['queued' => $queued, 'checked' => $checked];
    }
}
