<?php
/**
 * One post: what it says, where it is up to, and how it did.
 *
 * The same screen before and after it goes out, because they are the
 * same thing at two moments and splitting them would mean looking in
 * two places to answer "what did we say in that one?".
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Settings;
use App\Services\Social\Posts;
use App\Services\Social\Reports;

$status = (string) $post['status'];

$tone = match ($status) {
    'published' => 'green',
    'approved'  => 'navy',
    'awaiting'  => 'amber',
    'cancelled' => 'grey',
    default     => 'grey',
};

/** Whether this post can move to $to, and whether this person may. */
$can = static function (string $to) use ($status, $canManage, $canApprove): bool {
    if (!Posts::canMove($status, $to)) {
        return false;
    }

    return $to === 'approved' ? $canApprove : $canManage;
};

$engagement = 0;
$reach      = 0;
$unchecked  = 0;

foreach ($targets as $t) {
    $engagement += (int) $t['likes'] + (int) $t['comments'] + (int) $t['shares'] + (int) $t['saves'];
    $reach      += (int) $t['reach'];
    $unchecked  += $t['metrics_at'] === null ? 1 : 0;
}
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/social')) ?>">Social</a> <span>/</span> <?= e($post['ref']) ?>
    </div>
    <h1><?= e($post['title']) ?></h1>
    <div class="page-head__sub">
      <span class="badge badge--<?= $tone ?>"><?= e(Posts::STATUSES[$status] ?? $status) ?></span>
      <span class="badge badge--grey"><?= e(Posts::TYPES[$post['post_type']] ?? $post['post_type']) ?></span>
      <?php if ($post['campaign']): ?>
        <span class="badge badge--grey"><?= e($post['campaign']) ?></span>
      <?php endif; ?>
      <?php if ($post['scheduled_for']): ?>
        <span class="text-sm text-muted">
          <?= $status === 'published' ? 'Went out' : 'Going out' ?>
          <?= e(fdatetime($post['published_at'] ?: $post['scheduled_for'])) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if ($canManage && $status !== 'published'): ?>
      <a class="btn btn--outline" href="<?= e(url('/social/' . $post['id'] . '/edit')) ?>">
        <?= icon('edit') ?> Edit
      </a>
    <?php endif; ?>
    <a class="btn btn--ghost" href="<?= e(url('/social')) ?>">Back to the calendar</a>
  </div>
</div>

<?php if ($post['changes_asked']): ?>
  <div class="alert alert--warning mb-16">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>Changes asked for:</strong> <?= e($post['changes_asked']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head"><div class="card__title">What it says</div></div>
      <div class="card__body">
        <?php if ($post['caption']): ?>
          <?php // Escaped only. The blank lines are the writer's own —
                // .social__caption is pre-wrap, so nl2br on top of it
                // would turn every line break into two. ?>
          <div class="social__caption"><?= e($post['caption']) ?></div>
        <?php else: ?>
          <p class="text-sm text-muted">No caption written yet.</p>
        <?php endif; ?>

        <?php if ($post['hashtags']): ?>
          <p class="social__tags"><?= e($post['hashtags']) ?></p>
        <?php endif; ?>

        <?php if ($post['link_url']): ?>
          <p class="text-sm mt-8">
            <?= icon('external') ?>
            <a href="<?= e($post['link_url']) ?>" target="_blank" rel="noopener noreferrer">
              <?= e($post['link_url']) ?>
            </a>
          </p>
        <?php endif; ?>

        <?php if ($post['first_comment']): ?>
          <p class="text-sm text-muted mt-8">
            <strong>First comment:</strong> <?= e($post['first_comment']) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($images): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Picture</div></div>
        <div class="card__body">
          <div class="social__shots">
            <?php foreach ($images as $image): ?>
              <figure class="social__shot">
                <img src="<?= e(url('catalogue/image/social/' . $image['id'])) ?>"
                     alt="<?= e($image['alt_text'] ?? $post['title']) ?>" loading="lazy">
                <?php if ($canManage && $status !== 'published'): ?>
                  <form method="post"
                        action="<?= e(url('/social/' . $post['id'] . '/images/' . $image['id'] . '/delete')) ?>"
                        onsubmit="return confirm('Remove this picture?')">
                    <?= csrf_field() ?>
                    <button class="social__shot-x" title="Remove">&times;</button>
                  </form>
                <?php endif; ?>
              </figure>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php // ── Where it went, and how it did ──────────────────────── ?>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Where it goes</div>
          <div class="card__sub">
            <?= $status === 'published'
                ? 'Paste in each link and the numbers when you have them.'
                : 'Once it is out, come back and record where it landed.' ?>
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php if (!$targets): ?>
          <p class="text-sm text-muted">
            Not going anywhere yet.
            <?php if ($canManage): ?>
              <a href="<?= e(url('/social/' . $post['id'] . '/edit')) ?>">Choose the profiles</a>.
            <?php endif; ?>
          </p>
        <?php else: ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/record')) ?>">
            <?= csrf_field() ?>

            <?php foreach ($targets as $t): ?>
              <details class="target" <?= $status === 'published' ? 'open' : '' ?>>
                <summary class="target__head">
                  <span class="target__who">
                    <strong><?= e($t['account']) ?></strong>
                    <span class="text-xs text-muted">
                      <?= e(Settings::SOCIAL[$t['network']] ?? $t['network']) ?>
                    </span>
                  </span>
                  <?php if ($t['metrics_at']): ?>
                    <span class="badge badge--green">
                      <?= number_format((int) $t['likes'] + (int) $t['comments']
                                      + (int) $t['shares'] + (int) $t['saves']) ?> engagements
                    </span>
                  <?php else: ?>
                    <span class="badge badge--grey">Not recorded</span>
                  <?php endif; ?>
                  <?= icon('chevron-down', 'target__caret') ?>
                </summary>

                <div class="target__body">
                  <div class="form-grid form-grid--2">
                    <div class="field">
                      <label class="label">Link to the post</label>
                      <input class="input" name="t[<?= (int) $t['id'] ?>][post_url]"
                             maxlength="255" value="<?= e($t['post_url'] ?? '') ?>"
                             placeholder="facebook.com/shanfix/posts/…"
                             <?= $canManage ? '' : 'disabled' ?>>
                    </div>
                    <div class="field">
                      <label class="label">Went out</label>
                      <input class="input" type="datetime-local"
                             name="t[<?= (int) $t['id'] ?>][published_at]"
                             value="<?= $t['published_at'] ? e(date('Y-m-d\TH:i', strtotime((string) $t['published_at']))) : '' ?>"
                             <?= $canManage ? '' : 'disabled' ?>>
                    </div>
                  </div>

                  <div class="numbers">
                    <?php foreach (Reports::MEASURES as $key => $label): ?>
                      <div class="numbers__one">
                        <label class="label" for="m<?= (int) $t['id'] ?><?= $key ?>"><?= e($label) ?></label>
                        <input class="input" type="number" min="0" inputmode="numeric"
                               id="m<?= (int) $t['id'] ?><?= $key ?>"
                               name="t[<?= (int) $t['id'] ?>][<?= $key ?>]"
                               value="<?= $t['metrics_at'] ? (int) $t[$key] : '' ?>"
                               <?= $canManage ? '' : 'disabled' ?>>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <?php if ($t['metrics_at']): ?>
                    <p class="text-xs text-muted mt-4">
                      Last written down <?= e(fdatetime($t['metrics_at'])) ?>.
                    </p>
                  <?php endif; ?>

                  <?php if ($t['post_url']): ?>
                    <p class="text-sm mt-4">
                      <a href="<?= e($t['post_url']) ?>" target="_blank" rel="noopener noreferrer">
                        <?= icon('external') ?> Open it
                      </a>
                    </p>
                  <?php endif; ?>
                </div>
              </details>
            <?php endforeach; ?>

            <?php if ($canManage): ?>
              <button class="btn btn--primary mt-8"><?= icon('save') ?> Write it down</button>
              <p class="text-xs text-muted mt-4">
                Leave a box empty rather than typing nought — a post nobody has
                checked on is not a post that reached nobody, and the reports
                count those differently.
              </p>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside>
    <?php // ── Moving it along ─────────────────────────────────────── ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Where it is up to</div></div>
      <div class="card__body">
        <?php if ($post['approver']): ?>
          <p class="text-sm text-muted mb-8">
            Approved by <?= e($post['approver']) ?> on <?= e(fdate($post['approved_at'])) ?>.
          </p>
        <?php endif; ?>

        <?php if ($can('awaiting')): ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/move')) ?>" class="mb-8">
            <?= csrf_field() ?>
            <input type="hidden" name="to" value="awaiting">
            <button class="btn btn--outline btn--block">Put it up for approval</button>
          </form>
        <?php endif; ?>

        <?php if ($can('approved')): ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/move')) ?>" class="mb-8">
            <?= csrf_field() ?>
            <input type="hidden" name="to" value="approved">
            <button class="btn btn--primary btn--block"><?= icon('check-circle') ?> Approve it</button>
          </form>
        <?php endif; ?>

        <?php if ($status === 'awaiting' && $canApprove): ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/move')) ?>" class="mb-8">
            <?= csrf_field() ?>
            <input type="hidden" name="to" value="draft">
            <div class="field">
              <label class="label" for="note">Send it back, saying why</label>
              <textarea class="input" id="note" name="note" rows="2" maxlength="500"
                        placeholder="The price is last year's"></textarea>
            </div>
            <button class="btn btn--outline btn--block">Send it back</button>
          </form>
        <?php endif; ?>

        <?php if ($can('published')): ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/move')) ?>" class="mb-8">
            <?= csrf_field() ?>
            <input type="hidden" name="to" value="published">
            <button class="btn btn--primary btn--block"><?= icon('send') ?> It has gone out</button>
            <p class="text-xs text-muted mt-4">
              Post it in each app first, then mark it here.
            </p>
          </form>
        <?php endif; ?>

        <?php if ($can('cancelled')): ?>
          <form method="post" action="<?= e(url('/social/' . $post['id'] . '/move')) ?>"
                onsubmit="return confirm('Drop this post?')">
            <?= csrf_field() ?>
            <input type="hidden" name="to" value="cancelled">
            <button class="btn btn--ghost btn--block" style="color:var(--red-700)">Drop it</button>
          </form>
        <?php endif; ?>

        <?php if ($status === 'draft' && $needsYes && !$canApprove): ?>
          <p class="text-xs text-muted mt-8">
            What goes out under the company's name needs somebody else's yes
            first. Put it up for approval when it is ready.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($status === 'published' && $targets): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">How it did</div></div>
        <div class="card__body">
          <div class="hr-pair">
            <div class="hr-tile">
              <div class="hr-tile__label">Reach</div>
              <div class="hr-tile__value"><?= number_format($reach) ?></div>
            </div>
            <div class="hr-tile">
              <div class="hr-tile__label">Engagements</div>
              <div class="hr-tile__value"><?= number_format($engagement) ?></div>
            </div>
          </div>

          <?php if ($unchecked > 0): ?>
            <p class="text-xs text-muted mt-8">
              <?= $unchecked ?> of <?= count($targets) ?>
              <?= $unchecked === 1 ? 'profile has' : 'profiles have' ?>
              no numbers yet, so this is not the whole picture.
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__body">
        <dl class="dl">
          <dt>Reference</dt><dd><?= e($post['ref']) ?></dd>
          <dt>Written by</dt><dd><?= e($post['owner'] ?? '—') ?></dd>
          <dt>Started</dt><dd><?= e(fdate($post['created_at'])) ?></dd>
        </dl>

        <?php if ($post['notes']): ?>
          <p class="text-sm text-muted mt-8"><?= nl2br(e($post['notes'])) ?></p>
        <?php endif; ?>

        <?php if ($canManage && $status !== 'published'): ?>
          <form method="post" class="mt-16"
                action="<?= e(url('/social/' . $post['id'] . '/delete')) ?>"
                onsubmit="return confirm('Remove this post for good?')">
            <?= csrf_field() ?>
            <button class="btn btn--ghost btn--sm" style="color:var(--red-700)">Remove it</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </aside>
</div>
