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
</div>

<div class="card">
  <nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
      <a class="tab <?= $status === $k ? 'is-active' : '' ?>" href="<?= e($tabUrl($k)) ?>">
        <?= e($label) ?>
        <?php if ($n): ?><span class="tab__count"><?= (int) $n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
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
