<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/services') ?>">Services</a> <span>/</span> <?= e($service['code']) ?>
    </div>
    <h1><?= e($service['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($service['category_name'] ?: 'Uncategorised') ?> ·
      <code><?= e($service['code']) ?></code>
      <?php if (!$service['is_active']): ?> · <span class="badge badge--grey">Inactive</span><?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('services.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/services/' . $service['id'] . '/edit') ?>"><?= icon('edit') ?> Edit</a>
      <form method="post" action="<?= url('/services/' . $service['id'] . '/delete') ?>" style="display:inline"
            data-confirm="Delete &quot;<?= e($service['name']) ?>&quot;? Services used on documents will be deactivated instead.">
        <?= csrf_field() ?>
        <button class="btn btn--danger-soft" type="submit"><?= icon('trash') ?> Delete</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Standard rate</div>
    <div class="stat__value">
      <?php if ($service['pricing_type'] === 'project' || (float) $service['price'] <= 0): ?>
        On request
      <?php else: ?>
        <?= e(money($service['price'])) ?>
      <?php endif; ?>
    </div>
    <div class="stat__meta">
      <?= e($pricingTypes[$service['pricing_type']] ?? '') ?>
      <?= $service['unit_label'] ? '· ' . e($service['unit_label']) : '' ?>
    </div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Invoiced to date</div>
    <div class="stat__value"><?= e(money_short($revenue)) ?></div>
    <div class="stat__meta">Across <?= count($sold) ?> recent document(s)</div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Open leads</div>
    <div class="stat__value"><?= count($openLeads) ?></div>
    <div class="stat__meta">Prospects asking for this service</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Turnaround</div>
    <div class="stat__value" style="font-size:17px"><?= e($service['lead_time'] ?: 'Not set') ?></div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <?php if ($service['description']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">What's included</div></div>
        <div class="card__body">
          <p style="white-space:pre-line"><?= e($service['description']) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('image') ?>
        <div>
          <div class="card__title">Work we have done</div>
          <div class="card__sub">
            Finished jobs that show what this service looks like — useful when a
            client asks whether we have done it before.
          </div>
        </div>
      </div>

      <?php if (!$examples): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('image') ?></div>
          <div class="empty__title">No examples linked yet</div>
          <p class="empty__text">
            <?= can('services.manage')
                ? 'Pick a finished job below to show alongside this service.'
                : 'Nobody has linked past work to this service yet.' ?>
          </p>
        </div>
      <?php else: ?>
        <div class="worklist">
          <?php foreach ($examples as $x):
              $done = $x['delivered_at'] ?: ($x['completed_at'] ?: $x['created_at']);
          ?>
            <div class="workitem">
              <a class="workitem__shot <?= $x['proof_path'] ? '' : 'workitem__shot--empty' ?>"
                 href="<?= url('/jobs/' . $x['id']) ?>">
                <?php if ($x['proof_path']): ?>
                  <img src="<?= url('files/' . $x['proof_path']) ?>" alt="" loading="lazy">
                <?php elseif ($x['proof_kind']): ?>
                  <?php // Approved artwork exists, it just is not a picture. ?>
                  <span class="workitem__kind"><?= e($x['proof_kind']) ?></span>
                <?php else: ?>
                  <?= icon('printer') ?>
                <?php endif; ?>
              </a>

              <div class="workitem__body">
                <a class="workitem__title" href="<?= url('/jobs/' . $x['id']) ?>">
                  <?= e($x['title'] ?: $x['job_number']) ?>
                </a>
                <div class="text-xs text-muted">
                  <a href="<?= url('/clients/' . $x['client_id']) ?>"><?= e($x['client_name']) ?></a>
                  · <?= e(fdate($done)) ?>
                  <?php if ((float) $x['job_value'] > 0 && can('expenses.view')): ?>
                    · <?= e(money($x['job_value'], false)) ?>
                  <?php endif; ?>
                </div>
                <?php if ($x['note']): ?>
                  <div class="text-xs" style="color:var(--on-green)"><?= e($x['note']) ?></div>
                <?php endif; ?>
              </div>

              <?php if (can('services.manage')): ?>
                <form method="post" class="workitem__remove"
                      action="<?= url('/services/' . $service['id'] . '/examples/' . $x['id'] . '/remove') ?>"
                      data-confirm="Remove <?= e($x['job_number']) ?> from this service? The job card is not affected.">
                  <?= csrf_field() ?>
                  <button class="btn btn--ghost btn--sm" type="submit" title="Remove this example">
                    <?= icon('x') ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (can('services.manage') && $suggestions): ?>
        <div class="card__body" style="border-top:1px solid var(--border)">
          <div class="text-sm fw-600 mb-8">Link a finished job</div>
          <?php
            // Suggested rather than searched: the jobs already invoiced with
            // this service are almost always the ones wanted, and they are
            // marked so the obvious choice is obvious.
          ?>
          <form method="post" action="<?= url('/services/' . $service['id'] . '/examples') ?>" class="linkjob">
            <?= csrf_field() ?>
            <select class="select" name="job_id" required aria-label="Finished job">
              <option value="">Choose a finished job…</option>
              <?php foreach ($suggestions as $j): ?>
                <option value="<?= (int) $j['id'] ?>">
                  <?= $j['same_service'] ? '★ ' : '' ?><?= e($j['job_number']) ?>
                  — <?= e(str_excerpt($j['title'] ?: 'Untitled', 42)) ?>
                  (<?= e($j['client_name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <input class="input" type="text" name="note" maxlength="255"
                   placeholder="Why this one is a good example (optional)">
            <button class="btn btn--primary" type="submit"><?= icon('plus') ?> Link</button>
          </form>
          <div class="text-xs text-muted mt-8">
            ★ marks jobs already invoiced with this service. Only finished jobs appear here.
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Recent documents</div>
          <div class="card__sub">Quotations and invoices containing this service.</div>
        </div>
      </div>

      <?php if (!$sold): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('file-text') ?></div>
          <div class="empty__title">Not yet quoted</div>
          <p class="empty__text">Once this service is added to a quotation or invoice it will show up here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr>
                <th>Document</th><th>Type</th><th>Client</th><th>Date</th>
                <th class="num">Qty</th><th class="num">Value</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sold as $d):
                  $path = $d['doc_type'] === 'quotation' ? '/quotations/' : ($d['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/');
              ?>
                <tr>
                  <td><a class="table__primary" href="<?= url($path . $d['id']) ?>"><?= e($d['doc_number']) ?></a></td>
                  <td class="text-sm text-muted"><?= e(label_of($d['doc_type'])) ?></td>
                  <td class="text-sm"><?= e($d['client_name']) ?></td>
                  <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>
                  <td class="num"><?= e(qty($d['quantity'])) ?></td>
                  <td class="num fw-600"><?= e(money($d['line_total'], false)) ?></td>
                  <td><span class="badge <?= status_badge($d['status']) ?>"><?= e(label_of($d['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <?php $images = $images ?? []; ?>
    <?php if ($images): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('image') ?>
          <div>
            <div class="card__title">Photos</div>
            <div class="card__sub"><?= count($images) ?> of this work</div>
          </div>
        </div>
        <div class="card__body">
          <?php $main = $images[0]; ?>
          <a class="product-hero" href="<?= url('files/' . $main['file_path']) ?>"
             data-gallery="service" data-caption="<?= e($main['alt_text'] ?: $service['name']) ?>"
             title="View larger">
            <img src="<?= url('files/' . $main['file_path']) ?>"
                 alt="<?= e($main['alt_text'] ?: $service['name']) ?>" loading="lazy">
          </a>

          <?php if (count($images) > 1): ?>
            <div class="thumb-grid mt-12">
              <?php foreach ($images as $img): ?>
                <a class="thumb <?= (int) $img['is_primary'] === 1 ? 'thumb--primary' : '' ?>"
                   href="<?= url('files/' . $img['file_path']) ?>"
                   data-gallery="service"
                   data-caption="<?= e($img['alt_text'] ?: $img['file_name']) ?>"
                   title="<?= e($img['file_name']) ?>">
                  <img src="<?= url('files/' . ($img['thumb_path'] ?: $img['file_path'])) ?>"
                       alt="<?= e($img['alt_text'] ?: $service['name']) ?>" loading="lazy">
                  <?php if ((int) $img['is_primary'] === 1): ?>
                    <span class="thumb__badge">Main</span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (can('services.manage')): ?>
            <div class="thumb-actions mt-12">
              <?php foreach ($images as $img): ?>
                <div class="thumb-actions__row">
                  <span class="text-xs text-muted flex-1"><?= e(str_excerpt($img['file_name'], 26)) ?></span>

                  <?php if ((int) $img['is_primary'] !== 1): ?>
                    <form method="post"
                          action="<?= url('/services/' . $service['id'] . '/images/' . $img['id'] . '/primary') ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">Make main</button>
                    </form>
                  <?php endif; ?>

                  <form method="post"
                        action="<?= url('/services/' . $service['id'] . '/images/' . $img['id'] . '/delete') ?>"
                        data-confirm="Remove this photo from <?= e($service['name']) ?>?">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm text-red" type="submit"
                            aria-label="Remove this photo"><?= icon('trash') ?></button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">Details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Code</dt><dd><code><?= e($service['code']) ?></code></dd>
          <dt>Category</dt><dd><?= e($service['category_name'] ?: '—') ?></dd>
          <dt>Pricing</dt><dd><?= e($pricingTypes[$service['pricing_type']] ?? '') ?></dd>
          <dt>Unit</dt><dd><?= e($service['unit_label'] ?: '—') ?></dd>
          <dt>Status</dt>
          <dd><span class="badge <?= $service['is_active'] ? 'badge--green' : 'badge--grey' ?>">
            <?= $service['is_active'] ? 'Active' : 'Inactive' ?></span></dd>
          <dt>Created</dt><dd><?= e(fdate($service['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($openLeads): ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title">Open leads</div>
          <div class="card__actions"><a class="btn btn--ghost btn--sm" href="<?= url('/leads') ?>">All leads</a></div>
        </div>
        <div class="card__body--flush">
          <?php foreach ($openLeads as $l): ?>
            <a class="conv" href="<?= url('/leads/' . $l['id']) ?>">
              <span class="avatar avatar--sm"><?= e(initials($l['name'])) ?></span>
              <span class="conv__meta">
                <span class="conv__name"><?= e($l['name']) ?></span>
                <span class="conv__preview"><?= e($l['company'] ?: $l['lead_number']) ?></span>
              </span>
              <span class="conv__right">
                <span class="badge <?= status_badge($l['stage']) ?>"><?= e(label_of($l['stage'])) ?></span>
                <span class="conv__time"><?= e(money_short($l['estimated_value'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
