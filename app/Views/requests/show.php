<?php
require_once APP_PATH . '/Views/partials/icons.php';

$r      = $request;
$type   = \App\Services\JobBrief::TYPES[$r['brief_type']];
$filled = $r['submitted_at'] !== null;

$badge = [
    'draft'     => ['grey', 'Not sent yet'],
    'sent'      => ['navy',  'Waiting on the client'],
    'opened'    => ['amber', 'Client has opened it'],
    'submitted' => ['green', 'Answered'],
    'actioned'  => ['grey', 'Dealt with'],
    'cancelled' => ['red',   'Cancelled'],
][$r['status']] ?? ['grey', $r['status']];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($r['reference']) ?></h1>
    <div class="page-head__sub">
      <?= e($type) ?> brief for
      <a href="<?= url('/clients/' . $r['client_id']) ?>"><?= e($r['client_name']) ?></a>
      · raised by <?= e($r['raised_by'] ?: 'somebody since removed') ?>
      on <?= e(date('j M Y', strtotime((string) $r['created_at']))) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge badge--<?= e($badge[0]) ?>"><?= e($badge[1]) ?></span>
  </div>
</div>

<div class="grid-sidebar">
  <div>

    <?php if ($filled): ?>

      <div class="card">
        <div class="card__head">
          <?= icon('check-circle') ?>
          <div>
            <div class="card__title">What the client asked for</div>
            <div class="card__sub">
              <?php if ($r['filled_by_staff']): ?>
                Taken down by <?= e($r['filled_by_name']) ?>
              <?php else: ?>
                In the client's own words
              <?php endif; ?>
              · <?= e(date('j M Y \a\t H:i', strtotime((string) $r['submitted_at']))) ?>
            </div>
          </div>
        </div>
        <div class="card__body">
          <dl class="dl dl--stacked">
            <?php foreach ($fields as $field): ?>
              <?php $a = trim((string) ($answers[$field['key']] ?? '')); ?>
              <dt><?= e($field['label']) ?></dt>
              <dd<?= $a === '' ? ' class="text-muted"' : '' ?>>
                <?= $a === '' ? 'Not answered' : nl2br(e($a)) ?>
              </dd>
            <?php endforeach; ?>
          </dl>
        </div>
      </div>

    <?php else: ?>

      <div class="card">
        <div class="card__body text-center">
          <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('inbox') ?></div>
          <div class="card__title mt-8">Nothing back yet</div>
          <p class="text-sm text-muted">
            <?php if ($r['opened_at']): ?>
              The client opened this on <?= e(date('j M \a\t H:i', strtotime((string) $r['opened_at']))) ?>
              but has not sent it back. If it has been a while, a phone call
              usually beats another email.
            <?php elseif ($r['sent_at']): ?>
              Sent <?= e(date('j M \a\t H:i', strtotime((string) $r['sent_at']))) ?>.
              The client has not opened it yet.
            <?php else: ?>
              This has not been sent to the client. Send it, or fill it in with
              them over the phone.
            <?php endif; ?>
          </p>
          <?php if (!empty($canManage)): ?>
            <a class="btn btn--outline" href="<?= url('/requests/' . $r['id'] . '/fill') ?>">
              <?= icon('edit') ?> Fill it in with them
            </a>
          <?php endif; ?>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($files): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('paperclip') ?>
          <div>
            <div class="card__title">What they sent</div>
            <div class="card__sub"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></div>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <?php foreach ($files as $f): ?>
                <tr>
                  <td><?= e($f['original_name']) ?></td>
                  <td style="width:110px" class="text-muted"><?= e(human_bytes((int) $f['bytes'])) ?></td>
                  <td style="width:130px" class="text-right">
                    <a class="btn btn--sm btn--ghost"
                       href="<?= url('/requests/' . $r['id'] . '/files/' . $f['id']) ?>">
                      <?= icon('download') ?> Download
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($r['note'])): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('lock') ?>
          <div>
            <div class="card__title">Internal note</div>
            <div class="card__sub">Never shown to the client</div>
          </div>
        </div>
        <div class="card__body">
          <p class="text-sm mb-0" style="white-space:pre-line"><?= e($r['note']) ?></p>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <div>

    <?php if (!empty($canManage) && $r['status'] !== 'cancelled'): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('send') ?>
          <div>
            <div class="card__title">Send it to the client</div>
          </div>
        </div>
        <form method="post" action="<?= url('/requests/' . $r['id'] . '/send') ?>">
          <?= csrf_field() ?>
          <div class="card__body">
            <div class="field">
              <label class="check">
                <input type="checkbox" name="channels[]" value="email" checked
                       <?= empty($r['client_email']) ? 'disabled' : '' ?>>
                <span>
                  Email
                  <span class="field-hint">
                    <?= !empty($r['client_email']) ? e($r['client_email']) : 'No email address on file for this client' ?>
                  </span>
                </span>
              </label>
            </div>
            <div class="field">
              <label class="check">
                <input type="checkbox" name="channels[]" value="sms"
                       <?= empty($r['client_phone']) ? 'disabled' : '' ?>>
                <span>
                  Text message
                  <span class="field-hint">
                    <?= !empty($r['client_phone']) ? e($r['client_phone']) : 'No phone number on file for this client' ?>
                  </span>
                </span>
              </label>
            </div>
            <p class="text-xs text-muted mb-0">
              Both is usually right: an email goes to a spam folder unseen, a
              text gets read but is easy to lose.
            </p>
          </div>
          <div class="card__foot">
            <button class="btn btn--primary btn--block" type="submit">
              <?= $r['sent_at'] ? 'Send again' : 'Send' ?>
            </button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('external-link') ?>
        <div>
          <div class="card__title">The client's link</div>
        </div>
      </div>
      <div class="card__body">
        <pre class="code"><?= e($link) ?></pre>
        <p class="text-xs text-muted mb-0">
          Anyone holding this link can open the form — there is no password.
          Send it to the client rather than posting it anywhere public.
        </p>
      </div>
    </div>

    <?php if (!empty($canManage)): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('settings') ?>
          <div><div class="card__title">This request</div></div>
        </div>
        <div class="card__body">
          <a class="btn btn--outline btn--block" href="<?= url('/requests/' . $r['id'] . '/fill') ?>">
            <?= icon('edit') ?> <?= $filled ? 'Edit the answers' : 'Fill it in with them' ?>
          </a>

          <?php if ($filled && $r['status'] !== 'actioned'): ?>
            <form method="post" action="<?= url('/requests/' . $r['id'] . '/status') ?>" class="mt-8">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="actioned">
              <button class="btn btn--ghost btn--block" type="submit">
                <?= icon('check') ?> Mark as dealt with
              </button>
            </form>
          <?php endif; ?>

          <?php if ($r['status'] !== 'cancelled'): ?>
            <form method="post" action="<?= url('/requests/' . $r['id'] . '/status') ?>" class="mt-8"
                  data-confirm="Cancel <?= e($r['reference']) ?>? The client's link will stop working.">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="cancelled">
              <button class="btn btn--ghost btn--block text-red" type="submit">Cancel this request</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= url('/requests/' . $r['id'] . '/status') ?>" class="mt-8">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="sent">
              <button class="btn btn--ghost btn--block" type="submit">Reopen it</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('activity') ?>
        <div><div class="card__title">History</div></div>
      </div>
      <div class="card__body">
        <dl class="dl">
          <dt>Raised</dt>
          <dd><?= e(date('j M Y H:i', strtotime((string) $r['created_at']))) ?></dd>
          <dt>Sent</dt>
          <dd><?= $r['sent_at'] ? e(date('j M Y H:i', strtotime((string) $r['sent_at']))) : '—' ?></dd>
          <dt>Opened</dt>
          <dd><?= $r['opened_at'] ? e(date('j M Y H:i', strtotime((string) $r['opened_at']))) : '—' ?></dd>
          <dt>Answered</dt>
          <dd><?= $r['submitted_at'] ? e(date('j M Y H:i', strtotime((string) $r['submitted_at']))) : '—' ?></dd>
        </dl>
      </div>
    </div>

  </div>
</div>
