<?php
/**
 * Discussion attached to a record. Dropped into any show page with:
 *
 *   $threadType = 'job'; $threadId = (int) $job['id'];
 *   $threadTitle = $job['job_number'];
 *   require APP_PATH . '/Views/partials/thread.php';
 *
 * @var string $threadType
 * @var int    $threadId
 * @var string $threadTitle
 */
$thread = \App\Services\RecordThread::load($threadType, $threadId);
?>

<div class="card">
  <div class="card__head">
    <?= icon('message') ?>
    <div>
      <div class="card__title">Discussion</div>
      <div class="card__sub">
        Kept with this record, so the reasoning is here when someone opens it later.
        Type <code>@name</code> to pull a colleague in.
      </div>
    </div>
  </div>

  <?php if ($thread && $thread['messages']): ?>
    <div class="card__body" style="max-height:340px;overflow-y:auto">
      <?php foreach ($thread['messages'] as $m): ?>
        <div class="flex gap-8" style="margin-bottom:12px">
          <span class="avatar" style="background:<?= e($m['avatar_color'] ?: '#0C2B4A') ?>">
            <?= e(initials($m['author'])) ?>
          </span>
          <div style="flex:1;min-width:0">
            <div class="text-xs text-muted">
              <strong><?= e($m["author"]) ?></strong>
              · <?= e(time_ago($m['created_at'])) ?>
            </div>
            <?php if ($m['body']): ?>
              <div class="text-sm" style="white-space:pre-wrap;word-break:break-word">
                <?= \App\Services\Mentions::highlight(e($m['body']), $thread['members']) ?>
              </div>
            <?php endif; ?>
            <?php if ($m['attachment_path']): ?>
              <a class="text-xs" href="<?= url('files/' . $m['attachment_path']) ?>"
                 target="_blank" rel="noopener">
                <?= icon('paperclip') ?> <?= e($m['attachment_name']) ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card__body">
      <p class="text-sm text-muted mb-0">
        Nothing discussed yet. Anything written here stays with this record.
      </p>
    </div>
  <?php endif; ?>

  <?php if (can('chat.use')): ?>
    <div class="card__body" style="border-top:1px solid var(--border)">
      <form method="post" action="<?= url('/threads/post') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="entity_type" value="<?= e($threadType) ?>">
        <input type="hidden" name="entity_id"   value="<?= (int) $threadId ?>">
        <input type="hidden" name="title"       value="<?= e($threadTitle) ?>">

        <div class="field">
          <textarea class="textarea" name="body" rows="2"
                    placeholder="Add a note for the team…"></textarea>
        </div>
        <div class="flex gap-8 items-center flex-wrap">
          <input class="input" type="file" name="attachment" style="flex:1;min-width:180px">
          <button class="btn btn--primary btn--sm" type="submit">
            <?= icon('send') ?> Post
          </button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>
