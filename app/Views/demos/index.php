<?php
/**
 * People waiting to be shown a system.
 *
 * The ones still waiting come first, because every one of them is
 * somebody who asked to be sold to and has not been answered.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$filterUrl = static function (array $change) use ($filters): string {
    $q = array_filter(array_merge($filters, $change), static fn($v) => $v !== '');

    return url('/demos' . ($q ? '?' . http_build_query($q) : ''));
};

$badgeFor = static fn(string $status): string => match ($status) {
    'accepted' => 'badge--green',
    'declined' => 'badge--grey',
    'expired'  => 'badge--amber',
    default    => 'badge--navy',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Demo requests</h1>
    <div class="page-head__sub">
      <?php if ($waiting > 0): ?>
        <strong><?= (int) $waiting ?></strong> waiting on a login.
      <?php else: ?>
        Nobody is waiting. Requests arrive from the website.
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="stat-grid">
  <?php foreach ($statuses as $key => $label): ?>
    <a class="stat <?= $key === 'new' ? 'stat--navy' : ($key === 'accepted' ? 'stat--green' : 'stat--grey') ?>"
       href="<?= e($filterUrl(['status' => $filters['status'] === $key ? '' : $key])) ?>">
      <div class="stat__label"><?= e($label) ?></div>
      <div class="stat__value"><?= (int) ($counts[$key] ?? 0) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card__head">
    <div>
      <div class="card__title">
        <?= $filters['status'] !== '' ? e($statuses[$filters['status']]) : 'Everything' ?>
      </div>
      <div class="card__sub"><?= count($requests) ?> request(s)</div>
    </div>
    <?php if ($filters['status'] !== '' || $filters['product'] !== ''): ?>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('/demos')) ?>">Show all</a>
    <?php endif; ?>
  </div>

  <?php if (!$requests): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('inbox') ?></div>
        <div class="empty__title">Nothing here</div>
        <p class="text-sm text-muted">
          Requests come from the Request a demo button on the website.
        </p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Who</th>
            <th>Wants</th>
            <th>Asked</th>
            <th>Status</th>
            <th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $d): ?>
            <tr>
              <td><code><?= e($d['reference']) ?></code></td>
              <td>
                <a href="<?= e(url('/demos/' . (int) $d['id'])) ?>"><strong><?= e($d['name']) ?></strong></a>
                <?php if ($d['organisation']): ?>
                  <span class="d-block text-xs text-muted"><?= e($d['organisation']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($products[$d['product']] ?? $d['product']) ?></td>
              <td class="text-sm text-muted"><?= e(fdate($d['created_at'])) ?></td>
              <td>
                <span class="badge <?= $badgeFor($d['status']) ?>">
                  <?= e($statuses[$d['status']] ?? $d['status']) ?>
                </span>
                <?php if ($d['status'] === 'accepted' && $d['expires_on']): ?>
                  <span class="d-block text-xs text-muted">until <?= e(fdate($d['expires_on'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/demos/' . (int) $d['id'])) ?>">
                  <?= $d['status'] === 'new' && $canSend ? 'Answer' : 'Open' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
