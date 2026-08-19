<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\JobBrief;
use App\Services\Notifier;
use App\Services\StaffNotifier;

/**
 * The brief, as the client fills it in.
 *
 * There is no client login anywhere in this system, so the token in the
 * link is the credential: whoever holds it was sent it. That means the
 * page must give away nothing a stranger with a guessed URL should not
 * see, and 48 hex characters is far past guessing.
 *
 * This is the one page in the system a client fills in themselves, and
 * most of them will do it on a phone between other things. So: no
 * account, no password, one screen, and everything they type is kept if
 * something goes wrong rather than making them start again.
 */
class PublicJobRequestController extends Controller
{
    /** The form. */
    public function show(Request $request): void
    {
        $req = $this->findByToken((string) $request->param('token'));

        // Somebody has opened it. Worth knowing on our side: a request
        // opened three times and never sent back means the form is asking
        // for something the client cannot answer.
        if ($req['opened_at'] === null) {
            Database::update('job_requests', [
                'opened_at' => date('Y-m-d H:i:s'),
                'status'    => $req['status'] === 'sent' ? 'opened' : $req['status'],
            ], ['id' => $req['id']]);
        }

        $done = in_array($req['status'], ['submitted', 'actioned'], true) && $req['submitted_at'] !== null;

        $this->view('public/brief', [
            'title'    => 'Tell us what you need',
            'request'  => $req,
            'fields'   => JobBrief::fields($req['brief_type']),
            'answers'  => $this->answersFor((int) $req['id']),
            'files'    => Database::all(
                'SELECT id, original_name, bytes FROM job_request_files WHERE request_id = :id ORDER BY id',
                ['id' => $req['id']]
            ),
            'heading'  => JobBrief::HEADINGS[$req['brief_type']],
            'blurb'    => JobBrief::BLURBS[$req['brief_type']],
            'done'     => $done,
            'company'  => Settings::company(),
            'maxMb'    => max(1, Settings::int('job_request_max_mb', 10)),
        ], 'public');
    }

    /** What they sent back. */
    public function submit(Request $request): void
    {
        $token = (string) $request->param('token');
        $req   = $this->findByToken($token);

        if (in_array($req['status'], ['actioned', 'cancelled'], true)) {
            Session::error('This form has already been dealt with. Please call us if something has changed.');
            Response::to('/brief/' . $token);
        }

        $missing = JobRequestController::missingRequired($req['brief_type'], $request);

        if ($missing !== []) {
            // Handing back a blank form loses everything they typed, which
            // on a phone is the point at which people give up.
            Session::flashInput($request->all());
            Session::error('Please answer: ' . implode(', ', $missing));
            Response::to('/brief/' . $token);
        }

        JobRequestController::saveAnswers((int) $req['id'], $req['brief_type'], $request);

        $uploaded = $this->storeAttachments((int) $req['id']);

        $first = $req['submitted_at'] === null;

        Database::update('job_requests', [
            'status'       => 'submitted',
            'submitted_at' => $req['submitted_at'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $req['id']]);

        // The people who can act on it, not everybody.
        if ($first) {
            StaffNotifier::notify(
                $this->interested($req),
                [
                    'event'       => 'job_request_submitted',
                    'title'       => $req['client_name'] . ' has sent their ' . strtolower(JobBrief::TYPES[$req['brief_type']]) . ' brief',
                    'body'        => $req['reference'] . ' — ' . ($uploaded > 0 ? $uploaded . ' file(s) attached.' : 'No attachments.'),
                    'link'        => '/requests/' . $req['id'],
                    'entity_type' => 'job_request',
                    'entity_id'   => (int) $req['id'],
                ],
                ['email' => true, 'sms' => false]
            );
        }

        Session::success('Thank you — we have got it.');
        Response::to('/brief/' . $token);
    }

    // -- Internals ---------------------------------------------------------

    /**
     * Save whatever the client attached.
     *
     * A bad file is skipped with a message rather than losing the whole
     * submission: the answers matter more than the attachment, and asking
     * somebody to type it all again because one image was the wrong format
     * is the wrong trade.
     */
    private function storeAttachments(int $requestId): int
    {
        $files = $_FILES['attachments'] ?? null;

        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return 0;
        }

        $saved = 0;
        $count = min(count($files['name']), 10);

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $one = [
                'name'     => $files['name'][$i]     ?? '',
                'type'     => $files['type'][$i]     ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error'    => $files['error'][$i]    ?? UPLOAD_ERR_OK,
                'size'     => $files['size'][$i]     ?? 0,
            ];

            try {
                $stored = $this->storeUpload($one, 'briefs/' . $requestId);
            } catch (\Throwable $e) {
                Session::error($one['name'] . ' was not saved: ' . $e->getMessage());
                continue;
            }

            if ($stored === null) {
                continue;
            }

            Database::insert('job_request_files', [
                'request_id'    => $requestId,
                'original_name' => mb_substr((string) $one['name'], 0, 255),
                'stored_name'   => $stored,
                'mime'          => mb_substr((string) $one['type'], 0, 120),
                'bytes'         => (int) $one['size'],
            ]);

            $saved++;
        }

        return $saved;
    }

    /**
     * Who to tell. Whoever raised it, plus the people who would act on it.
     *
     * @return array<int,int>
     */
    private function interested(array $req): array
    {
        $ids = StaffNotifier::withRole(['admin', 'manager']);

        if (!empty($req['created_by'])) {
            $ids[] = (int) $req['created_by'];
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string,string> */
    private function answersFor(int $id): array
    {
        $rows = Database::all(
            'SELECT field_key, answer FROM job_request_answers WHERE request_id = :id',
            ['id' => $id]
        );

        return array_column($rows, 'answer', 'field_key');
    }

    /**
     * Resolve the full token from the emailed link, or the short prefix
     * used in a text message. An ambiguous prefix is refused rather than
     * guessed at.
     */
    private function findByToken(string $token): array
    {
        $token = strtolower(trim($token));
        $short = Notifier::SHORT_TOKEN_LENGTH;

        if (!preg_match('/^[a-f0-9]{48}$/', $token) && !preg_match('/^[a-f0-9]{' . $short . '}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $isShort = strlen($token) === $short;

        $rows = Database::all(
            'SELECT r.*, c.name AS client_name, c.contact_person
               FROM job_requests r
               JOIN clients c ON c.id = r.client_id
              WHERE ' . ($isShort ? 'LEFT(r.public_token, :len) = :token' : 'r.public_token = :token') . '
              LIMIT 2',
            $isShort ? ['len' => $short, 'token' => $token] : ['token' => $token]
        );

        if (count($rows) !== 1) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to send it again.');
        }

        if ($rows[0]['status'] === 'cancelled') {
            throw new HttpException(404, 'This request has been withdrawn. Please contact us.');
        }

        return $rows[0];
    }
}
