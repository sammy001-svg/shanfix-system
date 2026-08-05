<?php
/**
 * @var array $pager  From Controller::paginate()
 * Preserves existing query-string filters when changing page.
 */
if (($pager['pages'] ?? 1) <= 1) {
    return;
}

$page  = $pager['page'];
$pages = $pager['pages'];

// Window of pages around the current one.
$start = max(1, $page - 2);
$end   = min($pages, $start + 4);
$start = max(1, $end - 4);
?>
<nav class="pagination" aria-label="Pagination">
  <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>"
     href="<?= e(query_string(['page' => $page - 1])) ?>" aria-label="Previous page">&lsaquo;</a>

  <?php if ($start > 1): ?>
    <a href="<?= e(query_string(['page' => 1])) ?>">1</a>
    <?php if ($start > 2): ?><span class="is-disabled">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <?php if ($i === $page): ?>
      <span class="is-current" aria-current="page"><?= $i ?></span>
    <?php else: ?>
      <a href="<?= e(query_string(['page' => $i])) ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span class="is-disabled">…</span><?php endif; ?>
    <a href="<?= e(query_string(['page' => $pages])) ?>"><?= $pages ?></a>
  <?php endif; ?>

  <a class="<?= $page >= $pages ? 'is-disabled' : '' ?>"
     href="<?= e(query_string(['page' => $page + 1])) ?>" aria-label="Next page">&rsaquo;</a>
</nav>
