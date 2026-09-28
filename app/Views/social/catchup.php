<?php
/**
 * Every published post that still owes numbers, on one page.
 *
 * The weak point of the whole module. Recording a month one post at a
 * time is a dozen page loads, and whether the reports ever have
 * anything in them turns on this being quick: open the app on a phone,
 * work down the list, save once.
 *
 * Oldest first, because those are the ones about to be forgotten.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Settings;
use App\Services\Social\Posts;
use App\Services\Social\Reports;
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/social')) ?>">Social</a> <span>/</span> Numbers
    </div>
    <h1>Record the numbers</h1>
    <div class="page-head__sub">
      <?php if ($owed > 0): ?>
        <?= (int) $owed ?> <?= $owed === 1 ? 'post is' : 'posts are' ?>
        out there with nothing written down. Read them off each app and fill
        in what you can — a blank is fine, a wrong nought is not.
      <?php else: ?>
        Everything published has numbers against it. Nothing owed.
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if ($all): ?>
      <a class="btn btn--outline" href="<?= e(url('/social/catch-up')) ?>">
        Only the ones still owed
      </a>
    <?php else: ?>
      <a class="btn btn--outline" href="<?= e(url('/social/catch-up?all=1')) ?>">
        Show everything, to correct one
      </a>
    <?php endif; ?>
    <a class="btn btn--outline" href="<?= e(url('/social/reports')) ?>">
      <?= icon('bar-chart') ?> Report
    </a>
  </div>
</div>

<?php if (!$byPost): ?>
  <div class="card">
    <div class="card__body">
      <p class="text-sm text-muted">
        <?= $all
            ? 'Nothing has been published in the last four months.'
            : 'Nothing is waiting on numbers. Everything published has been checked.' ?>
      </p>
    </div>
  </div>
<?php else: ?>

<form method="post" action="<?= e(url('/social/catch-up')) ?>">
  <?= csrf_field() ?>

  <?php foreach ($byPost as $group): ?>
    <?php $post = $group['post']; ?>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">
            <a href="<?= e(url('/social/' . $post['id'])) ?>"><?= e($post['title']) ?></a>
          </div>
          <div class="card__sub">
            <?= e($post['ref']) ?> ·
            <?= e(Posts::TYPES[$post['post_type']] ?? $post['post_type']) ?> ·
            went out <?= e(fdate($post['published_at'])) ?>
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php foreach ($group['targets'] as $t): ?>
          <div class="catch">
            <div class="catch__who">
              <strong><?= e($t['account']) ?></strong>
              <span class="text-xs text-muted">
                <?= e(Settings::SOCIAL[$t['network']] ?? $t['network']) ?>
              </span>
              <?php if ($t['post_url']): ?>
                <a class="text-xs" href="<?= e($t['post_url']) ?>"
                   target="_blank" rel="noopener noreferrer">
                  <?= icon('external') ?> open it
                </a>
              <?php endif; ?>
              <?php if ($t['metrics_at']): ?>
                <span class="badge badge--green">
                  recorded <?= e(fdate($t['metrics_at'])) ?>
                </span>
              <?php endif; ?>
            </div>

            <div class="numbers">
              <?php foreach (Reports::MEASURES as $key => $label): ?>
                <div class="numbers__one">
                  <label class="label" for="c<?= (int) $t['id'] ?><?= $key ?>"><?= e($label) ?></label>
                  <input class="input" type="number" min="0" inputmode="numeric"
                         id="c<?= (int) $t['id'] ?><?= $key ?>"
                         name="t[<?= (int) $t['id'] ?>][<?= $key ?>]"
                         value="<?= $t['metrics_at'] ? (int) $t[$key] : '' ?>">
                </div>
              <?php endforeach; ?>
            </div>

            <?php if (!$t['post_url']): ?>
              <div class="field mt-4">
                <label class="label" for="u<?= (int) $t['id'] ?>">Link to the post</label>
                <input class="input" id="u<?= (int) $t['id'] ?>"
                       name="t[<?= (int) $t['id'] ?>][post_url]" maxlength="255"
                       placeholder="paste it in so somebody can find it again">
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php // One save for the lot. The whole point of the page is not
        // having to press save twelve times. ?>
  <div class="card">
    <div class="card__body catch__save">
      <button class="btn btn--primary"><?= icon('save') ?> Save all of it</button>
      <span class="text-sm text-muted">
        Anything left empty is left alone — it stays as "not recorded" rather
        than becoming a nought, because the reports count those differently.
      </span>
    </div>
  </div>
</form>

<?php endif; ?>
