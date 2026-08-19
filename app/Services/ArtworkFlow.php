<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Numbering;
use App\Core\Settings;

/**
 * The lifecycle of an artwork request, and who gets told about it.
 *
 * Kept out of the controller because two callers drive the same
 * transitions: a member of staff recording a decision the client gave on
 * the phone, and the client pressing the button on their share link. Both
 * must move the request, log the event and notify the same people, or the
 * two paths drift apart.
 */
class ArtworkFlow
{
    public const STATUSES = [
        'requested'         => 'Requested',
        'assigned'          => 'Allocated',
        'in_progress'       => 'In progress',
        'proof_sent'        => 'With the client',
        'changes_requested' => 'Changes requested',
        'approved'          => 'Approved',
        'completed'         => 'In production',
        'cancelled'         => 'Cancelled',
    ];

    // -- Events ------------------------------------------------------------

    public static function logEvent(int $requestId, ?string $from, ?string $to, string $note): void
    {
        Database::insert('artwork_events', [
            'request_id'  => $requestId,
            'from_status' => $from,
            'to_status'   => $to,
            'note'        => mb_substr($note, 0, 500),
            'user_id'     => Auth::id(),
        ]);
    }

    // -- Telling our own people ---------------------------------------------

    /** The designer it landed on. */
    public static function notifyAssigned(array $artwork): void
    {
        if (empty($artwork['assigned_to'])) {
            return;
        }

        StaffNotifier::notify([$artwork['assigned_to']], [
            'event'       => 'artwork_assigned',
            'title'       => 'Artwork allocated to you: ' . $artwork['title'],
            'body'        => $artwork['request_number'] . ' for ' . $artwork['client_name']
                             . self::dueSuffix($artwork),
            'link'        => '/artwork/' . $artwork['id'],
            'entity_type' => 'artwork',
            'entity_id'   => (int) $artwork['id'],
        ], ['email' => true, 'sms' => true]);
    }

    /** Nobody owns it yet, so whoever runs the studio needs to see it. */
    public static function notifyStudio(array $artwork): void
    {
        StaffNotifier::notify(StaffNotifier::withRole(['admin', 'manager', 'designer']), [
            'event'       => 'artwork_requested',
            'title'       => 'New artwork request: ' . $artwork['title'],
            'body'        => $artwork['request_number'] . ' for ' . $artwork['client_name']
                             . ' — not yet allocated.' . self::dueSuffix($artwork),
            'link'        => '/artwork/' . $artwork['id'],
            'entity_type' => 'artwork',
            'entity_id'   => (int) $artwork['id'],
        ], ['email' => true, 'sms' => true]);
    }

    // -- Sending the proof out ------------------------------------------------

    /**
     * Send the newest proof to the client for approval.
     *
     * @return array{ok:bool, error?:string, message?:string, warnings:array<int,string>}
     */
    public static function sendToClient(array $artwork, array $channels): array
    {
        $proof = self::latestProof((int) $artwork['id']);

        if ($proof === null) {
            return [
                'ok'       => false,
                'error'    => 'Upload a proof before sending it — there is nothing for the client to look at.',
                'warnings' => [],
            ];
        }

        $token = self::ensureToken((int) $artwork['id'], $artwork['public_token'] ?? null);

        $context = self::clientContext($artwork, $token, $proof);

        $channels = array_values(array_intersect($channels, ['email', 'sms']));
        $result   = Notifier::dispatch('artwork_ready', $context, true, $channels ?: ['email']);

        if ($result['queued'] === 0) {
            return [
                'ok'       => false,
                'error'    => $result['skipped'][0] ?? 'Nothing could be queued for sending.',
                'warnings' => [],
            ];
        }

        Notifier::processQueue(10);

        Database::update('artwork_requests', ['status' => 'proof_sent'], ['id' => $artwork['id']]);

        self::logEvent((int) $artwork['id'], $artwork['status'], 'proof_sent',
            'Proof v' . $proof['version'] . ' sent to the client');

        ActivityLog::record('artwork_proof_sent', 'artwork', (int) $artwork['id'],
            $artwork['request_number'] . ' sent for approval');

        return [
            'ok'       => true,
            'message'  => 'Proof sent to ' . $artwork['client_name'] . ' for approval.',
            'warnings' => $result['skipped'],
        ];
    }

    // -- The decision ----------------------------------------------------------

    /**
     * Record approval or a request for changes, whoever made it.
     *
     * @param string      $via  'client' when they pressed the button
     *                          themselves, 'staff' when relayed
     * @param string|null $name Who accepted, for the client-side path
     */
    public static function recordDecision(
        array $artwork,
        string $decision,
        string $feedback,
        string $via,
        ?string $name
    ): void {
        $proof  = self::latestProof((int) $artwork['id']);
        $status = $decision === 'approved' ? 'approved' : 'changes_requested';

        Database::transaction(static function () use ($artwork, $proof, $decision, $feedback, $status, $via, $name) {
            if ($proof) {
                Database::update('artwork_files', [
                    'status'          => $decision,
                    'client_feedback' => $feedback !== '' ? mb_substr($feedback, 0, 500) : null,
                    'decided_via'     => $via,
                    'decided_at'      => date('Y-m-d H:i:s'),
                ], ['id' => $proof['id']]);
            }

            $update = ['status' => $status];

            if ($decision === 'approved') {
                $update['approved_at']   = date('Y-m-d H:i:s');
                $update['approved_name'] = $name !== null ? mb_substr($name, 0, 160) : null;
                $update['approved_ip']   = $via === 'client'
                    ? mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
                    : null;
            }

            Database::update('artwork_requests', $update, ['id' => $artwork['id']]);
        });

        self::logEvent(
            (int) $artwork['id'],
            $artwork['status'],
            $status,
            ($via === 'client' ? 'Client' : 'Staff recorded that the client')
            . ' ' . ($decision === 'approved' ? 'approved' : 'requested changes to')
            . ' proof v' . ($proof['version'] ?? '?')
            . ($feedback !== '' ? ' — ' . mb_substr($feedback, 0, 200) : '')
        );

        ActivityLog::record('artwork_' . $decision, 'artwork', (int) $artwork['id'],
            $artwork['request_number'] . ' ' . $decision . ' (' . $via . ')');

        self::notifyDecision($artwork, $decision, $feedback);
    }

    /**
     * Tell everyone who has a stake in it.
     *
     * The designer because it is their work, production because approved
     * artwork becomes their job, and management because they answer for
     * the schedule.
     */
    private static function notifyDecision(array $artwork, string $decision, string $feedback): void
    {
        $approved = $decision === 'approved';

        $recipients = array_merge(
            [$artwork['assigned_to'], $artwork['created_by']],
            StaffNotifier::withRole($approved ? ['admin', 'manager', 'production'] : ['admin', 'manager'])
        );

        StaffNotifier::notify($recipients, [
            'event'       => 'artwork_' . $decision,
            'title'       => $approved
                ? 'Artwork approved: ' . $artwork['title']
                : 'Changes requested: ' . $artwork['title'],
            'body'        => $artwork['request_number'] . ' for ' . $artwork['client_name']
                             . ($approved
                                 ? ' — approved by the client and ready for production.'
                                 : ' — the client asked for changes.'
                                   . ($feedback !== '' ? ' "' . mb_substr($feedback, 0, 200) . '"' : '')),
            'link'        => '/artwork/' . $artwork['id'],
            'entity_type' => 'artwork',
            'entity_id'   => (int) $artwork['id'],
        ], ['email' => true, 'sms' => true]);

        // And thank the client for approving, so the last word is ours.
        if ($approved && $artwork['public_token']) {
            Notifier::dispatch(
                'artwork_approved',
                self::clientContext($artwork, $artwork['public_token'], null)
            );
            Notifier::processQueue(5);
        }
    }

    // -- Into production ---------------------------------------------------------

    /**
     * Turn approved artwork into a job card.
     *
     * @return array{ok:bool, error?:string, message?:string, job_id?:int}
     */
    public static function pushToProduction(array $artwork): array
    {
        if ($artwork['status'] !== 'approved') {
            return ['ok' => false, 'error' => 'Only approved artwork goes to production.'];
        }

        if ($artwork['job_id']) {
            return ['ok' => false, 'error' => 'This artwork is already on job ' . $artwork['job_number'] . '.'];
        }

        $jobId = Database::transaction(static function () use ($artwork) {
            $jobId = Database::insert('jobs', [
                'job_number'  => Numbering::next('job'),
                'client_id'   => $artwork['client_id'],
                'document_id' => $artwork['document_id'],
                'title'       => $artwork['title'],
                'description' => $artwork['brief'],
                // The artwork is signed off, so the job starts past the
                // design stages rather than repeating them.
                'stage'       => 'approved',
                'priority'    => $artwork['priority'],
                'due_date'    => $artwork['due_date'],
                'created_by'  => Auth::id(),
            ]);

            Database::insert('job_stages', [
                'job_id'     => $jobId,
                'from_stage' => 'approved',
                'to_stage'   => 'approved',
                'notes'      => 'Raised from artwork ' . $artwork['request_number']
                                . ', approved by the client.',
                'user_id'    => Auth::id(),
            ]);

            Database::update('artwork_requests', [
                'status' => 'completed',
                'job_id' => $jobId,
            ], ['id' => $artwork['id']]);

            return $jobId;
        });

        self::logEvent((int) $artwork['id'], 'approved', 'completed', 'Pushed to production');

        ActivityLog::record('artwork_to_production', 'artwork', (int) $artwork['id'],
            $artwork['request_number'] . ' became a job card');

        StaffNotifier::notify(
            StaffNotifier::withRole(['production', 'admin', 'manager']),
            [
                'event'       => 'artwork_in_production',
                'title'       => 'Ready to produce: ' . $artwork['title'],
                'body'        => $artwork['request_number'] . ' for ' . $artwork['client_name']
                                 . ' — artwork approved, job card raised.',
                'link'        => '/jobs/' . $jobId,
                'entity_type' => 'job',
                'entity_id'   => $jobId,
            ],
            ['email' => true, 'sms' => true]
        );

        return [
            'ok'      => true,
            'job_id'  => $jobId,
            'message' => 'Job card raised from approved artwork. Production has been notified.',
        ];
    }

    // -- Shared -------------------------------------------------------------------

    public static function latestProof(int $requestId): ?array
    {
        return Database::first(
            "SELECT * FROM artwork_files
              WHERE request_id = :id AND file_type = 'proof'
           ORDER BY version DESC, id DESC LIMIT 1",
            ['id' => $requestId]
        );
    }

    public static function ensureToken(int $requestId, ?string $existing = null): string
    {
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(24));

        Database::update('artwork_requests', ['public_token' => $token], ['id' => $requestId]);

        return $token;
    }

    /** Placeholders for the client-facing messages. */
    private static function clientContext(array $artwork, string $token, ?array $proof): array
    {
        return [
            'entity_type'    => 'artwork',
            'entity_id'      => (int) $artwork['id'],
            'client_id'      => (int) $artwork['client_id'],
            'client_name'    => $artwork['client_name'] ?? '',
            'contact_name'   => self::firstName($artwork['client_contact'] ?? ($artwork['client_name'] ?? '')),
            'email'          => $artwork['client_email'] ?? '',
            'phone'          => $artwork['client_phone'] ?? '',
            'company_name'   => Settings::get('company_name', 'Shanfix Technology'),
            'company_phone'  => Settings::get('company_phone', ''),
            'request_number' => $artwork['request_number'] ?? '',
            'title'          => $artwork['title'] ?? '',
            'version'        => (string) ($proof['version'] ?? ''),
            'link'           => Notifier::absoluteUrl('/review/' . $token),
            'short_link'     => Notifier::absoluteUrl('/a/' . substr($token, 0, Notifier::SHORT_TOKEN_LENGTH)),
        ];
    }

    private static function firstName(string $name): string
    {
        $name = trim($name);

        return $name === '' ? 'there' : explode(' ', $name)[0];
    }

    private static function dueSuffix(array $artwork): string
    {
        return !empty($artwork['due_date'])
            ? ' Due ' . fdate($artwork['due_date']) . '.'
            : '';
    }
}
