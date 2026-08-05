<?php
require_once APP_PATH . '/Views/partials/icons.php';

$logoPath = $company['logo'] ? url('storage/' . $company['logo']) : null;
$overdue  = $job['due_date'] && strtotime($job['due_date']) < time()
    && !in_array($job['stage'], ['delivered', 'cancelled'], true);
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url('/jobs/' . $job['id']) ?>">
    <?= icon('arrow-left') ?> Back
  </a>
  <a class="btn btn--primary btn--sm" href="<?= url('/jobs/' . $job['id'] . '/print?auto=1') ?>">
    <?= icon('printer') ?> Print
  </a>
  <span class="text-sm text-muted">Print this and clip it to the job for the floor.</span>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($logoPath): ?>
        <img class="doc-head__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <div class="doc-head__tag">Production Job Card — internal</div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type">JOB CARD</div>
      <div class="doc-head__no"><?= e($job['job_number']) ?></div>
      <div class="doc-head__dates">
        <strong>Opened:</strong> <?= e(fdate($job['created_at'])) ?><br>
        <strong>Deadline:</strong>
        <span style="<?= $overdue ? 'color:var(--red-700);font-weight:700' : '' ?>">
          <?= e(fdatetime($job['due_date'])) ?>
        </span><br>
        <strong>Priority:</strong> <?= e(label_of($job['priority'])) ?>
      </div>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label">Client</div>
      <div class="doc-party__name"><?= e($job['client_name']) ?></div>
      <div class="doc-party__lines">
        <?php if ($job['client_contact']): ?>Attn: <?= e($job['client_contact']) ?><br><?php endif; ?>
        <?php if ($job['client_phone']): ?><?= e($job['client_phone']) ?><br><?php endif; ?>
        <?php if ($job['client_address']): ?><?= e($job['client_address']) ?><br><?php endif; ?>
        <?php if ($job['client_city']): ?><?= e($job['client_city']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-party">
      <div class="doc-party__label">Job</div>
      <div class="doc-party__lines" style="font-weight:600;color:var(--ink)">
        <?= e($job['title']) ?>
      </div>
      <div class="doc-party__lines" style="margin-top:8px">
        <strong>Stage:</strong> <?= e($stages[$job['stage']]['label']) ?><br>
        <strong>Assigned:</strong> <?= e($job['assignee_name'] ?: 'Unassigned') ?><br>
        <?php if ($job['doc_number']): ?>
          <strong>Reference:</strong> <?= e($job['doc_number']) ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($job['description']): ?>
    <section class="doc-section" style="margin-top:8px">
      <div class="doc-section__label">Brief</div>
      <div class="doc-section__body"><?= e($job['description']) ?></div>
    </section>
  <?php endif; ?>

  <table class="doc-table" style="margin-top:20px">
    <thead>
      <tr>
        <th class="doc-table__idx">#</th>
        <th>Item to produce</th>
        <th style="width:190px">Specs / material / finish</th>
        <th class="num" style="width:82px">Qty</th>
        <th style="width:64px">Done</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="doc-table__idx"><?= $i + 1 ?></td>
          <td><div class="doc-table__desc"><?= nl2br(e($item['description'])) ?></div></td>
          <td><?= e($item['specs'] ?: '—') ?></td>
          <td class="num">
            <?= e(qty($item['quantity'])) ?>
            <?php if ($item['unit']): ?>
              <span class="doc-table__unit"><?= e($item['unit']) ?></span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($item['is_done']): ?>
              <span style="font-weight:700;color:var(--green-700)">&#10003;</span>
            <?php else: ?>
              <span style="display:inline-block;width:15px;height:15px;border:1.5px solid var(--slate-400);border-radius:3px"></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($job['production_notes']): ?>
    <section class="doc-section">
      <div class="doc-section__label">Production notes</div>
      <div class="doc-section__body"><?= e($job['production_notes']) ?></div>
    </section>
  <?php endif; ?>

  <section class="doc-section">
    <div class="doc-section__label">Stage sign-off</div>
    <table class="doc-table" style="margin-top:6px">
      <thead>
        <tr><th>Stage</th><th style="width:150px">By</th><th style="width:120px">Date</th><th style="width:130px">Signature</th></tr>
      </thead>
      <tbody>
        <?php foreach (['artwork' => 'Artwork', 'proof_sent' => 'Proof sent', 'approved' => 'Client approved',
                        'production' => 'Printed', 'finishing' => 'Finished', 'ready' => 'Quality checked'] as $label): ?>
          <tr>
            <td style="font-weight:600"><?= e($label) ?></td>
            <td style="height:26px"></td>
            <td></td>
            <td></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <footer class="doc-foot">
    <?= e($company['name']) ?> · Job card <?= e($job['job_number']) ?> ·
    Printed <?= e(date('d M Y, H:i')) ?>
    <br>
    <span style="font-size:10.5px">Internal document — not for the client.</span>
  </footer>
</div>
