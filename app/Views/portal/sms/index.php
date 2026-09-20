<?php
/**
 * The SMS page a customer lands on: send something now, and see how the
 * last few went.
 *
 * The quick send is the whole point of the page, so it is the first thing
 * on it, with the cost of what they have typed shown before they press
 * anything — the number of parts is the surprise people hate most about
 * SMS, and it is cheap to show.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'home';
$chartMax = max(1, ...array_map(static fn(array $d): int => $d['sent'] + $d['failed'], $daily));
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if (!$senders): ?>
    <div class="alert alert--info">
      <?= icon('info') ?>
      <div class="alert__body">
        Before you can send anything, you need a sender ID — the name your
        messages arrive from. <a href="<?= url('/portal/sms/senders') ?>"><strong>Ask for one</strong></a>;
        the networks usually approve it within a few working days.
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <form method="post" action="<?= url('/portal/sms/send') ?>" id="quick-send">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="recipients">Send to</label>
        <textarea class="textarea" id="recipients" name="recipients" rows="2" required
                  placeholder="0712345678, 0722000111"><?= e(old('recipients')) ?></textarea>
        <span class="field-hint">
          Up to 50 numbers, separated by commas or new lines. For a bigger
          list, <a href="<?= url('/portal/sms/campaigns/new') ?>">start a campaign</a>.
        </span>
      </div>

      <div class="portal-cols">
        <div class="field">
          <label class="label" for="sender_id">From</label>
          <select class="select" id="sender_id" name="sender_id" required <?= $senders ? '' : 'disabled' ?>>
            <?php foreach ($senders as $s): ?>
              <option value="<?= e($s['sender_id']) ?>"><?= e($s['sender_id']) ?></option>
            <?php endforeach; ?>
            <?php if (!$senders): ?><option value="">No sender ID yet</option><?php endif; ?>
          </select>
        </div>

        <?php if ($templates): ?>
          <div class="field">
            <label class="label" for="template">Use a saved message</label>
            <select class="select" id="template" data-sms-template>
              <option value="">Write a new one</option>
              <?php foreach ($templates as $t): ?>
                <option value="<?= e($t['message']) ?>"><?= e($t['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label class="label" for="message">Message</label>
        <textarea class="textarea" id="message" name="message" rows="4" required maxlength="918"
                  data-sms-counter="#sms-cost"><?= e(old('message')) ?></textarea>
        <span class="field-hint" id="sms-cost">0 characters · 1 part each</span>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit" <?= $senders ? '' : 'disabled' ?>>
        <?= icon('send') ?> Send now
      </button>
    </form>
  </div>

  <div class="portal-stats">
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= number_format($totals['sent']) ?></div>
      <div class="portal-stat__label">Sent in 30 days</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= $totals['rate'] === null ? '—' : e($totals['rate']) . '%' ?></div>
      <div class="portal-stat__label">Delivered</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= e(Present::units($totals['units'])) ?></div>
      <div class="portal-stat__label">Units used</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure">KES <?= e(number_format($price, 2)) ?></div>
      <div class="portal-stat__label">Per unit</div>
    </div>
  </div>

  <?php if (array_sum(array_map(static fn(array $d): int => $d['sent'], $daily)) > 0): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">The last two weeks</div></div>
      <div class="chart-columns">
        <?php foreach ($daily as $d): ?>
          <div class="chart-col" title="<?= e(fdate($d['day'])) ?>: <?= $d['sent'] ?> sent, <?= $d['delivered'] ?> delivered">
            <div class="chart-col__stack">
              <div class="chart-col__bar" style="height:<?= number_format($d['failed'] / $chartMax * 100, 2) ?>%;background:var(--red-600)"></div>
              <div class="chart-col__bar chart-col__bar--green" style="height:<?= number_format($d['sent'] / $chartMax * 100, 2) ?>%"></div>
            </div>
            <div class="chart-col__label"><?= e(date('j', strtotime($d['day']))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Recent campaigns</div>
      <a class="portal-card__more" href="<?= url('/portal/sms/campaigns') ?>">All campaigns</a>
    </div>

    <?php if (!$campaigns): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('send') ?></div>
        <div class="portal-empty__title">Nothing sent yet</div>
        <p class="text-sm text-muted">
          A campaign sends one message to a whole list — a contact group, or
          a spreadsheet you upload.
        </p>
        <a class="btn btn--outline" href="<?= url('/portal/sms/campaigns/new') ?>">Start a campaign</a>
      </div>
    <?php else: ?>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($campaigns as $c): ?>
          <?php [$cls, $word] = Present::campaign((string) $c['status']); ?>
          <li class="portal-list__row">
            <a class="portal-list__main" href="<?= url('/portal/sms/campaigns/' . (int) $c['id']) ?>">
              <span class="portal-list__title"><?= e($c['name']) ?></span>
              <span class="portal-list__meta">
                <?= number_format((int) $c['sent_count']) ?> sent
                <?php if ((int) $c['failed_count'] > 0): ?>· <?= number_format((int) $c['failed_count']) ?> failed<?php endif; ?>
                · <?= e(fdate($c['created_at'])) ?>
              </span>
            </a>
            <span class="portal-list__side"><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Saved messages</div>
    </div>

    <?php if ($templates): ?>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($templates as $t): ?>
          <li class="portal-list__row">
            <span class="portal-list__main">
              <span class="portal-list__title"><?= e($t['title']) ?></span>
              <span class="portal-list__meta"><?= e(mb_strimwidth((string) $t['message'], 0, 90, '…')) ?></span>
            </span>
            <span class="portal-list__side">
              <form method="post" action="<?= url('/portal/sms/templates/' . (int) $t['id'] . '/delete') ?>"
                    data-confirm="Delete this saved message?">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?></button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" action="<?= url('/portal/sms/templates') ?>" class="mt-8">
      <?= csrf_field() ?>
      <div class="portal-cols">
        <div class="field">
          <label class="label" for="tpl-title">Name</label>
          <input class="input" id="tpl-title" name="title" maxlength="120" required placeholder="e.g. Ready for collection">
        </div>
      </div>
      <div class="field">
        <label class="label" for="tpl-message">Message</label>
        <textarea class="textarea" id="tpl-message" name="message" rows="2" required maxlength="918"
                  placeholder="Hello {name}, your order is ready."></textarea>
        <span class="field-hint">
          {name} and any other column from your contact list is filled in for
          each person when you use it in a campaign.
        </span>
      </div>
      <button class="btn btn--outline btn--sm" type="submit"><?= icon('save') ?> Save this message</button>
    </form>
  </div>
</div>
