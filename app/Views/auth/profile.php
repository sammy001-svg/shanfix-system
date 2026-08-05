<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>My Profile</h1>
    <div class="page-head__sub">Your account details and password.</div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Account details</div>
          <div class="card__sub">Keep your contact information current.</div>
        </div>
      </div>
      <form method="post" action="<?= url('/profile') ?>">
        <?= csrf_field() ?>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Full name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($me['name']) ?>" required>
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="email">Email address <span class="req">*</span></label>
              <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>" type="email" id="email" name="email"
                     value="<?= e($me['email']) ?>" required>
              <?= error_for($errors ?? [], 'email') ?>
            </div>

            <div class="field">
              <label class="label" for="phone">Phone number</label>
              <input class="input" id="phone" name="phone" value="<?= e($me['phone']) ?>" placeholder="0712 345 678">
              <?= error_for($errors ?? [], 'phone') ?>
            </div>

            <div class="field">
              <label class="label" for="job_title">Job title</label>
              <input class="input" id="job_title" name="job_title" value="<?= e($me['job_title']) ?>"
                     placeholder="e.g. Sales Executive">
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save changes</button>
          </div>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Change password</div>
          <div class="card__sub">Use at least 8 characters.</div>
        </div>
      </div>
      <form method="post" action="<?= url('/profile/password') ?>">
        <?= csrf_field() ?>
        <div class="card__body">
          <div class="form-grid form-grid--3">
            <div class="field">
              <label class="label" for="current_password">Current password <span class="req">*</span></label>
              <input class="input <?= isset($errors['current_password']) ? 'has-error' : '' ?>"
                     type="password" id="current_password" name="current_password"
                     autocomplete="current-password" required>
              <?= error_for($errors ?? [], 'current_password') ?>
            </div>

            <div class="field">
              <label class="label" for="new_password">New password <span class="req">*</span></label>
              <input class="input <?= isset($errors['new_password']) ? 'has-error' : '' ?>"
                     type="password" id="new_password" name="new_password"
                     autocomplete="new-password" required minlength="8">
              <?= error_for($errors ?? [], 'new_password') ?>
            </div>

            <div class="field">
              <label class="label" for="new_password_confirm">Confirm new password <span class="req">*</span></label>
              <input class="input <?= isset($errors['new_password_confirm']) ? 'has-error' : '' ?>"
                     type="password" id="new_password_confirm" name="new_password_confirm"
                     autocomplete="new-password" required minlength="8">
              <?= error_for($errors ?? [], 'new_password_confirm') ?>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn--navy" type="submit"><?= icon('lock') ?> Update password</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__body text-center">
        <span class="avatar avatar--xl" style="background:<?= e($me['avatar_color']) ?>;margin:0 auto 12px">
          <?= e(initials($me['name'])) ?>
        </span>
        <div class="fw-700" style="font-size:16px"><?= e($me['name']) ?></div>
        <div class="text-sm text-muted"><?= e($me['job_title'] ?: label_of($me['role'])) ?></div>
        <div class="mt-8">
          <span class="badge badge--navy"><?= e(label_of($me['role'])) ?></span>
        </div>
      </div>
      <div class="card__body" style="border-top:1px solid var(--border)">
        <dl class="dl">
          <dt>Email</dt><dd class="truncate"><?= e($me['email']) ?></dd>
          <dt>Phone</dt><dd><?= e($me['phone'] ?: '—') ?></dd>
          <dt>Last login</dt><dd><?= e(fdatetime($me['last_login_at'])) ?></dd>
          <dt>Member since</dt><dd><?= e(fdate($me['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><div class="card__title">Your activity</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Clients added</dt><dd class="fw-700"><?= (int) $stats['clients'] ?></dd>
          <dt>Leads assigned</dt><dd class="fw-700"><?= (int) $stats['leads'] ?></dd>
          <dt>Invoices raised</dt><dd class="fw-700"><?= (int) $stats['invoices'] ?></dd>
        </dl>
      </div>
    </div>
  </aside>
</div>
