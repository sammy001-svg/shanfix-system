<?php
/**
 * Every post, as a list.
 *
 * The calendar is for planning; this is for looking something up — what
 * did we say in that one, which ones are still waiting on somebody.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Settings;
use App\Services\Social\Posts;

$tabs = ['all' => 'All'] + Posts::STATUSES;

$tabUrl = static function (string $key) use ($search): string {
    $q = ['status' => $key] + ($search !== '' ? ['q' => $search] : []);

    return url('/social/list?' . http_build_query($q));
};

$tone = static fn(string $status): string => match ($status) {
    'published' => 'green',
    'approved'  => 'navy',
    'awaiting'  => 'amber',
    default     => 'grey',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Social posts</h1>
    <div class="page-head__sub">Everything written, planned or posted.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(url('/social')) ?>"><?= icon('calendar') ?> Calendar</a>
    <a class="btn btn--outline" href="<?= e(url('/social/reports')) ?>"><?= icon('bar-chart') ?> Report</a>
    <?php if ($canManage): ?>
      <a class="btn btn--primary" href="<?= e(url('/social/new')) ?>"><?= icon('plus') ?> New post</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div class="field-row field-row--wrap">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="lc__tab<?= $show === $key ? ' is-on' : '' ?>" href="<?= e($tabUrl($key)) ?>">
          <?= e($label) ?>
          <?php if (($counts[$key] ?? 0) > 0): ?>
            <span class="lc__tab-n"><?= (int) $counts[$key] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e(url('/social/list')) ?>" class="field-row">
      <input type="hidden" name="status" value="<?= e($show) ?>">
      <input class="input" type="search" name="q" value="<?= e($search) ?>"
             placeholder="Search captions…" style="max-width:220px">
      <button class="btn btn--ghost btn--sm"><?= icon('search') ?></button>
    </form>
  </div>

  <div class="card__body card__body--flush">
    <?php if (!$posts): ?>
      <p class="text-sm text-muted p-16">
        Nothing here<?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?>.
      </p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Post</th>
            <th>Where</th>
            <th>When</th>
            <th>Written by</th>
            <th class="num">Engagements</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
            <tr>
              <td>
                <a href="<?= e(url('/social/' . $post['id'])) ?>">
                  <strong><?= e($post['title']) ?></strong>
                </a>
                <div class="text-xs text-muted">
                  <?= e($post['ref']) ?>
                  <?php if ($post['campaign']): ?> · <?= e($post['campaign']) ?><?php endif; ?>
                  <?php if ($post['caption']): ?>
                    · <?= e(str_excerpt($post['caption'], 60)) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-sm">
                <?php if ($post['networks']): ?>
                  <?php foreach (explode(',', (string) $post['networks']) as $net): ?>
                    <span class="badge badge--grey"><?= e(Settings::SOCIAL[$net] ?? $net) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-sm">
                <span class="badge badge--<?= $tone($post['status']) ?>">
                  <?= e(Posts::STATUSES[$post['status']] ?? $post['status']) ?>
                </span>
                <div class="text-xs text-muted">
                  <?= e(fdate($post['published_at'] ?: $post['scheduled_for'] ?: $post['created_at'])) ?>
                </div>
              </td>
              <td class="text-sm"><?= e($post['owner'] ?? '—') ?></td>
              <td class="num">
                <?= $post['status'] === 'published' ? number_format((int) $post['engagement']) : '—' ?>
              </td>
              <td class="actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/social/' . $post['id'])) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
