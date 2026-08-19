<?php
/**
 * Searching back through what the team said.
 *
 * @var string $q
 * @var array  $results
 * @var bool   $tooShort
 */
require_once APP_PATH . '/Views/partials/icons.php';

/** Show the matched words in context rather than the whole message. */
$excerpt = static function (string $body, string $needle): string {
    $pos = mb_stripos($body, $needle);

    if ($pos === false || mb_strlen($body) <= 160) {
        return $body;
    }

    $start = max(0, $pos - 60);

    return ($start > 0 ? '…' : '') . mb_substr($body, $start, 160) . '…';
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Search chat</h1>
    <div class="page-head__sub">Across every conversation you are part of.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/chat') ?>">
      <?= icon('arrow-left') ?> Back to chat
    </a>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/chat/search') ?>" class="flex gap-8">
      <input class="input" type="search" name="q" value="<?= e($q) ?>" autofocus
             placeholder="What was said, or who said it" style="flex:1">
      <button class="btn btn--primary" type="submit"><?= icon('search') ?> Search</button>
    </form>
    <?php if ($tooShort): ?>
      <span class="field-hint">Type at least three characters.</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($q !== '' && !$tooShort): ?>
  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">
          <?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?>
        </div>
        <?php if (count($results) >= 60): ?>
          <div class="card__sub">Showing the 60 most recent — narrow the search for older messages.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$results): ?>
      <div class="card__body">
        <div class="empty">
          <div class="empty__title">Nothing found</div>
          <p class="empty__text">
            No message you can see contains “<?= e($q) ?>”.
          </p>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url('/chat/' . $r['conversation_id']) ?>">
                    <?= $r['is_group'] ? '#' . e($r['channel_name']) : e($r['author']) ?>
                  </a>
                  <div class="text-sm" style="margin-top:2px">
                    <?= \App\Services\Mentions::highlight(
                          e($excerpt((string) $r['body'], $q)),
                          [['id' => 0, 'name' => $r['author']]]
                        ) ?>
                  </div>
                  <div class="text-xs text-muted">
                    <?= e($r['author']) ?> · <?= e(time_ago($r['created_at'])) ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
