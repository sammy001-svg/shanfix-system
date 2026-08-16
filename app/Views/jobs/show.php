<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isClosed = in_array($job['stage'], ['delivered', 'cancelled'], true);
$overdue  = $job['due_date'] && strtotime($job['due_date']) < time() && !$isClosed;

$itemCount = count($items);
$itemDone  = count(array_filter($items, static fn($i) => (int) $i['is_done'] === 1));
$progress  = $itemCount > 0 ? ($itemDone / $itemCount) * 100 : 0;

$priorityBadge = static fn(string $p): string => match ($p) {
    'urgent' => 'badge--red',
    'high'   => 'badge--amber',
    'low'    => 'badge--grey',
    default  => 'badge--navy',
};

$byType = ['proof' => [], 'artwork' => [], 'reference' => [], 'final' => []];
foreach ($files as $f) {
    $byType[$f['file_type']][] = $f;
}

$pendingProof = array_values(array_filter($byType['proof'], static fn($f) => $f['status'] === 'pending'));
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/jobs') ?>">Production</a> <span>/</span> <?= e($job['job_number']) ?>
    </div>
    <h1>
      <?= e($job['title']) ?>
      <span class="badge <?= $job['stage'] === 'delivered' ? 'badge--green'
          : ($job['stage'] === 'cancelled' ? 'badge--red'
          : ($job['stage'] === 'on_hold' ? 'badge--amber' : 'badge--navy')) ?>"
            style="vertical-align:middle;margin-left:6px">
        <?= e($stages[$job['stage']]['label']) ?>
      </span>
      <?php if ($job['priority'] !== 'normal'): ?>
        <span class="badge <?= $priorityBadge($job['priority']) ?>" style="vertical-align:middle">
          <?= e(label_of($job['priority'])) ?>
        </span>
      <?php endif; ?>
    </h1>
    <div class="page-head__sub">
      <code><?= e($job['job_number']) ?></code> ·
      <a href="<?= url('/clients/' . $job['client_id']) ?>"><?= e($job['client_name']) ?></a>
      <?php if ($job['doc_number']): ?>
        · <a href="<?= url(($job['doc_type'] === 'invoice' ? '/invoices/' : '/quotations/') . $job['document_id']) ?>">
            <?= e($job['doc_number']) ?>
          </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/jobs/' . $job['id'] . '/print') ?>" target="_blank" rel="noopener">
      <?= icon('printer') ?> Job card
    </a>

    <?php if (can('jobs.manage') && $job['stage'] === 'ready' && ($messagingOn ?? false)): ?>
      <form method="post" action="<?= url('/jobs/' . $job['id'] . '/notify-ready') ?>" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn--navy" type="submit"
                <?= ($job['client_email'] || $job['client_phone']) ? '' : 'disabled' ?>>
          <?= icon('send') ?> Tell client it's ready
        </button>
      </form>
    <?php endif; ?>

    <?php if (can('delivery.manage') && in_array($job['stage'], ['ready', 'finishing', 'production'], true)): ?>
      <form method="post" action="<?= url('/jobs/' . $job['id'] . '/delivery-note') ?>" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn--primary" type="submit"><?= icon('archive') ?> Raise delivery note</button>
      </form>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn--outline" type="button" data-dropdown><?= icon('more') ?></button>
      <div class="dropdown__menu">
        <?php if (can('jobs.manage')): ?>
          <a class="dropdown__item" href="<?= url('/jobs/' . $job['id'] . '/edit') ?>"><?= icon('edit') ?> Edit job card</a>
        <?php endif; ?>
        <?php if ($job['client_phone']): ?>
          <a class="dropdown__item" href="tel:<?= e($job['client_phone']) ?>"><?= icon('phone') ?> Call client</a>
        <?php endif; ?>
        <?php if (can('jobs.delete')): ?>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/jobs/' . $job['id'] . '/delete') ?>"
                data-confirm="Delete <?= e($job['job_number']) ?>? Jobs with production history are cancelled instead.">
            <?= csrf_field() ?>
            <button class="dropdown__item dropdown__item--danger" type="submit"><?= icon('trash') ?> Delete job</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($overdue): ?>
  <div class="alert alert--error">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>Past deadline.</strong> This job was due <?= e(fdatetime($job['due_date'])) ?>
      (<?= e(time_ago($job['due_date'])) ?>).
    </div>
  </div>
<?php endif; ?>

<?php if ($job['stage'] === 'on_hold' && $job['hold_reason']): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body"><strong>On hold:</strong> <?= e($job['hold_reason']) ?></div>
  </div>
<?php endif; ?>

<?php if ($pendingProof): ?>
  <div class="alert alert--warning">
    <?= icon('clock') ?>
    <div class="alert__body">
      <strong><?= count($pendingProof) ?> proof(s) awaiting the client's decision.</strong>
      Record their answer below before the job goes to print.
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <!-- Production checklist -->
    <div class="card">
      <div class="card__head">
        <?= icon('list') ?>
        <div>
          <div class="card__title">What to produce</div>
          <div class="card__sub"><?= $itemDone ?> of <?= $itemCount ?> done</div>
        </div>
        <div class="card__actions" style="min-width:130px">
          <div class="progress" style="width:110px">
            <div class="progress__bar" style="width:<?= number_format($progress, 2) ?>%"></div>
          </div>
        </div>
      </div>

      <?php if (!$items): ?>
        <div class="card__body"><p class="text-sm text-muted mb-0">Nothing listed on this job card.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr><th style="width:44px"></th><th>Item</th><th>Specs</th><th class="num">Qty</th><th>Done</th></tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td>
                    <?php if (can('jobs.manage') && !$isClosed): ?>
                      <form method="post" action="<?= url('/jobs/' . $job['id'] . '/items/' . $item['id'] . '/toggle') ?>"
                            data-offline data-offline-label="Checklist tick">
                        <?= csrf_field() ?>
                        <button class="btn <?= $item['is_done'] ? 'btn--primary' : 'btn--outline' ?> btn--sm btn--icon"
                                type="submit" title="<?= $item['is_done'] ? 'Mark not done' : 'Mark done' ?>">
                          <?= icon('check') ?>
                        </button>
                      </form>
                    <?php elseif ($item['is_done']): ?>
                      <span class="text-green"><?= icon('check-circle') ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="<?= $item['is_done'] ? 'text-muted' : '' ?>"
                      style="<?= $item['is_done'] ? 'text-decoration:line-through' : '' ?>">
                    <?= e($item['description']) ?>
                  </td>
                  <td class="text-sm text-muted"><?= e($item['specs'] ?: '—') ?></td>
                  <td class="num fw-600"><?= e(qty($item['quantity'])) ?>
                    <span class="text-xs text-muted"><?= e($item['unit']) ?></span></td>
                  <td class="text-xs text-muted">
                    <?= $item['is_done'] ? e($item['done_by_name'] ?: '') . '<br>' . e(fdate($item['done_at'], 'd M H:i')) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Artwork & proofs -->
    <div class="card">
      <div class="card__head">
        <?= icon('paperclip') ?>
        <div>
          <div class="card__title">Artwork &amp; proofs</div>
          <div class="card__sub">Client approval is recorded here before printing</div>
        </div>
        <?php if (can('jobs.manage') && !$isClosed): ?>
          <div class="card__actions">
            <button class="btn btn--primary btn--sm" type="button" data-modal-open="upload-modal">
              <?= icon('plus') ?> Upload
            </button>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$files): ?>
        <div class="empty" style="padding:32px 20px">
          <div class="empty__icon"><?= icon('paperclip') ?></div>
          <div class="empty__title">No files yet</div>
          <p class="empty__text">
            Upload the client's artwork, then send a proof and record whether they approved it.
          </p>
        </div>
      <?php else: ?>
        <div class="card__body">
          <?php foreach (['proof' => 'Proofs', 'artwork' => 'Artwork', 'reference' => 'Reference', 'final' => 'Final files'] as $type => $heading): ?>
            <?php if (!$byType[$type]) continue; ?>
            <div class="text-xs uppercase fw-700 text-muted mb-8"><?= e($heading) ?></div>

            <?php foreach ($byType[$type] as $f): ?>
              <div class="card" style="margin-bottom:10px;box-shadow:none">
                <div class="card__body" style="padding:12px 14px">
                  <div class="flex items-center gap-12 flex-wrap">
                    <span class="conv__hash" style="background:var(--navy-100);color:var(--navy-800)">
                      <?= icon('paperclip') ?>
                    </span>

                    <div class="flex-1" style="min-width:180px">
                      <a class="fw-600" href="<?= url('files/' . $f['file_path']) ?>" target="_blank" rel="noopener">
                        <?= e($f['file_name']) ?>
                      </a>
                      <div class="text-xs text-muted">
                        v<?= (int) $f['version'] ?>
                        · <?= e(number_format($f['file_size'] / 1024, 0)) ?> KB
                        · <?= e($f['uploaded_by_name'] ?: 'System') ?>
                        · <?= e(time_ago($f['created_at'])) ?>
                      </div>
                      <?php if ($f['notes']): ?>
                        <div class="text-sm text-muted mt-4"><?= e($f['notes']) ?></div>
                      <?php endif; ?>
                      <?php if ($f['client_feedback']): ?>
                        <div class="alert alert--warning mt-8 mb-0" style="padding:8px 11px">
                          <?= icon('message') ?>
                          <div class="alert__body text-sm">
                            <strong>Client said:</strong> <?= e($f['client_feedback']) ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="text-right">
                      <span class="badge <?= status_badge($f['status']) ?>"><?= e(label_of($f['status'])) ?></span>
                      <?php if ($f['approved_at']): ?>
                        <div class="text-xs text-muted mt-4">
                          <?= ($f['decided_via'] ?? '') === 'client'
                                ? 'by the client online'
                                : e($f['approved_by_name'] ?: '') ?><br>
                          <?= e(fdate($f['approved_at'], 'd M H:i')) ?>
                        </div>
                      <?php elseif ($f['file_type'] === 'proof' && !empty($f['viewed_at'])): ?>
                        <div class="text-xs text-muted mt-4">
                          Opened <?= e(time_ago($f['viewed_at'])) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ($f['file_type'] === 'proof' && $f['status'] === 'pending' && can('jobs.manage') && !$isClosed): ?>
                    <?php if (!empty($f['public_token'])): ?>
                      <?php $proofLink = \App\Services\Notifier::absoluteUrl('/proof/' . $f['public_token']); ?>
                      <div class="mt-12" style="border-top:1px solid var(--border);padding-top:12px">
                        <div class="text-xs uppercase fw-700 text-muted mb-4">Client approval link</div>
                        <div class="flex gap-8 items-center flex-wrap">
                          <input class="input flex-1" style="min-width:220px;font-size:12.5px"
                                 value="<?= e($proofLink) ?>" readonly
                                 onfocus="this.select()">
                          <a class="btn btn--outline btn--sm" href="<?= e($proofLink) ?>"
                             target="_blank" rel="noopener">Preview</a>
                        </div>
                        <span class="field-hint">
                          Sent with the proof notification. Anyone with this link can approve — share it only with the client.
                        </span>
                      </div>
                    <?php endif; ?>

                    <div class="flex gap-8 mt-12 flex-wrap" style="border-top:1px solid var(--border);padding-top:12px">
                      <div class="text-xs text-muted" style="width:100%">
                        Record the decision here if the client tells you by phone or in person:
                      </div>
                      <form method="post" action="<?= url('/jobs/files/' . $f['id'] . '/decide') ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="approved">
                        <button class="btn btn--primary btn--sm" type="submit">
                          <?= icon('check') ?> Client approved
                        </button>
                      </form>

                      <button class="btn btn--outline btn--sm" type="button"
                              data-modal-open="reject-<?= (int) $f['id'] ?>">
                        <?= icon('x') ?> Client wants changes
                      </button>
                    </div>
                  <?php endif; ?>

                  <?php
                    // Approved proofs are the sign-off record — only admins/managers may remove them.
                    $canRemove = can('jobs.manage') && !$isClosed
                        && !($f['file_type'] === 'proof' && $f['status'] === 'approved' && !\App\Core\Auth::is('admin', 'manager'));
                  ?>
                  <?php if ($canRemove): ?>
                    <div class="text-right mt-8">
                      <form method="post" action="<?= url('/jobs/files/' . $f['id'] . '/delete') ?>"
                            data-confirm="Remove <?= e($f['file_name']) ?>?">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit">
                          <?= icon('trash') ?> Remove
                        </button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($f['file_type'] === 'proof' && $f['status'] === 'pending' && can('jobs.manage')): ?>
                <div class="modal-backdrop" id="reject-<?= (int) $f['id'] ?>">
                  <div class="modal modal--sm">
                    <form method="post" action="<?= url('/jobs/files/' . $f['id'] . '/decide') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="decision" value="rejected">
                      <div class="modal__head">
                        <div class="modal__title">What does the client want changed?</div>
                        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
                      </div>
                      <div class="modal__body">
                        <div class="field">
                          <label class="label" for="fb-<?= (int) $f['id'] ?>">Client feedback <span class="req">*</span></label>
                          <textarea class="textarea" id="fb-<?= (int) $f['id'] ?>" name="client_feedback"
                                    rows="4" required
                                    placeholder="e.g. Logo too small, change the green to match the brand guide"></textarea>
                          <span class="field-hint">The job goes back to Artwork with this note attached.</span>
                        </div>
                      </div>
                      <div class="modal__foot">
                        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
                        <button class="btn btn--navy" type="submit"><?= icon('repeat') ?> Send back to artwork</button>
                      </div>
                    </form>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- History -->
    <div class="card">
      <div class="card__head">
        <?= icon('activity') ?>
        <div>
          <div class="card__title">Job history</div>
          <div class="card__sub">Every stage move, note and file, in order</div>
        </div>
        <?php if (can('jobs.manage') && !$isClosed): ?>
          <div class="card__actions">
            <button class="btn btn--ghost btn--sm" type="button" data-modal-open="note-modal">
              <?= icon('plus') ?> Add note
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="card__body">
        <div class="timeline">
          <?php foreach ($history as $h):
              $moved = $h['from_stage'] !== null && $h['from_stage'] !== $h['to_stage'];
          ?>
            <div class="timeline__item">
              <span class="timeline__dot <?= $moved ? '' : 'timeline__dot--grey' ?>">
                <?= icon($moved ? ($stages[$h['to_stage']]['icon'] ?? 'check') : 'edit') ?>
              </span>
              <div class="timeline__head">
                <span class="timeline__title">
                  <?php if ($moved): ?>
                    <?= e($stages[$h['from_stage']]['label'] ?? label_of($h['from_stage'])) ?>
                    &rarr;
                    <?= e($stages[$h['to_stage']]['label'] ?? label_of($h['to_stage'])) ?>
                  <?php else: ?>
                    Note
                  <?php endif; ?>
                </span>
                <span class="timeline__time"><?= e(fdatetime($h['created_at'])) ?></span>
              </div>
              <?php if ($h['notes']): ?>
                <div class="timeline__body"><?= nl2br(e($h['notes'])) ?></div>
              <?php endif; ?>
              <div class="text-xs text-muted mt-4">by <?= e($h['user_name'] ?: 'System') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <aside>
    <!-- Stage control -->
    <?php if (can('jobs.manage') && !$isClosed): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Move the job on</div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/jobs/' . $job['id'] . '/stage') ?>"
                data-offline data-offline-label="Stage move">
            <?= csrf_field() ?>

            <div class="field mb-12">
              <label class="label" for="stage">Stage</label>
              <select class="select" id="stage" name="stage" required>
                <?php foreach ($stages as $key => $stage): ?>
                  <option value="<?= e($key) ?>" <?= $job['stage'] === $key ? 'selected' : '' ?>>
                    <?= e($stage['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field mb-12">
              <label class="label" for="stage_note">Note</label>
              <input class="input" id="stage_note" name="stage_note" placeholder="Optional">
            </div>

            <div class="field mb-12">
              <label class="label" for="hold_reason">Reason, if putting on hold</label>
              <input class="input" id="hold_reason" name="hold_reason"
                     placeholder="e.g. Waiting for material delivery">
            </div>

            <?php if ($pendingProof): ?>
              <label class="check mb-12">
                <input type="checkbox" name="override_proof" value="1">
                <span class="check__text">
                  <strong>Proceed without a recorded proof approval</strong>
                  <span>Only tick this if the client approved verbally or in writing elsewhere.</span>
                </span>
              </label>
            <?php endif; ?>

            <button class="btn btn--navy btn--block" type="submit"><?= icon('arrow-right') ?> Update stage</button>
          </form>
        </div>
      </div>

      <?php if (can('jobs.assign')): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Assign</div></div>
          <div class="card__body">
            <form method="post" action="<?= url('/jobs/' . $job['id'] . '/assign') ?>">
              <?= csrf_field() ?>
              <div class="field mb-12">
                <select class="select" name="assigned_to">
                  <option value="">— Unassigned —</option>
                  <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $job['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>>
                      <?= e($u['name']) ?> (<?= e(label_of($u['role'])) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn--outline btn--block" type="submit"><?= icon('user') ?> Save assignment</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">Job details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Job no.</dt><dd><code><?= e($job['job_number']) ?></code></dd>
          <dt>Client</dt>
          <dd><a href="<?= url('/clients/' . $job['client_id']) ?>"><?= e($job['client_name']) ?></a></dd>
          <?php if ($job['client_contact']): ?>
            <dt>Contact</dt><dd><?= e($job['client_contact']) ?></dd>
          <?php endif; ?>
          <?php if ($job['client_phone']): ?>
            <dt>Phone</dt><dd><a href="tel:<?= e($job['client_phone']) ?>"><?= e($job['client_phone']) ?></a></dd>
          <?php endif; ?>
          <dt>Deadline</dt>
          <dd class="<?= $overdue ? 'text-red fw-700' : '' ?>"><?= e(fdatetime($job['due_date'])) ?></dd>
          <dt>Priority</dt>
          <dd><span class="badge <?= $priorityBadge($job['priority']) ?>"><?= e(label_of($job['priority'])) ?></span></dd>
          <dt>Assigned</dt><dd><?= e($job['assignee_name'] ?: 'Unassigned') ?></dd>
          <?php if ($job['started_at']): ?>
            <dt>Started</dt><dd><?= e(fdatetime($job['started_at'])) ?></dd>
          <?php endif; ?>
          <?php if ($job['completed_at']): ?>
            <dt>Completed</dt><dd><?= e(fdatetime($job['completed_at'])) ?></dd>
          <?php endif; ?>
          <?php if ($job['delivered_at']): ?>
            <dt>Delivered</dt><dd><?= e(fdatetime($job['delivered_at'])) ?></dd>
          <?php endif; ?>
          <dt>Opened</dt>
          <dd><?= e(fdate($job['created_at'])) ?> by <?= e($job['created_by_name'] ?: 'System') ?></dd>
        </dl>

        <?php if ($job['description']): ?>
          <hr>
          <div class="text-xs uppercase fw-700 text-muted mb-4">Brief</div>
          <p class="text-sm mb-0" style="white-space:pre-line"><?= e($job['description']) ?></p>
        <?php endif; ?>

        <?php if ($job['production_notes']): ?>
          <hr>
          <div class="text-xs uppercase fw-700 text-muted mb-4">Production notes (internal)</div>
          <p class="text-sm mb-0" style="white-space:pre-line"><?= e($job['production_notes']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($job['doc_number']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Billing</div></div>
        <div class="card__body">
          <dl class="dl">
            <dt>Document</dt>
            <dd>
              <a href="<?= url(($job['doc_type'] === 'invoice' ? '/invoices/' : '/quotations/') . $job['document_id']) ?>">
                <?= e($job['doc_number']) ?>
              </a>
            </dd>
            <dt>Value</dt><dd class="fw-700"><?= e(money($job['doc_total'])) ?></dd>
            <?php if ($job['doc_type'] === 'invoice'): ?>
              <dt>Status</dt>
              <dd><span class="badge <?= status_badge($job['invoice_status']) ?>">
                <?= e(label_of($job['invoice_status'])) ?></span></dd>
              <dt>Balance</dt>
              <dd class="<?= (float) $job['invoice_balance'] > 0 ? 'text-red fw-700' : 'text-green fw-600' ?>">
                <?= (float) $job['invoice_balance'] > 0 ? e(money($job['invoice_balance'])) : 'Settled' ?>
              </dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($costing !== null): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('dollar') ?>
          <div>
            <div class="card__title">Job costing</div>
            <div class="card__sub">Excluding VAT on both sides</div>
          </div>
        </div>
        <div class="card__body">
          <dl class="dl">
            <dt>Job value</dt><dd class="fw-600"><?= e(money($costing['revenue'])) ?></dd>
            <dt>Costs</dt><dd class="text-red fw-600"><?= e(money($costing['spent'])) ?></dd>
            <dt>Margin</dt>
            <dd class="fw-700 <?= $costing['margin'] >= 0 ? 'text-green' : 'text-red' ?>">
              <?= e(money($costing['margin'])) ?>
              <span class="text-xs text-muted">(<?= number_format($costing['margin_pct'], 1) ?>%)</span>
            </dd>
          </dl>

          <p class="field-hint mt-8">
            VAT charged to the client is owed to KRA and VAT paid on materials is
            reclaimable, so neither counts towards the profit on this job.
          </p>

          <?php if ($costing['expenses']): ?>
            <hr>
            <div class="text-xs uppercase fw-700 text-muted mb-8">
              Costs booked
              <span class="text-muted" style="font-weight:400;text-transform:none">
                — <?= e(money($costing['gross_spent'], false)) ?> paid,
                <?= e(money($costing['input_vat'], false)) ?> reclaimable VAT
              </span>
            </div>
            <?php foreach ($costing['expenses'] as $ex): ?>
              <div class="flex justify-between text-sm mb-4">
                <span class="truncate" style="max-width:160px"><?= e($ex['description']) ?></span>
                <span class="fw-600 nums"><?= e(money((float) $ex['amount'] - (float) $ex['vat_amount'], false)) ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if (can('expenses.manage')): ?>
            <a class="btn btn--outline btn--block mt-12"
               href="<?= url('/expenses/create?job_id=' . $job['id'] . '&client_id=' . $job['client_id']) ?>">
              <?= icon('plus') ?> Book a cost
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($deliveryNotes): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Delivery notes</div></div>
        <div class="card__body--flush">
          <?php foreach ($deliveryNotes as $dn): ?>
            <a class="conv" href="<?= url('/delivery-notes/' . $dn['id']) ?>">
              <span class="conv__hash"><?= icon('archive') ?></span>
              <span class="conv__meta">
                <span class="conv__name"><?= e($dn['dn_number']) ?></span>
                <span class="conv__preview">
                  <?= e(fdate($dn['delivery_date'])) ?>
                  <?= $dn['received_by'] ? ' · ' . e($dn['received_by']) : '' ?>
                </span>
              </span>
              <span class="conv__right">
                <span class="badge <?= $dn['status'] === 'delivered' ? 'badge--green'
                    : ($dn['status'] === 'dispatched' ? 'badge--amber' : 'badge--grey') ?>">
                  <?= e(label_of($dn['status'])) ?>
                </span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>

<?php if (can('jobs.manage') && !$isClosed): ?>

<div class="modal-backdrop" id="upload-modal">
  <div class="modal">
    <form method="post" action="<?= url('/jobs/' . $job['id'] . '/files') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Upload a file</div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>
      <div class="modal__body">
        <div class="field mb-16">
          <label class="label">What is it?</label>
          <div class="radio-cards">
            <label class="radio-card">
              <input type="radio" name="file_type" value="artwork" checked>
              <span class="radio-card__title">Artwork</span>
              <span class="radio-card__desc">Files from the client</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="file_type" value="proof">
              <span class="radio-card__title">Proof</span>
              <span class="radio-card__desc">Needs client sign-off</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="file_type" value="reference">
              <span class="radio-card__title">Reference</span>
              <span class="radio-card__desc">Brand guides, samples</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="file_type" value="final">
              <span class="radio-card__title">Final</span>
              <span class="radio-card__desc">Print-ready output</span>
            </label>
          </div>
        </div>

        <div class="field mb-12">
          <label class="label" for="job-file">File <span class="req">*</span></label>
          <input class="input" type="file" id="job-file" name="file" required>
          <span class="field-hint">
            Up to <?= (int) config('uploads.max_size_mb', 8) ?>MB —
            <?= e(implode(', ', config('uploads.allowed_types', []))) ?>
          </span>
        </div>

        <div class="field">
          <label class="label" for="file-notes">Notes</label>
          <input class="input" id="file-notes" name="notes" maxlength="500"
                 placeholder="e.g. Second revision, logo enlarged">
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('paperclip') ?> Upload</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="note-modal">
  <div class="modal modal--sm">
    <form method="post" action="<?= url('/jobs/' . $job['id'] . '/note') ?>"
          data-offline data-offline-label="Job note">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Add a note</div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>
      <div class="modal__body">
        <div class="field">
          <label class="label" for="job-note">Note</label>
          <textarea class="textarea" id="job-note" name="notes" rows="4" required
                    placeholder="e.g. Ran out of white vinyl, using the alternative stock"></textarea>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('save') ?> Add note</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
