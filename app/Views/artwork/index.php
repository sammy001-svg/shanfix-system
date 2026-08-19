<?php
/**
 * @var array $requests
 * @var array $pager
 * @var array $summary
 * @var array $statuses
 * @var array $filters
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = static fn(string $s): string => match ($s) {
    'approved', 'completed' => 'badge--green',
    'proof_sent'            => 'badge--blue',
    'changes_requested'     => 'badge--amber',
    'cancelled'             => 'badge--red',
    'requested'             => 'badge--navy',
    default                 => 'badge--grey',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Artwork</h1>
    <div class="page-head__sub">Design work, from a request to approved artwork.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline <?= $filters['mine'] ? 'is-active' : '' ?>"
       href="<?= url('/artwork?mine=1') ?>">
      <?= icon('user') ?> Mine
    </a>
    <?php if (can('artwork.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/artwork/create') ?>">
        <?= icon('plus') ?> New request
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat <?= (int) $summary['unassigned'] > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__label">Not allocated</div>
    <div class="stat__value"><?= number_format((int) $summary['unassigned']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">In the studio</div>
    <div class="stat__value"><?= number_format((int) $summary['in_studio']) ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">With the client</div>
    <div class="stat__value"><?= number_format((int) $summary['awaiting_client']) ?></div>
  </div>
  <div class="stat <?= (int) $summary['changes'] > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__label">Changes requested</div>
    <div class="stat__value"><?= number_format((int) $summary['changes']) ?></div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/artwork') ?>">
    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="input" id="status" name="status">
        <option value="">All</option>
        <?php foreach ($statuses as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <button class="btn btn--outline" type="submit"><?= icon('filter') ?> Filter</button>
    </div>
  </form>

  <?php if (!$requests): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('image') ?></div>
        <div class="empty__title">No artwork requests</div>
        <p class="empty__text">
          Raise a request when a client asks for design work. Allocate it to a
          designer, send the proof for approval, and push it to production once
          the client is happy.
        </p>
        <?php if (can('artwork.manage')): ?>
          <a class="btn btn--primary mt-12" href="<?= url('/artwork/create') ?>">
            <?= icon('plus') ?> New request
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Client</th>
            <th style="width:150px">Designer</th>
            <th style="width:110px">Due</th>
            <th style="width:150px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $a): ?>
            <?php
            $late = $a['due_date']
                 && !in_array($a['status'], ['approved', 'completed', 'cancelled'], true)
                 && strtotime($a['due_date']) < strtotime('today');
            ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/artwork/' . $a['id']) ?>"><?= e($a['title']) ?></a>
                <div class="text-xs text-muted">
                  <?= e($a['request_number']) ?>
                  <?php if ($a['priority'] !== 'normal'): ?>
                    · <span class="fw-600"><?= e(label_of($a['priority'])) ?></span>
                  <?php endif; ?>
                  <?php if ((int) $a['proof_count'] > 0): ?>
                    · <?= (int) $a['proof_count'] ?> proof(s)
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-sm"><?= e($a['client_name']) ?></td>
              <td class="text-sm"><?= e($a['designer_name'] ?: '—') ?></td>
              <td class="text-sm <?= $late ? 'text-red fw-600' : '' ?>">
                <?= $a['due_date'] ? e(fdate($a['due_date'])) : '—' ?>
              </td>
              <td>
                <span class="badge <?= $badge($a['status']) ?>">
                  <?= e($statuses[$a['status']] ?? $a['status']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($requests) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
