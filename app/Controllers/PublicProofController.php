<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Notifier;

/**
 * The client-facing proof approval page.
 *
 * Reached from the link in a proof_ready email or SMS — no account, no
 * login, the token is the credential. The client sees the proof, then
 * approves it or asks for changes, and that decision drives the job stage
 * exactly as a staff-recorded one does (see JobFileController::decide).
 *
 * Deliberately shows nothing but the proof itself: no prices, no internal
 * notes, no other jobs.
 */
class PublicProofController extends Controller
{
    public function show(Request $request): void
    {
        $proof = $this->findByToken((string) $request->param('token'));

        // Record the first open, so the team can see it landed.
        if (empty($proof['viewed_at'])) {
            Database::update('job_files', ['viewed_at' => date('Y-m-d H:i:s')], ['id' => $proof['id']]);
            $proof['viewed_at'] = date('Y-m-d H:i:s');
        }

        $this->view('public/proof', [
            'title'   => 'Proof ' . $proof['job_number'],
            'proof'   => $proof,
            'company' => Settings::company(),
            'isImage' => $this->isImage($proof['file_path']),
        ], 'public');
    }

    /**
     * Stream the proof file itself.
     *
     * /files/... needs a session, which the client does not have, so the
     * artwork gets its own route gated by the same token.
     */
    public function file(Request $request): void
    {
        $proof = $this->findByToken((string) $request->param('token'));

        $full = realpath(STORAGE_PATH . '/' . $proof['file_path']);
        $root = realpath(STORAGE_PATH . '/uploads');

        if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
            throw new HttpException(404, 'That proof file is missing. Please ask us to resend it.');
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $full) ?: $mime;
            finfo_close($finfo);
        }

        // Only show inline what cannot carry script; everything else downloads.
        $inlineSafe  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $disposition = in_array($mime, $inlineSafe, true) ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($proof['file_name']) . '"');
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;
    }

    /**
     * The client's own decision. Mirrors JobFileController::decide(), but
     * attributed to the client rather than to a member of staff.
     */
    public function decide(Request $request): void
    {
        $token = (string) $request->param('token');
        $proof = $this->findByToken($token);

        // A proof already settled must not be flipped by a stale tab or a
        // forwarded link.
        if ($proof['status'] !== 'pending') {
            Session::info('This proof has already been ' . $proof['status'] . '. Please contact us if that is not right.');
            Response::to('/proof/' . $token);
        }

        $decision = (string) $request->input('decision', '');

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'That decision is not valid.');
        }

        $feedback = trim((string) $request->input('client_feedback', ''));

        if ($decision === 'rejected' && $feedback === '') {
            Session::error('Please tell us what needs changing, so our designer knows what to do.');
            Response::to('/proof/' . $token);
        }

        Database::transaction(function () use ($proof, $decision, $feedback) {
            Database::update('job_files', [
                'status'          => $decision,
                'client_feedback' => $feedback !== '' ? mb_substr($feedback, 0, 500) : null,
                'approved_at'     => date('Y-m-d H:i:s'),
                'decided_via'     => 'client',
                // approved_by stays NULL: no member of staff made this call.
            ], ['id' => $proof['id']]);

            $newStage = $decision === 'approved' ? 'approved' : 'artwork';

            Database::insert('job_stages', [
                'job_id'     => $proof['job_id'],
                'from_stage' => $proof['job_stage'],
                'to_stage'   => $newStage,
                'notes'      => 'Client ' . $decision . ' proof v' . $proof['version'] . ' online'
                                . ($feedback !== '' ? ' — ' . mb_substr($feedback, 0, 200) : ''),
                'user_id'    => null,
            ]);

            $update = ['stage' => $newStage];
            if ($decision === 'approved') {
                $update['hold_reason'] = null;
            }

            Database::update('jobs', $update, ['id' => $proof['job_id']]);
        });

        ActivityLog::record(
            'job_proof_' . $decision,
            'job',
            (int) $proof['job_id'],
            $proof['job_number'] . ': client ' . $decision . ' proof v' . $proof['version'] . ' online'
        );

        // Until now the client's decision reached nobody on our side until
        // somebody happened to open the job. Tell the people it affects.
        \App\Services\StaffNotifier::notify(
            array_merge(
                [$proof['assigned_to'] ?? null],
                \App\Services\StaffNotifier::withRole(
                    $decision === 'approved' ? ['admin', 'manager', 'production'] : ['admin', 'manager', 'designer']
                )
            ),
            [
                'event'       => 'proof_' . $decision,
                'title'       => $decision === 'approved'
                    ? 'Proof approved: ' . $proof['job_number']
                    : 'Changes requested: ' . $proof['job_number'],
                'body'        => $proof['job_title'] . ' for ' . $proof['client_name']
                                 . ($decision === 'approved'
                                     ? ' — cleared for production.'
                                     : ' — ' . mb_substr($feedback, 0, 200)),
                'link'        => '/jobs/' . $proof['job_id'],
                'entity_type' => 'job',
                'entity_id'   => (int) $proof['job_id'],
            ],
            ['email' => true, 'sms' => true]
        );

        Session::success(
            $decision === 'approved'
                ? 'Thank you — your approval is recorded and we are starting production.'
                : 'Thank you — your comments have gone to our designer, who will send a revised proof.'
        );

        Response::to('/proof/' . $token);
    }

    /**
     * Resolve the full token from an email link or the short prefix used
     * in SMS. An ambiguous prefix is refused rather than guessed at.
     */
    private function findByToken(string $token): array
    {
        $token = strtolower(trim($token));

        if (!preg_match('/^[a-f0-9]{48}$/', $token)
            && !preg_match('/^[a-f0-9]{' . Notifier::SHORT_TOKEN_LENGTH . '}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $isShort = strlen($token) === Notifier::SHORT_TOKEN_LENGTH;

        $rows = Database::all(
            'SELECT f.*, j.id AS job_id, j.job_number, j.title AS job_title,
                    j.stage AS job_stage, j.assigned_to, c.name AS client_name
               FROM job_files f
               JOIN jobs j ON j.id = f.job_id
               JOIN clients c ON c.id = j.client_id
              WHERE ' . ($isShort ? 'LEFT(f.public_token, :len) = :token' : 'f.public_token = :token') . '
              LIMIT 2',
            $isShort
                ? ['len' => Notifier::SHORT_TOKEN_LENGTH, 'token' => $token]
                : ['token' => $token]
        );

        if (count($rows) !== 1) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to resend it.');
        }

        $proof = $rows[0];

        // A cancelled job should not still be collecting approvals.
        if (in_array($proof['job_stage'], ['cancelled'], true)) {
            throw new HttpException(404, 'This job has been cancelled. Please contact us.');
        }

        return $proof;
    }

    private function isImage(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            true
        );
    }
}
