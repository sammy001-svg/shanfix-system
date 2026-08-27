<?php
require_once APP_PATH . '/Views/partials/icons.php';

// How close a renewal is, in the words somebody would actually use.
$whenTone = static function (?int $days, string $status): array {
    if ($status !== 'active') {
        return ['grey', ucfirst($status)];
    }
    if ($days === null)  { return ['grey',  'No date set']; }
    if ($days < 0)       { return ['red',   abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue']; }
    if ($days === 0)     { return ['red',   'Due today']; }
    if ($days <= 7)      { return ['amber', 'In ' . $days . ' day' . ($days === 1 ? '' : 's')]; }
    if ($days <= 30)     { return ['amber', 'In ' . $days . ' days']; }
    return ['green', 'In ' . $days . ' days'];
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your recurring services</h1>
    <p class="portal-lede">
      What we look after for you, what it costs, and when each one is next due.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('refresh') ?></div>
      <div class="card__title mt-8">Nothing recurring</div>
      <p class="text-sm text-muted mb-0">
        Hosting, domains and retainers would appear here.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php [$tone, $when] = $whenTone($r['days_away'], (string) $r['status']); ?>
      <div class="portal-card portal-sub">
        <div class="portal-sub__main">
          <div class="portal-sub__name"><?= e($r['name']) ?></div>

          <div class="text-sm text-muted">
            <?= e(money($r['amount'], false)) ?>
            <?php if ($r['billing_cycle']): ?>
              every <?= e(strtolower(label_of($r['billing_cycle']))) ?>
            <?php endif; ?>
            <?php if ($r['catalogue_name']): ?> · <?= e($r['catalogue_name']) ?><?php endif; ?>
          </div>

          <?php if ($r['url']): ?>
            <?php // rel=noopener so the opened tab cannot reach back into
                  // this one; a link a client clicks is still a link. ?>
            <a class="portal-sub__link" href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer">
              <?= icon('external-link') ?> <?= e(str_excerpt(preg_replace('#^https?://#', '', $r['url']), 44)) ?>
            </a>
          <?php endif; ?>
        </div>

        <div class="portal-sub__due">
          <span class="badge badge--<?= e($tone) ?>"><?= e($when) ?></span>
          <?php if ($r['next_renewal_date']): ?>
            <div class="text-xs text-muted mt-4"><?= e(fdate($r['next_renewal_date'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="text-sm text-muted mt-16">
      We invoice these before they fall due. If something here should stop or
      change, tell us on <?= e($company['phone']) ?> and we will sort it out.
    </p>
  <?php endif; ?>
</div>
