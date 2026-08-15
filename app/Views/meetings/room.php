<?php
require_once APP_PATH . '/Views/partials/icons.php';

// $base is the URL prefix for this room's endpoints — /meetings/{id} when
// signed in, /join/{token} for a guest. Everything below is identical
// either way, which is the point.
$isGuest = str_contains($base, '/join/');
?>

<div class="room">
  <header class="room__bar">
    <div class="room__id">
      <span class="room__dot" data-live-dot></span>
      <div>
        <div class="room__title"><?= e($meeting['title']) ?></div>
        <div class="room__sub">
          <?= e(fdate($meeting['scheduled_at'])) ?> ·
          <?= e(date('H:i', strtotime($meeting['scheduled_at']))) ?> ·
          <?= (int) $meeting['duration_mins'] ?> min
        </div>
      </div>
    </div>

    <div class="room__actions">
      <button class="btn btn--outline btn--sm" type="button" data-share-screen>
        <?= icon('monitor') ?> <span data-share-label>Share my screen</span>
      </button>
      <button class="btn btn--outline btn--sm" type="button" data-toggle-mic aria-pressed="false">
        <?= icon('mic') ?> <span data-mic-label>Turn on microphone</span>
      </button>
      <a class="btn btn--ghost btn--sm" href="<?= $isGuest ? '#' : url('/meetings/' . $meeting['id']) ?>"
         <?= $isGuest ? 'data-leave' : '' ?>>
        <?= icon('x') ?> Leave
      </a>
    </div>
  </header>

  <div class="room__body">
    <section class="room__stage">
      <div class="stage" data-stage>
        <div class="stage__idle" data-stage-idle>
          <?= icon('monitor') ?>
          <div class="stage__idle-title">Nobody is sharing yet</div>
          <p class="stage__idle-text">
            Press <strong>Share my screen</strong> to show your screen to everyone
            in this room. Your browser will ask which window to share.
          </p>
        </div>
        <video class="stage__video" data-stage-video autoplay playsinline hidden></video>
      </div>

      <div class="room__status" data-status role="status" aria-live="polite"></div>
    </section>

    <aside class="room__side">
      <div class="room__side-head">
        <?= icon('edit') ?>
        <div>
          <div class="room__side-title">Minutes</div>
          <div class="room__side-sub">Typed as you go. Everyone in the room sees them.</div>
        </div>
      </div>

      <div class="notes" data-notes>
        <?php foreach ($notes as $n): ?>
          <div class="note note--<?= e($n['kind']) ?>">
            <div class="note__meta">
              <span class="note__who"><?= e($n['author_name']) ?></span>
              <span class="note__at"><?= e(date('H:i', strtotime($n['created_at']))) ?></span>
            </div>
            <div class="note__body"><?= nl2br(e($n['body'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <form class="notes__form" data-note-form>
        <?= csrf_field() ?>
        <div class="notes__kinds">
          <label><input type="radio" name="kind" value="note" checked> Note</label>
          <label><input type="radio" name="kind" value="decision"> Decision</label>
          <label><input type="radio" name="kind" value="action"> Action</label>
        </div>
        <textarea class="textarea" name="body" rows="2" required
                  placeholder="What was said, decided or agreed…"></textarea>
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?> Add</button>
      </form>
    </aside>
  </div>
</div>

<?php
  // The room's own settings, handed to the script as data rather than
  // written into it — the script is a cached static file and cannot carry
  // per-meeting values.
?>
<div data-room
     data-base="<?= e($base) ?>"
     data-me="<?= e($meName) ?>"
     data-last-note="<?= (int) ($notes ? end($notes)['id'] : 0) ?>"
     data-ice="<?= e(json_encode($ice, JSON_UNESCAPED_SLASHES)) ?>"
     hidden></div>
