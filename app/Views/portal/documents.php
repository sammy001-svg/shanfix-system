<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice = $type === 'invoice';

// What a status means to the person reading it, rather than to us.
$tone = static fn(string $s): string => match ($s) {
    'paid', 'accepted'   => 'green',
    'overdue'            => 'red',
    'partial'            => 'amber',
    'rejected', 'expired', 'cancelled' => 'grey',
    default              => 'navy',
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1"><?= $isInvoice ? 'Your invoices' : 'Your quotations' ?></h1>
    <p class="portal-lede">
      <?= $isInvoice
        ? 'Everything we have billed you, and what is still outstanding.'
        : 'Prices we have quoted you. Ask us anything that is not clear.' ?>
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('file-text') ?></div>
      <div class="card__title mt-8">Nothing here yet</div>
      <p class="text-sm text-muted mb-0">
        <?= $isInvoice
          ? 'When we invoice you, it will appear here.'
          : 'When we quote you for something, it will appear here.' ?>
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:130px">Number</th>
              <th>What for</th>
              <th style="width:110px">Date</th>
              <th style="width:110px"><?= $isInvoice ? 'Due' : 'Valid to' ?></th>
              <th style="width:120px" class="num">Amount</th>
              <?php if ($isInvoice): ?><th style="width:120px" class="num">Outstanding</th><?php endif; ?>
              <th style="width:110px">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <a class="table__primary"
                     href="<?= url('/portal/' . ($isInvoice ? 'invoices' : 'quotations') . '/' . $r['id']) ?>">
                    <code class="text-xs"><?= e($r['doc_number']) ?></code>
                  </a>
                </td>
                <td><?= e(str_excerpt($r['title'] ?: '—', 46)) ?></td>
                <td class="text-xs text-muted"><?= e(fdate($r['issue_date'])) ?></td>
                <td class="text-xs text-muted">
                  <?= e(fdate($isInvoice ? $r['due_date'] : $r['valid_until']) ?: '—') ?>
                </td>
                <td class="num fw-600"><?= e(money($r['total'], false)) ?></td>
                <?php if ($isInvoice): ?>
                  <td class="num">
                    <?php if ((float) $r['balance'] > 0.009): ?>
                      <span class="fw-700 text-red"><?= e(money($r['balance'], false)) ?></span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td><span class="badge badge--<?= e($tone($r['status'])) ?>"><?= e(label_of($r['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
