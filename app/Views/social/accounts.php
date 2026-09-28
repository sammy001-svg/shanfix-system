<?php
/**
 * The profiles we post from, and the campaigns worth grouping by.
 *
 * Two things on one page because neither is big enough for its own and
 * both are set up once and then rarely touched.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/social')) ?>">Social</a> <span>/</span> Accounts
    </div>
    <h1>Profiles and campaigns</h1>
    <div class="page-head__sub">
      Where the company posts from, and the pushes worth reporting on separately.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(url('/social')) ?>"><?= icon('calendar') ?> Calendar</a>
  </div>
</div>

<div class="grid-2">
  <div class="hr-col">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Profiles</div>
          <div class="card__sub">
            One row per page we post to. Several on the same network is normal —
            a main page and one for a product line — and posting to the wrong
            one is the mistake this makes visible.
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php if (!$accounts): ?>
          <p class="text-sm text-muted">
            None yet. Add the company's Facebook page to begin.
          </p>
        <?php endif; ?>

        <?php foreach ($accounts as $account): ?>
          <details class="canned">
            <summary class="canned__head">
              <span class="canned__title">
                <?= e($account['name']) ?>
                <span class="text-xs text-muted">
                  · <?= e($networks[$account['network']] ?? $account['network']) ?>
                  <?= $account['handle'] ? ' · ' . e($account['handle']) : '' ?>
                </span>
              </span>
              <?php if ($account['status'] === 'inactive'): ?>
                <span class="badge badge--grey">Off</span>
              <?php endif; ?>
              <span class="canned__uses" title="Posts sent here"><?= (int) $account['posts'] ?></span>
              <?= icon('chevron-down', 'canned__caret') ?>
            </summary>

            <div class="canned__body">
              <form method="post" action="<?= e(url('/social/accounts')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">

                <div class="form-grid form-grid--2">
                  <div class="field">
                    <label class="label">Name</label>
                    <input class="input" name="name" maxlength="80" required
                           value="<?= e($account['name']) ?>">
                  </div>

                  <div class="field">
                    <label class="label">Network</label>
                    <select class="select" name="network">
                      <?php foreach ($networks as $key => $label): ?>
                        <option value="<?= e($key) ?>"
                          <?= $account['network'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="field">
                    <label class="label">Handle</label>
                    <input class="input" name="handle" maxlength="80"
                           value="<?= e($account['handle'] ?? '') ?>" placeholder="@shanfix">
                  </div>

                  <div class="field">
                    <label class="label">Followers</label>
                    <input class="input" type="number" min="0" name="followers"
                           value="<?= (int) $account['followers'] ?>">
                    <span class="field-hint">
                      <?= $account['followers_at']
                          ? 'Last looked at ' . e(fdate($account['followers_at']))
                          : 'Never looked at' ?>
                    </span>
                  </div>
                </div>

                <div class="field">
                  <label class="label">Link to the profile</label>
                  <input class="input" name="profile_url" maxlength="255"
                         value="<?= e($account['profile_url'] ?? '') ?>">
                </div>

                <div class="form-grid form-grid--2">
                  <div class="field">
                    <label class="label">Order</label>
                    <input class="input" type="number" min="0" name="position"
                           value="<?= (int) $account['position'] ?>">
                  </div>

                  <div class="field">
                    <label class="label">Status</label>
                    <select class="select" name="status">
                      <option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>In use</option>
                      <option value="inactive" <?= $account['status'] === 'inactive' ? 'selected' : '' ?>>Switched off</option>
                    </select>
                  </div>
                </div>

                <button class="btn btn--primary btn--sm">Save</button>
              </form>

              <form method="post" class="mt-8"
                    action="<?= e(url('/social/accounts/' . $account['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Remove <?= e(addslashes($account['name'])) ?>?')">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" style="color:var(--red-700)">Remove</button>
              </form>
            </div>
          </details>
        <?php endforeach; ?>

        <hr class="mt-16">

        <form method="post" action="<?= e(url('/social/accounts')) ?>" class="mt-8">
          <?= csrf_field() ?>
          <div class="card__title mb-8">Add a profile</div>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="newName">Name <span class="req">*</span></label>
              <input class="input" id="newName" name="name" maxlength="80" required
                     placeholder="Shanfix Technology">
            </div>

            <div class="field">
              <label class="label" for="newNetwork">Network</label>
              <select class="select" id="newNetwork" name="network">
                <?php foreach ($networks as $key => $label): ?>
                  <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="label" for="newHandle">Handle</label>
            <input class="input" id="newHandle" name="handle" maxlength="80" placeholder="@shanfix">
          </div>

          <button class="btn btn--primary">Add it</button>
        </form>
      </div>
    </div>
  </div>

  <div class="hr-col">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Campaigns</div>
          <div class="card__sub">
            Optional. Most posts are not part of anything; the ones that are
            want grouping in the report, with what they cost.
          </div>
        </div>
      </div>

      <div class="card__body">
        <?php foreach ($campaigns as $campaign): ?>
          <details class="canned">
            <summary class="canned__head">
              <span class="canned__title"><?= e($campaign['name']) ?></span>
              <span class="badge badge--<?= match ($campaign['status']) {
                  'running' => 'green', 'done' => 'navy', 'cancelled' => 'grey', default => 'amber',
              } ?>"><?= e(ucfirst($campaign['status'])) ?></span>
              <?= icon('chevron-down', 'canned__caret') ?>
            </summary>

            <div class="canned__body">
              <form method="post" action="<?= e(url('/social/campaigns')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">

                <div class="field">
                  <label class="label">Name</label>
                  <input class="input" name="name" maxlength="120" required
                         value="<?= e($campaign['name']) ?>">
                </div>

                <div class="field">
                  <label class="label">What it is for</label>
                  <input class="input" name="goal" maxlength="255"
                         value="<?= e($campaign['goal'] ?? '') ?>">
                </div>

                <div class="form-grid form-grid--2">
                  <div class="field">
                    <label class="label">From</label>
                    <input class="input" type="date" name="starts_on"
                           value="<?= e($campaign['starts_on'] ?? '') ?>">
                  </div>
                  <div class="field">
                    <label class="label">To</label>
                    <input class="input" type="date" name="ends_on"
                           value="<?= e($campaign['ends_on'] ?? '') ?>">
                  </div>
                  <div class="field">
                    <label class="label">Spent boosting (KES)</label>
                    <input class="input" type="number" min="0" step="0.01" name="spend"
                           value="<?= e($campaign['spend']) ?>">
                  </div>
                  <div class="field">
                    <label class="label">Status</label>
                    <select class="select" name="status">
                      <?php foreach (['planned' => 'Planned', 'running' => 'Running',
                                      'done' => 'Finished', 'cancelled' => 'Dropped'] as $k => $l): ?>
                        <option value="<?= e($k) ?>"
                          <?= $campaign['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <button class="btn btn--primary btn--sm">Save</button>
              </form>
            </div>
          </details>
        <?php endforeach; ?>

        <hr class="mt-16">

        <form method="post" action="<?= e(url('/social/campaigns')) ?>" class="mt-8">
          <?= csrf_field() ?>
          <div class="card__title mb-8">Add a campaign</div>

          <div class="field">
            <label class="label" for="cName">Name <span class="req">*</span></label>
            <input class="input" id="cName" name="name" maxlength="120" required
                   placeholder="Graduation season">
          </div>

          <div class="field">
            <label class="label" for="cGoal">What it is for</label>
            <input class="input" id="cGoal" name="goal" maxlength="255"
                   placeholder="Gowns, sashes and photo banners for December">
          </div>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="cFrom">From</label>
              <input class="input" type="date" id="cFrom" name="starts_on">
            </div>
            <div class="field">
              <label class="label" for="cTo">To</label>
              <input class="input" type="date" id="cTo" name="ends_on">
            </div>
          </div>

          <button class="btn btn--primary">Add it</button>
        </form>
      </div>
    </div>
  </div>
</div>
