<?php
require_once APP_PATH . '/Views/partials/icons.php';

$logoPath = $company['logo'] ? url('storage/' . $company['logo']) : null;
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url('/delivery-notes/' . $note['id']) ?>">
    <?= icon('arrow-left') ?> Back
  </a>
  <a class="btn btn--primary btn--sm" href="<?= url('/delivery-notes/' . $note['id'] . '/print?auto=1') ?>">
    <?= icon('printer') ?> Print
  </a>
  <span class="text-sm text-muted">Print two copies — one for the client, one signed back to you.</span>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($logoPath): ?>
        <img class="doc-head__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <?php if ($company['tagline']): ?>
        <div class="doc-head__tag"><?= e($company['tagline']) ?></div>
      <?php endif; ?>
      <div class="doc-head__lines">
        <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
        <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
        <?php if ($company['email']): ?><?= e($company['email']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type">DELIVERY<br>NOTE</div>
      <div class="doc-head__no"><?= e($note['dn_number']) ?></div>
      <div class="doc-head__dates">
        <strong>Date:</strong> <?= e(fdate($note['delivery_date'])) ?><br>
        <?php if ($note['job_number']): ?>
          <strong>Job:</strong> <?= e($note['job_number']) ?><br>
        <?php endif; ?>
        <?php if ($note['doc_number']): ?>
          <strong>Invoice:</strong> <?= e($note['doc_number']) ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label">Deliver to</div>
      <div class="doc-party__name"><?= e($note['client_name']) ?></div>
      <div class="doc-party__lines">
        <?php if ($note['delivered_to']): ?>Attn: <?= e($note['delivered_to']) ?><br><?php endif; ?>
        <?php if ($note['delivery_address']): ?><?= e($note['delivery_address']) ?><br><?php endif; ?>
        <?php if ($note['client_phone']): ?><?= e($note['client_phone']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-party">
      <div class="doc-party__label">Delivered by</div>
      <div class="doc-party__lines">
        <?php if ($note['delivered_by']): ?>
          <strong style="color:var(--ink)"><?= e($note['delivered_by']) ?></strong><br>
        <?php endif; ?>
        <?php if ($note['vehicle_reg']): ?>Vehicle: <?= e($note['vehicle_reg']) ?><br><?php endif; ?>
        <?php if ($note['job_title']): ?>Job: <?= e($note['job_title']) ?><?php endif; ?>
      </div>
    </div>
  </section>

  <table class="doc-table">
    <thead>
      <tr>
        <th class="doc-table__idx">#</th>
        <th>Description of goods</th>
        <th class="num" style="width:110px">Quantity</th>
        <th style="width:110px">Condition</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="doc-table__idx"><?= $i + 1 ?></td>
          <td><div class="doc-table__desc"><?= nl2br(e($item['description'])) ?></div></td>
          <td class="num">
            <?= e(qty($item['quantity'])) ?>
            <?php if ($item['unit']): ?>
              <span class="doc-table__unit"><?= e($item['unit']) ?></span>
            <?php endif; ?>
          </td>
          <td></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($note['notes']): ?>
    <section class="doc-section">
      <div class="doc-section__label">Notes</div>
      <div class="doc-section__body"><?= e($note['notes']) ?></div>
    </section>
  <?php endif; ?>

  <section class="doc-section">
    <div class="doc-section__body" style="font-size:11.5px">
      Goods received in good order and condition. Please check the quantities above
      before signing. Any damage or shortage must be reported to
      <?= e($company['name']) ?> within 24 hours of delivery.
    </div>
  </section>

  <div class="doc-sign">
    <div class="doc-sign__box">
      <div class="doc-sign__line">
        <?php if ($note['delivered_by']): ?>
          <span style="font-size:12px"><?= e($note['delivered_by']) ?></span>
        <?php endif; ?>
      </div>
      <div class="doc-sign__label">Delivered by &amp; date</div>
    </div>

    <div class="doc-sign__box">
      <div class="doc-sign__line">
        <?php if ($note['received_by']): ?>
          <span style="font-size:12px"><?= e($note['received_by']) ?></span>
        <?php endif; ?>
      </div>
      <div class="doc-sign__label">
        Received by — name, signature &amp; date
        <?php if ($note['received_at']): ?>
          <br><?= e(fdatetime($note['received_at'])) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <footer class="doc-foot">
    <strong>Thank you for your business.</strong><br>
    <?= e($company['name']) ?><?= $company['phone'] ? ' · ' . e($company['phone']) : '' ?>
    <br>
    <span style="font-size:10.5px">
      <?= e($note['dn_number']) ?> · Printed <?= e(date('d M Y, H:i')) ?>
    </span>
  </footer>
</div>
