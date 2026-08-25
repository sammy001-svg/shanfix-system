<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/delivery-notes') ?>">Delivery notes</a> <span>/</span> <?= e($note['dn_number']) ?>
    </div>
    <h1>
      <?= e($note['dn_number']) ?>
      <span class="badge <?= $note['status'] === 'delivered' ? 'badge--green'
          : ($note['status'] === 'dispatched' ? 'badge--amber' : 'badge--grey') ?>"
            style="vertical-align:middle;margin-left:6px">
        <?= e(label_of($note['status'])) ?>
      </span>
    </h1>
    <div class="page-head__sub">
      <a href="<?= url('/clients/' . $note['client_id']) ?>"><?= e($note['client_name']) ?></a>
      <?php if ($note['job_number']): ?>
        · <a href="<?= url('/jobs/' . $note['job_id']) ?>"><?= e($note['job_number']) ?></a>
      <?php endif; ?>
      · <?= e(fdate($note['delivery_date'])) ?>
    </div>
  </div>

  <div class="page-head__actions">
    <a class="btn btn--primary" href="<?= url('/delivery-notes/' . $note['id'] . '/print') ?>"
       target="_blank" rel="noopener">
      <?= icon('printer') ?> Print delivery note
    </a>
    <?php if (can('delivery.manage')): ?>
      <form method="post" action="<?= url('/delivery-notes/' . $note['id'] . '/delete') ?>" style="display:inline"
            data-confirm="Delete <?= e($note['dn_number']) ?>?">
        <?= csrf_field() ?>
        <button class="btn btn--danger-soft" type="submit" aria-label="Delete this delivery note"><?= icon('trash') ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <?= icon('package') ?>
        <div>
          <div class="card__title">Goods</div>
          <div class="card__sub"><?= count($items) ?> line(s)</div>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:32px">#</th><th>Description</th><th class="num">Quantity</th></tr></thead>
          <tbody>
            <?php foreach ($items as $i => $item): ?>
              <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td><?= nl2br(e($item['description'])) ?></td>
                <td class="num fw-600">
                  <?= e(qty($item['quantity'])) ?>
                  <span class="text-xs text-muted"><?= e($item['unit']) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if (can('delivery.manage')): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Delivery details</div>
            <div class="card__sub">Fill these in before the driver leaves, then confirm on return.</div>
          </div>
        </div>
        <form method="post" action="<?= url('/delivery-notes/' . $note['id']) ?>">
          <?= csrf_field() ?>
          <div class="card__body">
            <div class="form-grid form-grid--2">
              <div class="field">
                <label class="label" for="delivery_date">Delivery date <span class="req">*</span></label>
                <input class="input" type="date" id="delivery_date" name="delivery_date" required
                       value="<?= e($note['delivery_date']) ?>">
              </div>

              <div class="field">
                <label class="label" for="status">Status</label>
                <select class="select" id="status" name="status">
                  <option value="draft"      <?= $note['status'] === 'draft'      ? 'selected' : '' ?>>Draft</option>
                  <option value="dispatched" <?= $note['status'] === 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                  <option value="delivered"  <?= $note['status'] === 'delivered'  ? 'selected' : '' ?>>Delivered</option>
                </select>
                <span class="field-hint">Marking it delivered closes the job card.</span>
              </div>

              <div class="field">
                <label class="label" for="delivered_to">Deliver to (contact)</label>
                <input class="input" id="delivered_to" name="delivered_to" maxlength="160"
                       value="<?= e($note['delivered_to']) ?>">
              </div>

              <div class="field">
                <label class="label" for="delivered_by">Our driver / rider</label>
                <input class="input" id="delivered_by" name="delivered_by" maxlength="160"
                       value="<?= e($note['delivered_by']) ?>" placeholder="Who is taking it out">
              </div>

              <div class="field">
                <label class="label" for="vehicle_reg">Vehicle registration</label>
                <input class="input" id="vehicle_reg" name="vehicle_reg" maxlength="40"
                       value="<?= e($note['vehicle_reg']) ?>" placeholder="e.g. KDA 123X">
              </div>

              <div class="field">
                <label class="label" for="received_by">Received by</label>
                <input class="input" id="received_by" name="received_by" maxlength="160"
                       value="<?= e($note['received_by']) ?>" placeholder="Name of the person who signed">
                <span class="field-hint">Required before marking it delivered.</span>
              </div>

              <div class="field field--full">
                <label class="label" for="delivery_address">Delivery address</label>
                <input class="input" id="delivery_address" name="delivery_address" maxlength="255"
                       value="<?= e($note['delivery_address']) ?>">
              </div>

              <div class="field field--full">
                <label class="label" for="notes">Notes</label>
                <textarea class="textarea" id="notes" name="notes" rows="3"
                          placeholder="Access instructions, part deliveries, anything the driver needs."><?= e($note['notes']) ?></textarea>
              </div>
            </div>

            <div class="form-actions">
              <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save delivery details</button>
            </div>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Summary</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Number</dt><dd><code><?= e($note['dn_number']) ?></code></dd>
          <dt>Client</dt>
          <dd><a href="<?= url('/clients/' . $note['client_id']) ?>"><?= e($note['client_name']) ?></a></dd>
          <?php if ($note['job_number']): ?>
            <dt>Job</dt><dd><a href="<?= url('/jobs/' . $note['job_id']) ?>"><?= e($note['job_number']) ?></a></dd>
          <?php endif; ?>
          <?php if ($note['doc_number']): ?>
            <dt>Invoice</dt>
            <dd><a href="<?= url('/invoices/' . $note['document_id']) ?>"><?= e($note['doc_number']) ?></a></dd>
          <?php endif; ?>
          <dt>Date</dt><dd><?= e(fdate($note['delivery_date'])) ?></dd>
          <dt>Status</dt>
          <dd><span class="badge <?= $note['status'] === 'delivered' ? 'badge--green'
              : ($note['status'] === 'dispatched' ? 'badge--amber' : 'badge--grey') ?>">
            <?= e(label_of($note['status'])) ?></span></dd>
          <?php if ($note['received_at']): ?>
            <dt>Received</dt><dd><?= e(fdatetime($note['received_at'])) ?></dd>
          <?php endif; ?>
          <dt>Raised by</dt><dd><?= e($note['created_by_name'] ?: 'System') ?></dd>
        </dl>
      </div>
    </div>
  </aside>
</div>
