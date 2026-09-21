<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Portal support</h1>
    <div class="page-head__sub">Messages clients sent through their portal. Reply here and they see it immediately.</div>
  </div>
</div>

<?php if (!$threads): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('message-circle') ?></div>
      <div class="empty__title">No messages yet</div>
      <p class="empty__text">When a client sends a support message through their portal, it will appear here.</p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Client</th>
            <th>Last message</th>
            <th class="num">Messages</th>
            <th>Unread</th>
            <th>Last activity</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($threads as $t): ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/portal-support/' . (int) $t['client_id']) ?>">
                  <?= e($t['client_name']) ?>
                </a>
              </td>
              <td class="text-sm text-muted" style="max-width:300px">
                <span class="truncate d-block" style="max-width:280px"><?= e(str_excerpt((string) $t['last_body'], 60)) ?></span>
              </td>
              <td class="num"><?= (int) $t['total'] ?></td>
              <td>
                <?php if ((int) $t['unread_client'] > 0): ?>
                  <span class="badge badge--amber"><?= (int) $t['unread_client'] ?> from client</span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-sm text-muted"><?= e(fdate($t['last_at'])) ?></td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/portal-support/' . (int) $t['client_id']) ?>">
                  <?= icon('message-circle') ?> Open
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
