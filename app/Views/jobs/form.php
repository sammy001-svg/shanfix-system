<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$editing = $job !== null;
$action  = $editing ? url('/jobs/' . $job['id']) : url('/jobs');

$val = static function (string $key, $fallback = '') use ($job) {
    $old = Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $job[$key] ?? $fallback;
};

$defaultDue = $editing && $job['due_date']
    ? date('Y-m-d\TH:i', strtotime($job['due_date']))
    : date('Y-m-d\TH:i', strtotime("+{$leadDays} days 17:00"));

$rows = $items ?: [];
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/jobs') ?>">Production</a> <span>/</span>
      <?= $editing ? e($job['job_number']) : 'New job card' ?>
    </div>
    <h1><?= $editing ? 'Edit job card' : 'New job card' ?></h1>
    <div class="page-head__sub">
      <?php if ($document): ?>
        Raising a job from <strong><?= e($document['doc_number']) ?></strong> — items pulled across automatically.
      <?php elseif (!$editing): ?>
        Job number will be <code><?= e($nextNumber) ?></code>
      <?php else: ?>
        Stage moves are done from the job page so history stays intact.
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>
  <?php if ($document): ?>
    <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
  <?php elseif ($editing && $job['document_id']): ?>
    <input type="hidden" name="document_id" value="<?= (int) $job['document_id'] ?>">
  <?php endif; ?>

  <?php if (!empty($errors['items'])): ?>
    <div class="alert alert--error">
      <?= icon('x-circle') ?>
      <div class="alert__body"><?= e($errors['items']) ?></div>
    </div>
  <?php endif; ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">The job</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field field--full">
              <label class="label" for="title">Job title <span class="req">*</span></label>
              <input class="input <?= isset($errors['title']) ? 'has-error' : '' ?>" id="title" name="title"
                     required maxlength="200"
                     value="<?= e($val('title', $document['title'] ?? '')) ?>"
                     placeholder="e.g. Riverside Hotel — reception signage &amp; brochures">
              <?= error_for($errors ?? [], 'title') ?>
            </div>

            <div class="field">
              <label class="label" for="client_id">Client <span class="req">*</span></label>
              <select class="select" id="client_id" name="client_id" required <?= $document ? 'disabled' : '' ?>>
                <option value="">Select a client…</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                          <?= (int) $val('client_id', $document['client_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?> — <?= e($c['client_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($document): ?>
                <input type="hidden" name="client_id" value="<?= (int) $document['client_id'] ?>">
              <?php endif; ?>
              <?= error_for($errors ?? [], 'client_id') ?>
            </div>

            <div class="field">
              <label class="label" for="due_date">Deadline</label>
              <input class="input" type="datetime-local" id="due_date" name="due_date" value="<?= e($defaultDue) ?>">
              <span class="field-hint">Drives the overdue warnings on the board.</span>
              <?= error_for($errors ?? [], 'due_date') ?>
            </div>

            <div class="field field--full">
              <label class="label" for="description">Brief</label>
              <textarea class="textarea" id="description" name="description" rows="3"
                        placeholder="What the client asked for, delivery expectations, anything the floor needs to know."><?= e($val('description')) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <?= icon('list') ?>
          <div>
            <div class="card__title">What has to be produced</div>
            <div class="card__sub">The shop-floor checklist — one line per deliverable</div>
          </div>
          <div class="card__actions">
            <button class="btn btn--outline btn--sm" type="button" id="add-job-item">
              <?= icon('plus') ?> Add line
            </button>
          </div>
        </div>

        <div class="table-wrap">
          <table class="items-table" id="job-items-table">
            <thead>
              <tr>
                <th style="width:26px">#</th>
                <th>Item</th>
                <th>Specs / material / finish</th>
                <th class="col-w-qty num">Qty</th>
                <th style="width:80px">Unit</th>
                <th style="width:38px"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $item): ?>
                <tr>
                  <td class="items-table__drag" data-f="index"><?= $i + 1 ?></td>
                  <td>
                    <textarea class="textarea" data-f="description" name="items[<?= $i ?>][description]"
                              rows="2" style="min-height:38px" required><?= e($item['description']) ?></textarea>
                  </td>
                  <td>
                    <input class="input" data-f="specs" name="items[<?= $i ?>][specs]" maxlength="500"
                           value="<?= e($item['specs'] ?? '') ?>"
                           placeholder="e.g. 3mm acrylic, brushed silver">
                  </td>
                  <td class="num">
                    <input class="input num" type="number" step="0.01" min="0.01"
                           data-f="quantity" name="items[<?= $i ?>][quantity]"
                           value="<?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')) ?>" required>
                  </td>
                  <td>
                    <input class="input" data-f="unit" name="items[<?= $i ?>][unit]" maxlength="30"
                           value="<?= e($item['unit'] ?? '') ?>" placeholder="pcs">
                  </td>
                  <td>
                    <button class="items-table__del" type="button" aria-label="Remove line"><?= icon('trash') ?></button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Scheduling</div></div>
        <div class="card__body">
          <?php if (!$editing): ?>
            <div class="field mb-12">
              <label class="label" for="stage">Starting stage</label>
              <select class="select" id="stage" name="stage">
                <?php foreach ($stages as $key => $stage): ?>
                  <?php if (in_array($key, ['delivered', 'cancelled'], true)) continue; ?>
                  <option value="<?= e($key) ?>" <?= $val('stage', 'pending') === $key ? 'selected' : '' ?>>
                    <?= e($stage['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="field mb-12">
            <label class="label" for="priority">Priority</label>
            <select class="select" id="priority" name="priority">
              <?php foreach ($priorities as $p): ?>
                <option value="<?= e($p) ?>" <?= $val('priority', 'normal') === $p ? 'selected' : '' ?>>
                  <?= e(label_of($p)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">Urgent and high jobs sort to the top of the board.</span>
          </div>

          <div class="field">
            <label class="label" for="assigned_to">Assign to</label>
            <select class="select" id="assigned_to" name="assigned_to">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) $val('assigned_to') === (int) $u['id'] ? 'selected' : '' ?>>
                  <?= e($u['name']) ?> (<?= e(label_of($u['role'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><div class="card__title">Production notes</div></div>
        <div class="card__body">
          <div class="field">
            <textarea class="textarea" name="production_notes" rows="4"
                      placeholder="Machine settings, stock to use, anything internal."><?= e($val('production_notes')) ?></textarea>
            <span class="field-hint">Internal only — never printed for the client.</span>
          </div>
        </div>
      </div>

      <?php if ($document): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Source document</div></div>
          <div class="card__body">
            <dl class="dl">
              <dt>Number</dt><dd><code><?= e($document['doc_number']) ?></code></dd>
              <dt>Client</dt><dd><?= e($document['client_name']) ?></dd>
              <dt>Value</dt><dd class="fw-700"><?= e(money($document['total'])) ?></dd>
            </dl>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Open job card' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url('/jobs/' . $job['id']) : url('/jobs') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>

<template id="job-item-template">
  <tr>
    <td class="items-table__drag" data-f="index">1</td>
    <td>
      <textarea class="textarea" data-f="description" name="items[][description]"
                rows="2" style="min-height:38px" placeholder="What has to be made?" required></textarea>
    </td>
    <td>
      <input class="input" data-f="specs" name="items[][specs]" maxlength="500"
             placeholder="Size, material, finish">
    </td>
    <td class="num">
      <input class="input num" type="number" step="0.01" min="0.01"
             data-f="quantity" name="items[][quantity]" value="1" required>
    </td>
    <td>
      <input class="input" data-f="unit" name="items[][unit]" maxlength="30" placeholder="pcs">
    </td>
    <td>
      <button class="items-table__del" type="button" aria-label="Remove line"><?= icon('trash') ?></button>
    </td>
  </tr>
</template>
