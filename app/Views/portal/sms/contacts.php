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
      <button class="btn btn--outline btn--sm" type="button" data-modal-open="import-contacts">
        <?= icon('download') ?> Import a file
      </button>
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

<div class="modal-backdrop" id="import-contacts">
  <div class="modal">
    <form method="post" action="<?= url($base . '/contacts/import') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="card__title">Import contacts</div>
        <button class="modal__close" type="button" data-modal-close>&times;</button>
      </div>
      <div class="modal__body">
        <div class="field">
          <label class="label" for="im-file">The file</label>
          <input class="input" type="file" id="im-file" name="list" accept=".csv,.xlsx,.txt" required>
          <span class="field-hint">
            CSV or Excel. A column called phone, mobile or number is enough;
            a name column is used if there is one, and everything else is
            kept so you can write {column} in a message. A plain list of
            numbers with no headings works too.
          </span>
        </div>
        <div class="field">
          <label class="label" for="im-group">Put them in</label>
          <select class="select" id="im-group" name="group_id">
            <option value="">No list</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field mb-0">
          <label class="label" for="im-new">Or a new list called</label>
          <input class="input" id="im-new" name="new_group" maxlength="120" placeholder="e.g. Expo leads">
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit">Import</button>
      </div>
    </form>
  </div>
</div>
