<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Artwork, proofs and final files attached to a job — plus the client
 * approval that has to be on record before anything goes to print.
 */
class JobFileController extends Controller
{
    private const TYPES = ['artwork', 'proof', 'reference', 'final'];

    public function upload(Request $request): void
    {
        $this->authorize('jobs.manage');

        $job  = $this->job($request->paramInt('id'));
        $file = $request->file('file');

        $v = new Validator($request->all());
        $v->in('file_type', self::TYPES, 'File type')
          ->maxLen('notes', 500, 'Notes');

        if ($file === null) {
            $v->custom('file', false, 'Choose a file to upload.');
        }

        if ($v->fails()) {
            $v->redirectBack('/jobs/' . $job['id']);
        }

        $type = (string) $request->input('file_type', 'artwork');

        // Versions run per job per type, so "Proof v3" means something.
        $version = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(version), 0) FROM job_files WHERE job_id = :id AND file_type = :type',
            ['id' => $job['id'], 'type' => $type],
            0
        );

        $stored = $this->storeUpload($file, 'artwork');

        $fileId = Database::insert('job_files', [
            'job_id'      => $job['id'],
            'file_type'   => $type,
            'file_path'   => $stored,
            'file_name'   => mb_substr((string) ($file['name'] ?? 'file'), 0, 200),
            'file_size'   => (int) ($file['size'] ?? 0),
            'version'     => $version,
            // Only proofs need signing off; artwork and references don't.
            'status'      => $type === 'proof' ? 'pending' : 'approved',
            'notes'       => $request->input('notes') ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        Database::insert('job_stages', [
            'job_id'     => $job['id'],
            'from_stage' => $job['stage'],
            'to_stage'   => $job['stage'],
            'notes'      => ucfirst($type) . ' v' . $version . ' uploaded: ' . mb_substr((string) $file['name'], 0, 80),
            'user_id'    => Auth::id(),
        ]);

        ActivityLog::record('job_file_uploaded', 'job', (int) $job['id'], ucfirst($type) . " v{$version} added to " . $job['job_number']);

        Session::success(
            ucfirst($type) . ' v' . $version . ' uploaded.'
            . ($type === 'proof' ? ' Send it to the client, then record their decision here.' : '')
        );

        Response::to('/jobs/' . $job['id']);
    }

    /**
     * Record the client's decision on a proof. We are logging what the
     * client said — the client does not log in.
     */
    public function decide(Request $request): void
    {
        $this->authorize('jobs.manage');

        $fileId   = $request->paramInt('fileId');
        $decision = (string) $request->input('decision', '');

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'That decision is not valid.');
        }

        $file = Database::first(
            'SELECT f.*, j.job_number, j.stage AS job_stage, j.id AS job_id
               FROM job_files f JOIN jobs j ON j.id = f.job_id
              WHERE f.id = :id',
            ['id' => $fileId]
        );

        if (!$file) {
            throw new HttpException(404, 'That file does not exist.');
        }

        $feedback = trim((string) $request->input('client_feedback', ''));

        if ($decision === 'rejected' && $feedback === '') {
            Session::error('Record what the client wants changed, so the designer knows.');
            Response::back('/jobs/' . $file['job_id']);
        }

        Database::transaction(function () use ($file, $decision, $feedback) {
            Database::update('job_files', [
                'status'          => $decision,
                'client_feedback' => $feedback !== '' ? mb_substr($feedback, 0, 500) : null,
                'approved_by'     => Auth::id(),
                'approved_at'     => date('Y-m-d H:i:s'),
                // A member of staff relaying what the client said, as opposed
                // to the client pressing the button on the share link.
                'decided_via'     => 'staff',
            ], ['id' => $file['id']]);

            Database::insert('job_stages', [
                'job_id'     => $file['job_id'],
                'from_stage' => $file['job_stage'],
                'to_stage'   => $decision === 'approved' ? 'approved' : 'artwork',
                'notes'      => 'Client ' . $decision . ' proof v' . $file['version']
                                . ($feedback !== '' ? ' — ' . mb_substr($feedback, 0, 200) : ''),
                'user_id'    => Auth::id(),
            ]);

            // Approval clears the job to print; a rejection sends it back to design.
            $newStage = $decision === 'approved' ? 'approved' : 'artwork';

            $update = ['stage' => $newStage];
            if ($decision === 'approved') {
                $update['hold_reason'] = null;
            }

            Database::update('jobs', $update, ['id' => $file['job_id']]);
        });

        ActivityLog::record(
            'job_proof_' . $decision,
            'job',
            (int) $file['job_id'],
            $file['job_number'] . ': proof v' . $file['version'] . ' ' . $decision
        );

        if ($decision === 'approved') {
            Session::success('Proof approved. The job is cleared for production.');
        } else {
            Session::warning('Proof rejected. Job sent back to artwork with the client\'s feedback.');
        }

        Response::to('/jobs/' . $file['job_id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('jobs.manage');

        $fileId = $request->paramInt('fileId');

        $file = Database::first(
            'SELECT f.*, j.job_number FROM job_files f JOIN jobs j ON j.id = f.job_id WHERE f.id = :id',
            ['id' => $fileId]
        );

        if (!$file) {
            throw new HttpException(404, 'That file does not exist.');
        }

        // An approved proof is the evidence the client signed off; keep it.
        if ($file['file_type'] === 'proof' && $file['status'] === 'approved' && !Auth::is('admin', 'manager')) {
            Session::error('Approved proofs are kept as the record of client sign-off.');
            Response::back('/jobs/' . $file['job_id']);
        }

        $this->deleteUpload($file['file_path']);
        Database::delete('job_files', ['id' => $fileId]);

        ActivityLog::record('job_file_deleted', 'job', (int) $file['job_id'], 'Removed ' . $file['file_name'] . ' from ' . $file['job_number']);
        Session::success('File removed.');
        Response::to('/jobs/' . $file['job_id']);
    }

    private function job(int $id): array
    {
        $job = Database::first('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);

        if (!$job) {
            throw new HttpException(404, 'That job card does not exist.');
        }

        return $job;
    }
}
