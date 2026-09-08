<?php
/**
 * A partner putting one of their customers on our books.
 *
 * The same details we would take ourselves, plus the one thing only they
 * can tell us: what the customer actually wants. That brief is the point
 * of the form — everything above it is so we can reach the person, and
 * everything below it is so somebody here can start work.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$type = old('client_type', 'company');
?>

<div class="portal-wrap">
  <p class="text-sm mb-12">
    <a href="<?= url('/partners/customers') ?>">&larr; Your customers</a>
  </p>

  <div class="portal-hello">
    <h1 class="portal-h1">Register a customer</h1>
    <p class="portal-lede">
      Put them on our books against your account. Everything they spend
      with us from then on earns you commission — the first job and every
      one after it.
    </p>
  </div>

  <form method="post" action="<?= url('/partners/clients/new') ?>">
    <?= csrf_field() ?>

    <section class="portal-card mb-16">
      <h2 class="portal-card__title mb-12">Who they are</h2>

      <div class="field">
        <label class="label" for="client_type">Kind of customer</label>
        <select class="select" id="client_type" name="client_type">
          <option value="company"    <?= $type === 'company'    ? 'selected' : '' ?>>A business</option>
          <option value="individual" <?= $type === 'individual' ? 'selected' : '' ?>>A person</option>
        </select>
      </div>

      <div class="field">
        <label class="label" for="name">
          <?= $type === 'individual' ? 'Their name' : 'Business name' ?>
        </label>
        <div class="input-icon">
          <?= icon('briefcase') ?>
          <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
                 type="text" id="name" name="name" value="<?= old('name') ?>"
                 required maxlength="180" autofocus>
        </div>
        <?= error_for($errors ?? [], 'name') ?>
      </div>

      <div class="field">
        <label class="label" for="contact_person">
          Person to speak to <span class="text-muted">(optional)</span>
        </label>
        <div class="input-icon">
          <?= icon('user') ?>
          <input class="input" type="text" id="contact_person" name="contact_person"
                 value="<?= old('contact_person') ?>" maxlength="140">
        </div>
      </div>

      <div class="field">
        <label class="label" for="industry">
          What they do <span class="text-muted">(optional)</span>
        </label>
        <input class="input" type="text" id="industry" name="industry"
               value="<?= old('industry') ?>" maxlength="120"
               placeholder="Hotel, school, law firm…">
      </div>
    </section>

    <section class="portal-card mb-16">
      <h2 class="portal-card__title mb-12">How we reach them</h2>
      <p class="text-sm text-muted mb-12">
        A phone number or an email address at the very least — without one
        of them there is nobody to call.
      </p>

      <div class="field">
        <label class="label" for="phone">Phone number</label>
        <div class="input-icon">
          <?= icon('phone') ?>
          <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
                 type="tel" id="phone" name="phone" value="<?= old('phone') ?>"
                 maxlength="30" inputmode="tel" placeholder="07XX XXX XXX">
        </div>
        <?= error_for($errors ?? [], 'phone') ?>
      </div>

      <div class="field">
        <label class="label" for="email">Email address</label>
        <div class="input-icon">
          <?= icon('mail') ?>
          <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
                 type="email" id="email" name="email" value="<?= old('email') ?>"
                 maxlength="160" inputmode="email">
        </div>
        <?= error_for($errors ?? [], 'email') ?>
      </div>

      <div class="field">
        <label class="label" for="alt_phone">
          Another number <span class="text-muted">(optional)</span>
        </label>
        <input class="input" type="tel" id="alt_phone" name="alt_phone"
               value="<?= old('alt_phone') ?>" maxlength="30" inputmode="tel">
      </div>

      <div class="field">
        <label class="label" for="address">
          Address <span class="text-muted">(optional)</span>
        </label>
        <input class="input" type="text" id="address" name="address"
               value="<?= old('address') ?>" maxlength="255">
      </div>

      <div class="field">
        <label class="label" for="city">
          Town <span class="text-muted">(optional)</span>
        </label>
        <div class="input-icon">
          <?= icon('map-pin') ?>
          <input class="input" type="text" id="city" name="city"
                 value="<?= old('city') ?>" maxlength="80">
        </div>
      </div>

      <div class="field">
        <label class="label" for="kra_pin">
          KRA PIN <span class="text-muted">(optional)</span>
        </label>
        <input class="input" type="text" id="kra_pin" name="kra_pin"
               value="<?= old('kra_pin') ?>" maxlength="30">
        <span class="field-hint">
          Needed on their invoice if they are a business. We can get it later.
        </span>
      </div>
    </section>

    <section class="portal-card mb-16">
      <h2 class="portal-card__title mb-12">What they want</h2>
      <p class="text-sm text-muted mb-12">
        The part only you can tell us. Say as much as you know — what the
        job is, roughly how big, and when they need it.
      </p>

      <div class="field">
        <label class="label" for="brief">The brief</label>
        <textarea class="input <?= isset($errors['brief']) ? 'has-error' : '' ?>"
                  id="brief" name="brief" rows="6" required
                  placeholder="e.g. Branding for three delivery vans and a shopfront sign. They want it before the end of the month."><?= old('brief') ?></textarea>
        <?= error_for($errors ?? [], 'brief') ?>
      </div>
    </section>

    <?php // Said plainly rather than discovered later. A partner who
          // expects to be able to send the quotation themselves will be
          // disappointed at the wrong moment. ?>
    <div class="alert alert--info mb-16">
      <?= icon('info') ?>
      <div class="alert__body">
        <strong>We do the quoting and the invoicing.</strong>
        You will see every quotation and invoice we raise for them, what
        they have paid and what is still owing — and you will be emailed
        as each one happens. Raising them, and taking the money, stays
        with us.
      </div>
    </div>

    <button class="btn btn--primary btn--block btn--lg" type="submit">
      Register them
      <?= icon('arrow-right') ?>
    </button>
  </form>
</div>
