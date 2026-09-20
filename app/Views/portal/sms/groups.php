<?php
/**
 * Contact lists: what there is, how many are in each, and the tidying
 * jobs people actually do — rename, move everybody across, empty one
 * out, delete it.
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
        <a class="btn btn--ghost btn--sm" href="<?= url($base . '/contacts') ?>">All contacts</a>
      </div>
    </div>

    <p class="text-sm text-muted">
      A list is what a campaign sends to. <?= number_format($contacts) ?>
      contact<?= $contacts === 1 ? '' : 's' ?> in your address book<?php
        if ($loose > 0): ?>, <?= number_format($loose) ?> of them in no list at all<?php endif; ?>.
    </p>

    <?php if (!$groups): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('users') ?></div>
        <div class="portal-empty__title">No lists yet</div>
        <p class="text-sm text-muted">Make one below, or import a file straight into a new one.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>List</th>
              <th class="num" style="width:110px">Contacts</th>
              <th style="width:140px">Made</th>
              <th style="width:280px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $g): ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url($base . '/contacts?group=' . (int) $g['id']) ?>"><?= e($g['name']) ?></a>
                  <?php if ($g['description']): ?>
                    <div class="text-xs text-muted"><?= e($g['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= number_format((int) $g['people']) ?></td>
                <td class="text-sm text-muted"><?= e(fdate($g['created_at'])) ?></td>
                <td>
                  <div class="btn-group">
                    <button class="btn btn--ghost btn--sm" type="button" data-modal-open="rename-<?= (int) $g['id'] ?>">Rename</button>
                    <a class="btn btn--ghost btn--sm" href="<?= url($base . '/contacts/export?group=' . (int) $g['id']) ?>">Download</a>
                    <a class="btn btn--ghost btn--sm" href="<?= url($base . '/contacts/import?group=' . (int) $g['id']) ?>">Add to it</a>
                    <form method="post" action="<?= url($base . '/groups/' . (int) $g['id'] . '/empty') ?>"
                          data-confirm="Delete all <?= (int) $g['people'] ?> contacts in &quot;<?= e($g['name']) ?>&quot;? The list itself stays.">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">Empty</button>
                    </form>
                    <form method="post" action="<?= url($base . '/groups/' . (int) $g['id'] . '/delete') ?>"
                          data-confirm="Delete the list &quot;<?= e($g['name']) ?>&quot;? The contacts in it stay in your address book.">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit" title="Delete the list"><?= icon('trash') ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Make a list</div></div>
    <form method="post" action="<?= url($base . '/groups') ?>">
      <?= csrf_field() ?>
      <div class="portal-cols">
        <div class="field">
          <label class="label" for="g-name">Name</label>
          <input class="input" id="g-name" name="name" maxlength="120" required placeholder="e.g. Customers in Nairobi">
        </div>
        <div class="field">
          <label class="label" for="g-desc">What is it for</label>
          <input class="input" id="g-desc" name="description" maxlength="255" placeholder="Optional">
        </div>
      </div>
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?> Create</button>
    </form>
  </div>

  <?php if ($groups): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Move contacts</div></div>
      <p class="text-sm text-muted">
        Everybody in one list, moved to another. Useful after an import
        that went into the wrong place.
      </p>
      <form method="post" action="<?= url($base . '/contacts/move') ?>" class="row-form"
            data-confirm="Move every contact from one list to the other?">
        <?= csrf_field() ?>
        <div class="field mb-0" style="flex:1">
          <label class="label" for="mv-from">From</label>
          <select class="select" id="mv-from" name="from_group">
            <option value="">No list</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?> (<?= (int) $g['people'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field mb-0" style="flex:1">
          <label class="label" for="mv-to">To</label>
          <select class="select" id="mv-to" name="to_group">
            <option value="">No list</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn--outline" type="submit">Move them</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php foreach ($groups as $g): ?>
  <div class="modal-backdrop" id="rename-<?= (int) $g['id'] ?>">
    <div class="modal modal--sm">
      <form method="post" action="<?= url($base . '/groups') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
        <div class="modal__head">
          <div class="card__title">Rename <?= e($g['name']) ?></div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field">
            <label class="label" for="rn-<?= (int) $g['id'] ?>">Name</label>
            <input class="input" id="rn-<?= (int) $g['id'] ?>" name="name" maxlength="120" required value="<?= e($g['name']) ?>">
          </div>
          <div class="field mb-0">
            <label class="label" for="rd-<?= (int) $g['id'] ?>">What is it for</label>
            <input class="input" id="rd-<?= (int) $g['id'] ?>" name="description" maxlength="255" value="<?= e($g['description'] ?? '') ?>">
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
