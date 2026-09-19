<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use RuntimeException;

/**
 * The sending engine.
 *
 * Ported from the old platform's SMS class with its guarantees intact:
 *
 *   - Units are taken before the gateway is called and given back for
 *     every recipient the gateway refuses, so an account is never
 *     charged for a message that did not go and never sends one it could
 *     not pay for.
 *   - A campaign is claimed atomically, reports a heartbeat after every
 *     batch, and records how far it has got — so a worker that dies
 *     halfway is picked up and carries on from where it stopped rather
 *     than texting the first half of the list twice.
 *   - A campaign cancelled mid-send stops at the next batch.
 *   - Invalid numbers are logged as failed messages rather than dropped,
 *     so a campaign's failed count always matches the rows behind it.
 *
 * What changed is where the units live (bulk_accounts, through Wallet,
 * with a ledger) and one correction: a failed message used to be
 * recorded as having cost its parts even though the units were refunded,
 * which overstated spend on every report that added them up. A refused
 * message now records 0.
 */
final class Engine
{
    /** Recipients per flush: Onfon's 20-per-call limit, five calls at once. */
    private const FLUSH_SIZE = Onfon::BATCH_SIZE * 5;

    /** The longest message accepted — six 160-character parts. */
    public const MAX_LENGTH = 918;

    // =================================================================
    // Message arithmetic
    // =================================================================

    /**
     * How many parts a message costs, and whether it must go as Unicode.
     *
     * Anything outside the GSM-7 alphabet — an emoji, a curly quote pasted
     * from Word — forces the whole message into UCS-2, where a part is 70
     * characters instead of 160. That is the single most common surprise
     * on an SMS bill, which is why the send screens show it before sending.
     *
     * @return array{parts:int, unicode:bool, length:int}
     */
    public static function measure(string $message): array
    {
        $unicode = self::isUnicode($message);
        $length  = mb_strlen($message);

        return [
            'parts'   => max(1, (int) ceil($length / ($unicode ? 70 : 160))),
            'unicode' => $unicode,
            'length'  => $length,
        ];
    }

    /** True when the message needs UCS-2 — built from the GSM 03.38 tables. */
    public static function isUnicode(string $message): bool
    {
        static $gsm7 = null;

        if ($gsm7 === null) {
            $points = [
                // Basic character set
                0x40, 0xA3, 0x24, 0xA5, 0xE8, 0xE9, 0xF9, 0xEC, 0xF2, 0xC7,
                0x0A, 0xD8, 0xF8, 0x0D, 0xC5, 0xE5, 0x394, 0x5F, 0x3A6, 0x393,
                0x39B, 0x3A9, 0x3A0, 0x3A8, 0x3A3, 0x398, 0x39E, 0x1B, 0xC6, 0xE6,
                0xDF, 0xC9,
                0x20, 0x21, 0x22, 0x23, 0xA4, 0x25, 0x26, 0x27, 0x28, 0x29,
                0x2A, 0x2B, 0x2C, 0x2D, 0x2E, 0x2F,
                0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36, 0x37, 0x38, 0x39,
                0x3A, 0x3B, 0x3C, 0x3D, 0x3E, 0x3F, 0xA1,
                0x41, 0x42, 0x43, 0x44, 0x45, 0x46, 0x47, 0x48, 0x49, 0x4A,
                0x4B, 0x4C, 0x4D, 0x4E, 0x4F, 0x50, 0x51, 0x52, 0x53, 0x54,
                0x55, 0x56, 0x57, 0x58, 0x59, 0x5A,
                0xC4, 0xD6, 0xD1, 0xDC, 0xA7, 0xBF,
                0x61, 0x62, 0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6A,
                0x6B, 0x6C, 0x6D, 0x6E, 0x6F, 0x70, 0x71, 0x72, 0x73, 0x74,
                0x75, 0x76, 0x77, 0x78, 0x79, 0x7A,
                0xE4, 0xF6, 0xF1, 0xFC, 0xE0,
                // Extended set (escape-prefixed, still GSM-7)
                0x7C, 0x5E, 0x20AC, 0x7B, 0x7D, 0x5B, 0x7E, 0x5D, 0x5C,
            ];
            $gsm7 = array_flip(array_map('mb_chr', $points));
        }

        foreach (mb_str_split($message) as $char) {
            if (!isset($gsm7[$char])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Kenyan mobile number as +254XXXXXXXXX, or null.
     *
     * Kept exactly as the old platform wrote it, including the Excel case:
     * a number column opened in Excel comes back as 2.54712345678E+11.
     * Stored messages from the old platform are in this form, so the
     * migrated history and new messages match.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        if (preg_match('/[eE]/', $phone)) {
            $float = (float) $phone;
            if ($float > 0) {
                $phone = number_format($float, 0, '.', '');
            }
        }

        $n = preg_replace('/[^0-9]/', '', $phone);

        if ($n === '' || $n === null) {
            return null;
        }

        if (strlen($n) === 9 && ($n[0] === '7' || $n[0] === '1')) {
            $n = '254' . $n;
        } elseif (strlen($n) === 10 && $n[0] === '0') {
            $n = '254' . substr($n, 1);
        } elseif (strlen($n) === 14 && str_starts_with($n, '00')) {
            $n = substr($n, 2);
        }

        if (strlen($n) !== 12 || !str_starts_with($n, '254')) {
            return null;
        }

        return '+' . $n;
    }

    /**
     * The approved sender ID as stored, or null.
     *
     * Compared exactly — case included — because the networks register
     * "ShanfixTech" and "SHANFIXTECH" as different names and will refuse
     * the wrong one.
     */
    public static function approvedSender(int $accountId, string $senderId): ?string
    {
        $row = Database::scalar(
            "SELECT sender_id FROM bulk_sender_ids
              WHERE account_id = :a AND BINARY sender_id = :s AND status = 'approved'
              LIMIT 1",
            ['a' => $accountId, 's' => trim($senderId)]
        );

        return $row === null ? null : (string) $row;
    }

    // =================================================================
    // Sending now
    // =================================================================

    /**
     * Send one message to one or many numbers, synchronously.
     *
     * For quick sends from a screen and for the API — anything up to a
     * thousand recipients, where the caller is waiting for the answer.
     * Bigger audiences are campaigns, which go through the queue.
     *
     * @param list<string> $phones
     * @param array{source?:string, actor_type?:string, actor_id?:int} $who
     * @return array{ok:bool, error?:string, submitted?:int, sent?:int, failed?:int,
     *               invalid?:list<string>, units?:float, balance?:float,
     *               message_ids?:list<int>, results?:list<array>}
     */
    public static function sendNow(int $accountId, array $phones, string $message, string $senderId, array $who = []): array
    {
        $account = Accounts::find($accountId);

        if ($account === null) {
            return ['ok' => false, 'error' => 'There is no such SMS account.'];
        }

        if ($account['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This SMS account is suspended.'];
        }

        $message = self::clean($message);

        if ($message === '') {
            return ['ok' => false, 'error' => 'The message is empty.'];
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => 'The message is longer than ' . self::MAX_LENGTH . ' characters.'];
        }

        $sender = self::approvedSender($accountId, $senderId);

        if ($sender === null) {
            return ['ok' => false, 'error' => "Sender ID '" . $senderId . "' is not approved for this account."];
        }

        $seen = [];
        $recipients = [];
        $invalid = [];

        foreach ($phones as $raw) {
            $phone = self::normalizePhone((string) $raw);

            if ($phone === null) {
                $invalid[] = (string) $raw;
            } elseif (!isset($seen[$phone])) {
                $seen[$phone] = true;
                $recipients[] = ['phone' => $phone, 'message' => $message];
            }
        }

        if ($recipients === []) {
            return ['ok' => false, 'error' => 'None of those is a valid phone number.', 'invalid' => $invalid];
        }

        $size = self::measure($message);
        $source = $who['source'] ?? 'portal';

        // Checked up front so a send that cannot be afforded is refused as a
        // whole, with the real number, rather than going out in part.
        $need = count($recipients) * $size['parts'];

        if (Wallet::balance($accountId) < $need) {
            return [
                'ok'    => false,
                'error' => 'Not enough SMS units: this needs ' . self::units($need)
                         . ' and the account holds ' . self::units(Wallet::balance($accountId)) . '.',
            ];
        }

        $sent = 0;
        $failed = 0;
        $units = 0.0;
        $ids = [];
        $results = [];

        foreach (array_chunk($recipients, self::FLUSH_SIZE) as $chunk) {
            $outcome = self::dispatch($accountId, $sender, $chunk, $size['parts'], null, $size['unicode'], $source, $who);

            $sent   += $outcome['sent'];
            $failed += $outcome['failed'];
            $units  += $outcome['units'];
            $ids     = array_merge($ids, $outcome['ids']);
            $results = array_merge($results, $outcome['results']);
        }

        Alerts::lowBalanceCheck($accountId);

        return [
            'ok'          => $sent > 0,
            'error'       => $sent > 0 ? null : ($results[0]['reason'] ?? 'Nothing was sent.'),
            'submitted'   => count($recipients),
            'sent'        => $sent,
            'failed'      => $failed,
            'invalid'     => $invalid,
            'units'       => round($units, 4),
            'balance'     => Wallet::balance($accountId),
            'message_ids' => $ids,
            'results'     => $results,
        ];
    }

    // =================================================================
    // Campaigns
    // =================================================================

    /**
     * Put a campaign on the queue.
     *
     * Checks what can be checked now — the sender, the message, that the
     * account can afford at least the audience it can count — and leaves
     * the sending to a worker. A file's size is not known until the
     * worker reads it, so a file campaign is only checked for having any
     * units at all; the engine refuses batches it cannot pay for.
     *
     * @param array{name:string, sender_id:string, message:string, group_id?:?int,
     *              recipients?:?string, file_path?:?string, scheduled_at?:?string,
     *              source?:string, actor_type?:string, actor_id?:int} $data
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function queueCampaign(int $accountId, array $data): array
    {
        $account = Accounts::find($accountId);

        if ($account === null || $account['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This SMS account cannot send.'];
        }

        $message = self::clean((string) ($data['message'] ?? ''));
        $name    = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $name = 'Campaign ' . date('j M Y, H:i');
        }

        if ($message === '') {
            return ['ok' => false, 'error' => 'Write the message first.'];
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => 'The message is longer than ' . self::MAX_LENGTH . ' characters.'];
        }

        $sender = self::approvedSender($accountId, (string) ($data['sender_id'] ?? ''));

        if ($sender === null) {
            return ['ok' => false, 'error' => 'Choose one of your approved sender IDs.'];
        }

        $groupId    = !empty($data['group_id']) ? (int) $data['group_id'] : null;
        $numbers    = trim((string) ($data['recipients'] ?? ''));
        $file       = !empty($data['file_path']) ? (string) $data['file_path'] : null;

        if ($groupId !== null) {
            $owns = Database::scalar(
                'SELECT 1 FROM bulk_contact_groups WHERE id = :g AND account_id = :a',
                ['g' => $groupId, 'a' => $accountId]
            );

            if (!$owns) {
                return ['ok' => false, 'error' => 'That contact group does not belong to this account.'];
            }
        }

        $count = 0;

        if ($file !== null) {
            $count = 0; // known only once the worker has read it
        } elseif ($groupId !== null) {
            $count = (int) Database::scalar(
                'SELECT COUNT(*) FROM bulk_contacts WHERE group_id = :g AND account_id = :a',
                ['g' => $groupId, 'a' => $accountId]
            );
        } elseif ($numbers !== '') {
            $count = count(self::splitNumbers($numbers));
        }

        if ($file === null && $count === 0) {
            return ['ok' => false, 'error' => 'There is nobody to send to.'];
        }

        $size = self::measure($message);
        $need = $count * $size['parts'];
        $balance = Wallet::balance($accountId);

        if ($balance <= 0 || ($need > 0 && $balance < $need)) {
            return [
                'ok'    => false,
                'error' => 'Not enough SMS units: this needs ' . self::units(max($need, $size['parts']))
                         . ' and the account holds ' . self::units($balance) . '.',
            ];
        }

        $scheduled = null;

        if (!empty($data['scheduled_at'])) {
            $ts = strtotime((string) $data['scheduled_at']);

            if ($ts === false) {
                return ['ok' => false, 'error' => 'That is not a date and time we can read.'];
            }

            // A time a minute or less away is "now"; a time in the past is
            // almost certainly a mistake in the form, not a wish.
            if ($ts < time() - 60) {
                return ['ok' => false, 'error' => 'The scheduled time has already passed.'];
            }

            if ($ts > time() + 60) {
                $scheduled = date('Y-m-d H:i:s', $ts);
            }
        }

        $id = Database::insert('bulk_campaigns', [
            'account_id'   => $accountId,
            'name'         => mb_substr($name, 0, 180),
            'sender_id'    => $sender,
            'message'      => $message,
            'group_id'     => $groupId,
            'recipients'   => $file === null && $groupId === null ? implode(',', self::splitNumbers($numbers)) : null,
            'file_path'    => $file,
            'total_count'  => $count,
            'status'       => $scheduled !== null ? 'scheduled' : 'queued',
            'scheduled_at' => $scheduled,
            'source'       => $data['source'] ?? 'portal',
            'actor_type'   => $data['actor_type'] ?? 'system',
            'actor_id'     => $data['actor_id'] ?? null,
        ]);

        if ($scheduled === null) {
            Worker::kick();
        }

        return ['ok' => true, 'id' => $id, 'scheduled' => $scheduled !== null];
    }

    /**
     * Cancel a campaign that has not finished.
     *
     * A running worker notices at its next batch; anything already handed
     * to the gateway has gone and is not refunded, because it was sent.
     */
    public static function cancelCampaign(int $campaignId, int $accountId): bool
    {
        return Database::run(
            "UPDATE bulk_campaigns SET status = 'cancelled', locked_at = NULL
              WHERE id = :id AND account_id = :a
                AND status IN ('draft','scheduled','queued','sending')",
            ['id' => $campaignId, 'a' => $accountId]
        )->rowCount() > 0;
    }

    /**
     * Put a failed or cancelled campaign back on the queue.
     *
     * The counts are kept, and the engine skips that many recipients — so
     * a retry sends to the ones that were never reached, not to everybody
     * again.
     */
    public static function retryCampaign(int $campaignId): bool
    {
        $done = Database::run(
            "UPDATE bulk_campaigns SET status = 'queued', locked_at = NULL, last_heartbeat_at = NULL,
                    failure_reason = NULL
              WHERE id = :id AND status IN ('failed','cancelled')",
            ['id' => $campaignId]
        )->rowCount() > 0;

        if ($done) {
            Worker::kick();
        }

        return $done;
    }

    /**
     * Send a campaign. Called by the worker, never by a web request.
     *
     * Returns quietly if another worker got there first.
     */
    public static function runCampaign(int $campaignId): void
    {
        $claimed = Database::run(
            "UPDATE bulk_campaigns SET status = 'sending', locked_at = NOW(), last_heartbeat_at = NOW()
              WHERE id = :id AND status IN ('queued','scheduled')",
            ['id' => $campaignId]
        )->rowCount();

        if ($claimed === 0) {
            return;
        }

        $campaign = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $campaignId]);

        if ($campaign === null) {
            return;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '256M');

        try {
            self::sendCampaign($campaign);
        } catch (CampaignCancelled) {
            Database::run(
                'UPDATE bulk_campaigns SET locked_at = NULL, sent_at = NOW() WHERE id = :id',
                ['id' => $campaignId]
            );
        } catch (\Throwable $e) {
            Logger::error('Bulk SMS campaign #' . $campaignId . ' crashed: ' . $e->getMessage(), [
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            self::failCampaign($campaignId, 'The send stopped with an error. Retry to carry on from where it stopped.');
        }
    }

    private static function sendCampaign(array $campaign): void
    {
        $id        = (int) $campaign['id'];
        $accountId = (int) $campaign['account_id'];
        $template  = (string) $campaign['message'];

        $sender = self::approvedSender($accountId, (string) $campaign['sender_id']);

        if ($sender === null) {
            self::failCampaign($id, 'The sender ID is no longer approved for this account.');
            return;
        }

        $account = Accounts::find($accountId);

        if ($account === null || $account['status'] !== 'active') {
            self::failCampaign($id, 'The account is suspended.');
            return;
        }

        $size    = self::measure($template);
        $pauseUs = max(0, Settings::int('onfon_batch_delay_ms', 100)) * 1000;
        $source  = (string) $campaign['source'];

        // A rescued or retried campaign keeps its counts; that many
        // recipients have already been dealt with and are skipped.
        $skip   = (int) $campaign['sent_count'] + (int) $campaign['failed_count'];
        $sent   = (int) $campaign['sent_count'];
        $failed = (int) $campaign['failed_count'];
        $units  = (float) $campaign['units_used'];

        $batch   = [];
        $invalid = [];
        $flushes = 0;

        $flush = function () use (&$batch, &$sent, &$failed, &$units, &$flushes, $accountId, $sender, $size, $id, $pauseUs, $source): void {
            if ($batch === []) {
                return;
            }

            if ($flushes > 0 && $pauseUs > 0) {
                usleep($pauseUs);
            }

            $flushes++;

            $outcome = self::dispatch($accountId, $sender, $batch, $size['parts'], $id, $size['unicode'], $source, []);

            $sent   += $outcome['sent'];
            $failed += $outcome['failed'];
            $units  += $outcome['units'];

            // For a group, the furthest contact this batch reached. Saved
            // with the counts after every batch rather than after every
            // page of a thousand, so a worker that dies resends at most
            // the batch it was in the middle of.
            $reached = 0;
            foreach ($batch as $item) {
                $reached = max($reached, (int) ($item['cid'] ?? 0));
            }

            $batch = [];

            // Progress and heartbeat together: proof the worker is alive.
            Database::run(
                'UPDATE bulk_campaigns SET sent_count = :s, failed_count = :f, units_used = :u,
                        resume_contact_id = GREATEST(resume_contact_id, :c),
                        last_heartbeat_at = NOW() WHERE id = :id',
                ['s' => $sent, 'f' => $failed, 'u' => $units, 'c' => $reached, 'id' => $id]
            );

            $status = Database::scalar('SELECT status FROM bulk_campaigns WHERE id = :id', ['id' => $id]);

            if ($status === 'cancelled') {
                throw new CampaignCancelled();
            }

            // Out of units part-way: stop rather than log every remaining
            // recipient as a failure one batch at a time.
            if ($outcome['broke'] ?? false) {
                throw new OutOfUnits();
            }
        };

        $logInvalid = function (bool $force = false) use (&$invalid, $accountId, $sender, $id, $template, $source): void {
            if ($invalid === [] || (!$force && count($invalid) < 500)) {
                return;
            }

            self::logInvalid($accountId, $sender, $id, $template, $invalid, $source);
            $invalid = [];
        };

        try {
            // ---- Source 1: an uploaded file --------------------------
            $file = $campaign['file_path'] ? (string) $campaign['file_path'] : null;

            if ($file !== null && is_file($file)) {
                $file = self::prepareFile($id, $file);

                if ($file === null) {
                    return;
                }

                $fh = fopen($file, 'r');

                if ($fh === false) {
                    throw new RuntimeException('Cannot open the campaign file.');
                }

                $headers = array_map(static fn($h): string => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);

                // The first column that looks like a phone number column.
                $phoneAt = -1;
                foreach ($headers as $i => $h) {
                    if (in_array($h, ['phone', 'mobile', 'number', 'contact'], true)
                        || str_contains($h, 'phone') || str_contains($h, 'mobile')) {
                        $phoneAt = $i;
                        break;
                    }
                }

                if ($phoneAt === -1) {
                    fclose($fh);
                    @unlink($file);
                    self::failCampaign($id, 'The file has no column called phone, mobile or number.');
                    return;
                }

                $headers[$phoneAt] = 'phone';
                $row = 0;

                while (($cells = fgetcsv($fh)) !== false) {
                    if ($row++ < $skip) {
                        continue;
                    }

                    $raw   = trim((string) ($cells[$phoneAt] ?? ''));
                    $phone = self::normalizePhone($raw);

                    if ($phone === null) {
                        if ($raw === '' && count(array_filter($cells, static fn($c) => trim((string) $c) !== '')) === 0) {
                            continue; // a blank line at the end of the sheet
                        }
                        $failed++;
                        $invalid[] = $raw;
                        $logInvalid();
                        continue;
                    }

                    // ##Name##, ##name## and {name} all work: the first two
                    // are what the old platform's customers already use.
                    $text = $template;
                    foreach ($headers as $i => $h) {
                        $value = trim((string) ($cells[$i] ?? ''));
                        $text  = str_replace(['##' . ucfirst($h) . '##', '##' . $h . '##', '{' . $h . '}'], $value, $text);
                    }

                    $batch[] = ['phone' => $phone, 'message' => $text];

                    if (count($batch) >= self::FLUSH_SIZE) {
                        $flush();
                    }
                }

                fclose($fh);

                Database::run(
                    'UPDATE bulk_campaigns SET total_count = GREATEST(total_count, :n) WHERE id = :id',
                    ['n' => $row, 'id' => $id]
                );
            }

            // ---- Source 2: a contact group ---------------------------
            if ($campaign['group_id']) {
                $groupId = (int) $campaign['group_id'];
                $after   = (int) $campaign['resume_contact_id'];

                do {
                    $contacts = Database::all(
                        'SELECT id, name, phone, metadata FROM bulk_contacts
                          WHERE group_id = :g AND account_id = :a AND id > :after
                          ORDER BY id LIMIT 1000',
                        ['g' => $groupId, 'a' => $accountId, 'after' => $after]
                    );

                    foreach ($contacts as $c) {
                        $phone = self::normalizePhone((string) $c['phone']);

                        if ($phone === null) {
                            $failed++;
                            $invalid[] = (string) $c['phone'];
                            $logInvalid();
                            continue;
                        }

                        $fields = ['name' => (string) $c['name']];
                        $meta   = $c['metadata'] ? json_decode((string) $c['metadata'], true) : null;

                        if (is_array($meta)) {
                            $fields = array_merge($fields, $meta);
                        }

                        $text = $template;
                        foreach ($fields as $k => $v) {
                            if (is_scalar($v)) {
                                $text = str_replace(['{' . $k . '}', '##' . $k . '##', '##' . ucfirst((string) $k) . '##'], (string) $v, $text);
                            }
                        }

                        $batch[] = ['phone' => $phone, 'message' => $text, 'cid' => (int) $c['id']];

                        if (count($batch) >= self::FLUSH_SIZE) {
                            $flush();
                        }
                    }

                    if ($contacts !== []) {
                        $after = (int) end($contacts)['id'];
                    }
                } while (count($contacts) === 1000);
            }

            // ---- Source 3: typed numbers -----------------------------
            if (!empty($campaign['recipients'])) {
                $numbers = self::splitNumbers((string) $campaign['recipients']);

                if ($skip > 0) {
                    $numbers = array_slice($numbers, $skip);
                }

                foreach ($numbers as $raw) {
                    $phone = self::normalizePhone($raw);

                    if ($phone === null) {
                        $failed++;
                        $invalid[] = $raw;
                        $logInvalid();
                        continue;
                    }

                    $batch[] = ['phone' => $phone, 'message' => $template];

                    if (count($batch) >= self::FLUSH_SIZE) {
                        $flush();
                    }
                }
            }

            $flush();
        } catch (OutOfUnits) {
            $logInvalid(true);
            self::finish($id, $sent, $failed, $units, 'failed', 'The account ran out of SMS units. Top up and retry to send to the rest.');
            Alerts::campaignFinished($id);
            Alerts::lowBalanceCheck($accountId);
            return;
        }

        $logInvalid(true);

        self::finish($id, $sent, $failed, $units, 'completed');

        // Removed only once the campaign is marked finished, so a worker
        // killed before this point still has the file to resume from.
        if (!empty($campaign['file_path'])) {
            foreach ([(string) $campaign['file_path'], preg_replace('/\.xlsx$/i', '.csv', (string) $campaign['file_path'])] as $path) {
                if ($path && is_file($path)) {
                    @unlink($path);
                }
            }
        }

        Alerts::campaignFinished($id);
        Alerts::lowBalanceCheck($accountId);
    }

    /** An .xlsx is converted in the worker, never in the upload request. */
    private static function prepareFile(int $campaignId, string $file): ?string
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'xlsx') {
            return $file;
        }

        $csv = (string) preg_replace('/\.xlsx$/i', '.csv', $file);

        try {
            $rows = XlsxReader::toCsv($file, $csv);
        } catch (\RuntimeException $e) {
            Logger::warning('Bulk SMS campaign #' . $campaignId . ' spreadsheet: ' . $e->getMessage());
            self::failCampaign($campaignId, 'The spreadsheet could not be read. Save it as .xlsx or .csv and try again.');
            return null;
        }

        @unlink($file);

        Database::run(
            'UPDATE bulk_campaigns SET file_path = :f, total_count = :n WHERE id = :id',
            ['f' => $csv, 'n' => $rows, 'id' => $campaignId]
        );

        return $csv;
    }

    private static function finish(int $id, int $sent, int $failed, float $units, string $status, ?string $reason = null): void
    {
        Database::run(
            'UPDATE bulk_campaigns
                SET status = :st, total_count = GREATEST(total_count, :total),
                    sent_count = :s, failed_count = :f, units_used = :u,
                    failure_reason = :r, sent_at = NOW(), locked_at = NULL
              WHERE id = :id',
            ['st' => $status, 'total' => $sent + $failed, 's' => $sent, 'f' => $failed,
             'u' => $units, 'r' => $reason, 'id' => $id]
        );
    }

    private static function failCampaign(int $id, string $reason): void
    {
        Database::run(
            "UPDATE bulk_campaigns SET status = 'failed', failure_reason = :r, locked_at = NULL
              WHERE id = :id AND status <> 'cancelled'",
            ['r' => mb_substr($reason, 0, 255), 'id' => $id]
        );
    }

    // =================================================================
    // The one place money meets the gateway
    // =================================================================

    /**
     * Charge, send, refund, and log one batch.
     *
     * @param list<array{phone:string, message:string}> $recipients
     * @return array{sent:int, failed:int, units:float, ids:list<int>, results:list<array>, broke:bool}
     */
    private static function dispatch(
        int $accountId,
        string $sender,
        array $recipients,
        int $parts,
        ?int $campaignId,
        bool $unicode,
        string $source,
        array $who
    ): array {
        $count = count($recipients);
        $cost  = $count * $parts;
        $meta  = [
            'ref_type'   => $campaignId !== null ? 'campaign' : 'send',
            'ref_id'     => $campaignId,
            'actor_type' => $who['actor_type'] ?? 'system',
            'actor_id'   => $who['actor_id'] ?? null,
        ];

        $charged = Wallet::debit($accountId, $cost, 'send', $meta + [
            'note' => $count . ' message' . ($count === 1 ? '' : 's') . ' × ' . $parts . ' part' . ($parts === 1 ? '' : 's'),
        ]);

        if ($charged === null) {
            $reasons = array_fill(0, $count, 'Insufficient SMS balance');
            $ids = self::log($accountId, $sender, $recipients, $parts, $campaignId, [], $reasons, $source);

            return [
                'sent'    => 0,
                'failed'  => $count,
                'units'   => 0.0,
                'ids'     => $ids,
                'results' => self::results($recipients, $ids, [], $reasons),
                'broke'   => true,
            ];
        }

        $batches = array_chunk($recipients, Onfon::BATCH_SIZE);
        $answers = Onfon::sendBatches($batches, $sender, $unicode);

        $gatewayIds = [];
        $reasons    = [];
        $offset     = 0;

        foreach ($batches as $b => $batch) {
            $answer = $answers[$b] ?? ['sent' => [], 'failed' => range(0, count($batch) - 1), 'reasons' => []];

            foreach ($answer['sent'] as $s) {
                $gatewayIds[$offset + $s['idx']] = $s['msg_id'];
            }

            foreach ($answer['failed'] as $f) {
                $reasons[$offset + $f] = $answer['reasons'][$f] ?? 'Refused by the gateway';
            }

            $offset += count($batch);
        }

        $failed = count($reasons);
        $sent   = $count - $failed;

        if ($failed > 0) {
            Wallet::credit($accountId, $failed * $parts, 'refund', $meta + [
                'note' => $failed . ' refused by the gateway',
            ]);
        }

        if ($sent === 0) {
            Logger::warning(sprintf('Bulk SMS: a whole batch of %d failed for account #%d (sender %s): %s',
                $count, $accountId, $sender, reset($reasons) ?: 'no reason given'));
        }

        $ids = self::log($accountId, $sender, $recipients, $parts, $campaignId, $gatewayIds, $reasons, $source);

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'units'   => (float) ($sent * $parts),
            'ids'     => $ids,
            'results' => self::results($recipients, $ids, $gatewayIds, $reasons),
            'broke'   => false,
        ];
    }

    /**
     * One INSERT for a whole batch. Returns the new row ids in order.
     *
     * @param array<int,?string> $gatewayIds index => Onfon message id, for those sent
     * @param array<int,string>  $reasons    index => why it failed, for those that did
     * @return list<int>
     */
    private static function log(
        int $accountId,
        string $sender,
        array $recipients,
        int $parts,
        ?int $campaignId,
        array $gatewayIds,
        array $reasons,
        string $source
    ): array {
        if ($recipients === []) {
            return [];
        }

        $rows = [];
        $params = [];
        $now = date('Y-m-d H:i:s');

        foreach (array_values($recipients) as $i => $r) {
            $failed = isset($reasons[$i]);
            $rows[] = '(?,?,?,?,?,?,?,?,?,?,?)';
            array_push($params,
                $campaignId, $accountId, $sender, $r['phone'], $r['message'],
                $failed ? 0 : $parts,
                $failed ? 'failed' : 'sent',
                $failed ? null : ($gatewayIds[$i] ?? null),
                $failed ? null : $now,
                $failed ? mb_substr((string) $reasons[$i], 0, 500) : null,
                $source
            );
        }

        Database::run(
            'INSERT INTO bulk_messages
               (campaign_id, account_id, sender_id, recipient, message, units_charged,
                status, gateway_msg_id, sent_at, failed_reason, source)
             VALUES ' . implode(',', $rows),
            $params
        );

        // A multi-row INSERT hands out consecutive ids starting at
        // lastInsertId() — guaranteed by InnoDB for a single statement
        // under the default lock mode, which is what cPanel ships.
        $first = (int) Database::pdo()->lastInsertId();

        return range($first, $first + count($recipients) - 1);
    }

    /** Invalid numbers, logged as failed at no charge. */
    private static function logInvalid(int $accountId, string $sender, ?int $campaignId, string $message, array $phones, string $source): void
    {
        if ($phones === []) {
            return;
        }

        $rows = [];
        $params = [];

        foreach ($phones as $p) {
            $rows[] = "(?,?,?,?,?,0,'failed',?,?)";
            array_push($params, $campaignId, $accountId, $sender, mb_substr((string) $p, 0, 20), $message,
                'Invalid or unrecognised phone number', $source);
        }

        Database::run(
            'INSERT INTO bulk_messages
               (campaign_id, account_id, sender_id, recipient, message, units_charged, status, failed_reason, source)
             VALUES ' . implode(',', $rows),
            $params
        );
    }

    private static function results(array $recipients, array $ids, array $gatewayIds, array $reasons): array
    {
        $out = [];

        foreach (array_values($recipients) as $i => $r) {
            $out[] = [
                'id'         => $ids[$i] ?? null,
                'recipient'  => $r['phone'],
                'status'     => isset($reasons[$i]) ? 'failed' : 'sent',
                'gateway_id' => $gatewayIds[$i] ?? null,
                'reason'     => $reasons[$i] ?? null,
            ];
        }

        return $out;
    }

    // =================================================================

    /** Numbers typed or pasted: commas, spaces, new lines, semicolons. */
    public static function splitNumbers(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', $text) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn($p) => $p !== '')));
    }

    /** Line endings normalised and the ends trimmed. */
    private static function clean(string $message): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $message));
    }

    public static function units(float $units): string
    {
        return rtrim(rtrim(number_format($units, 2, '.', ','), '0'), '.') . ' unit' . (abs($units - 1) < 0.00001 ? '' : 's');
    }
}

/** Thrown between batches when the campaign has been cancelled. */
final class CampaignCancelled extends \RuntimeException {}

/** Thrown between batches when the account can no longer pay. */
final class OutOfUnits extends \RuntimeException {}
