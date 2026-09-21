<?php
/**
 * The newsletter list, as staff see it.
 *
 * People who signed up from the website footer. The export is what goes
 * to whatever sends the mailing, and it holds only the subscribed — the
 * unsubscribed are kept here as a record that they asked to be left
 * alone, and are never exported.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$pager = ['page' => $page, 'pages' => $pages];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Newsletter</h1>
    <div class="page-head__sub">People who subscribed from the foot of the website</div>
  </div>
  <div class="page-head__actions">
    <?php if ($counts['subscribed'] > 0): ?>
      <a class="btn btn--primary" href="<?= e(url('/newsletter/export')) ?>">
        <?= icon('download') ?> Export subscribers (CSV)
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat stat--green">
    <div class="stat__value"><?= number_format($counts['subscribed']) ?></div>
    <div class="stat__label">Subscribed</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= number_format($counts['this_month']) ?></div>
    <div class="stat__label">Joined this month</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= number_format($counts['unsubscribed']) ?></div>
    <div class="stat__label">Unsubscribed</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $show === 'subscribed' ? 'is-active' : '' ?>" href="<?= e(url('/newsletter')) ?>">Subscribed</a>
    <a class="tab <?= $show === 'unsubscribed' ? 'is-active' : '' ?>" href="<?= e(url('/newsletter?show=unsubscribed')) ?>">Unsubscribed</a>
  </nav>

  <form class="card__body" method="get" action="<?= e(url('/newsletter')) ?>">
    <?php if ($show === 'unsubscribed'): ?><input type="hidden" name="show" value="unsubscribed"><?php endif; ?>
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by email address">
  </form>

  <?php if (!$subscribers): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('mail') ?></div>
      <div class="empty__title"><?= $search !== '' ? 'Nobody matches that' : ($show === 'subscribed' ? 'No subscribers yet' : 'Nobody has unsubscribed') ?></div>
      <?php if ($show === 'subscribed' && $search === ''): ?>
        <p class="text-sm text-muted">Sign-ups from the website footer will appear here.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Email</th>
            <th>Signed up on</th>
            <th><?= $show === 'subscribed' ? 'Subscribed' : 'Left' ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subscribers as $s): ?>
            <tr>
              <td><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></td>
              <td class="text-muted text-sm">
                <?= e($s['source_page'] ? (parse_url($s['source_page'], PHP_URL_PATH) ?: '/') : '—') ?>
              </td>
              <td class="text-sm">
                <?= e(date('d M Y', strtotime($show === 'subscribed' ? $s['subscribed_at'] : ($s['unsubscribed_at'] ?? $s['subscribed_at'])))) ?>
              </td>
              <td class="text-right">
                <?php if ($show === 'subscribed'): ?>
                  <form method="post" action="<?= e(url('/newsletter/' . $s['id'] . '/remove')) ?>"
                        onsubmit="return confirm('Unsubscribe <?= e(addslashes($s['email'])) ?>?')">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">Unsubscribe</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card__body">
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
