<?php
/**
 * Statement of account.
 *
 * Rendered for staff and, on a share link, for the client. $isPublic
 * decides which controls appear — the figures are identical either way.
 *
 * @var array  $statement
 * @var array  $company
 * @var bool   $isPublic
 * @var string $shareLink
 */
require_once APP_PATH . '/Views/partials/icons.php';

$client  = $statement['client'];
$rows    = $statement['rows'];
$ageing  = $statement['ageing'];
$closing = (float) $statement['closing'];

$logoPath = $company['logo']
    ? ($isPublic ? url('brand/logo') : url('files/' . $company['logo']))
    : null;

$buckets = [
    'current' => 'Not yet due',
    '1_30'    => '1 – 30 days',
    '31_60'   => '31 – 60 days',
    '61_90'   => '61 – 90 days',
    '90_plus' => 'Over 90 days',
];
?>

<div class="print-bar no-print" style="justify-content:space-between">
  <div class="flex items-center gap-8">
    <?php if ($isPublic): ?>
      <?php if ($logoPath): ?>
        <img src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>" style="height:28px;width:auto">
      <?php else: ?>
        <span class="sidebar__mark" style="width:30px;height:30px;flex-basis:30px;font-size:12px">SF</span>
      <?php endif; ?>
      <span class="fw-600"><?= e($company['name']) ?></span>
    <?php else: ?>
      <a class="btn btn--outline btn--sm" href="<?= url('/clients/' . $client['id']) ?>">
        <?= icon('arrow-left') ?> Back to client
      </a>
    <?php endif; ?>
  </div>

  <div class="flex gap-8">
    <button class="btn btn--outline btn--sm" type="button" data-print>
      <?= icon('printer') ?> Print
    </button>
    <?php if (!$isPublic && $shareLink !== ''): ?>
      <a class="btn btn--primary btn--sm" href="<?= e($shareLink) ?>" target="_blank" rel="noopener">
        <?= icon('send') ?> Client link
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isPublic && $shareLink !== ''): ?>
  <div class="print-bar no-print" style="margin-bottom:14px">
    <div class="alert alert--info mb-0" style="width:100%">
      <?= icon('info') ?>
      <div class="alert__body text-sm">
        <strong>Share this statement:</strong>
        <input class="input" style="margin-top:6px;font-size:12.5px"
               value="<?= e($shareLink) ?>" readonly onfocus="this.select()">
        Anyone with the link can view this client's account, so send it only
        to them.
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($logoPath): ?>
        <img class="doc-head__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <?php if ($company['tagline']): ?>
        <div class="doc-head__tag"><?= e($company['tagline']) ?></div>
      <?php endif; ?>
      <div class="doc-head__lines">
        <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
        <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
        <?php if ($company['email']): ?><?= e($company['email']) ?><br><?php endif; ?>
        <?php if ($company['kra_pin']): ?>PIN: <?= e($company['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type">Statement</div>
      <div class="text-sm text-muted" style="margin-top:6px">
        As at <?= e(fdate($statement['to'])) ?>
      </div>
      <?php if ($statement['from']): ?>
        <div class="text-xs text-muted">
          From <?= e(fdate($statement['from'])) ?>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label">Account</div>
      <div class="doc-party__name"><?= e($client['name']) ?></div>
      <div class="doc-party__lines">
        <?php if (!empty($client['contact_person'])): ?>Attn: <?= e($client['contact_person']) ?><br><?php endif; ?>
        <?php if (!empty($client['address'])): ?><?= e($client['address']) ?><br><?php endif; ?>
        <?php if (!empty($client['city'])): ?><?= e($client['city']) ?><br><?php endif; ?>
        <?php if (!empty($client['phone'])): ?><?= e($client['phone']) ?><br><?php endif; ?>
        <?php if (!empty($client['email'])): ?><?= e($client['email']) ?><?php endif; ?>
      </div>
    </div>
    <div class="doc-party text-right">
      <div class="doc-party__label">Balance due</div>
      <div style="font-size:26px;font-weight:750;color:<?= $closing > 0.004 ? 'var(--red-700, #A62A20)' : 'var(--green-700)' ?>">
        <?= e(money($closing)) ?>
      </div>
      <?php if ($closing <= 0.004): ?>
        <div class="text-sm text-muted">Nothing outstanding — thank you</div>
      <?php endif; ?>
    </div>
  </section>

  <?php if (!$rows): ?>
    <div class="empty" style="padding:40px 0">
      <div class="empty__title">No activity in this period</div>
      <p class="empty__text">There are no invoices or payments to show.</p>
    </div>
  <?php else: ?>

    <table class="doc-table">
      <thead>
        <tr>
          <th style="width:92px">Date</th>
          <th style="width:120px">Reference</th>
          <th>Description</th>
          <th class="text-right" style="width:110px">Charges</th>
          <th class="text-right" style="width:110px">Payments</th>
          <th class="text-right" style="width:120px">Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($statement['from']): ?>
          <tr>
            <td><?= e(fdate($statement['from'])) ?></td>
            <td>—</td>
            <td><em>Balance brought forward</em></td>
            <td class="text-right">—</td>
            <td class="text-right">—</td>
            <td class="text-right fw-600"><?= e(money($statement['opening'], false)) ?></td>
          </tr>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= e(fdate($row['date'])) ?></td>
            <td>
              <?php if (!$isPublic && $row['link_id']): ?>
                <a href="<?= url('/invoices/' . $row['link_id']) ?>"><?= e($row['ref']) ?></a>
              <?php else: ?>
                <?= e($row['ref']) ?>
              <?php endif; ?>
            </td>
            <td>
              <?= e($row['description']) ?>
              <?php if ($row['type'] === 'invoice' && $row['due_date']): ?>
                <span class="text-xs text-muted">· due <?= e(fdate($row['due_date'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <?= $row['debit'] > 0 ? e(money($row['debit'], false)) : '' ?>
            </td>
            <td class="text-right" style="color:var(--green-700)">
              <?= $row['credit'] > 0 ? e(money($row['credit'], false)) : '' ?>
            </td>
            <td class="text-right fw-600"><?= e(money($row['balance'], false)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" class="text-right fw-700">Totals</td>
          <td class="text-right fw-700"><?= e(money($statement['invoiced'], false)) ?></td>
          <td class="text-right fw-700"><?= e(money($statement['paid'], false)) ?></td>
          <td class="text-right fw-700"><?= e(money($closing, false)) ?></td>
        </tr>
      </tfoot>
    </table>

  <?php endif; ?>

  <?php if ($statement['ageing_total'] > 0.004): ?>
    <div style="margin-top:26px">
      <div class="text-xs uppercase fw-700 text-muted mb-8">How long it has been outstanding</div>
      <table class="doc-table">
        <thead>
          <tr>
            <?php foreach ($buckets as $label): ?>
              <th class="text-right"><?= e($label) ?></th>
            <?php endforeach; ?>
            <th class="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <?php foreach ($buckets as $key => $label): ?>
              <td class="text-right <?= $key === '90_plus' && $ageing[$key] > 0 ? 'fw-700' : '' ?>"
                  style="<?= $key === '90_plus' && $ageing[$key] > 0 ? 'color:var(--red-700,#A62A20)' : '' ?>">
                <?= $ageing[$key] > 0.004 ? e(money($ageing[$key], false)) : '—' ?>
              </td>
            <?php endforeach; ?>
            <td class="text-right fw-700"><?= e(money($statement['ageing_total'], false)) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <footer class="doc-foot">
    <?php if ($closing > 0.004): ?>
      <strong>Payment</strong>
      Please quote the invoice number when paying.
      <?php if ($company['phone']): ?>
        Any query on this statement, call <?= e($company['phone']) ?>.
      <?php endif; ?>
      <br>
    <?php endif; ?>
    Statement produced <?= e(fdate(date('Y-m-d'))) ?>.
    Amounts in <?= e(setting('currency', 'KES')) ?>.
  </footer>

</div>
