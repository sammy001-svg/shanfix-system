<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\ArtworkFlow;
use App\Services\Notifier;

/**
 * Where the client sees their artwork and says yes or not yet.
 *
 * No login: the token is the credential, exactly as for a shared invoice
 * or a proof on a job. Shows the artwork, the brief it was made against,
 * and nothing else about the account.
 */
class PublicArtworkController extends Controller
{
    public function show(Request $request): void
    {
        $artwork = $this->findByToken((string) $request->param('token'));

        if (empty($artwork['viewed_at'])) {
            Database::update(
                'artwork_requests',
                ['viewed_at' => date('Y-m-d H:i:s')],
                ['id' => $artwork['id']]
            );
        }

        $proof = ArtworkFlow::latestProof((int) $artwork['id']);

        $this->view('public/artwork', [
            'title'   => 'Artwork ' . $artwork['request_number'],
            'artwork' => $artwork,
            'proof'   => $proof,
            'company' => Settings::company(),
            'isImage' => $proof !== null && $this->isImage($proof['file_path']),
            'history' => Database::all(
                "SELECT version, status, client_feedback, decided_at
                   FROM artwork_files
                  WHERE request_id = :id AND file_type = 'proof' AND status <> 'pending'
               ORDER BY version DESC",
                ['id' => $artwork['id']]
            ),
        ], 'public');
    }

    /** Stream the proof itself, gated by the same token. */
    public function file(Request $request): void
    {
        $artwork = $this->findByToken((string) $request->param('token'));
        $proof   = ArtworkFlow::latestProof((int) $artwork['id']);

        if ($proof === null) {
            throw new HttpException(404, 'There is no artwork to show yet.');
        }

        $full = realpath(STORAGE_PATH . '/' . $proof['file_path']);
        $root = realpath(STORAGE_PATH . '/uploads');

        if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
            throw new HttpException(404, 'That file is missing. Please ask us to resend it.');
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $full) ?: $mime;
            finfo_close($finfo);
        }

        $inlineSafe = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

        header('Content-Type: ' . $mime);
        header('Content-Disposition: '
            . (in_array($mime, $inlineSafe, true) ? 'inline' : 'attachment')
            . '; filename="' . basename($proof['file_name']) . '"');
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;
    }

    /** Approve, or ask for changes. */
    public function decide(Request $request): void
    {
        $token   = (string) $request->param('token');
        $artwork = $this->findByToken($token);

        if ($artwork['status'] !== 'proof_sent') {
            Session::info('This artwork is not waiting on you at the moment.');
            Response::to('/review/' . $token);
        }

        $decision = (string) $request->input('decision', '');

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'That decision is not valid.');
        }

        $feedback = trim((string) $request->input('client_feedback', ''));

        if ($decision === 'rejected' && $feedback === '') {
            Session::error('Please tell us what needs changing, so our designer knows what to do.');
            Response::to('/review/' . $token);
        }

        $name = trim((string) $request->input('approved_name', ''));

        if ($decision === 'approved' && $name === '') {
            Session::error('Please type your name to approve the artwork.');
            Response::to('/review/' . $token);
        }

        ArtworkFlow::recordDecision($artwork, $decision, $feedback, 'client', $name ?: null);

        Session::success($decision === 'approved'
            ? 'Thank you — your approval is recorded and the work is going into production.'
            : 'Thank you — your comments have gone to our designer, who will send a revised version.');

        Response::to('/review/' . $token);
    }

    // -- internals -------------------------------------------------------

    private function findByToken(string $token): array
    {
        $token = strtolower(trim($token));

        if (!preg_match('/^[a-f0-9]{48}$/', $token)
            && !preg_match('/^[a-f0-9]{' . Notifier::SHORT_TOKEN_LENGTH . '}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $isShort = strlen($token) === Notifier::SHORT_TOKEN_LENGTH;

        $rows = Database::all(
            'SELECT a.*, c.name AS client_name, c.contact_person AS client_contact
               FROM artwork_requests a
               JOIN clients c ON c.id = a.client_id
              WHERE ' . ($isShort ? 'LEFT(a.public_token, :len) = :token' : 'a.public_token = :token') . '
              LIMIT 2',
            $isShort
                ? ['len' => Notifier::SHORT_TOKEN_LENGTH, 'token' => $token]
                : ['token' => $token]
        );

        if (count($rows) !== 1) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to resend it.');
        }

        if ($rows[0]['status'] === 'cancelled') {
            throw new HttpException(410, 'This artwork request has been withdrawn. Please contact us.');
        }

        return $rows[0];
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
