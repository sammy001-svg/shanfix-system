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
use App\Services\Commission;
use App\Services\Notifier;
use App\Services\Payouts;

/**
 * Paying partners their commission.
 *
 * A month is gathered into a run, checked, approved, handed to the bank
 * as a file, and then reconciled line by line as the money lands. The
 * shape is deliberate: the old button marked everything paid the instant
 * it was pressed, which meant a bounced transfer left the ledger saying
 * we had paid somebody we had not.
 *
 * Reading a run is open to anyone who can see partners, so sales can
 * answer "have they been paid yet". Moving money is finance and admin.
 */
class PayoutController extends Controller
{
    /** Every run, and what could be paid right now. */
    public function index(Request $request): void
    {
        $this->authorize('partners.view');

        $period = (string) $request->query('period', '');

        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            // Last month by default: this month is still being earned, and
            // a run against a moving total is not a run.
            $periods = Commission::periods();
            $period  = $periods[0] ?? date('Y-m', strtotime('first day of last month'));
        }

        $waiting = Payouts::available($period);

        $this->view('payouts/index', [
            'title'    => 'Commission payouts',
            'runs'     => Payouts::recent(),
            'periods'  => Commission::periods(),
            'period'   => $period,
            'waiting'  => $waiting,
            'owed'     => array_sum(array_map(
                static fn(array $r): float => (float) $r['amount'],
                $waiting
            )),
            'openRun'  => Payouts::openRun($period),
        ]);
    }

    /** Gather a month into a draft run. */
    public function build(Request $request): void
    {
        $this->authorize('partners.pay');

        $period = trim((string) $request->input('period'));

        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $period) !== 1) {
            Session::error('Pick a month to pay.');
            Response::to('/payouts');
        }

        // Two open runs for one month is how the same commission goes out
        // twice. The attachment on each row would stop the money doubling,
        // but the second run would look real and pay nothing, which is
        // worse than refusing.
        $open = Payouts::openRun($period);

        if ($open) {
            Session::warning('There is already a run for ' . $period . ' that has not been closed.');
            Response::to('/payouts/' . $open['id']);
        }

        $built = Payouts::build($period, Auth::id());

        if ($built['lines'] === 0 && $built['held'] === 0) {
            Database::run('DELETE FROM payout_runs WHERE id = :id', ['id' => $built['run_id']]);

            Session::warning('There is nothing owed for ' . $period . '.');
            Response::to('/payouts?period=' . $period);
        }

        ActivityLog::record(
            'payout_run_built',
            'payout_run',
            $built['run_id'],
            'Built the ' . $period . ' commission run: ' . money($built['total'])
            . ' across ' . $built['lines'] . ' partners'
        );

        Session::success(
            'Gathered ' . money($built['total']) . ' for ' . $built['lines'] . ' partner'
            . ($built['lines'] === 1 ? '' : 's') . '.'
            . ($built['held'] > 0
                ? ' ' . $built['held'] . ' could not be paid yet and ' . ($built['held'] === 1 ? 'is' : 'are') . ' held.'
                : '')
        );

        Response::to('/payouts/' . $built['run_id']);
    }

    public function show(Request $request): void
    {
        $this->authorize('partners.view');

        $run = Payouts::find($request->paramInt('id'));

        if (!$run) {
            throw new HttpException(404, 'That payout run does not exist.');
        }

        $this->view('payouts/show', [
            'title'   => 'Commission run — ' . $run['period'],
            'run'     => $run,
            'lines'   => Payouts::lines((int) $run['id']),
            'pending' => Payouts::pendingByMethod((int) $run['id']),
        ]);
    }

    public function approve(Request $request): void
    {
        $this->authorize('partners.pay');

        $id = $request->paramInt('id');

        if (!Payouts::approve($id, (int) Auth::id())) {
            Session::error('That run cannot be approved. It may already have been, or have nothing payable in it.');
            Response::to('/payouts/' . $id);
        }

        $run = Payouts::find($id);

        ActivityLog::record(
            'payout_run_approved',
            'payout_run',
            $id,
            'Approved ' . money($run['total']) . ' for ' . $run['period']
        );

        Session::success('Approved. Download the file and send it, then come back and mark it sent.');
        Response::to('/payouts/' . $id);
    }

    /**
     * The file for the bank or for M-Pesa.
     *
     * A plain download that changes nothing. Fetching a file to check it
     * should not commit you to having sent it — that is a separate,
     * deliberate step.
     */
    public function export(Request $request): void
    {
        $this->authorize('partners.pay');

        $id   = $request->paramInt('id');
        $kind = (string) $request->param('kind');

        if (!in_array($kind, ['mpesa', 'bank'], true)) {
            throw new HttpException(404, 'There is no such payment file.');
        }

        $run = Payouts::find($id);

        if (!$run) {
            throw new HttpException(404, 'That payout run does not exist.');
        }

        if ($run['status'] === 'draft') {
            Session::warning('Approve the run before sending it to the bank.');
            Response::to('/payouts/' . $id);
        }

        $parts = Payouts::csvParts($id, $kind);

        if (!$parts['rows']) {
            Session::warning('There is nothing left to send by ' . ($kind === 'mpesa' ? 'M-Pesa' : 'bank transfer') . '.');
            Response::to('/payouts/' . $id);
        }

        ActivityLog::record(
            'payout_exported',
            'payout_run',
            $id,
            'Downloaded the ' . $kind . ' file for ' . $run['period']
        );

        Response::csv(
            'commission-' . $run['period'] . '-' . $kind . '.csv',
            $parts['headers'],
            $parts['rows']
        );
    }

    /** Say the file has gone out, so the lines stop being editable. */
    public function sent(Request $request): void
    {
        $this->authorize('partners.pay');

        $id  = $request->paramInt('id');
        $run = Payouts::find($id);

        if (!$run || !in_array($run['status'], ['approved', 'sent'], true)) {
            Session::error('That run is not ready to be sent.');
            Response::to('/payouts/' . $id);
        }

        Payouts::markExported($id);

        ActivityLog::record('payout_sent', 'payout_run', $id, 'Sent the ' . $run['period'] . ' payment file');

        Session::success('Marked as sent. Settle each line as the money lands.');
        Response::to('/payouts/' . $id);
    }

    /** Settle everything still outstanding under one batch reference. */
    public function settleAll(Request $request): void
    {
        $this->authorize('partners.pay');

        $id  = $request->paramInt('id');
        $ref = trim((string) $request->input('ref'));

        if ($ref === '') {
            Session::error('Put the batch reference in, so this can be traced later.');
            Response::to('/payouts/' . $id);
        }

        $lines = Payouts::lines($id);
        $n     = Payouts::settleRemaining($id, $ref);

        if ($n === 0) {
            Session::warning('There was nothing left waiting to be settled.');
            Response::to('/payouts/' . $id);
        }

        foreach ($lines as $line) {
            if (in_array($line['status'], ['pending', 'sent'], true)) {
                $this->tellThem($line, $ref);
            }
        }

        Notifier::processQueue(8);

        ActivityLog::record('payout_settled', 'payout_run', $id, 'Settled ' . $n . ' lines against ' . $ref);

        Session::success('Settled ' . $n . ' payment' . ($n === 1 ? '' : 's') . ' against ' . $ref . '.');
        Response::to('/payouts/' . $id);
    }

    // -- One line at a time -------------------------------------------------

    public function settleLine(Request $request): void
    {
        $this->authorize('partners.pay');

        $line = $this->line($request->paramInt('id'));
        $ref  = trim((string) $request->input('ref'));

        if ($ref === '') {
            Session::error('Put the transaction reference in.');
            Response::to('/payouts/' . $line['run_id']);
        }

        if (!Payouts::settle((int) $line['id'], $ref)) {
            Session::error('That line cannot be settled.');
            Response::to('/payouts/' . $line['run_id']);
        }

        $this->tellThem($line, $ref);
        Notifier::processQueue(4);

        ActivityLog::record(
            'partner_paid',
            'partner',
            (int) $line['partner_id'],
            'Paid ' . money($line['amount']) . ' to ' . $line['name'] . ' (' . $ref . ')'
        );

        Session::success('Settled ' . money($line['amount']) . ' to ' . $line['name'] . '.');
        Response::to('/payouts/' . $line['run_id']);
    }

    public function failLine(Request $request): void
    {
        $this->authorize('partners.pay');

        $line   = $this->line($request->paramInt('id'));
        $reason = trim((string) $request->input('reason'));

        if (!Payouts::fail((int) $line['id'], $reason)) {
            Session::error('That line cannot be marked as failed.');
            Response::to('/payouts/' . $line['run_id']);
        }

        ActivityLog::record(
            'payout_line_failed',
            'partner',
            (int) $line['partner_id'],
            'Payment of ' . money($line['amount']) . ' to ' . $line['name'] . ' did not go through'
            . ($reason !== '' ? ': ' . $reason : '')
        );

        Session::warning(
            money($line['amount']) . ' to ' . $line['name']
            . ' is owed again and will fall into the next run.'
        );
        Response::to('/payouts/' . $line['run_id']);
    }

    public function holdLine(Request $request): void
    {
        $this->authorize('partners.pay');

        $line = $this->line($request->paramInt('id'));

        if (!Payouts::hold((int) $line['id'], trim((string) $request->input('reason')))) {
            Session::error('That line cannot be held back.');
            Response::to('/payouts/' . $line['run_id']);
        }

        Session::success('Held back. ' . $line['name'] . ' stays owed.');
        Response::to('/payouts/' . $line['run_id']);
    }

    public function admitLine(Request $request): void
    {
        $this->authorize('partners.pay');

        $line = $this->line($request->paramInt('id'));

        if (!Payouts::admit((int) $line['id'])) {
            $fresh = Database::first('SELECT failure_reason FROM payout_lines WHERE id = :id', ['id' => $line['id']]);

            Session::error(
                'That line still cannot be paid'
                . (!empty($fresh['failure_reason']) ? ': ' . $fresh['failure_reason'] : '.')
            );
            Response::to('/payouts/' . $line['run_id']);
        }

        Session::success($line['name'] . ' is now in the run.');
        Response::to('/payouts/' . $line['run_id']);
    }

    // -- Plumbing ------------------------------------------------------------

    private function line(int $id): array
    {
        $line = Database::first(
            'SELECT l.*, p.name, p.email, p.phone
               FROM payout_lines l
               JOIN partners p ON p.id = l.partner_id
              WHERE l.id = :id',
            ['id' => $id]
        );

        if (!$line) {
            throw new HttpException(404, 'That payment line does not exist.');
        }

        return $line;
    }

    /** Tell the partner the money has gone. */
    private function tellThem(array $line, string $ref): void
    {
        Notifier::dispatch('partner_paid', [
            'entity_type'  => 'partner',
            'entity_id'    => (int) $line['partner_id'],
            'contact_name' => $line['name'],
            'email'        => $line['email'] ?? '',
            'phone'        => $line['phone'] ?? '',
            'amount'       => money($line['amount']),
            'payment_ref'  => $ref,
        ], true);
    }
}
