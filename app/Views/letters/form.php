<?php
require_once APP_PATH . '/Views/partials/icons.php';

$editing = $letter !== null;
$action  = $editing ? url('/letters/' . $letter['id']) : url('/letters');

$defaults = $defaults ?? [];
$prefill  = $prefill  ?? [];

// What a field should show: what was just typed if the save bounced,
// then the letter being edited, then anything prefilled from a client,
// then the default for a new letter.
$val = static function (string $key, $fallback = '') use ($letter, $defaults, $prefill) {
    $old = \App\Core\Session::old($key, null);

    if ($old !== null && $old !== '') {
        return $old;
    }

    foreach ([$letter, $prefill, $defaults] as $source) {
        if (is_array($source) && isset($source[$key]) && $source[$key] !== null && $source[$key] !== '') {
            return $source[$key];
        }
    }

    return $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $editing ? 'Edit ' . e($letter['reference']) : 'New letter' ?></h1>
    <div class="page-head__sub">
      It prints on the company letterhead — logo, contact details and the
      company vision are added for you.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= $editing ? url('/letters/' . $letter['id']) : url('/letters') ?>">Cancel</a>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>

      <div class="card">
        <div class="card__head">
          <?= icon('user') ?>
          <div>
            <div class="card__title">Who it is going to</div>
            <div class="card__sub">A client, or anybody else — a bank, a supplier, a county office</div>
          </div>
        </div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="client_id">A client on file <span class="text-muted">(optional)</span></label>
            <select class="select" id="client_id" name="client_id">
              <option value="">Not a client — I will type the address</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (string) $val('client_id') === (string) $c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">
              Linking a client files the letter against them. The address below is
              still what gets printed, so it stays right even if their record changes.
            </span>
          </div>

          <div class="grid-2">
            <div class="field">
              <label class="label" for="recipient_title">Their title <span class="text-muted">(optional)</span></label>
              <input class="input" type="text" id="recipient_title" name="recipient_title"
                     value="<?= e($val('recipient_title')) ?>" maxlength="120"
                     placeholder="The Manager">
            </div>

            <div class="field">
              <label class="label" for="recipient_name">Addressed to <span class="req">*</span></label>
              <input class="input" type="text" id="recipient_name" name="recipient_name"
                     value="<?= e($val('recipient_name')) ?>" maxlength="160" required
                     placeholder="Grace Njeri">
            </div>
          </div>

          <div class="field">
            <label class="label" for="recipient_org">Organisation <span class="text-muted">(optional)</span></label>
            <input class="input" type="text" id="recipient_org" name="recipient_org"
                   value="<?= e($val('recipient_org')) ?>" maxlength="160"
                   placeholder="Riverside Hotel Ltd">
          </div>

          <div class="field">
            <label class="label" for="recipient_address">Address <span class="text-muted">(optional)</span></label>
            <textarea class="textarea" id="recipient_address" name="recipient_address" rows="3"
                      placeholder="P.O. Box 1234&#10;Nairobi"><?= e($val('recipient_address')) ?></textarea>
            <span class="field-hint">One line per line, as it should appear on the letter.</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <?= icon('file-text') ?>
          <div><div class="card__title">The letter</div></div>
        </div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="salutation">Greeting</label>
            <input class="input" type="text" id="salutation" name="salutation"
                   value="<?= e($val('salutation', 'Dear Sir/Madam')) ?>" maxlength="120">
            <span class="field-hint">
              The comma is added for you. Use their name if you know it —
              and then close with "Yours sincerely" rather than "Yours faithfully".
            </span>
          </div>

          <div class="field">
            <label class="label" for="subject">Subject <span class="req">*</span></label>
            <input class="input" type="text" id="subject" name="subject"
                   value="<?= e($val('subject')) ?>" maxlength="200" required
                   placeholder="Request for quotation — office signage">
            <span class="field-hint">Printed in bold above the first paragraph.</span>
          </div>

          <div class="field">
            <label class="label" for="body">The letter itself <span class="req">*</span></label>
            <textarea class="textarea" id="body" name="body" rows="16" required
                      placeholder="Write the letter here.&#10;&#10;Leave a blank line between paragraphs — the blank lines become the paragraph breaks on the printed page."><?= e($val('body')) ?></textarea>
            <span class="field-hint">
              A blank line starts a new paragraph. Do not type the greeting,
              the closing or your name — those are added below.
            </span>
          </div>
        </div>
      </div>

    </div>

    <aside>
      <div class="card">
        <div class="card__head">
          <?= icon('calendar') ?>
          <div><div class="card__title">Details</div></div>
        </div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="letter_date">Date <span class="req">*</span></label>
            <input class="input" type="date" id="letter_date" name="letter_date"
                   value="<?= e($val('letter_date', date('Y-m-d'))) ?>" required>
          </div>

          <div class="field">
            <label class="label" for="closing">Closing</label>
            <select class="select" id="closing" name="closing">
              <?php foreach ($closings as $c): ?>
                <option value="<?= e($c) ?>" <?= $val('closing', 'Yours faithfully') === $c ? 'selected' : '' ?>>
                  <?= e($c) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="signatory_name">Signed by <span class="req">*</span></label>
            <input class="input" type="text" id="signatory_name" name="signatory_name"
                   value="<?= e($val('signatory_name')) ?>" maxlength="120" required>
          </div>

          <div class="field">
            <label class="label" for="signatory_title">Their position</label>
            <input class="input" type="text" id="signatory_title" name="signatory_title"
                   value="<?= e($val('signatory_title')) ?>" maxlength="120"
                   placeholder="Managing Director">
          </div>

          <div class="field">
            <label class="check">
              <input type="checkbox" name="status" value="final"
                     <?= $val('status') === 'final' ? 'checked' : '' ?>>
              <span>
                <strong>This one is final</strong>
                <span class="field-hint">
                  Signed off and sent. A final letter is the record of what
                  went out, so it cannot be deleted without putting it back
                  to a draft first.
                </span>
              </span>
            </label>
          </div>
        </div>
        <div class="card__foot">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Save letter' ?>
          </button>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <p class="text-sm text-muted mb-0">
            The logo, address, phone, email and website come from
            <a href="<?= url('/settings') ?>">Settings</a>, and the vision line at
            the foot comes from there too — so correcting one of them corrects
            every letter, not only the ones written afterwards.
          </p>
        </div>
      </div>
    </aside>
  </div>
</form>
