<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($meta['plural']) ?></h1>
    <div class="page-head__sub">
      <?php if ($type === 'quotation'): ?>
        Quotes issued to clients and prospects. Convert an accepted quote into an invoice in one click.
      <?php elseif ($type === 'invoice'): ?>
        Invoices raised, what has been paid and what is still outstanding.
      <?php else: ?>
        Receipts issued to clients confirming payment.
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('documents.manage') && $type !== 'receipt'): ?>
      <a class="btn btn--primary" href="<?= url($meta['path'] . '/create') ?>">
        <?= icon('plus') ?> New <?= e(strtolower($meta['label'])) ?>
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Total <?= e(strtolower($meta['plural'])) ?></div>
    <div class="stat__value"><?= number_format((int) $summary['count']) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Total value</div>
    <div class="stat__value"><?= e(money_short($summary['total_value'])) ?></div>
  </div>
  <?php if ($type === 'invoice'): ?>
    <div class="stat stat--green">
      <div class="stat__label">Collected</div>
      <div class="stat__value"><?= e(money_short($summary['total_paid'])) ?></div>
    </div>
    <div class="stat stat--red">
      <div class="stat__label">Outstanding</div>
      <div class="stat__value"><?= e(money_short($summary['total_balance'])) ?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url($meta['path']) ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Number, client or title" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(label_of($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="from">From</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>" data-auto-submit>
    </div>

    <div class="field">
      <label class="label" for="to">To</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>" data-auto-submit>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url($meta['path']) ?>">Clear</a>
  </form>

  <?php if (!$documents): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon($type === 'quotation' ? 'file-text' : ($type === 'invoice' ? 'receipt' : 'check-circle')) ?></div>
      <div class="empty__title">No <?= e(strtolower($meta['plural'])) ?> found</div>
      <p class="empty__text">
        <?php if ($type === 'receipt'): ?>
          Receipts are generated from paid invoices — open a paid invoice and choose “Issue receipt”.
        <?php else: ?>
          Create your first <?= e(strtolower($meta['label'])) ?> to get started.
        <?php endif; ?>
      </p>
      <?php if (can('documents.manage') && $type !== 'receipt'): ?>
        <a class="btn btn--primary" href="<?= url($meta['path'] . '/create') ?>">
          <?= icon('plus') ?> New <?= e(strtolower($meta['label'])) ?>
        </a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Number</th>
            <th>Client</th>
            <th>Description</th>
            <th>Issued</th>
            <?php if ($type === 'invoice'): ?><th>Due</th><?php endif; ?>
            <?php if ($type === 'quotation'): ?><th>Valid until</th><?php endif; ?>
            <th class="num">Total</th>
            <?php if ($type === 'invoice'): ?><th class="num">Balance</th><?php endif; ?>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $d):
              $overdue = $type === 'invoice'
                  && $d['due_date']
                  && strtotime($d['due_date']) < strtotime('today')
                  && (float) $d['balance'] > 0.009
                  && !in_array($d['status'], ['cancelled', 'paid', 'draft'], true);
          ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url($meta['path'] . '/' . $d['id']) ?>"><?= e($d['doc_number']) ?></a>
                <div class="table__muted"><?= e($d['created_by_name'] ?: 'System') ?></div>
              </td>
              <td>
                <a href="<?= url('/clients/' . $d['client_id']) ?>"><?= e($d['client_name']) ?></a>
                <div class="table__muted"><?= e($d['client_code']) ?></div>
              </td>
              <td class="text-sm"><?= e(str_excerpt($d['title'], 40) ?: '—') ?></td>
              <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>

              <?php if ($type === 'invoice'): ?>
                <td class="text-sm <?= $overdue ? 'text-red fw-600' : '' ?>">
                  <?= e(fdate($d['due_date'])) ?>
                  <?php if ($overdue): ?>
                    <div class="text-xs">
                      <?= (int) floor((time() - strtotime($d['due_date'])) / 86400) ?>d overdue
                    </div>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <?php if ($type === 'quotation'): ?>
                <?php $expired = $d['valid_until'] && strtotime($d['valid_until']) < strtotime('today'); ?>
                <td class="text-sm <?= $expired ? 'text-amber' : '' ?>"><?= e(fdate($d['valid_until'])) ?></td>
              <?php endif; ?>

              <td class="num fw-600"><?= e(money($d['total'], false)) ?></td>

              <?php if ($type === 'invoice'): ?>
                <td class="num">
                  <?php if ((float) $d['balance'] > 0.009): ?>
                    <span class="fw-700 text-red"><?= e(money($d['balance'], false)) ?></span>
                  <?php else: ?>
                    <span class="text-green fw-600">Settled</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <td>
                <span class="badge <?= status_badge($overdue ? 'overdue' : $d['status']) ?>">
                  <?= e(label_of($overdue ? 'overdue' : $d['status'])) ?>
                </span>
              </td>

              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url($meta['path'] . '/' . $d['id']) ?>" title="View">
                  <?= icon('eye') ?>
                </a>
                <a class="btn btn--outline btn--sm" href="<?= url($meta['path'] . '/' . $d['id'] . '/print') ?>"
                   target="_blank" rel="noopener" title="Print">
                  <?= icon('printer') ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($documents) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
