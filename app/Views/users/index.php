<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Users &amp; Roles</h1>
    <div class="page-head__sub">Who can sign in, and what they are allowed to do.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="<?= url('/users/create') ?>"><?= icon('user-plus') ?> Add user</a>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <?= icon('shield') ?>
    <div>
      <div class="card__title">What each role can do</div>
    </div>
  </div>
  <div class="card__body">
    <dl class="dl dl--wide">
      <?php foreach ($roles as $key => $description):
          [$roleName, $detail] = array_pad(explode('—', $description, 2), 2, '');
      ?>
        <dt><span class="badge badge--navy"><?= e(label_of($key)) ?></span></dt>
        <dd class="text-sm text-muted"><?= e(trim($detail)) ?></dd>
      <?php endforeach; ?>
    </dl>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div class="card__title">Team members</div>
    <div class="card__sub"><?= count($users) ?> account(s)</div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Name</th><th>Contact</th><th>Role</th>
          <th class="num">Leads</th><th class="num">Documents</th>
          <th>Last login</th><th>Status</th><th class="actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $isMe = (int) $u['id'] === (int) auth()['id'];
        ?>
          <tr>
            <td>
              <div class="flex items-center gap-8">
                <span class="avatar" style="background:<?= e($u['avatar_color']) ?>">
                  <?= e(initials($u['name'])) ?>
                </span>
                <span>
                  <span class="table__primary"><?= e($u['name']) ?></span>
                  <?php if ($isMe): ?><span class="badge badge--green text-xs">You</span><?php endif; ?>
                  <div class="table__muted"><?= e($u['job_title'] ?: '—') ?></div>
                </span>
              </div>
            </td>
            <td class="text-sm">
              <div class="truncate" style="max-width:180px"><?= e($u['email']) ?></div>
              <?php if ($u['phone']): ?>
                <div class="table__muted"><?= e($u['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php
                // The main role first and in navy; anything else alongside it
                // in grey, so the badge someone is known by stays obvious.
                $mine  = $assignments[(int) $u['id']] ?? [$u['role']];
                $extra = array_values(array_diff($mine, [$u['role']]));
              ?>
              <div class="badge-set">
                <span class="badge badge--navy"><?= e(label_of($u['role'])) ?></span>
                <?php foreach ($extra as $role): ?>
                  <span class="badge badge--grey"><?= e(label_of($role)) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="num"><?= (int) $u['lead_count'] ?></td>
            <td class="num"><?= (int) $u['doc_count'] ?></td>
            <td class="text-sm text-muted"><?= e(time_ago($u['last_login_at'])) ?></td>
            <td>
              <span class="badge <?= $u['is_active'] ? 'badge--green' : 'badge--grey' ?>">
                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td class="actions">
              <a class="btn btn--outline btn--sm" href="<?= url('/users/' . $u['id'] . '/edit') ?>" title="Edit">
                <?= icon('edit') ?>
              </a>

              <?php if (!$isMe): ?>
                <form method="post" action="<?= url('/users/' . $u['id'] . '/toggle') ?>" style="display:inline"
                      data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e($u['name']) ?>?">
                  <?= csrf_field() ?>
                  <button class="btn btn--outline btn--sm" type="submit"
                          title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                    <?= icon($u['is_active'] ? 'lock' : 'check') ?>
                  </button>
                </form>

                <form method="post" action="<?= url('/users/' . $u['id'] . '/delete') ?>" style="display:inline"
                      data-confirm="Delete <?= e($u['name']) ?>? Accounts with history are deactivated instead.">
                  <?= csrf_field() ?>
                  <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
