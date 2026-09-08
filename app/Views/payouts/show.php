<?php
/**
 * One month's paying-out.
 *
 * The order of the buttons is the order of the work: check it, approve
 * it, download the file, send it, then settle each line as the money
 * lands. Nothing here marks commission paid except a line settling,
 * which is the whole point of the screen — a bounced transfer has to be
 * able to leave the partner still owed.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$canPay = Auth::can('partners.pay');
$status = (string) $run['status'];

$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};

$runBadge = match ($status) {
    'draft'    => ['badge--grey',  'Draft'],
    'approved' => ['badge--blue',  'Approved'],
    'sent'     => ['badge--amber', 'With the bank'],
    'closed'   => ['badge--green', 'Closed'],
    default    => ['badge--grey',  ucfirst($status)],
};

$lineBadge = static function (string $s): array {
    return match ($s) {
        'pending' => ['badge--grey',  'Waiting'],
        'sent'    => ['badge--amber', 'Sent'],
        'settled' => ['badge--green', 'Paid'],
        'failed'  => ['badge--red',   'Did not go through'],
        'held'    => ['badge--navy',  'Held'],
        default   => ['badge--grey',  ucfirst($s)],
    };
};

$counts = ['pending' => 0, 'sent' => 0, 'settled' => 0, 'failed' => 0, 'held' => 0];

foreach ($lines as $line) {
    $counts[(string) $line['status']] = ($counts[(string) $line['status']] ?? 0) + 1;
}

$settled = 0.0;

foreach ($lines as $line) {
    if ($line['status'] === 'settled') {
        $settled += (float) $line['amount'];
    }
}

$waiting = $counts['pending'] + $counts['sent'];
?>

<p class="text-sm mb-12">
  <a href="<?= url('/payouts') ?>">&larr; All payouts</a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($monthName((string) $run['period'])) ?> commission</h1>
    <div class="page-head__sub">
      <span class="badge <?= e($runBadge[0]) ?>"><?= e($runBadge[1]) ?></span>
      <?php if ($run['created_name']): ?>
        &middot; built by <?= e($run['created_name']) ?> on <?= e(fdate($run['created_at'])) ?>
      <?php endif; ?>
      <?php if ($run['approved_name']): ?>
        &middot; approved by <?= e($run['approved_name']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat">
    <div class="stat__value"><?= e(money($run['total'], false)) ?></div>
    <div class="stat__label">In this run</div>
  </div>
  <div class="stat <?= $settled > 0.009 ? 'stat--green' : '' ?>">
    <div class="stat__value"><?= e(money($settled, false)) ?></div>
    <div class="stat__label">Actually paid</div>
  </div>
  <div class="stat <?= $waiting > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= (int) $waiting ?></div>
    <div class="stat__label">Still in flight</div>
  </div>
  <div class="stat <?= $counts['held'] > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= (int) $counts['held'] ?></div>
    <div class="stat__label">Held back</div>
  </div>
</div>

<?php // ---- What to do next, in the order it happens ------------------- ?>
<?php if ($canPay && $status !== 'closed'): ?>
  <div class="card">
    <div class="card__body">

      <?php if ($status === 'draft'): ?>
        <p class="text-sm">
          Check the lines below, then approve. Approving does not pay
          anybody — it says the figures are right and the file can go out.
        </p>
        <form method="post" action="<?= url('/payouts/' . (int) $run['id'] . '/approve') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit">
            <?= icon('check-circle') ?> Approve <?= e(money($run['total'], false)) ?>
          </button>
        </form>

      <?php else: ?>
        <p class="text-sm">
          Download the file for each method, upload it to the bank or the
          M-Pesa portal, then say it has gone.
        </p>
        <div class="row-form">
          <?php if ($pending['mpesa'] > 0): ?>
            <a class="btn btn--outline" href="<?= url('/payouts/' . (int) $run['id'] . '/export/mpesa') ?>">
              <?= icon('download') ?> M-Pesa file (<?= (int) $pending['mpesa'] ?>)
            </a>
          <?php endif; ?>
          <?php if ($pending['bank'] > 0): ?>
            <a class="btn btn--outline" href="<?= url('/payouts/' . (int) $run['id'] . '/export/bank') ?>">
              <?= icon('download') ?> Bank file (<?= (int) $pending['bank'] ?>)
            </a>
          <?php endif; ?>

          <?php if ($status === 'approved'): ?>
            <form method="post" action="<?= url('/payouts/' . (int) $run['id'] . '/sent') ?>">
              <?= csrf_field() ?>
              <button class="btn btn--primary" type="submit">
                <?= icon('send') ?> I have sent this
              </button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($status === 'sent' && $waiting > 0): ?>
          <hr class="rule">
          <p class="text-sm">
            When the whole batch has gone through, settle it in one go with
            the batch reference. Settle a line on its own below if only some
            of them landed.
          </p>
          <form method="post" action="<?= url('/payouts/' . (int) $run['id'] . '/settle') ?>" class="row-form">
            <?= csrf_field() ?>
            <div class="field mb-0" style="max-width:260px">
              <label class="label" for="ref">Batch reference</label>
              <input class="input" type="text" id="ref" name="ref" required
                     placeholder="e.g. the bank's batch number">
            </div>
            <button class="btn btn--primary" type="submit">
              <?= icon('check') ?> Settle all <?= (int) $waiting ?>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
<?php endif; ?>

<?php // ---- The lines ---------------------------------------------------- ?>
<div class="card">
  <div class="card__head">
    <div class="card__title">Partners</div>
    <span class="text-xs text-muted"><?= count($lines) ?></span>
  </div>

  <?php if (!$lines): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('users') ?></div>
      <div class="card__title mt-8">Nothing in this run</div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Partner</th>
            <th style="width:170px">Goes to</th>
            <th style="width:130px" class="num">Amount</th>
            <th style="width:150px">State</th>
            <?php if ($canPay): ?><th style="width:300px">Reconcile</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <?php [$cls, $label] = $lineBadge((string) $line['status']); ?>
            <tr>
              <td>
                <a href="<?= url('/partners-admin/' . (int) $line['partner_id']) ?>" class="fw-600">
                  <?= e($line['company'] ?: $line['name']) ?>
                </a>
                <div class="text-xs text-muted">
                  <?= e($line['partner_code'] ?: '') ?>
                  &middot; <?= (int) $line['entries'] ?> entr<?= (int) $line['entries'] === 1 ? 'y' : 'ies' ?>
                </div>
              </td>

              <td class="text-sm">
                <?php if ($line['method']): ?>
                  <?= $line['method'] === 'mpesa' ? 'M-Pesa' : 'Bank' ?>
                  <div class="text-xs text-muted"><?= e($line['destination']) ?></div>
                  <?php if ($line['account_name']): ?>
                    <div class="text-xs text-muted"><?= e($line['account_name']) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>

              <td class="num fw-700"><?= e(money($line['amount'], false)) ?></td>

              <td>
                <span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
                <?php if ($line['status'] === 'settled' && $line['ref']): ?>
                  <div class="text-xs text-muted mt-4"><?= e($line['ref']) ?></div>
                <?php elseif (!empty($line['failure_reason'])): ?>
                  <div class="text-xs text-muted mt-4"><?= e($line['failure_reason']) ?></div>
                <?php endif; ?>
              </td>

              <?php if ($canPay): ?>
                <td>
                  <?php if (in_array($line['status'], ['pending', 'sent'], true)): ?>
                    <form method="post"
                          action="<?= url('/payouts/line/' . (int) $line['id'] . '/settle') ?>"
                          class="row-form">
                      <?= csrf_field() ?>
                      <div class="field mb-0 flex-1">
                        <input class="input" type="text" name="ref" required
                               placeholder="Reference"
                               aria-label="Reference for <?= e($line['name']) ?>">
                      </div>
                      <button class="btn btn--primary btn--sm" type="submit">Paid</button>
                    </form>

                    <?php // The unhappy paths, one click further in, because
                          // most lines simply settle. ?>
                    <details class="mt-4">
                      <summary class="text-xs text-muted" style="cursor:pointer">
                        It did not go through
                      </summary>
                      <form method="post"
                            action="<?= url('/payouts/line/' . (int) $line['id'] . '/fail') ?>"
                            class="row-form mt-4">
                        <?= csrf_field() ?>
                        <div class="field mb-0 flex-1">
                          <input class="input" type="text" name="reason"
                                 placeholder="What happened"
                                 aria-label="Why the payment to <?= e($line['name']) ?> failed">
                        </div>
                        <button class="btn btn--outline btn--sm" type="submit">Bounced</button>
                      </form>
                      <form method="post"
                            action="<?= url('/payouts/line/' . (int) $line['id'] . '/hold') ?>"
                            class="mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reason" value="Held back for checking">
                        <button class="btn btn--ghost btn--sm" type="submit">
                          Hold back for now
                        </button>
                      </form>
                    </details>

                  <?php elseif ($line['status'] === 'held' && $status !== 'closed'): ?>
                    <form method="post" action="<?= url('/payouts/line/' . (int) $line['id'] . '/admit') ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--outline btn--sm" type="submit">
                        <?= icon('plus') ?> Put it back in
                      </button>
                    </form>
                    <div class="text-xs text-muted mt-4">
                      Fix the details on their profile first.
                    </div>

                  <?php elseif ($line['status'] === 'failed'): ?>
                    <span class="text-xs text-muted">
                      Owed again — it will fall into the next run.
                    </span>

                  <?php elseif ($line['status'] === 'settled'): ?>
                    <span class="text-xs text-muted">
                      <?= e(fdatetime($line['settled_at'])) ?>
                    </span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
