<?php
/**
 * Whether any of this is working.
 *
 * Every figure here was typed in by whoever posted, so the screen says
 * how much of the month has actually been checked before it says
 * anything else. A report that quietly averages blanks is worse than no
 * report, because somebody acts on it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Settings;
use App\Services\Social\Reports;

$days = ['1' => 'Sunday', '2' => 'Monday', '3' => 'Tuesday', '4' => 'Wednesday',
         '5' => 'Thursday', '6' => 'Friday', '7' => 'Saturday'];
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/social')) ?>">Social</a> <span>/</span> Report
    </div>
    <h1>Social media report</h1>
    <div class="page-head__sub">
      <?= e(fdate($from)) ?> to <?= e(fdate($to)) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <form method="get" action="<?= e(url('/social/reports')) ?>" class="field-row">
      <input class="input" type="date" name="from" value="<?= e($from) ?>">
      <span class="text-muted">to</span>
      <input class="input" type="date" name="to" value="<?= e($to) ?>">
      <button class="btn btn--outline btn--sm">Show</button>
    </form>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat stat--green">
    <div class="stat__label">Posted</div>
    <div class="stat__value"><?= number_format($summary['posted']) ?></div>
    <div class="stat__meta"><?= number_format($summary['planned']) ?> more planned</div>
  </div>

  <div class="stat stat--navy">
    <div class="stat__label">Reach</div>
    <div class="stat__value"><?= number_format($summary['reach']) ?></div>
    <div class="stat__meta">people the posts got in front of</div>
  </div>

  <div class="stat stat--navy">
    <div class="stat__label">Engagements</div>
    <div class="stat__value"><?= number_format($summary['engagement']) ?></div>
    <div class="stat__meta">likes, comments, shares and saves</div>
  </div>

  <div class="stat <?= $summary['follows'] > 0 ? 'stat--green' : '' ?>">
    <div class="stat__label">New followers</div>
    <div class="stat__value"><?= number_format($summary['follows']) ?></div>
    <div class="stat__meta">gained from these posts</div>
  </div>
</div>

<?php if ($summary['unchecked'] > 0): ?>
  <?php // Said before anything else. Somebody reading "1,200 reach" has
        // to know whether that is the month or the third of it that
        // anybody went back and looked at. ?>
  <div class="alert alert--warning mb-16">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong><?= (int) $summary['unchecked'] ?></strong>
      <?= $summary['unchecked'] === 1 ? 'post has' : 'posts have' ?>
      no numbers written down yet, so everything below is what has been
      checked and not what went out. Open a post and record what the app says.
    </div>
  </div>
<?php endif; ?>

<?php if ($summary['awaiting'] > 0): ?>
  <div class="alert alert--info mb-16">
    <?= icon('info') ?>
    <div class="alert__body">
      <strong><?= (int) $summary['awaiting'] ?></strong>
      <?= $summary['awaiting'] === 1 ? 'post is' : 'posts are' ?>
      waiting for approval.
      <a href="<?= e(url('/social/list?status=awaiting')) ?>">Look at them</a>.
    </div>
  </div>
<?php endif; ?>

<div class="grid-2">
  <div class="hr-col">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Network by network</div>
          <div class="card__sub">
            The same caption does differently in each place. This is the split
            that says which is worth the effort.
          </div>
        </div>
      </div>
      <div class="card__body card__body--flush">
        <?php if (!$networks): ?>
          <p class="text-sm text-muted p-16">Nothing published in this period.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Network</th>
                <th class="num">Posts</th>
                <th class="num">Reach</th>
                <th class="num">Engagements</th>
                <th class="num">Followers</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($networks as $row): ?>
                <tr>
                  <td>
                    <strong><?= e(Settings::SOCIAL[$row['network']] ?? $row['network']) ?></strong>
                    <?php if ((int) $row['checked'] < (int) $row['posts']): ?>
                      <div class="text-xs text-muted">
                        <?= (int) $row['checked'] ?> of <?= (int) $row['posts'] ?> checked
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= number_format((int) $row['posts']) ?></td>
                  <td class="num"><?= number_format((int) $row['reach']) ?></td>
                  <td class="num"><?= number_format((int) $row['engagement']) ?></td>
                  <td class="num"><?= number_format((int) $row['follows']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><div class="card__title">The ones that worked</div></div>
      <div class="card__body card__body--flush">
        <?php if (!$best): ?>
          <p class="text-sm text-muted p-16">
            Nothing with numbers against it yet.
          </p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Post</th>
                <th class="num">Reach</th>
                <th class="num">Engagements</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($best as $row): ?>
                <tr>
                  <td>
                    <a href="<?= e(url('/social/' . $row['id'])) ?>">
                      <strong><?= e(str_excerpt($row['title'], 46)) ?></strong>
                    </a>
                    <div class="text-xs text-muted">
                      <?= e(fdate($row['published_at'])) ?>
                      <?php if ($row['campaign']): ?> · <?= e($row['campaign']) ?><?php endif; ?>
                      <?php if ($row['networks']): ?>
                        · <?= e(implode(', ', array_map(
                              static fn(string $n): string => Settings::SOCIAL[$n] ?? $n,
                              explode(',', (string) $row['networks'])
                          ))) ?>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="num"><?= number_format((int) $row['reach']) ?></td>
                  <td class="num"><strong><?= number_format((int) $row['engagement']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="hr-col">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Where each page stands</div>
          <div class="card__sub">
            Typed in when somebody looks, so the date matters as much as the
            number.
          </div>
        </div>
      </div>
      <div class="card__body card__body--flush">
        <?php if (!$following): ?>
          <p class="text-sm text-muted p-16">
            No profiles set up. <a href="<?= e(url('/social/accounts')) ?>">Add them</a>.
          </p>
        <?php else: ?>
          <table class="table">
            <tbody>
              <?php foreach ($following as $row): ?>
                <tr>
                  <td>
                    <strong><?= e($row['name']) ?></strong>
                    <div class="text-xs text-muted">
                      <?= e(Settings::SOCIAL[$row['network']] ?? $row['network']) ?>
                      <?= $row['handle'] ? ' · ' . e($row['handle']) : '' ?>
                    </div>
                  </td>
                  <td class="num">
                    <?= $row['followers_at'] ? number_format((int) $row['followers']) : '—' ?>
                    <div class="text-xs text-muted">
                      <?= $row['followers_at'] ? e(fdate($row['followers_at'])) : 'never checked' ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($campaigns): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Campaigns</div></div>
        <div class="card__body card__body--flush">
          <table class="table">
            <thead>
              <tr>
                <th>Campaign</th>
                <th class="num">Posts</th>
                <th class="num">Reach</th>
                <th class="num">Spent</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($campaigns as $row): ?>
                <tr>
                  <td>
                    <strong><?= e($row['name']) ?></strong>
                    <?php if ($row['goal']): ?>
                      <div class="text-xs text-muted"><?= e(str_excerpt($row['goal'], 50)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= number_format((int) $row['posts']) ?></td>
                  <td class="num"><?= number_format((int) $row['reach']) ?></td>
                  <td class="num">
                    <?= (float) $row['spend'] > 0 ? number_format((float) $row['spend'], 0) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($people && count($people) > 1): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Who wrote what</div></div>
        <div class="card__body card__body--flush">
          <table class="table">
            <tbody>
              <?php foreach ($people as $row): ?>
                <tr>
                  <td><?= e($row['name']) ?></td>
                  <td class="num">
                    <?= number_format((int) $row['posts']) ?>
                    <?= (int) $row['posts'] === 1 ? 'post' : 'posts' ?>
                  </td>
                  <td class="num"><?= number_format((int) $row['engagement']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($timing): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">When the good ones went out</div>
            <div class="card__sub">
              A hint, not a finding. A few posts a week is not enough to rebuild
              your week around.
            </div>
          </div>
        </div>
        <div class="card__body card__body--flush">
          <table class="table">
            <tbody>
              <?php foreach (array_slice($timing, 0, 6) as $row): ?>
                <tr>
                  <td>
                    <?= e($days[(string) $row['dow']] ?? '') ?>
                    <?= e(str_pad((string) $row['hour'], 2, '0', STR_PAD_LEFT)) ?>:00
                  </td>
                  <td class="num">
                    <?= (int) $row['posts'] ?> <?= (int) $row['posts'] === 1 ? 'post' : 'posts' ?>
                  </td>
                  <td class="num"><?= number_format((int) $row['engagement']) ?> avg</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
