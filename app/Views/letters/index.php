<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tabUrl = static fn(?string $s): string => url('/letters' . query_string(['status' => $s]));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Letters</h1>
    <div class="page-head__sub">
      Company letters on the letterhead — and a record of what was sent to whom
    </div>
  </div>
  <?php if (can('letters.manage')): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= url('/letters/create') ?>">
        <?= icon('plus') ?> Write a letter
      </a>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $filters['status'] === '' ? 'is-active' : '' ?>" href="<?= e($tabUrl(null)) ?>">All</a>
    <a class="tab <?= $filters['status'] === 'draft' ? 'is-active' : '' ?>" href="<?= e($tabUrl('draft')) ?>">
      Drafts
      <?php if (!empty($counts['draft'])): ?><span class="tab__count"><?= (int) $counts['draft'] ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $filters['status'] === 'final' ? 'is-active' : '' ?>" href="<?= e($tabUrl('final')) ?>">
      Sent
      <?php if (!empty($counts['final'])): ?><span class="tab__count"><?= (int) $counts['final'] ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="card__body">
    <form method="get" action="<?= url('/letters') ?>">
      <?php if ($filters['status'] !== ''): ?>
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
      <?php endif; ?>
      <input class="input" type="search" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Search by subject, recipient or reference…"
             aria-label="Search letters" data-debounce-submit>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$letters): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('mail') ?></div>
      <div class="card__title mt-8">
        <?= $filters['search'] !== '' ? 'Nothing matching that' : 'No letters yet' ?>
      </div>
      <p class="text-sm text-muted mb-0">
        <?php if ($filters['search'] !== ''): ?>
          Try a different word, or clear the search.
        <?php else: ?>
          Letters written here print on the company letterhead, and stay on
          record so anybody can find what was sent.
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:130px">Reference</th>
            <th>Subject</th>
            <th style="width:190px">To</th>
            <th style="width:110px">Date</th>
            <th style="width:90px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($letters as $l): ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/letters/' . $l['id']) ?>">
                  <code class="text-xs"><?= e($l['reference']) ?></code>
                </a>
              </td>
              <td>
                <strong><?= e(str_excerpt($l['subject'], 60)) ?></strong>
                <?php if ($l['author']): ?>
                  <div class="table__muted"><?= e($l['author']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?= e($l['recipient_name']) ?>
                <?php if ($l['recipient_org']): ?>
                  <div class="table__muted"><?= e(str_excerpt($l['recipient_org'], 28)) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-xs text-muted">
                <?= e(date('j M Y', strtotime((string) $l['letter_date']))) ?>
              </td>
              <td>
                <span class="badge badge--<?= $l['status'] === 'final' ? 'green' : 'grey' ?>">
                  <?= $l['status'] === 'final' ? 'Sent' : 'Draft' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
