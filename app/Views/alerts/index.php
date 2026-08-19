<?php
/**
 * @var array $alerts
 * @var array $pager
 * @var bool  $unread
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>My alerts</h1>
    <div class="page-head__sub">What the system needs to tell you.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline <?= $unread ? 'is-active' : '' ?>"
       href="<?= url('/alerts' . ($unread ? '' : '?filter=unread')) ?>">
      <?= $unread ? 'Show all' : 'Unread only' ?>
    </a>
    <form method="post" action="<?= url('/alerts/read-all') ?>" style="display:inline">
      <?= csrf_field() ?>
      <button class="btn btn--primary" type="submit"><?= icon('check') ?> Mark all read</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$alerts): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('bell') ?></div>
        <div class="empty__title"><?= $unread ? 'Nothing unread' : 'Nothing yet' ?></div>
        <p class="empty__text">
          You will be told here when artwork is allocated to you, a client
          approves something, or work reaches your part of the process.
        </p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <?php foreach ($alerts as $a): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/alerts/' . $a['id'] . '/open') ?>">
                  <?= $a['read_at'] === null ? '<span class="badge badge--navy">New</span> ' : '' ?>
                  <?= e($a['title']) ?>
                </a>
                <?php if ($a['body']): ?>
                  <div class="text-sm text-muted"><?= e($a['body']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-right text-sm text-muted" style="width:130px">
                <?= e(time_ago($a['created_at'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($alerts) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
