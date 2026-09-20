<?php
/**
 * What is going out later, and what is going out now.
 *
 * The one part of Bulk SMS that happens while nobody is watching, so it
 * gets a page of its own where the time can be changed or the whole
 * thing stopped before it goes.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Waiting to go</div>
      <a class="btn btn--primary btn--sm" href="<?= url($base . '/campaigns/new') ?>">
        <?= icon('plus') ?> Schedule one
      </a>
    </div>

    <?php if (!$rows): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('clock') ?></div>
        <div class="portal-empty__title">Nothing waiting</div>
        <p class="text-sm text-muted">
          A campaign with a date and time on it goes out on its own — you do
          not need to stay signed in.
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>Campaign</th>
              <th style="width:160px">Going to</th>
              <th style="width:170px">When</th>
              <th style="width:120px">State</th>
              <th style="width:210px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $c): ?>
              <?php [$cls, $word] = Present::campaign((string) $c['status']); ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url($base . '/campaigns/' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
                  <div class="text-xs text-muted code"><?= e($c['sender_id']) ?></div>
                </td>
                <td class="text-sm text-muted">
                  <?php if ($c['group_name']): ?>
                    <?= e($c['group_name']) ?>
                  <?php elseif ($c['file_path']): ?>
                    An uploaded file
                  <?php else: ?>
                    <?= number_format((int) $c['total_count']) ?> number<?= (int) $c['total_count'] === 1 ? '' : 's' ?>
                  <?php endif; ?>
                </td>
                <td class="text-sm">
                  <?= $c['scheduled_at'] ? e(fdatetime($c['scheduled_at'])) : 'As soon as possible' ?>
                </td>
                <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                <td>
                  <div class="btn-group">
                    <?php if ($c['status'] === 'scheduled'): ?>
                      <button class="btn btn--ghost btn--sm" type="button" data-modal-open="move-<?= (int) $c['id'] ?>">Change the time</button>
                    <?php endif; ?>
                    <form method="post" action="<?= url($base . '/campaigns/' . (int) $c['id'] . '/cancel') ?>"
                          data-confirm="Stop &quot;<?= e($c['name']) ?>&quot;? Anything already sent cannot be recalled.">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">Stop it</button>
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

  <?php if ($recent): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Scheduled ones that have gone</div></div>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($recent as $c): ?>
          <li class="portal-list__row">
            <a class="portal-list__main" href="<?= url($base . '/campaigns/' . (int) $c['id']) ?>">
              <span class="portal-list__title"><?= e($c['name']) ?></span>
              <span class="portal-list__meta">
                Sent <?= e(fdatetime($c['sent_at'] ?: $c['scheduled_at'])) ?>
                · <?= number_format((int) $c['sent_count']) ?> message<?= (int) $c['sent_count'] === 1 ? '' : 's' ?>
                · <?= e(Present::units($c['units_used'])) ?> units
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<?php foreach ($rows as $c): ?>
  <?php if ($c['status'] !== 'scheduled') continue; ?>
  <div class="modal-backdrop" id="move-<?= (int) $c['id'] ?>">
    <div class="modal modal--sm">
      <form method="post" action="<?= url($base . '/campaigns/' . (int) $c['id'] . '/reschedule') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Change when <?= e($c['name']) ?> goes</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field mb-0">
            <label class="label" for="sw-<?= (int) $c['id'] ?>">New time</label>
            <input class="input" type="datetime-local" id="sw-<?= (int) $c['id'] ?>" name="scheduled_at" required
                   value="<?= e(date('Y-m-d\TH:i', strtotime((string) $c['scheduled_at']))) ?>">
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Move it</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
