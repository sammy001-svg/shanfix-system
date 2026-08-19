<?php
/**
 * The salesperson's dashboard.
 *
 * Only what is allocated to this person, so every figure here can be
 * opened and acted on. A number they cannot drill into would just raise
 * a question nobody could answer.
 *
 * @var array $stages
 * @var array $byStage
 * @var float $openValue
 * @var int   $openCount
 * @var array $thisMonth
 * @var array $stale
 * @var array $reminders
 * @var array $awaiting
 */
require_once APP_PATH . '/Views/partials/icons.php';

$me  = auth();
$won = (int) ($thisMonth['won'] ?? 0);
$lost = (int) ($thisMonth['lost'] ?? 0);
$decided = $won + $lost;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>My pipeline</h1>
    <div class="page-head__sub">
      <?= e(explode(' ', trim((string) ($me['name'] ?? 'there')))[0]) ?>, here is what is on your desk.
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('leads.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/leads/create') ?>">
        <?= icon('plus') ?> New lead
      </a>
    <?php endif; ?>
    <a class="btn btn--outline" href="<?= url('/leads') ?>">
      <?= icon('target') ?> Full board
    </a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Open leads</div>
    <div class="stat__value"><?= number_format($openCount) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Pipeline value</div>
    <div class="stat__value"><?= e(money_short($openValue)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Won this month</div>
    <div class="stat__value"><?= number_format($won) ?></div>
  </div>
  <div class="stat <?= count($stale) > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__label">Going quiet</div>
    <div class="stat__value"><?= number_format(count($stale)) ?></div>
  </div>
</div>

<div class="grid-2">

  <div class="card">
    <div class="card__head">
      <?= icon('target') ?>
      <div>
        <div class="card__title">Where your leads are</div>
        <?php if ($decided > 0): ?>
          <div class="card__sub">
            <?= $won ?> won and <?= $lost ?> lost this month
            — <?= round(($won / $decided) * 100) ?>% of those decided
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card__body">
      <?php if ($openCount === 0): ?>
        <div class="empty">
          <div class="empty__title">Nothing open</div>
          <p class="empty__text">
            No leads are allocated to you at the moment. Ask your manager to
            allocate some, or add one yourself.
          </p>
        </div>
      <?php else: ?>
        <?php foreach ($stages as $key => $stage): ?>
          <?php
          if (in_array($key, ['won', 'lost'], true)) continue;
          $row   = $byStage[$key] ?? null;
          $count = (int) ($row['count'] ?? 0);
          $width = $openCount > 0 ? round(($count / $openCount) * 100) : 0;
          ?>
          <div style="margin-bottom:12px">
            <div class="flex items-center justify-between text-sm">
              <a href="<?= url('/leads?view=board') ?>" class="fw-600">
                <?= e($stage['label'] ?? ucfirst($key)) ?>
              </a>
              <span class="text-muted">
                <?= $count ?>
                <?php if ($row && (float) $row['value'] > 0): ?>
                  · <?= e(money_short($row['value'])) ?>
                <?php endif; ?>
              </span>
            </div>
            <div class="progress mt-4">
              <div class="progress__bar" style="width:<?= (int) $width ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <?= icon('bell') ?>
      <div>
        <div class="card__title">Follow up now</div>
        <div class="card__sub">Due today or overdue</div>
      </div>
    </div>
    <?php if (!$reminders): ?>
      <div class="card__body">
        <div class="empty">
          <div class="empty__title">Nothing due</div>
          <p class="empty__text">No follow-ups are waiting on you.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <?php foreach ($reminders as $r): ?>
              <?php $late = strtotime($r['remind_at']) < time(); ?>
              <tr>
                <td>
                  <div class="fw-600"><?= e($r['title'] ?? 'Follow up') ?></div>
                  <?php if ($r['lead_id']): ?>
                    <a class="text-xs" href="<?= url('/leads/' . $r['lead_id']) ?>">
                      <?= e($r['lead_number'] ?? '') ?> · <?= e($r['lead_name'] ?? '') ?>
                    </a>
                  <?php endif; ?>
                </td>
                <td class="text-right text-sm <?= $late ? 'text-red fw-600' : 'text-muted' ?>"
                    style="width:120px">
                  <?= e(time_ago($r['remind_at'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<div class="grid-2">

  <div class="card">
    <div class="card__head">
      <?= icon('alert-triangle') ?>
      <div>
        <div class="card__title">Going quiet</div>
        <div class="card__sub">Nothing logged for a fortnight</div>
      </div>
    </div>
    <?php if (!$stale): ?>
      <div class="card__body">
        <div class="empty">
          <div class="empty__title">All current</div>
          <p class="empty__text">Every open lead has had activity recently.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Lead</th><th style="width:110px">Value</th><th style="width:110px">Last touch</th></tr>
          </thead>
          <tbody>
            <?php foreach ($stale as $l): ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url('/leads/' . $l['id']) ?>"><?= e($l['name']) ?></a>
                  <div class="text-xs text-muted">
                    <?= e($l['company'] ?: $l['lead_number']) ?>
                    · <?= e(label_of($l['stage'])) ?>
                  </div>
                </td>
                <td class="text-sm"><?= e(money_short($l['estimated_value'])) ?></td>
                <td class="text-sm text-red"><?= e(time_ago($l['updated_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <?= icon('file-text') ?>
      <div>
        <div class="card__title">Awaiting an answer</div>
        <div class="card__sub">Sent, not yet accepted or rejected</div>
      </div>
    </div>
    <?php if (!$awaiting): ?>
      <div class="card__body">
        <div class="empty">
          <div class="empty__title">Nothing outstanding</div>
          <p class="empty__text">No proposals or quotations of yours are waiting on a client.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Document</th><th style="width:120px">Value</th><th style="width:110px">Expires</th></tr>
          </thead>
          <tbody>
            <?php foreach ($awaiting as $d): ?>
              <?php
              $path    = $d['doc_type'] === 'proposal' ? '/proposals/' : '/quotations/';
              $lapsing = $d['valid_until'] && strtotime($d['valid_until']) < strtotime('+7 days');
              ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url($path . $d['id']) ?>"><?= e($d['doc_number']) ?></a>
                  <div class="text-xs text-muted"><?= e($d['client_name']) ?></div>
                </td>
                <td class="text-sm"><?= e(money_short($d['total'])) ?></td>
                <td class="text-sm <?= $lapsing ? 'text-red fw-600' : 'text-muted' ?>">
                  <?= $d['valid_until'] ? e(fdate($d['valid_until'])) : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
