<?php require_once APP_PATH . '/Views/partials/icons.php';

$tabUrl = static fn(string $s): string => url('/portal-requests' . ($s !== '' ? '?status=' . $s : ''));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Portal access requests</h1>
    <div class="page-head__sub">
      Clients whose email we do not have, asking to be let in
    </div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Turned down', '' => 'All'] as $k => $label): ?>
      <a class="tab <?= $status === $k ? 'is-active' : '' ?>" href="<?= e($tabUrl($k)) ?>">
        <?= e($label) ?>
        <?php if ($k !== '' && !empty($counts[$k])): ?>
          <span class="tab__count"><?= (int) $counts[$k] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if (!$requests): ?>
  <div class="card">
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('inbox') ?></div>
      <div class="card__title mt-8">Nothing here</div>
      <p class="text-sm text-muted mb-0">
        Requests appear when a client asks for portal access without an email
        address on file.
      </p>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($requests as $req): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('user') ?>
        <div>
          <div class="card__title"><?= e($req['full_name']) ?></div>
          <div class="card__sub">
            <?= e($req['phone']) ?>
            <?php if ($req['email']): ?> · <?= e($req['email']) ?><?php endif; ?>
            · asked <?= e(date('j M Y', strtotime((string) $req['created_at']))) ?>
          </div>
        </div>
        <span class="badge badge--<?= $req['status'] === 'pending' ? 'amber' : ($req['status'] === 'approved' ? 'green' : 'grey') ?>">
          <?= e(ucfirst($req['status'])) ?>
        </span>
      </div>

      <div class="card__body">
        <?php if ($req['note']): ?>
          <p class="text-sm text-muted">They added: <?= e($req['note']) ?></p>
        <?php endif; ?>

        <?php if ($req['status'] !== 'pending'): ?>
          <p class="text-sm text-muted mb-0">
            <?= e(ucfirst($req['status'])) ?>
            <?php if ($req['decided_by_name']): ?> by <?= e($req['decided_by_name']) ?><?php endif; ?>
            <?php if ($req['matched_name']): ?> — matched to <?= e($req['matched_name']) ?><?php endif; ?>
            <?php if ($req['decision_note']): ?><br><?= e($req['decision_note']) ?><?php endif; ?>
          </p>
        <?php else: ?>

          <?php // The evidence, so the decision is made against the record
                // rather than against a name somebody typed. ?>
          <?php if (!$req['candidates']): ?>
            <div class="alert alert--warning">
              <?= icon('alert-triangle') ?>
              <div class="alert__body">
                Nothing on file matches that name or number. Approving is not
                possible without a match — turn it down, or find them first.
              </div>
            </div>
          <?php else: ?>
            <form method="post" action="<?= url('/portal-requests/' . $req['id'] . '/approve') ?>">
              <?= csrf_field() ?>
              <div class="field">
                <label class="label" for="client_<?= (int) $req['id'] ?>">Which client is this?</label>
                <select class="select" id="client_<?= (int) $req['id'] ?>" name="client_id" required>
                  <option value="">Choose the matching client…</option>
                  <?php foreach ($req['candidates'] as $c): ?>
                    <option value="<?= (int) $c['id'] ?>">
                      <?= e($c['name']) ?> — <?= e($c['client_code']) ?>
                      <?php if ($c['phone']): ?> (<?= e($c['phone']) ?>)<?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="field-hint">
                  The code is texted to <?= e($req['phone']) ?>, so that number has
                  to already be on the client you choose.
                </span>
              </div>
              <button class="btn btn--primary" type="submit">
                <?= icon('check') ?> Approve and text the code
              </button>
            </form>
          <?php endif; ?>

          <form method="post" action="<?= url('/portal-requests/' . $req['id'] . '/reject') ?>" class="mt-12"
                data-confirm="Turn down this request? Nothing is sent to them.">
            <?= csrf_field() ?>
            <div class="flex items-center gap-8" style="flex-wrap:wrap">
              <input class="input" type="text" name="note" maxlength="255"
                     style="min-width:220px" placeholder="Why, for our own record"
                     aria-label="Why this was turned down">
              <button class="btn btn--ghost btn--sm" type="submit">Turn down</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
