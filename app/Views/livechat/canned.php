<?php
/**
 * The replies the desk can drop into a conversation.
 *
 * The same six questions arrive every week. Typing the answer again
 * each time is how replies get shorter and worse — and how one agent
 * quotes a price another does not.
 *
 * Each reply is one card that expands to be edited, rather than a list
 * and a separate form: what a saved reply says is the whole of it, and
 * having to open another page to read one is why nobody checks them.
 */
require_once APP_PATH . '/Views/partials/icons.php';

/** Replies grouped under the department they belong to. */
$byDepartment = [];

foreach ($replies as $r) {
    $byDepartment[$r['department'] ?? ''][] = $r;
}
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/livechat')) ?>">Live chat</a> <span>/</span> Saved replies
    </div>
    <h1>Saved replies</h1>
    <div class="page-head__sub">
      Answers the desk can drop into a conversation and adjust before sending.
      A reply with no department is offered in every queue.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(url('/livechat/departments')) ?>">
      <?= icon('users') ?> Departments
    </a>
    <a class="btn btn--outline" href="<?= e(url('/livechat')) ?>">
      <?= icon('message') ?> Back to the desk
    </a>
  </div>
</div>

<div class="grid-2">
  <div class="hr-col">
    <?php if (!$replies): ?>
      <div class="card">
        <div class="card__body">
          <p class="text-sm text-muted">
            Nothing saved yet. The box beside this one is where the first one goes.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($byDepartment as $department => $group): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">
              <?= $department === '' ? 'Every department' : e($department) ?>
            </div>
            <div class="card__sub">
              <?= count($group) ?> <?= count($group) === 1 ? 'reply' : 'replies' ?>
            </div>
          </div>
        </div>

        <div class="card__body">
          <?php foreach ($group as $r): ?>
            <details class="canned">
              <summary class="canned__head">
                <span class="canned__title"><?= e($r['title']) ?></span>
                <?php // How often it has actually been reached for. A
                      // reply nobody uses is one that says the wrong
                      // thing, and this is the only way to spot it. ?>
                <span class="canned__uses" title="Times this has been used">
                  <?= (int) $r['uses'] ?>
                </span>
                <?= icon('chevron-down', 'canned__caret') ?>
              </summary>

              <div class="canned__body">
                <p class="canned__text"><?= nl2br(e($r['body'])) ?></p>

                <div class="text-xs text-muted mb-8">
                  <?php if ($r['editor']): ?>
                    Last changed by <?= e($r['editor']) ?>
                    on <?= e(fdate($r['updated_at'])) ?>
                  <?php elseif ($r['author']): ?>
                    Written by <?= e($r['author']) ?> on <?= e(fdate($r['created_at'])) ?>
                  <?php else: ?>
                    Came with the system
                  <?php endif; ?>
                </div>

                <form method="post" action="<?= e(url('/livechat/canned')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                  <div class="field">
                    <label class="label">Name</label>
                    <input class="input" name="title" maxlength="80" required
                           value="<?= e($r['title']) ?>">
                  </div>

                  <div class="field">
                    <label class="label">What it says</label>
                    <textarea class="input" name="body" rows="4" required
                              maxlength="4000"><?= e($r['body']) ?></textarea>
                  </div>

                  <div class="field">
                    <label class="label">Offer it in</label>
                    <select class="select" name="department_id">
                      <option value="">Every department</option>
                      <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"
                          <?= (int) $r['department_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                          <?= e($d['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <button class="btn btn--primary btn--sm">Save</button>
                </form>

                <form method="post" class="mt-8"
                      action="<?= e(url('/livechat/canned/' . $r['id'] . '/delete')) ?>"
                      onsubmit="return confirm('Remove &quot;<?= e(addslashes($r['title'])) ?>&quot;?')">
                  <?= csrf_field() ?>
                  <button class="btn btn--ghost btn--sm" style="color:var(--red-700)">Remove</button>
                </form>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php // ── A new one ─────────────────────────────────────────────── ?>
  <div class="hr-col">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Save a reply</div>
          <div class="card__sub">
            Write it the way you would say it. Whoever uses it can change the
            wording before it goes.
          </div>
        </div>
      </div>

      <div class="card__body">
        <form method="post" action="<?= e(url('/livechat/canned')) ?>">
          <?= csrf_field() ?>

          <div class="field">
            <label class="label" for="newTitle">Name <span class="req">*</span></label>
            <input class="input" id="newTitle" name="title" maxlength="80" required
                   placeholder="Turnaround">
            <span class="field-hint">What the person answering will look for in the list.</span>
          </div>

          <div class="field">
            <label class="label" for="newBody">What it says <span class="req">*</span></label>
            <textarea class="input" id="newBody" name="body" rows="5" required
                      maxlength="4000"
                      placeholder="Once the artwork is approved it is usually ready in 2 to 3 working days."></textarea>
          </div>

          <div class="field">
            <label class="label" for="newDept">Offer it in</label>
            <select class="select" id="newDept" name="department_id">
              <option value="">Every department</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">
              A price or a lead time usually belongs to one queue. A greeting does not.
            </span>
          </div>

          <button class="btn btn--primary">Save it</button>
        </form>
      </div>
    </div>
  </div>
</div>
