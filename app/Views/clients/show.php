<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tabUrl = static fn(string $t): string => url('/clients/' . $client['id']) . ($t === 'overview' ? '' : '?tab=' . $t);

$docPath = static fn(string $type): string => match ($type) {
    'quotation' => '/quotations/',
    'invoice'   => '/invoices/',
    default     => '/receipts/',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/clients') ?>">Clients</a> <span>/</span> <?= e($client['client_code']) ?>
    </div>
    <div class="flex items-center gap-12">
      <span class="avatar avatar--lg"><?= e(initials($client['name'])) ?></span>
      <span>
        <h1><?= e($client['name']) ?></h1>
        <div class="page-head__sub">
          <?= e(label_of($client['client_type'])) ?>
          <?= $client['industry'] ? ' · ' . e($client['industry']) : '' ?>
          · <span class="badge <?= $client['status'] === 'active' ? 'badge--green' : 'badge--grey' ?>">
              <?= e(label_of($client['status'])) ?>
            </span>
        </div>
      </span>
    </div>
  </div>

  <div class="page-head__actions">
    <?php if (can('requests.manage')): ?>
      <button class="btn btn--outline" type="button" data-modal-open="ask-details">
        <?= icon('inbox') ?> Ask for job details
      </button>
    <?php endif; ?>
    <a class="btn btn--outline" href="<?= url('/clients/' . $client['id'] . '/statement') ?>">
      <?= icon('file-text') ?> Statement
    </a>
    <?php if (can('documents.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/quotations/create?client_id=' . $client['id']) ?>">
        <?= icon('file-text') ?> New quotation
      </a>
      <a class="btn btn--primary" href="<?= url('/invoices/create?client_id=' . $client['id']) ?>">
        <?= icon('receipt') ?> New invoice
      </a>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn--outline" type="button" data-dropdown><?= icon('more') ?></button>
      <div class="dropdown__menu">
        <?php if (can('clients.manage')): ?>
          <a class="dropdown__item" href="<?= url('/clients/' . $client['id'] . '/edit') ?>">
            <?= icon('edit') ?> Edit client
          </a>
        <?php endif; ?>
        <?php if ($client['phone']): ?>
          <a class="dropdown__item" href="tel:<?= e($client['phone']) ?>"><?= icon('phone') ?> Call client</a>
        <?php endif; ?>
        <?php if ($client['email']): ?>
          <a class="dropdown__item" href="mailto:<?= e($client['email']) ?>"><?= icon('mail') ?> Send email</a>
        <?php endif; ?>
        <?php if (can('clients.delete')): ?>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/clients/' . $client['id'] . '/delete') ?>"
                data-confirm="Delete <?= e($client['name']) ?>? Clients with documents are archived instead.">
            <?= csrf_field() ?>
            <button class="dropdown__item dropdown__item--danger" type="submit">
              <?= icon('trash') ?> Delete client
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($subscriptions)): ?>
  <div class="stat-grid">
    <div class="stat stat--navy">
      <div class="stat__label">Recurring services</div>
      <div class="stat__value"><?= (int) $siteCount ?></div>
      <div class="stat__meta">
        <?php
          // Websites are what people ask about by name, so they are counted
          // out separately from hosting, domains and retainers.
          $sites = count(array_filter(
              $subscriptions,
              static fn(array $s): bool => $s['service_type'] === 'website' && $s['status'] === 'active'
          ));
        ?>
        <?= $sites ?> website(s) linked
      </div>
    </div>
    <div class="stat <?= (float) $renewalDue > 0 ? 'stat--red' : 'stat--green' ?>">
      <div class="stat__label">Renewals owing</div>
      <div class="stat__value"><?= e(money_short($renewalDue)) ?></div>
      <div class="stat__meta"><?= (float) $renewalDue > 0 ? 'On renewal invoices' : 'All renewals settled' ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Total billed</div>
    <div class="stat__value"><?= e(money_short($stats['total_billed'])) ?></div>
    <div class="stat__meta"><?= (int) $stats['invoice_count'] ?> invoice(s)</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Total paid</div>
    <div class="stat__value"><?= e(money_short($stats['total_paid'])) ?></div>
    <div class="stat__meta"><?= (int) $paymentCount ?> payment(s) received</div>
  </div>
  <div class="stat <?= (float) $stats['outstanding'] > 0 ? 'stat--red' : 'stat--green' ?>">
    <div class="stat__label">Outstanding balance</div>
    <div class="stat__value"><?= e(money_short($stats['outstanding'])) ?></div>
    <div class="stat__meta">
      <?php if ((int) $stats['overdue_count'] > 0): ?>
        <span class="text-red fw-600"><?= (int) $stats['overdue_count'] ?> overdue</span>
      <?php else: ?>
        Nothing overdue
      <?php endif; ?>
    </div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Quotations</div>
    <div class="stat__value"><?= (int) $stats['quotation_count'] ?></div>
    <div class="stat__meta"><?= (int) $stats['receipt_count'] ?> receipt(s) issued</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $tab === 'overview'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('overview')) ?>">
      <?= icon('grid') ?> Overview
    </a>
    <a class="tab <?= $tab === 'quotations' ? 'is-active' : '' ?>" href="<?= e($tabUrl('quotations')) ?>">
      <?= icon('file-text') ?> Quotations <span class="tab__count"><?= (int) $stats['quotation_count'] ?></span>
    </a>
    <a class="tab <?= $tab === 'invoices'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('invoices')) ?>">
      <?= icon('receipt') ?> Invoices <span class="tab__count"><?= (int) $stats['invoice_count'] ?></span>
    </a>
    <a class="tab <?= $tab === 'receipts'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('receipts')) ?>">
      <?= icon('check-circle') ?> Receipts <span class="tab__count"><?= (int) $stats['receipt_count'] ?></span>
    </a>
    <a class="tab <?= $tab === 'payments'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('payments')) ?>">
      <?= icon('credit-card') ?> Payments <span class="tab__count"><?= (int) $paymentCount ?></span>
    </a>
    <a class="tab <?= $tab === 'activity'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('activity')) ?>">
      <?= icon('activity') ?> Activity
    </a>
  </nav>
</div>

<div class="grid-sidebar">
  <div>
    <?php if ($tab === 'activity'): ?>

      <div class="card">
        <div class="card__head"><div class="card__title">Activity log</div></div>
        <?php if (!$activities): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('activity') ?></div>
            <div class="empty__title">No activity recorded</div>
            <p class="empty__text">Changes to this client's record will be listed here.</p>
          </div>
        <?php else: ?>
          <div class="card__body">
            <div class="timeline">
              <?php foreach ($activities as $a): ?>
                <div class="timeline__item">
                  <span class="timeline__dot timeline__dot--navy"><?= icon('activity') ?></span>
                  <div class="timeline__head">
                    <span class="timeline__title"><?= e(label_of($a['action'])) ?></span>
                    <span class="timeline__time"><?= e(fdatetime($a['created_at'])) ?></span>
                  </div>
                  <div class="timeline__body">
                    <?= e($a['description'] ?: '—') ?>
                    <span class="text-muted">· <?= e($a['user_name'] ?: 'System') ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'payments'): ?>

      <div class="card">
        <div class="card__head">
          <div class="card__title">Payments received</div>
          <?php if (can('payments.manage')): ?>
            <div class="card__actions">
              <a class="btn btn--primary btn--sm" href="<?= url('/payments/create?client_id=' . $client['id']) ?>">
                <?= icon('plus') ?> Record payment
              </a>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$payments): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('credit-card') ?></div>
            <div class="empty__title">No payments yet</div>
            <p class="empty__text">M-Pesa, bank and cash payments from this client will appear here.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Receipt no.</th><th>Date</th><th>Invoice</th>
                  <th>Method</th><th>Reference</th><th class="num">Amount</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td class="table__primary"><?= e($p['payment_number']) ?></td>
                    <td class="text-sm"><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
                    <td>
                      <?php if ($p['doc_number']): ?>
                        <a href="<?= url('/invoices/' . $p['document_id']) ?>"><?= e($p['doc_number']) ?></a>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-sm"><?= e(label_of($p['method'])) ?></td>
                    <td class="text-sm text-muted"><?= e($p['reference'] ?: '—') ?></td>
                    <td class="num fw-700 text-green"><?= e(money($p['amount'], false)) ?></td>
                    <td><span class="badge <?= status_badge($p['status']) ?>"><?= e(label_of($p['status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <?php else: ?>

      <?php
        $tabTitle = match ($tab) {
            'quotations' => 'Quotations',
            'invoices'   => 'Invoices',
            'receipts'   => 'Receipts',
            default      => 'Recent documents',
        };
      ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title"><?= e($tabTitle) ?></div>
          <?php if (can('documents.manage')): ?>
            <div class="card__actions">
              <a class="btn btn--outline btn--sm" href="<?= url('/quotations/create?client_id=' . $client['id']) ?>">
                <?= icon('plus') ?> Quotation
              </a>
              <a class="btn btn--primary btn--sm" href="<?= url('/invoices/create?client_id=' . $client['id']) ?>">
                <?= icon('plus') ?> Invoice
              </a>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$documents): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('file-text') ?></div>
            <div class="empty__title">Nothing here yet</div>
            <p class="empty__text">
              Create a quotation for <?= e($client['name']) ?>, then convert it to an invoice
              and issue a receipt once payment lands.
            </p>
            <?php if (can('documents.manage')): ?>
              <a class="btn btn--primary" href="<?= url('/quotations/create?client_id=' . $client['id']) ?>">
                <?= icon('plus') ?> Create quotation
              </a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Number</th>
                  <?php if ($tab === 'overview'): ?><th>Type</th><?php endif; ?>
                  <th>Description</th>
                  <th>Date</th>
                  <th class="num">Total</th>
                  <th class="num">Balance</th>
                  <th>Status</th>
                  <th class="actions"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($documents as $d): ?>
                  <tr>
                    <td>
                      <a class="table__primary" href="<?= url($docPath($d['doc_type']) . $d['id']) ?>">
                        <?= e($d['doc_number']) ?>
                      </a>
                    </td>
                    <?php if ($tab === 'overview'): ?>
                      <td class="text-sm text-muted"><?= e(label_of($d['doc_type'])) ?></td>
                    <?php endif; ?>
                    <td class="text-sm"><?= e(str_excerpt($d['title'], 44) ?: '—') ?></td>
                    <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>
                    <td class="num fw-600"><?= e(money($d['total'], false)) ?></td>
                    <td class="num">
                      <?php if ($d['doc_type'] === 'invoice' && (float) $d['balance'] > 0.009): ?>
                        <span class="text-red fw-600"><?= e(money($d['balance'], false)) ?></span>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= status_badge($d['status']) ?>"><?= e(label_of($d['status'])) ?></span></td>
                    <td class="actions">
                      <a class="btn btn--outline btn--sm" href="<?= url($docPath($d['doc_type']) . $d['id']) ?>">
                        <?= icon('eye') ?>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($tab === 'overview' && $payments): ?>
        <div class="card">
          <div class="card__head">
            <div class="card__title">Recent payments</div>
            <div class="card__actions">
              <a class="btn btn--ghost btn--sm" href="<?= e($tabUrl('payments')) ?>">View all</a>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
              </thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td class="table__primary"><?= e($p['payment_number']) ?></td>
                    <td class="text-sm"><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
                    <td class="text-sm"><?= e(label_of($p['method'])) ?></td>
                    <td class="text-sm text-muted"><?= e($p['reference'] ?: '—') ?></td>
                    <td class="num fw-700 text-green"><?= e(money($p['amount'], false)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <aside>
    <?php if (!empty($jobRequests)): ?>
      <?php
        $jrBadge = [
            'draft'     => ['grey',  'Not sent'],
            'sent'      => ['navy',  'Waiting'],
            'opened'    => ['amber', 'Opened'],
            'submitted' => ['green', 'Answered'],
            'actioned'  => ['grey',  'Dealt with'],
        ];
      ?>
      <div class="card">
        <div class="card__head">
          <?= icon('inbox') ?>
          <div>
            <div class="card__title">Job details asked for</div>
            <div class="card__sub">What this client says they want</div>
          </div>
        </div>
        <div class="card__body">
          <?php foreach ($jobRequests as $jr): ?>
            <?php $b = $jrBadge[$jr['status']] ?? ['grey', $jr['status']]; ?>
            <div class="siterow">
              <div class="siterow__main">
                <a class="siterow__name" href="<?= url('/requests/' . $jr['id']) ?>">
                  <?= e(\App\Services\JobBrief::TYPES[$jr['brief_type']] ?? $jr['brief_type']) ?>
                </a>
                <div class="text-xs text-muted">
                  <?= e($jr['title'] ?: $jr['reference']) ?>
                  · <?= e(date('j M Y', strtotime((string) $jr['created_at']))) ?>
                </div>
              </div>
              <span class="badge badge--<?= e($b[0]) ?>"><?= e($b[1]) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($subscriptions)): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('refresh') ?>
          <div>
            <div class="card__title">Recurring services</div>
            <div class="card__sub">Websites and retainers we bill on a cycle.</div>
          </div>
        </div>
        <div class="card__body">
          <?php foreach ($subscriptions as $s):
              $days = (int) floor((strtotime($s['next_renewal_date']) - strtotime(date('Y-m-d'))) / 86400);
              $tone = $s['status'] !== 'active' ? 'grey' : ($days < 0 ? 'red' : ($days <= 30 ? 'amber' : 'green'));
          ?>
            <div class="siterow">
              <div class="siterow__main">
                <a class="siterow__name" href="<?= url('/subscriptions/' . $s['id']) ?>"><?= e($s['name']) ?></a>
                <div class="text-xs text-muted">
                  <?= e(money($s['amount'], false)) ?>
                  · renews <?= e(fdate($s['next_renewal_date'])) ?>
                  <?php if ((float) $s['due_balance'] > 0): ?>
                    · <span class="text-red fw-600"><?= e(money($s['due_balance'], false)) ?> owing</span>
                  <?php endif; ?>
                </div>
              </div>

              <span class="siterow__side">
                <span class="badge badge--<?= e($tone) ?>">
                  <?= $s['status'] !== 'active'
                      ? e(label_of($s['status']))
                      : ($days < 0 ? abs($days) . 'd late' : $days . 'd') ?>
                </span>
                <?php if ($s['url']): ?>
                  <a class="btn btn--outline btn--sm" href="<?= e($s['url']) ?>"
                     target="_blank" rel="noopener noreferrer" title="Open <?= e($s['url']) ?>">
                    <?= icon('external-link') ?>
                  </a>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>

          <?php if (can('subscriptions.manage')): ?>
            <a class="btn btn--outline btn--sm mt-8"
               href="<?= url('/subscriptions/create?client=' . $client['id']) ?>">
              <?= icon('plus') ?> Add a service
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php elseif (can('subscriptions.manage')): ?>
      <div class="card">
        <div class="card__body">
          <div class="card__title mb-4">Recurring services</div>
          <p class="text-sm text-muted">
            Nothing recurring for this client yet — register a website, hosting
            package or retainer to track its renewals.
          </p>
          <a class="btn btn--outline btn--sm"
             href="<?= url('/subscriptions/create?client=' . $client['id']) ?>">
            <?= icon('plus') ?> Add a service
          </a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($openInvoices && can('payments.stk')): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('smartphone') ?>
          <div>
            <div class="card__title">Request payment</div>
            <div class="card__sub">Send an M-Pesa STK Push to the client's phone.</div>
          </div>
        </div>
        <div class="card__body">
          <?php if (!$stkEnabled): ?>
            <div class="alert alert--warning mb-0">
              <?= icon('alert-triangle') ?>
              <div class="alert__body">
                KopoKopo is not configured yet.
                <?php if (can('settings.manage')): ?>
                  <a href="<?= url('/settings?tab=payments') ?>">Add your API credentials</a> to enable STK Push.
                <?php else: ?>
                  Ask an administrator to add the API credentials.
                <?php endif; ?>
              </div>
            </div>
          <?php elseif (!$client['phone']): ?>
            <div class="alert alert--warning mb-0">
              <?= icon('alert-triangle') ?>
              <div class="alert__body">
                This client has no phone number on file.
                <a href="<?= url('/clients/' . $client['id'] . '/edit') ?>">Add one</a> to send STK Push requests.
              </div>
            </div>
          <?php else: ?>
            <form method="post" action="<?= url('/payments/stk') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">

              <div class="field mb-12">
                <label class="label" for="stk_document_id">Invoice</label>
                <select class="select" id="stk_document_id" name="document_id" data-fills="#stk_amount" required>
                  <?php foreach ($openInvoices as $inv): ?>
                    <option value="<?= (int) $inv['id'] ?>" data-value="<?= e(number_format((float) $inv['balance'], 2, '.', '')) ?>">
                      <?= e($inv['doc_number']) ?> — <?= e(money($inv['balance'])) ?> due
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field mb-12">
                <label class="label" for="stk_phone">Phone number</label>
                <input class="input" id="stk_phone" name="phone" value="<?= e($client['phone']) ?>" required>
                <span class="field-hint">The prompt is sent to this Safaricom number.</span>
              </div>

              <div class="field mb-16">
                <label class="label" for="stk_amount">Amount</label>
                <div class="input-group">
                  <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                  <input class="input" type="number" step="1" min="1" id="stk_amount" name="amount"
                         value="<?= e((string) (int) ceil((float) $openInvoices[0]['balance'])) ?>" required>
                </div>
                <span class="field-hint">Partial payments are allowed.</span>
              </div>

              <button class="btn btn--primary btn--block" type="submit">
                <?= icon('smartphone') ?> Send STK Push
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">Contact</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Client code</dt><dd><code><?= e($client['client_code']) ?></code></dd>
          <?php if ($client['contact_person']): ?>
            <dt>Contact</dt><dd><?= e($client['contact_person']) ?></dd>
          <?php endif; ?>
          <dt>Phone</dt>
          <dd>
            <?php if ($client['phone']): ?>
              <a href="tel:<?= e($client['phone']) ?>"><?= e($client['phone']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </dd>
          <?php if ($client['alt_phone']): ?>
            <dt>Alt. phone</dt><dd><?= e($client['alt_phone']) ?></dd>
          <?php endif; ?>
          <dt>Email</dt>
          <dd>
            <?php if ($client['email']): ?>
              <a href="mailto:<?= e($client['email']) ?>" class="truncate" style="display:block"><?= e($client['email']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </dd>
          <?php if ($client['address'] || $client['city']): ?>
            <dt>Address</dt>
            <dd><?= e(trim(($client['address'] ?? '') . ($client['city'] ? ', ' . $client['city'] : ''), ', ')) ?></dd>
          <?php endif; ?>
          <?php if ($client['kra_pin']): ?>
            <dt>KRA PIN</dt><dd><code><?= e($client['kra_pin']) ?></code></dd>
          <?php endif; ?>
          <?php if ($client['industry']): ?>
            <dt>Industry</dt><dd><?= e($client['industry']) ?></dd>
          <?php endif; ?>
          <?php if ((float) $client['credit_limit'] > 0): ?>
            <dt>Credit limit</dt><dd><?= e(money($client['credit_limit'])) ?></dd>
          <?php endif; ?>
          <dt>Registered</dt><dd><?= e(fdate($client['created_at'])) ?></dd>
          <dt>Added by</dt><dd><?= e($client['created_by_name'] ?: 'System') ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($client['notes']): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Internal notes</div>
            <div class="card__sub">Not shown to the client.</div>
          </div>
        </div>
        <div class="card__body">
          <p class="text-sm" style="white-space:pre-line"><?= e($client['notes']) ?></p>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>

<?php if (can('requests.manage')): ?>
  <div class="modal-backdrop" id="ask-details">
    <div class="modal__panel">
      <form method="post" action="<?= url('/requests') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
        <div class="modal__head">
          <div class="card__title">Ask <?= e($client['name']) ?> for job details</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            They get a link by email and text, fill in what they need in their
            own words, and can attach anything they already have. What comes
            back lands on this profile.
          </p>

          <div class="field">
            <label class="label">What is it for?</label>
            <div class="checkgrid">
              <?php foreach (\App\Services\JobBrief::TYPES as $key => $label): ?>
                <label class="check-row">
                  <input type="radio" name="brief_type" value="<?= e($key) ?>"
                         <?= $key === 'design' ? 'checked' : '' ?> required>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <span class="field-hint">
              One per request. A client wanting a logo and a website gets two,
              which keeps each brief to the point.
            </span>
          </div>

          <div class="field">
            <label class="label" for="jr_title">What to call it <span class="text-muted">(optional)</span></label>
            <input class="input" id="jr_title" name="title" maxlength="200"
                   placeholder="e.g. Shop front signage">
            <span class="field-hint">Just for us, to tell several requests apart.</span>
          </div>

          <div class="field">
            <label class="label" for="jr_note">Internal note <span class="text-muted">(optional)</span></label>
            <textarea class="textarea" id="jr_note" name="note" rows="2"
                      placeholder="Anything the team should know. Never shown to the client."></textarea>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Create the request</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
