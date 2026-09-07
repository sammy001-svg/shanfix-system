<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tabs = [
  ''          => ['All',         $counts['all']],
  'pending'   => ['Waiting',     $counts['pending']],
  'active'    => ['Active',      $counts['active']],
  'suspended' => ['Suspended',   $counts['suspended']],
  'rejected'  => ['Turned down', $counts['rejected']],
];
$tabUrl = static fn(string $k): string => url('/partners-admin' . ($k !== '' ? '?status=' . $k : ''));

$tone = static fn(string $s): string => match ($s) {
  'active'    => 'green',
  'pending'   => 'amber',
  'suspended' => 'red',
  default     => 'grey',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Partners</h1>
    <div class="page-head__sub">
      People who bring us customers, and what we owe them
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/partners-admin/runs') ?>">
      <?= icon('calendar') ?> Commission run
    </a>
    <?php if (\App\Core\Auth::can('partners.create')): ?>
      <a class="btn btn--primary" href="<?= url('/partners-admin/new') ?>">
        <?= icon('user-plus') ?> Register a partner
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
      <a class="tab <?= (!$mine && $status === $k) ? 'is-active' : '' ?>" href="<?= e($tabUrl($k)) ?>">
        <?= e($label) ?>
        <?php if ($n): ?><span class="tab__count"><?= (int) $n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>

    <?php // Sales are the contact point for named partners, so "mine" is a
          // real filter for them rather than a convenience. ?>
    <a class="tab <?= $mine ? 'is-active' : '' ?>" href="<?= url('/partners-admin?mine=1') ?>">
      Looked after by me
      <?php if (!empty($counts['mine'])): ?>
        <span class="tab__count"><?= (int) $counts['mine'] ?></span>
      <?php endif; ?>
    </a>
  </nav>
</div>

<?php if (!$rows): ?>
  <div class="card">
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('users') ?></div>
      <div class="card__title mt-8">Nobody here</div>
      <p class="text-sm text-muted mb-0">
        Applications arrive from the partner sign-in page.
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Partner</th>
            <th style="width:110px">Code</th>
            <th style="width:80px" class="num">Rate</th>
            <th style="width:150px">Looked after by</th>
            <th style="width:100px" class="num">Customers</th>
            <th style="width:130px" class="num">Owed</th>
            <th style="width:130px" class="num">Paid out</th>
            <th style="width:110px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/partners-admin/' . (int) $p['id']) ?>">
                  <?= e($p['company'] ?: $p['name']) ?>
                </a>
                <div class="table__muted"><?= e($p['name']) ?> &middot; <?= e($p['email']) ?></div>
              </td>
              <td class="text-xs"><?= e($p['partner_code'] ?: '—') ?></td>
              <td class="num"><?= e(rtrim(rtrim(number_format((float) $p['default_rate'], 2), '0'), '.')) ?>%</td>
              <td class="text-xs">
                <?php if ($p['manager_name']): ?>
                  <?= e($p['manager_name']) ?>
                <?php else: ?>
                  <span class="text-muted">Nobody</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $p['customers'] ?></td>
              <td class="num <?= (float) $p['due'] > 0.009 ? 'fw-700' : 'text-muted' ?>">
                <?= e(money($p['due'], false)) ?>
              </td>
              <td class="num text-muted"><?= e(money($p['paid'], false)) ?></td>
              <td>
                <span class="badge badge--<?= e($tone((string) $p['status'])) ?>">
                  <?= e(label_of((string) $p['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
