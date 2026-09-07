<?php
/**
 * Introducing somebody.
 *
 * This is where a partner's commission starts: the introduction becomes a
 * lead, and when that lead becomes a customer the customer is tagged to
 * them for good.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$stageTone = static fn(string $s): string => match ($s) {
    'won'  => 'green',
    'lost' => 'grey',
    'new'  => 'navy',
    default => 'amber',
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Introduce a customer</h1>
    <p class="portal-lede">
      Tell us who they are and what they need. If they become a customer they
      are yours, and every invoice they ever pay earns you commission.
    </p>
  </div>

  <div class="portal-cols">
    <section class="portal-card">
      <form method="post" action="<?= url('/partners/refer') ?>">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="name">Their name</label>
          <div class="input-icon">
            <?= icon('user') ?>
            <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
                   type="text" id="name" name="name" value="<?= old('name') ?>"
                   required maxlength="180" autofocus>
          </div>
          <?= error_for($errors ?? [], 'name') ?>
        </div>

        <div class="field">
          <label class="label" for="company">
            Their business <span class="text-muted">(optional)</span>
          </label>
          <div class="input-icon">
            <?= icon('briefcase') ?>
            <input class="input" type="text" id="company" name="company"
                   value="<?= old('company') ?>" maxlength="180">
          </div>
        </div>

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
          <label class="label" for="email">
            Email address <span class="text-muted">(optional)</span>
          </label>
          <div class="input-icon">
            <?= icon('mail') ?>
            <input class="input" type="email" id="email" name="email"
                   value="<?= old('email') ?>" maxlength="160" inputmode="email">
          </div>
          <?= error_for($errors ?? [], 'email') ?>
        </div>

        <div class="field">
          <label class="label" for="requirement">What do they need?</label>
          <textarea class="input <?= isset($errors['requirement']) ? 'has-error' : '' ?>"
                    id="requirement" name="requirement" rows="4" required
                    placeholder="Signage for a new branch, 500 menus, a website — whatever they told you."><?= old('requirement') ?></textarea>
          <?= error_for($errors ?? [], 'requirement') ?>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">
          Send it to us
          <?= icon('arrow-right') ?>
        </button>
      </form>
    </section>

    <div class="portal-side">
      <section class="portal-card portal-card--flush">
        <header class="portal-card__head">
          <h2 class="portal-card__title">What you have sent us</h2>
        </header>

        <?php if (!$mine): ?>
          <div class="portal-empty portal-empty--inline">
            <p class="mb-0 text-sm text-muted">
              Nothing yet. Introductions you send appear here with where they
              have got to.
            </p>
          </div>
        <?php else: ?>
          <ul class="portal-list portal-list--tight">
            <?php foreach ($mine as $l): ?>
              <li>
                <span class="portal-list__row">
                  <span class="portal-list__main">
                    <span class="portal-list__title"><?= e($l['company'] ?: $l['name']) ?></span>
                    <span class="portal-list__meta">
                      <?= e($l['lead_number']) ?> &middot; <?= e(fdate($l['created_at'])) ?>
                    </span>
                  </span>
                  <span class="portal-list__side">
                    <span class="badge badge--<?= e($stageTone((string) $l['stage'])) ?>">
                      <?= $l['converted_client_id'] ? 'Now yours' : e(label_of((string) $l['stage'])) ?>
                    </span>
                  </span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
