<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$editing = $user !== null;
$action  = $editing ? url('/users/' . $user['id']) : url('/users');

$val = static function (string $key, $fallback = '') use ($user) {
    $old = Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $user[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/users') ?>">Users &amp; Roles</a> <span>/</span>
      <?= $editing ? e($user['name']) : 'New user' ?>
    </div>
    <h1><?= $editing ? 'Edit user' : 'Add a team member' ?></h1>
    <div class="page-head__sub">
      <?= $editing ? 'Update their details, role or password.' : 'They will sign in with the email and password you set here.' ?>
    </div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Details</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Full name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($val('name')) ?>" required maxlength="120">
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="job_title">Job title</label>
              <input class="input" id="job_title" name="job_title" value="<?= e($val('job_title')) ?>"
                     maxlength="120" placeholder="e.g. Production Manager">
            </div>

            <div class="field">
              <label class="label" for="email">Email address <span class="req">*</span></label>
              <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>" type="email" id="email" name="email"
                     value="<?= e($val('email')) ?>" required maxlength="160"
                     autocomplete="off" placeholder="name@shanfix.co.ke">
              <span class="field-hint">This is their sign-in username.</span>
              <?= error_for($errors ?? [], 'email') ?>
            </div>

            <div class="field">
              <label class="label" for="phone">Phone number</label>
              <input class="input" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30">
              <?= error_for($errors ?? [], 'phone') ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title"><?= $editing ? 'Reset password' : 'Password' ?></div>
            <div class="card__sub">
              <?= $editing
                  ? 'Leave both fields blank to keep their current password.'
                  : 'At least 8 characters. Share it with them securely, not by email.' ?>
            </div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="password">
                <?= $editing ? 'New password' : 'Password' ?>
                <?php if (!$editing): ?><span class="req">*</span><?php endif; ?>
              </label>
              <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>" type="password"
                     id="password" name="password" autocomplete="new-password"
                     minlength="8" <?= $editing ? '' : 'required' ?>>
              <?= error_for($errors ?? [], 'password') ?>
            </div>

            <div class="field">
              <label class="label" for="password_confirm">
                Confirm password
                <?php if (!$editing): ?><span class="req">*</span><?php endif; ?>
              </label>
              <input class="input <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>" type="password"
                     id="password_confirm" name="password_confirm" autocomplete="new-password"
                     minlength="8" <?= $editing ? '' : 'required' ?>>
              <?= error_for($errors ?? [], 'password_confirm') ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Role &amp; access</div></div>
        <div class="card__body">
          <?php
            // Roles ticked last time round survive a validation error; on a
            // fresh form it is whatever the account already holds.
            $heldRoles = old_array('roles', $held ?? []);
            $primary   = $val('role', 'staff');
          ?>

          <div class="field mb-16">
            <label class="label" for="role">Main role <span class="req">*</span></label>
            <select class="select <?= isset($errors['role']) ? 'has-error' : '' ?>" id="role" name="role" required>
              <?php foreach ($roles as $key => $description): ?>
                <option value="<?= e($key) ?>" <?= $primary === $key ? 'selected' : '' ?>>
                  <?= e(label_of($key)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="text-xs text-muted mt-4">Shown on their badge, and decides where they land after signing in.</div>
            <?= error_for($errors ?? [], 'role') ?>
          </div>

          <div class="field mb-16">
            <label class="label">Additional roles</label>
            <div class="text-xs text-muted mb-8">
              Tick anything else this person does. Someone can be Reception and Sales at once —
              they get everything both roles allow, and nothing more.
            </div>

            <?php foreach ($roles as $key => $description):
                [$roleName, $detail] = array_pad(explode('—', $description, 2), 2, '');
            ?>
              <label class="check-row" data-role-option="<?= e($key) ?>">
                <input type="checkbox" name="roles[]" value="<?= e($key) ?>"
                       <?= in_array($key, $heldRoles, true) || $primary === $key ? 'checked' : '' ?>>
                <span>
                  <strong><?= e(label_of($key)) ?></strong>
                  <span class="text-xs text-muted d-block"><?= e(trim($detail)) ?></span>
                </span>
              </label>
            <?php endforeach; ?>

            <?= error_for($errors ?? [], 'roles') ?>
          </div>

          <hr>

          <label class="check">
            <input type="checkbox" name="is_active" value="1" <?= (int) $val('is_active', 1) === 1 ? 'checked' : '' ?>>
            <span class="check__text">
              <strong>Active</strong>
              <span>Inactive accounts cannot sign in but keep all their history.</span>
            </span>
          </label>
        </div>
      </div>

      <?php if ($editing): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Account</div></div>
          <div class="card__body">
            <dl class="dl">
              <dt>Created</dt><dd><?= e(fdate($user['created_at'])) ?></dd>
              <dt>Last login</dt><dd><?= e(fdatetime($user['last_login_at'])) ?></dd>
              <dt>Last seen</dt><dd><?= e(time_ago($user['last_seen_at'])) ?></dd>
            </dl>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Create user' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8" href="<?= url('/users') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
