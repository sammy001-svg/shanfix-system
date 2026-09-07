<?php
/**
 * Which door.
 *
 * Three kinds of account sign in to this system, and none of them works
 * in another's form. Asking once, here, is kinder than letting somebody
 * find out by being refused — and the refusal has to stay vague, because
 * a form that says "no such customer" tells a stranger who our customers
 * are.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<h1 class="login__title">Sign in</h1>
<p class="login__intro">Which of these are you?</p>

<div class="choose">
  <a class="choose__row" href="<?= url('/login') ?>">
    <span class="choose__icon choose__icon--staff"><?= icon('briefcase') ?></span>
    <span class="choose__body">
      <strong>Staff</strong>
      <em>You work here. Jobs, quotations, clients and the rest of the system.</em>
    </span>
    <span class="choose__go"><?= icon('chevron-right') ?></span>
  </a>

  <?php if ($portalOn): ?>
    <a class="choose__row" href="<?= url('/portal/login') ?>">
      <span class="choose__icon choose__icon--client"><?= icon('user') ?></span>
      <span class="choose__body">
        <strong>Customer</strong>
        <em>Your quotations, invoices and statements, and paying what you owe.</em>
      </span>
      <span class="choose__go"><?= icon('chevron-right') ?></span>
    </a>
  <?php endif; ?>

  <?php if ($partnerOn): ?>
    <a class="choose__row" href="<?= url('/partners/login') ?>">
      <span class="choose__icon choose__icon--partner"><?= icon('users') ?></span>
      <span class="choose__body">
        <strong>Partner</strong>
        <em>You bring us customers. What you have sold, and what you have earned.</em>
      </span>
      <span class="choose__go"><?= icon('chevron-right') ?></span>
    </a>
  <?php endif; ?>
</div>

<?php if ($partnerOn && \App\Core\Settings::bool('partner_signup_enabled', true)): ?>
  <p class="login__help">
    Not one of these yet?
    <a href="<?= url('/partners/apply') ?>">Apply to become a partner</a>.
  </p>
<?php endif; ?>
