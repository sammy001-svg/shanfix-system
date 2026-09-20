<?php
/**
 * Saved messages: the ones they send again and again.
 *
 * Each shows what it will cost before it is used, because a saved
 * message with a curly apostrophe in it quietly costs twice as much as
 * the same words without one.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Engine;
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Write a new one</div></div>

    <form method="post" action="<?= url($base . '/templates') ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label class="label" for="tpl-title">Name it</label>
        <input class="input" id="tpl-title" name="title" maxlength="120" required placeholder="e.g. Ready for collection">
        <span class="field-hint">Only you see this.</span>
      </div>
      <div class="field">
        <label class="label" for="message">The message</label>
        <textarea class="textarea" id="message" name="message" rows="4" required maxlength="918"
                  data-sms-counter="#tpl-cost" placeholder="Hello {name}, your order is ready for collection."></textarea>
        <span class="field-hint" id="tpl-cost">0 characters · 1 part each</span>
      </div>
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('save') ?> Save it</button>
    </form>
  </div>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Your saved messages</div>
      <span class="text-sm text-muted"><?= count($templates) ?></span>
    </div>

    <?php if (!$templates): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('file-text') ?></div>
        <div class="portal-empty__title">Nothing saved yet</div>
        <p class="text-sm text-muted">
          Save the messages you send often and they appear on the send and
          campaign pages, ready to use.
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th style="width:200px">Name</th>
              <th>Message</th>
              <th class="num" style="width:120px">Cost each</th>
              <th style="width:150px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($templates as $t): ?>
              <?php $size = Engine::measure((string) $t['message']); ?>
              <tr>
                <td class="fw-600"><?= e($t['title']) ?></td>
                <td class="text-sm" style="white-space:pre-wrap"><?= e($t['message']) ?></td>
                <td class="num text-sm">
                  <?= $size['parts'] ?> unit<?= $size['parts'] === 1 ? '' : 's' ?>
                  <div class="text-xs text-muted">
                    <?= $size['length'] ?> characters<?= $size['unicode'] ? ' · special' : '' ?>
                  </div>
                </td>
                <td>
                  <div class="btn-group">
                    <button class="btn btn--ghost btn--sm" type="button" data-modal-open="tpl-<?= (int) $t['id'] ?>">Edit</button>
                    <form method="post" action="<?= url($base . '/templates/' . (int) $t['id'] . '/delete') ?>"
                          data-confirm="Delete &quot;<?= e($t['title']) ?>&quot;?">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?></button>
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

  <div class="portal-card portal-card--quiet">
    <div class="text-sm">
      <div class="fw-600 mb-8">Putting people's own details in</div>
      <p class="text-muted mb-0">
        Write <span class="code">{name}</span> and each person gets their own
        name. Any other column from your contact list works the same way —
        <span class="code">{town}</span>, <span class="code">{balance}</span>,
        whatever your file called them. A placeholder nobody has a value for
        is left as it is, so check one before sending to thousands.
      </p>
    </div>
  </div>
</div>

<?php foreach ($templates as $t): ?>
  <div class="modal-backdrop" id="tpl-<?= (int) $t['id'] ?>">
    <div class="modal">
      <form method="post" action="<?= url($base . '/templates') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <div class="modal__head">
          <div class="card__title">Edit <?= e($t['title']) ?></div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field">
            <label class="label" for="et-<?= (int) $t['id'] ?>">Name</label>
            <input class="input" id="et-<?= (int) $t['id'] ?>" name="title" maxlength="120" required value="<?= e($t['title']) ?>">
          </div>
          <div class="field mb-0">
            <label class="label" for="em-<?= (int) $t['id'] ?>">Message</label>
            <textarea class="textarea" id="em-<?= (int) $t['id'] ?>" name="message" rows="4" required maxlength="918"><?= e($t['message']) ?></textarea>
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
