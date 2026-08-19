<?php
/**
 * @var array|null $artwork
 * @var array      $clients
 * @var array      $designers
 * @var int        $clientId
 */
require_once APP_PATH . '/Views/partials/icons.php';

$a      = $artwork ?? [];
$action = $artwork ? url('/artwork/' . $artwork['id']) : url('/artwork');
$val    = static fn(string $k, string $d = '') => old($k, $a[$k] ?? $d);
$picked = (int) ($a['client_id'] ?? $clientId);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $artwork ? 'Edit ' . e($artwork['request_number']) : 'New artwork request' ?></h1>
    <div class="page-head__sub">
      <?= $artwork ? 'Design work for ' . e($artwork['client_name'] ?? '') : 'A code is allocated when you save.' ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline"
       href="<?= url($artwork ? '/artwork/' . $artwork['id'] : '/artwork') ?>">Cancel</a>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <?= icon('image') ?>
      <div>
        <div class="card__title">What the client wants</div>
        <div class="card__sub">The clearer the brief, the fewer revisions</div>
      </div>
    </div>
    <div class="card__body">
      <div class="form-grid form-grid--2">

        <div class="field">
          <label class="label" for="client_id">Client <span class="req">*</span></label>
          <select class="input <?= isset($errors['client_id']) ? 'has-error' : '' ?>"
                  id="client_id" name="client_id" required>
            <option value="">Choose…</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $picked === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?= error_for($errors ?? [], 'client_id') ?>
        </div>

        <div class="field">
          <label class="label" for="title">Title <span class="req">*</span></label>
          <input class="input <?= isset($errors['title']) ? 'has-error' : '' ?>"
                 id="title" name="title" required maxlength="200"
                 value="<?= $val('title') ?>"
                 placeholder="e.g. Company profile cover">
          <?= error_for($errors ?? [], 'title') ?>
        </div>

        <div class="field field--full">
          <label class="label" for="brief">The brief</label>
          <textarea class="textarea" id="brief" name="brief" rows="5"
                    placeholder="What they asked for, in their words where you can."><?= $val('brief') ?></textarea>
        </div>

        <div class="field field--full">
          <label class="label" for="specs">Specifications</label>
          <input class="input" id="specs" name="specs" maxlength="500"
                 value="<?= $val('specs') ?>"
                 placeholder="Size, material, colours, finish">
        </div>

        <div class="field">
          <label class="label" for="assigned_to">Designer</label>
          <select class="input" id="assigned_to" name="assigned_to">
            <option value="">— not allocated yet —</option>
            <?php foreach ($designers as $d): ?>
              <option value="<?= (int) $d['id'] ?>"
                      <?= (int) ($a['assigned_to'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>
                <?= e($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">They are notified as soon as you save.</span>
        </div>

        <div class="field">
          <label class="label" for="priority">Priority</label>
          <select class="input" id="priority" name="priority">
            <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $k => $l): ?>
              <option value="<?= e($k) ?>" <?= ($a['priority'] ?? 'normal') === $k ? 'selected' : '' ?>>
                <?= e($l) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="due_date">Needed by</label>
          <input class="input" type="date" id="due_date" name="due_date" value="<?= $val('due_date') ?>">
        </div>

      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit">
          <?= icon('save') ?> <?= $artwork ? 'Save changes' : 'Create request' ?>
        </button>
      </div>
    </div>
  </div>
</form>
