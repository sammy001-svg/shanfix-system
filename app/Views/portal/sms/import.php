<?php
/**
 * Importing contacts from a spreadsheet.
 *
 * A page rather than a modal, because this is where people arrive with a
 * file somebody sent them and no idea what shape it should be in. The
 * right-hand side answers that before they upload anything, and the
 * browser shows what it found in their file before it leaves their
 * machine.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if ($result): ?>
    <div class="alert alert--success">
      <?= icon('check-circle') ?>
      <div class="alert__body">
        Last import: <?= number_format((int) $result['added']) ?> added<?php
          if (($result['updated'] ?? 0) > 0): ?>, <?= number_format((int) $result['updated']) ?> updated<?php endif; ?><?php
          if (($result['skipped'] ?? 0) > 0): ?>, <?= number_format((int) $result['skipped']) ?> already there<?php endif; ?><?php
          if (($result['bad'] ?? 0) > 0): ?>, <?= number_format((int) $result['bad']) ?> not valid numbers<?php endif; ?>.
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-cols">
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Upload your file</div></div>

      <form method="post" action="<?= url($base . '/contacts/import') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="im-file">The file</label>
          <input class="input" type="file" id="im-file" name="list" accept=".csv,.xlsx,.txt" required
                 data-sms-preview="#import-preview">
          <span class="field-hint">
            A CSV or an Excel .xlsx. Old .xls files are not read — open it in
            Excel and save as .xlsx or CSV first.
          </span>
        </div>

        <div id="import-preview" hidden></div>

        <div class="field">
          <label class="label" for="im-group">Put them in</label>
          <select class="select" id="im-group" name="group_id">
            <option value="">No list</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>>
                <?= e($g['name']) ?> (<?= number_format((int) $g['people']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="im-new">Or a new list called</label>
          <input class="input" id="im-new" name="new_group" maxlength="120" placeholder="e.g. Expo leads">
          <span class="field-hint">Fill this in and the list above is ignored.</span>
        </div>

        <div class="field">
          <label class="label" for="im-dupes">If a number is already in that list</label>
          <select class="select" id="im-dupes" name="duplicates">
            <option value="skip">Leave the one I have — skip it</option>
            <option value="update">Update it from the file</option>
            <option value="allow">Add it again anyway</option>
          </select>
          <span class="field-hint">
            Adding again means that person gets the message twice, so choose
            it only when you mean to.
          </span>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('download') ?> Import
        </button>
      </form>
    </div>

    <div>
      <div class="portal-card">
        <div class="portal-card__head"><div class="portal-card__title">What the file needs</div></div>
        <p class="text-sm text-muted">
          One column of phone numbers, with a heading that says so —
          <span class="code">phone</span>, <span class="code">mobile</span>
          or <span class="code">number</span>. A <span class="code">name</span>
          column is used if there is one.
        </p>
        <pre class="code" style="white-space:pre-wrap">phone,name,town
0712345678,Amina,Nairobi
254733000111,Brian,Nakuru</pre>
        <p class="text-sm text-muted">
          Every other column is kept, so you can write
          <span class="code">{town}</span> in a message and each person gets
          their own. A plain list of numbers with no headings works too.
        </p>
        <a class="btn btn--outline btn--sm btn--block" href="<?= url($base . '/contacts/template') ?>">
          <?= icon('download') ?> Download a template
        </a>
      </div>

      <div class="portal-card portal-card--quiet">
        <div class="text-sm">
          <div class="fw-600 mb-8">Numbers</div>
          <p class="text-muted mb-0">
            07…, 7…, 254… and +254… are all understood and stored the same
            way, so a list from anywhere works. Anything that is not a
            Kenyan mobile number is counted and reported back rather than
            quietly dropped.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
