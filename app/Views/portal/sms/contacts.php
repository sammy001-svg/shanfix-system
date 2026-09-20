<?php
/**
 * The customer's address book: lists, the people in them, and importing
 * a spreadsheet.
 */
require_once APP_PATH . '/Views/partials/icons.php';

?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Your lists</div>
      <div>
        <a class="btn btn--outline btn--sm" href="<?= url($base . '/contacts/import') ?>">
          <?= icon('download') ?> Import a file
        </a>
        <a class="btn btn--ghost btn--sm" href="<?= url($base . '/groups') ?>">Manage lists</a>
        <a class="btn btn--ghost btn--sm" href="<?= url($base . '/contacts/export' . ($groupId ? '?group=' . (int) $groupId : '')) ?>">Download</a>
      </div>
    </div>

    <?php if ($groups): ?>
      <ul class="portal-list portal-list--tight">
        <li class="portal-list__row">
          <a class="portal-list__main" href="<?= url($base . '/contacts') ?>">
            <span class="portal-list__title">Everybody</span>
          </a>
        </li>
        <?php foreach ($groups as $g): ?>
          <li class="portal-list__row">
            <a class="portal-list__main" href="<?= url($base . '/contacts?group=' . (int) $g['id']) ?>">
              <span class="portal-list__title <?= $groupId === (int) $g['id'] ? 'fw-700' : '' ?>"><?= e($g['name']) ?></span>
              <span class="portal-list__meta"><?= number_format((int) $g['people']) ?> contact<?= (int) $g['people'] === 1 ? '' : 's' ?></span>
            </a>
            <span class="portal-list__side">
              <form method="post" action="<?= url($base . '/groups/' . (int) $g['id'] . '/delete') ?>"
                    data-confirm="Delete the list &quot;<?= e($g['name']) ?>&quot;? The contacts in it stay in your address book.">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?></button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-sm text-muted">No lists yet. A list is what a campaign sends to.</p>
    <?php endif; ?>

    <form method="post" action="<?= url($base . '/groups') ?>" class="row-form mt-8">
      <?= csrf_field() ?>
      <div class="field mb-0" style="flex:1">
        <label class="label" for="g-name">New list</label>
        <input class="input" id="g-name" name="name" maxlength="120" required placeholder="e.g. Customers in Nairobi">
      </div>
      <button class="btn btn--outline" type="submit">Create</button>
    </form>
  </div>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">
        Contacts<?php if ($groupId): ?> in this list<?php endif; ?>
        <span class="text-sm text-muted">(<?= number_format($pager['total']) ?>)</span>
      </div>
    </div>

    <form method="get" action="<?= url($base . '/contacts') ?>" class="row-form">
      <?php if ($groupId): ?><input type="hidden" name="group" value="<?= (int) $groupId ?>"><?php endif; ?>
      <div class="field mb-0" style="flex:1">
        <input class="input" name="q" value="<?= e($q) ?>" placeholder="Search a name or number">
      </div>
      <button class="btn btn--outline" type="submit"><?= icon('search') ?></button>
    </form>

    <form method="post" action="<?= url($base . '/contacts') ?>" class="row-form mt-8">
      <?= csrf_field() ?>
      <?php if ($groupId): ?><input type="hidden" name="group_id" value="<?= (int) $groupId ?>"><?php endif; ?>
      <div class="field mb-0" style="flex:1">
        <label class="label" for="c-name">Name</label>
        <input class="input" id="c-name" name="name" maxlength="120">
      </div>
      <div class="field mb-0" style="flex:1">
        <label class="label" for="c-phone">Phone</label>
        <input class="input" id="c-phone" name="phone" required placeholder="0712345678">
      </div>
      <?php if (!$groupId && $groups): ?>
        <div class="field mb-0" style="flex:1">
          <label class="label" for="c-group">List</label>
          <select class="select" id="c-group" name="group_id">
            <option value="">No list</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <button class="btn btn--primary" type="submit"><?= icon('plus') ?> Add</button>
    </form>

    <?php if (!$rows): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('users') ?></div>
        <div class="portal-empty__title">No contacts <?= $q !== '' ? 'match that' : 'here yet' ?></div>
        <p class="text-sm text-muted">Add them one at a time above, or import a spreadsheet.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap mt-8">
        <table class="table table--compact">
          <thead><tr><th>Name</th><th style="width:150px">Phone</th><th style="width:170px">List</th><th style="width:60px"></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= e($r['name'] ?: '—') ?></td>
                <td class="code text-sm"><?= e($r['phone']) ?></td>
                <td class="text-sm text-muted"><?= e($r['group_name'] ?: '—') ?></td>
                <td>
                  <form method="post" action="<?= url($base . '/contacts/' . (int) $r['id'] . '/delete') ?>"
                        data-confirm="Remove this contact?">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
    <?php endif; ?>
  </div>
</div>
