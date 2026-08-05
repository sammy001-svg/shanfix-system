<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Audit Trail</h1>
    <div class="page-head__sub">Who did what, and when. Useful when a figure looks wrong.</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/audit') ?>">
    <div class="field" style="min-width:210px">
      <label class="label" for="action">Action contains</label>
      <input class="input" id="action" name="action" value="<?= e($filters['action']) ?>"
             placeholder="e.g. payment, invoice, login" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="user">User</label>
      <select class="select" id="user" name="user" data-auto-submit>
        <option value="">Everyone</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $filters['userId'] === (int) $u['id'] ? 'selected' : '' ?>>
            <?= e($u['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/audit') ?>">Clear</a>
  </form>

  <?php if (!$entries): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('activity') ?></div>
      <div class="empty__title">No entries</div>
      <p class="empty__text">Nothing matches these filters.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table--compact">
        <thead>
          <tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>Record</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td class="text-sm" style="white-space:nowrap"><?= e(fdatetime($entry['created_at'])) ?></td>
              <td>
                <?php if ($entry['user_name']): ?>
                  <div class="flex items-center gap-6">
                    <span class="avatar avatar--sm" style="background:<?= e($entry['avatar_color'] ?: '#0C2B4A') ?>">
                      <?= e(initials($entry['user_name'])) ?>
                    </span>
                    <span class="text-sm"><?= e($entry['user_name']) ?></span>
                  </div>
                <?php else: ?>
                  <span class="text-muted text-sm">System</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= str_contains($entry['action'], 'delete') || str_contains($entry['action'], 'failed')
                    ? 'badge--red'
                    : (str_contains($entry['action'], 'created') ? 'badge--green' : 'badge--grey') ?>">
                  <?= e(label_of($entry['action'])) ?>
                </span>
              </td>
              <td class="text-sm"><?= e($entry['description'] ?: '—') ?></td>
              <td class="text-sm text-muted">
                <?= $entry['entity_type'] ? e(label_of($entry['entity_type'])) . ' #' . (int) $entry['entity_id'] : '—' ?>
              </td>
              <td class="text-xs text-muted"><?= e($entry['ip_address']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($entries) ?> of <?= number_format($pager['total']) ?> entries</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
