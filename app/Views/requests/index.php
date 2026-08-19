<?php
require_once APP_PATH . '/Views/partials/icons.php';

$badges = [
    'draft'     => ['grey', 'Not sent'],
    'sent'      => ['navy',  'Waiting'],
    'opened'    => ['amber', 'Opened'],
    'submitted' => ['green', 'Answered'],
    'actioned'  => ['grey', 'Dealt with'],
    'cancelled' => ['red',   'Cancelled'],
];

$filterUrl = static fn(array $o): string => url('/requests' . query_string($o));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Job detail requests</h1>
    <div class="page-head__sub">What clients have told us they want, before the work starts</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $status === '' ? 'is-active' : '' ?>" href="<?= e($filterUrl(['status' => null])) ?>">
      All
    </a>
    <?php foreach (['submitted' => 'Answered', 'opened' => 'Opened', 'sent' => 'Waiting', 'draft' => 'Not sent'] as $k => $label): ?>
      <a class="tab <?= $status === $k ? 'is-active' : '' ?>" href="<?= e($filterUrl(['status' => $k])) ?>">
        <?= e($label) ?>
        <?php if (!empty($counts[$k])): ?><span class="tab__count"><?= (int) $counts[$k] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</div>

<div class="card">
  <?php if (!$requests): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('inbox') ?></div>
      <div class="card__title mt-8">Nothing here</div>
      <p class="text-sm text-muted mb-0">
        Requests are raised from a client's profile — open a client and use
        <strong>Ask for job details</strong>.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:130px">Reference</th>
            <th>Client</th>
            <th style="width:150px">For</th>
            <th style="width:150px">Status</th>
            <th style="width:120px">Raised</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <?php $b = $badges[$req['status']] ?? ['grey', $req['status']]; ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/requests/' . $req['id']) ?>">
                  <code class="text-xs"><?= e($req['reference']) ?></code>
                </a>
              </td>
              <td>
                <strong><?= e($req['client_name']) ?></strong>
                <?php if (!empty($req['title'])): ?>
                  <div class="table__muted"><?= e($req['title']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= e($types[$req['brief_type']] ?? $req['brief_type']) ?></td>
              <td><span class="badge badge--<?= e($b[0]) ?>"><?= e($b[1]) ?></span></td>
              <td class="text-muted text-xs">
                <?= e(date('j M Y', strtotime((string) $req['created_at']))) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
