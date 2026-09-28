<?php
/**
 * The month, as a calendar.
 *
 * The question somebody opens this to answer is "is next week empty?",
 * which a list sorted by date answers badly and a grid answers at a
 * glance. Anything with no date yet sits alongside rather than inside,
 * because that holding list is exactly what you reach for when a week
 * turns out to be bare.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Settings;
use App\Services\Social\Posts;

$first     = strtotime($month . '-01');
$daysIn    = (int) date('t', $first);
// Monday-first, which is how a working week is read here.
$leading   = ((int) date('N', $first)) - 1;
$today     = date('Y-m-d');

$prev = date('Y-m', strtotime('-1 month', $first));
$next = date('Y-m', strtotime('+1 month', $first));

/** The little coloured dot a status gets. */
$tone = static fn(string $status): string => match ($status) {
    'published' => 'green',
    'approved'  => 'navy',
    'awaiting'  => 'amber',
    'cancelled' => 'grey',
    default     => 'grey',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Social calendar</h1>
    <div class="page-head__sub">
      What is going out, and when. Posting itself is still done in each app —
      this is the plan, the approval and the record of how it did.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(url('/social/list')) ?>"><?= icon('list') ?> All posts</a>
    <a class="btn btn--outline" href="<?= e(url('/social/reports')) ?>"><?= icon('bar-chart') ?> Report</a>
    <?php if ($canManage): ?>
      <a class="btn btn--primary" href="<?= e(url('/social/new')) ?>"><?= icon('plus') ?> New post</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= e(date('F Y', $first)) ?></div>
        <div class="cal__nav">
          <a class="btn btn--ghost btn--sm" href="<?= e(url('/social?month=' . $prev)) ?>">
            <?= icon('chevron-left') ?> <?= e(date('M', strtotime($prev . '-01'))) ?>
          </a>
          <a class="btn btn--ghost btn--sm" href="<?= e(url('/social?month=' . date('Y-m'))) ?>">This month</a>
          <a class="btn btn--ghost btn--sm" href="<?= e(url('/social?month=' . $next)) ?>">
            <?= e(date('M', strtotime($next . '-01'))) ?> <?= icon('chevron-right') ?>
          </a>
        </div>
      </div>

      <div class="card__body card__body--flush">
        <div class="cal">
          <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label): ?>
            <div class="cal__dow"><?= $label ?></div>
          <?php endforeach; ?>

          <?php for ($i = 0; $i < $leading; $i++): ?>
            <div class="cal__day cal__day--empty"></div>
          <?php endfor; ?>

          <?php for ($day = 1; $day <= $daysIn; $day++): ?>
            <?php
              $date = $month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
              $mine = $byDay[$date] ?? [];
            ?>
            <div class="cal__day<?= $date === $today ? ' is-today' : '' ?>">
              <div class="cal__date">
                <?= $day ?>
                <?php if ($canManage): ?>
                  <?php // Straight into a new post already dated. The
                        // gap between seeing an empty Thursday and
                        // filling it should be one click. ?>
                  <a class="cal__add" title="Plan something for this day"
                     href="<?= e(url('/social/new?on=' . $date)) ?>">+</a>
                <?php endif; ?>
              </div>

              <?php foreach ($mine as $post): ?>
                <a class="cal__post cal__post--<?= $tone($post['status']) ?>"
                   href="<?= e(url('/social/' . $post['id'])) ?>"
                   title="<?= e($post['title']) ?> — <?= e(Posts::STATUSES[$post['status']] ?? '') ?>">
                  <span class="cal__time"><?= e(date('H:i', strtotime((string) $post['scheduled_for']))) ?></span>
                  <span class="cal__what"><?= e(str_excerpt($post['title'], 40)) ?></span>
                  <?php if ($post['networks']): ?>
                    <span class="cal__nets">
                      <?php foreach (array_slice(explode(',', (string) $post['networks']), 0, 4) as $net): ?>
                        <span class="cal__net" title="<?= e(Settings::SOCIAL[$net] ?? $net) ?>">
                          <?= e(mb_strtoupper(mb_substr($net, 0, 1))) ?>
                        </span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">No date yet</div>
          <div class="card__sub">
            Ideas waiting for a slot. Open one and give it a date to put it on
            the calendar.
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php if (!$waiting): ?>
          <p class="text-sm text-muted">
            Nothing waiting. <?= $canManage ? 'A week with gaps is easier to fill from a list of ideas than from a blank page.' : '' ?>
          </p>
        <?php endif; ?>

        <?php foreach ($waiting as $post): ?>
          <a class="idea" href="<?= e(url('/social/' . $post['id'])) ?>">
            <span class="idea__title"><?= e($post['title']) ?></span>
            <span class="idea__meta">
              <span class="badge badge--<?= $tone($post['status']) ?>">
                <?= e(Posts::STATUSES[$post['status']] ?? $post['status']) ?>
              </span>
              <?php if ($post['owner']): ?>
                <span class="text-xs text-muted"><?= e($post['owner']) ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__body">
        <div class="text-xs text-muted">
          <strong>Posting is still done in each app.</strong>
          Facebook and Instagram only take posts from an application they have
          reviewed and approved, which is paperwork rather than something that
          can be written here. So: plan it here, post it there, then paste the
          link back in and write down how it did.
        </div>
      </div>
    </div>
  </aside>
</div>
