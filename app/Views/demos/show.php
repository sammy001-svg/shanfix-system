<?php
/**
 * One demo request, and what to do about it.
 *
 * Accepting sends somebody outside the company a working login to a
 * running system, so the form says so plainly rather than looking like
 * any other save.
 *
 * The password is not shown again after it is sent. It is kept — the
 * only reason being so it can be sent a second time when somebody loses
 * the e-mail — and printing it back onto a screen anybody with the page
 * open can read is not worth the convenience.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$sent     = $demo['status'] === 'accepted';
$declined = $demo['status'] === 'declined';
$expired  = $demo['status'] === 'expired';
$open     = $demo['status'] === 'new';
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/demos') ?>">Demo requests</a> <span>/</span>
      <?= e($demo['reference']) ?>
    </div>
    <h1><?= e($demo['name']) ?></h1>
    <div class="page-head__sub">
      Wants to see <?= e($products[$demo['product']] ?? $demo['product']) ?>
      <span aria-hidden="true">·</span> asked <?= e(fdatetime($demo['created_at'])) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge <?= $sent ? 'badge--green' : ($open ? 'badge--navy' : 'badge--grey') ?>">
      <?= e($statuses[$demo['status']] ?? $demo['status']) ?>
    </span>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <?php if ($open && $canSend): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('key') ?>
          <div>
            <div class="card__title">Send them a login</div>
            <div class="card__sub">
              These details go to <?= e($demo['email']) ?> and
              <?= e($demo['phone']) ?>, by e-mail and by text.
            </div>
          </div>
        </div>

        <form class="card__body" method="post" action="<?= e(url('/demos/' . (int) $demo['id'] . '/accept')) ?>"
              autocomplete="off">
          <?= csrf_field() ?>

          <div class="alert alert--warning mb-16">
            <?= icon('alert-triangle') ?>
            <div class="alert__body">
              Set this account up on the demo system first, with data worth
              showing and nothing real in it. What you type here is sent as
              it is — we do not create the account, we pass it on.
            </div>
          </div>

          <div class="field">
            <label class="label" for="demo_url">Where the demo is <span class="req">*</span></label>
            <input class="input" id="demo_url" name="demo_url" required maxlength="255"
                   placeholder="demo.saccopro.co.ke">
            <span class="field-hint">https:// is added if you leave it off.</span>
          </div>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="demo_username">Username <span class="req">*</span></label>
              <input class="input" id="demo_username" name="demo_username" required maxlength="160"
                     autocomplete="off">
            </div>

            <div class="field">
              <label class="label" for="demo_password">Password <span class="req">*</span></label>
              <input class="input" id="demo_password" name="demo_password" required maxlength="160"
                     autocomplete="new-password">
              <span class="field-hint">
                Stored encrypted, and not shown again. Use one made for this
                demo and nothing else.
              </span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="days">Good for</label>
            <input class="input" type="number" id="days" name="days" min="1" max="365"
                   value="<?= (int) setting('demo_default_days', 14) ?>">
            <span class="field-hint">
              Days. After this the request is marked expired here — lock the
              account out on the demo system itself as well.
            </span>
          </div>

          <button class="btn btn--primary" type="submit">
            <?= icon('send') ?> Accept and send the details
          </button>
        </form>
      </div>

      <div class="card">
        <div class="card__head"><div class="card__title">Or turn it down</div></div>
        <form class="card__body" method="post" action="<?= e(url('/demos/' . (int) $demo['id'] . '/decline')) ?>">
          <?= csrf_field() ?>
          <div class="field">
            <label class="label" for="reason">Why, for our own records</label>
            <input class="input" id="reason" name="reason" maxlength="400"
                   placeholder="e.g. a competitor, or not a real enquiry">
            <span class="field-hint">
              Nothing is sent to them. A demo turned down is worth a phone
              call, not a form letter.
            </span>
          </div>
          <button class="btn btn--outline" type="submit">Mark as declined</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($sent || $expired): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('check-circle') ?>
          <div>
            <div class="card__title">What they were sent</div>
            <div class="card__sub">
              <?= $demo['sent_at'] ? 'Sent ' . e(fdatetime($demo['sent_at'])) : 'Not sent' ?>
            </div>
          </div>
        </div>
        <div class="card__body">
          <dl class="dl">
            <dt>Demo at</dt>
            <dd>
              <a href="<?= e($demo['demo_url']) ?>" target="_blank" rel="noopener noreferrer">
                <?= e($demo['demo_url']) ?>
              </a>
            </dd>
            <dt>Username</dt><dd><code><?= e($demo['demo_username']) ?></code></dd>
            <dt>Password</dt>
            <dd class="text-muted">
              Not shown. Kept encrypted so it can be sent again.
            </dd>
            <dt>Good until</dt>
            <dd class="<?= $expired ? 'text-amber fw-600' : '' ?>">
              <?= e(fdate($demo['expires_on'])) ?>
              <?= $expired ? ' — expired' : '' ?>
            </dd>
            <?php if ($decidedBy): ?>
              <dt>Sent by</dt><dd><?= e($decidedBy) ?></dd>
            <?php endif; ?>
          </dl>

          <?php if ($canSend): ?>
            <form method="post" action="<?= e(url('/demos/' . (int) $demo['id'] . '/resend')) ?>" class="mt-12">
              <?= csrf_field() ?>
              <button class="btn btn--outline btn--sm" type="submit">
                <?= icon('send') ?> Send the same details again
              </button>
              <span class="field-hint d-block mt-4">
                For when they say the e-mail never arrived.
              </span>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($declined): ?>
      <div class="card">
        <div class="card__body">
          <div class="alert alert--info">
            <?= icon('info') ?>
            <div class="alert__body">
              <strong>Turned down<?= $demo['decided_at'] ? ' ' . e(fdate($demo['decided_at'])) : '' ?>.</strong>
              <?= $demo['decline_reason'] ? ' ' . e($demo['decline_reason']) : '' ?>
              Nothing was sent to them.
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">What they said</div></div>
      <div class="card__body">
        <?php if ($demo['message']): ?>
          <p class="mb-0" style="white-space:pre-wrap"><?= e($demo['message']) ?></p>
        <?php else: ?>
          <p class="text-sm text-muted mb-0">They left the message blank.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Who asked</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Name</dt><dd><?= e($demo['name']) ?></dd>
          <?php if ($demo['organisation']): ?>
            <dt>Sacco</dt><dd><?= e($demo['organisation']) ?></dd>
          <?php endif; ?>
          <?php if ($demo['role']): ?>
            <dt>Their role</dt><dd><?= e($demo['role']) ?></dd>
          <?php endif; ?>
          <dt>Email</dt>
          <dd><a href="mailto:<?= e($demo['email']) ?>"><?= e($demo['email']) ?></a></dd>
          <dt>Phone</dt>
          <dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $demo['phone'])) ?>"><?= e($demo['phone']) ?></a></dd>
          <?php if ($demo['members']): ?>
            <dt>Size</dt><dd><?= e($demo['members']) ?></dd>
          <?php endif; ?>
          <dt>Reference</dt><dd><code><?= e($demo['reference']) ?></code></dd>
          <?php if ($demo['source_page']): ?>
            <dt>From</dt><dd class="text-sm"><?= e($demo['source_page']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <?php if ($lead): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">In the pipeline</div></div>
        <div class="card__body">
          <p class="text-sm mb-8">
            Raised as a lead when the request came in, so it is on the sales
            board with everything else.
          </p>
          <a class="btn btn--outline btn--block btn--sm" href="<?= e(url('/leads/' . (int) $lead['id'])) ?>">
            <?= icon('target') ?> <?= e($lead['lead_number']) ?>
            <span class="badge badge--grey"><?= e(label_of($lead['stage'])) ?></span>
          </a>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
