<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isClosed  = in_array($lead['stage'], ['won', 'lost'], true);
$converted = (bool) $lead['converted_client_id'];

$activityIcon = static fn(string $type): string => match ($type) {
    'call'           => 'phone',
    'email'          => 'mail',
    'whatsapp'       => 'message',
    'meeting'        => 'users',
    'site_visit'     => 'map-pin',
    'quotation_sent' => 'file-text',
    'stage_change'   => 'trending-up',
    default          => 'edit',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/leads') ?>">Leads</a> <span>/</span> <?= e($lead['lead_number']) ?>
    </div>
    <h1>
      <?= e($lead['name']) ?>
      <span class="badge <?= status_badge($lead['stage']) ?>" style="vertical-align:middle;margin-left:6px">
        <?= e($stages[$lead['stage']]['label'] ?? label_of($lead['stage'])) ?>
      </span>
    </h1>
    <div class="page-head__sub">
      <?= e($lead['company'] ?: 'Individual') ?>
      · <?= e(label_of($lead['source'])) ?>
      <?= $lead['service_name'] ? ' · ' . e($lead['service_name']) : '' ?>
    </div>
  </div>

  <div class="page-head__actions">
    <?php // A lead becomes a priced document without anyone retyping it. ?>
    <?php if (can('documents.manage') && $lead['stage'] !== 'lost'): ?>
      <form method="post" action="<?= url('/leads/' . $lead['id'] . '/document') ?>"
            style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="quotation">
        <button class="btn btn--outline" type="submit">
          <?= icon('file-text') ?> Raise quotation
        </button>
      </form>
      <form method="post" action="<?= url('/leads/' . $lead['id'] . '/document') ?>"
            style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="proposal">
        <button class="btn btn--outline" type="submit">
          <?= icon('briefcase') ?> Raise proposal
        </button>
      </form>
    <?php endif; ?>

    <?php if (can('leads.manage') && !$isClosed): ?>
      <button class="btn btn--primary" type="button" data-modal-open="activity-modal">
        <?= icon('plus') ?> Log activity
      </button>
      <button class="btn btn--outline" type="button" data-modal-open="reminder-modal">
        <?= icon('bell') ?> Set reminder
      </button>
    <?php endif; ?>

    <?php if ($converted): ?>
      <a class="btn btn--navy" href="<?= url('/clients/' . $lead['converted_client_id']) ?>">
        <?= icon('users') ?> Open client
      </a>
    <?php elseif (can('clients.manage')): ?>
      <form method="post" action="<?= url('/leads/' . $lead['id'] . '/convert') ?>" style="display:inline"
            data-confirm="Convert <?= e($lead['name']) ?> into a client? This closes the deal as won.">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm_duplicate" value="0">
        <button class="btn btn--primary" type="submit"><?= icon('user-plus') ?> Convert to client</button>
      </form>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn--outline" type="button" data-dropdown aria-label="More actions for this lead"><?= icon('more') ?></button>
      <div class="dropdown__menu">
        <?php if (can('leads.manage')): ?>
          <a class="dropdown__item" href="<?= url('/leads/' . $lead['id'] . '/edit') ?>"><?= icon('edit') ?> Edit lead</a>
        <?php endif; ?>
        <?php if ($lead['phone']): ?>
          <a class="dropdown__item" href="tel:<?= e($lead['phone']) ?>"><?= icon('phone') ?> Call</a>
          <a class="dropdown__item" href="https://wa.me/<?= e(normalize_phone($lead['phone']) ?? '') ?>"
             target="_blank" rel="noopener"><?= icon('message') ?> WhatsApp</a>
        <?php endif; ?>
        <?php if ($lead['email']): ?>
          <a class="dropdown__item" href="mailto:<?= e($lead['email']) ?>"><?= icon('mail') ?> Email</a>
        <?php endif; ?>
        <?php if (can('leads.delete') && !$converted): ?>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/leads/' . $lead['id'] . '/delete') ?>"
                data-confirm="Delete this lead and all its activity history?">
            <?= csrf_field() ?>
            <button class="dropdown__item dropdown__item--danger" type="submit"><?= icon('trash') ?> Delete lead</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($lead['stage'] === 'lost' && $lead['lost_reason']): ?>
  <div class="alert alert--error">
    <?= icon('x-circle') ?>
    <div class="alert__body"><strong>Marked lost:</strong> <?= e($lead['lost_reason']) ?></div>
  </div>
<?php endif; ?>

<?php if ($converted): ?>
  <div class="alert alert--success">
    <?= icon('check-circle') ?>
    <div class="alert__body">
      Converted to client <a href="<?= url('/clients/' . $lead['converted_client_id']) ?>"><?= e($lead['client_name']) ?></a>
      on <?= e(fdate($lead['converted_at'])) ?>.
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <?php if (can('leads.manage') && !$isClosed): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Move through the pipeline</div>
            <div class="card__sub">Every change is recorded in the activity trail.</div>
          </div>
        </div>
        <div class="card__body">
          <form method="post" action="<?= url('/leads/' . $lead['id'] . '/stage') ?>">
            <?= csrf_field() ?>
            <div class="form-grid form-grid--3">
              <div class="field">
                <label class="label" for="stage">New stage</label>
                <select class="select" id="stage" name="stage" required>
                  <?php foreach ($stages as $key => $stage): ?>
                    <option value="<?= e($key) ?>" <?= $lead['stage'] === $key ? 'selected' : '' ?>>
                      <?= e($stage['label']) ?> (<?= (int) $stage['probability'] ?>%)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label class="label" for="stage_note">Note</label>
                <input class="input" id="stage_note" name="stage_note" placeholder="Why the move? (optional)">
              </div>

              <div class="field">
                <label class="label" for="lost_reason">Reason, if lost</label>
                <input class="input" id="lost_reason" name="lost_reason" placeholder="e.g. Price, went elsewhere">
              </div>
            </div>
            <div class="form-actions">
              <button class="btn btn--navy" type="submit"><?= icon('trending-up') ?> Update stage</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Activity trail</div>
          <div class="card__sub"><?= count($activities) ?> entries — every call, email and meeting</div>
        </div>
      </div>

      <?php if (!$activities): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('activity') ?></div>
          <div class="empty__title">No activity logged</div>
          <p class="empty__text">Record each interaction so nothing gets forgotten and handovers are easy.</p>
        </div>
      <?php else: ?>
        <div class="card__body">
          <div class="timeline">
            <?php foreach ($activities as $a): ?>
              <div class="timeline__item">
                <span class="timeline__dot <?= $a['activity_type'] === 'stage_change' ? 'timeline__dot--navy' : '' ?>">
                  <?= icon($activityIcon($a['activity_type'])) ?>
                </span>
                <div class="timeline__head">
                  <span class="timeline__title">
                    <?= e($a['subject'] ?: label_of($a['activity_type'])) ?>
                  </span>
                  <span class="badge badge--grey text-xs"><?= e(label_of($a['activity_type'])) ?></span>
                  <span class="timeline__time"><?= e(fdatetime($a['activity_date'])) ?></span>
                </div>
                <div class="timeline__body">
                  <?= nl2br(e($a['notes'])) ?>
                  <?php if ($a['outcome']): ?>
                    <div class="mt-4"><span class="chip"><?= icon('flag') ?> <?= e($a['outcome']) ?></span></div>
                  <?php endif; ?>
                  <div class="text-xs text-muted mt-4">by <?= e($a['user_name'] ?: 'System') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($documents): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Documents raised</div></div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Number</th><th>Type</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($documents as $d):
                  $path = $d['doc_type'] === 'quotation' ? '/quotations/' : ($d['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/');
              ?>
                <tr>
                  <td><a class="table__primary" href="<?= url($path . $d['id']) ?>"><?= e($d['doc_number']) ?></a></td>
                  <td class="text-sm text-muted"><?= e(label_of($d['doc_type'])) ?></td>
                  <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>
                  <td class="num fw-600"><?= e(money($d['total'], false)) ?></td>
                  <td><span class="badge <?= status_badge($d['status']) ?>"><?= e(label_of($d['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

    <?php
    $threadType  = 'lead';
    $threadId    = (int) $lead['id'];
    $threadTitle = $lead['lead_number'];
    require APP_PATH . '/Views/partials/thread.php';
    ?>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Lead details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Lead no.</dt><dd><code><?= e($lead['lead_number']) ?></code></dd>
          <dt>Contact</dt><dd><?= e($lead['name']) ?></dd>
          <?php if ($lead['company']): ?><dt>Company</dt><dd><?= e($lead['company']) ?></dd><?php endif; ?>
          <dt>Phone</dt>
          <dd><?= $lead['phone'] ? '<a href="tel:' . e($lead['phone']) . '">' . e($lead['phone']) . '</a>' : '—' ?></dd>
          <dt>Email</dt>
          <dd class="truncate"><?= $lead['email'] ? '<a href="mailto:' . e($lead['email']) . '">' . e($lead['email']) . '</a>' : '—' ?></dd>
          <dt>Source</dt><dd><?= e(label_of($lead['source'])) ?></dd>
          <?php if ($lead['service_name']): ?>
            <dt>Service</dt><dd><?= e($lead['service_name']) ?></dd>
          <?php endif; ?>
          <?php if ($lead['item_name']): ?>
            <dt>Product</dt><dd><?= e($lead['item_name']) ?></dd>
          <?php endif; ?>
          <dt>Est. value</dt><dd class="fw-700 text-green"><?= e(money($lead['estimated_value'])) ?></dd>
          <dt>Probability</dt>
          <dd>
            <?= (int) $lead['probability'] ?>%
            <div class="progress mt-4"><div class="progress__bar" style="width:<?= (int) $lead['probability'] ?>%"></div></div>
          </dd>
          <dt>Expected close</dt><dd><?= e(fdate($lead['expected_close_date'])) ?></dd>
          <dt>Assigned to</dt><dd><?= e($lead['assignee_name'] ?: 'Unassigned') ?></dd>
          <dt>Registered</dt><dd><?= e(fdate($lead['created_at'])) ?> by <?= e($lead['created_by_name'] ?: 'System') ?></dd>
        </dl>

        <?php if ($lead['requirement']): ?>
          <hr>
          <div class="text-xs uppercase fw-700 text-muted mb-4">Requirement</div>
          <p class="text-sm mb-0" style="white-space:pre-line"><?= e($lead['requirement']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div class="card__title">Follow-ups</div>
        <?php if (can('leads.manage') && !$isClosed): ?>
          <div class="card__actions">
            <button class="btn btn--ghost btn--sm" type="button" data-modal-open="reminder-modal" aria-label="Add a follow-up reminder">
              <?= icon('plus') ?>
            </button>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$reminders): ?>
        <div class="card__body">
          <p class="text-sm text-muted mb-0">No reminders set for this lead.</p>
        </div>
      <?php else: ?>
        <div class="card__body">
          <?php foreach ($reminders as $r):
              $overdue = !$r['is_done'] && strtotime($r['remind_at']) < time();
          ?>
            <div class="flex items-start gap-8 mb-12">
              <?php if (!$r['is_done']): ?>
                <form method="post" action="<?= url('/reminders/' . $r['id'] . '/done') ?>" style="margin-top:2px">
                  <?= csrf_field() ?>
                  <button class="btn btn--outline btn--sm btn--icon" type="submit" title="Mark done">
                    <?= icon('check') ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="text-green" style="margin-top:4px"><?= icon('check-circle') ?></span>
              <?php endif; ?>

              <div class="flex-1">
                <div class="text-sm fw-600 <?= $r['is_done'] ? 'text-muted' : '' ?>"
                     style="<?= $r['is_done'] ? 'text-decoration:line-through' : '' ?>">
                  <?= e($r['title']) ?>
                </div>
                <div class="text-xs <?= $overdue ? 'text-red fw-600' : 'text-muted' ?>">
                  <?= e(fdatetime($r['remind_at'])) ?>
                  <?= $overdue ? ' · overdue' : '' ?>
                  <?= $r['user_name'] ? ' · ' . e($r['user_name']) : '' ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </aside>
</div>

<?php if (can('leads.manage') && !$isClosed): ?>

<div class="modal-backdrop" id="activity-modal">
  <div class="modal modal--lg">
    <form method="post" action="<?= url('/leads/' . $lead['id'] . '/activity') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Log activity — <?= e($lead['name']) ?></div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>

      <div class="modal__body">
        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="activity_type">What happened? <span class="req">*</span></label>
            <select class="select" id="activity_type" name="activity_type" required>
              <?php foreach ($activityTypes as $t): ?>
                <option value="<?= e($t) ?>"><?= e(label_of($t)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="activity_date">When</label>
            <input class="input" type="datetime-local" id="activity_date" name="activity_date"
                   value="<?= e(date('Y-m-d\TH:i')) ?>">
          </div>

          <div class="field field--full">
            <label class="label" for="subject">Subject</label>
            <input class="input" id="subject" name="subject" maxlength="200"
                   placeholder="e.g. Discussed banner sizes and delivery date">
          </div>

          <div class="field field--full">
            <label class="label" for="notes">Details <span class="req">*</span></label>
            <textarea class="textarea" id="notes" name="notes" rows="4" required
                      placeholder="What was said or agreed? Anything the next person needs to know."></textarea>
          </div>

          <div class="field">
            <label class="label" for="outcome">Outcome</label>
            <input class="input" id="outcome" name="outcome" maxlength="160"
                   placeholder="e.g. Wants a formal quotation">
          </div>

          <div class="field">
            <label class="label" for="next_follow_up">Next follow-up</label>
            <input class="input" type="datetime-local" id="next_follow_up" name="next_follow_up"
                   value="<?= e(date('Y-m-d\TH:i', strtotime('+3 days 09:00'))) ?>">
            <span class="field-hint">Leave blank if no follow-up is needed.</span>
          </div>
        </div>
      </div>

      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save activity</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="reminder-modal">
  <div class="modal">
    <form method="post" action="<?= url('/leads/' . $lead['id'] . '/reminder') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Set a reminder</div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>

      <div class="modal__body">
        <div class="field mb-12">
          <label class="label" for="reminder_title">Reminder <span class="req">*</span></label>
          <input class="input" id="reminder_title" name="title" required maxlength="200"
                 value="Follow up with <?= e($lead['name']) ?>">
        </div>

        <div class="field mb-12">
          <label class="label" for="remind_at">When <span class="req">*</span></label>
          <input class="input" type="datetime-local" id="remind_at" name="remind_at" required
                 value="<?= e(date('Y-m-d\TH:i', strtotime('+2 days 09:00'))) ?>">
        </div>

        <div class="field mb-12">
          <label class="label" for="reminder_user">Assign to</label>
          <select class="select" id="reminder_user" name="user_id">
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"
                      <?= (int) ($lead['assigned_to'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                <?= e($u['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="reminder_notes">Notes</label>
          <textarea class="textarea" id="reminder_notes" name="notes" rows="3"></textarea>
        </div>
      </div>

      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('bell') ?> Set reminder</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
