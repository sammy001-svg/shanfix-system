<?php
/**
 * Writing a post.
 *
 * One caption, one picture, several places it goes. The places are
 * ticked here rather than being separate posts, because writing the
 * same words three times is how three versions of them come to exist.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;
use App\Core\Settings;
use App\Services\Social\Posts;

$editing = $post !== null;
$action  = $editing ? url('/social/' . $post['id']) : url('/social');

$val = static function (string $key, $fallback = '') use ($post) {
    $old = Session::old($key, null);

    if ($old !== null && $old !== '') {
        return $old;
    }

    return $post[$key] ?? $fallback;
};

// Coming from a "+" on a day in the calendar: that day, at nine.
$preset = '';

if (!$editing && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['on'] ?? ''))) {
    $preset = $_GET['on'] . 'T09:00';
}

$when = (string) $val('scheduled_for', '');
$when = $when !== '' ? date('Y-m-d\TH:i', strtotime($when)) : $preset;

$chosen = array_map('intval', array_column($targets, 'account_id'));
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= e(url('/social')) ?>">Social</a> <span>/</span>
      <?= $editing ? e($post['ref']) : 'New post' ?>
    </div>
    <h1><?= $editing ? 'Edit post' : 'New post' ?></h1>
  </div>
</div>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">What it says</div></div>
        <div class="card__body">
          <div class="field">
            <label class="label" for="title">Call it <span class="req">*</span></label>
            <input class="input" id="title" name="title" required maxlength="140"
                   value="<?= e($val('title')) ?>"
                   placeholder="Graduation season — banners and gowns">
            <span class="field-hint">
              For the calendar and the lists. Not what the public sees.
            </span>
          </div>

          <div class="field">
            <label class="label" for="caption">Caption</label>
            <textarea class="input" id="caption" name="caption" rows="7"
                      maxlength="5000" data-counted><?= e($val('caption')) ?></textarea>
            <span class="field-hint">
              <span data-count>0</span> characters ·
              Instagram allows 2,200, X allows 280. Emoji are fine.
            </span>
          </div>

          <div class="field">
            <label class="label" for="hashtags">Hashtags</label>
            <input class="input" id="hashtags" name="hashtags" maxlength="500"
                   value="<?= e($val('hashtags')) ?>"
                   placeholder="printing nakuru banners">
            <span class="field-hint">
              Typed however you like — with or without the hash, spaces or commas.
              They are kept apart from the caption so they can be reused.
            </span>
          </div>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="link_url">Link</label>
              <input class="input" id="link_url" name="link_url" maxlength="255"
                     value="<?= e($val('link_url')) ?>" placeholder="shanfixtechnology.com/services">
            </div>

            <div class="field">
              <label class="label" for="first_comment">First comment</label>
              <input class="input" id="first_comment" name="first_comment" maxlength="500"
                     value="<?= e($val('first_comment')) ?>"
                     placeholder="Where the hashtags go on Instagram">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Picture</div>
            <div class="card__sub">
              Up to <?= (int) setting('social_images_max', 10) ?>. Resized on the way in,
              so the original size off the designer's machine is fine.
            </div>
          </div>
        </div>
        <div class="card__body">
          <div class="field">
            <input class="input" type="file" name="images[]" multiple accept="image/*">
          </div>
          <?php if ($editing): ?>
            <p class="text-xs text-muted">
              Anything already on this post stays. Remove one from the post itself.
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Where and when</div></div>
        <div class="card__body">
          <div class="field">
            <label class="label">Post it to</label>

            <?php if (!$accounts): ?>
              <p class="text-sm text-muted">
                No profiles set up yet.
                <a href="<?= e(url('/social/accounts')) ?>">Add the company's pages</a>
                and they will appear here.
              </p>
            <?php endif; ?>

            <?php foreach ($accounts as $account): ?>
              <label class="check-row">
                <input type="checkbox" name="accounts[]" value="<?= (int) $account['id'] ?>"
                       <?= in_array((int) $account['id'], $chosen, true) ? 'checked' : '' ?>>
                <span>
                  <strong><?= e($account['name']) ?></strong>
                  <span class="text-xs text-muted d-block">
                    <?= e(Settings::SOCIAL[$account['network']] ?? $account['network']) ?>
                    <?= $account['handle'] ? ' · ' . e($account['handle']) : '' ?>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="field">
            <label class="label" for="scheduled_for">Going out</label>
            <input class="input" type="datetime-local" id="scheduled_for"
                   name="scheduled_for" value="<?= e($when) ?>">
            <span class="field-hint">
              Leave it empty and it waits in the holding list beside the calendar.
            </span>
          </div>

          <div class="field">
            <label class="label" for="post_type">Kind</label>
            <select class="select" id="post_type" name="post_type">
              <?php foreach (Posts::TYPES as $key => $label): ?>
                <option value="<?= e($key) ?>"
                  <?= $val('post_type', 'post') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="campaign_id">Part of</label>
            <select class="select" id="campaign_id" name="campaign_id">
              <option value="">Nothing in particular</option>
              <?php foreach ($campaigns as $campaign): ?>
                <option value="<?= (int) $campaign['id'] ?>"
                  <?= (int) $val('campaign_id') === (int) $campaign['id'] ? 'selected' : '' ?>>
                  <?= e($campaign['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="owner_id">Written by</label>
            <select class="select" id="owner_id" name="owner_id">
              <option value="">Nobody in particular</option>
              <?php foreach ($people as $person): ?>
                <option value="<?= (int) $person['id'] ?>"
                  <?= (int) $val('owner_id', \App\Core\Auth::id()) === (int) $person['id'] ? 'selected' : '' ?>>
                  <?= e($person['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="notes">Notes for the team</label>
            <textarea class="input" id="notes" name="notes" rows="3"
                      maxlength="1000"><?= e($val('notes')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Start it' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= e($editing ? url('/social/' . $post['id']) : url('/social')) ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
