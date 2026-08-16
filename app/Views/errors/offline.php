<?php
/**
 * Shown when a page is requested that the device has never cached and
 * there is no connection to fetch it with.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="offline-page">
  <div class="card offline-page__card">
    <div class="card__body" style="text-align:center">

      <span class="sidebar__mark" style="margin:0 auto 14px">SF</span>

      <h1 style="font-size:20px;margin:0 0 8px">You are offline</h1>

      <p class="text-muted mb-16">
        This page has not been saved to this device yet, so it cannot be
        opened without a connection.
      </p>

      <div class="alert alert--info" style="text-align:left">
        <?= icon('info') ?>
        <div class="alert__body text-sm">
          <strong>Anything you opened earlier is still available.</strong>
          Go back and pick it up from there. Stage moves, checklist ticks and
          notes you make offline are saved on this device and sent
          automatically the moment you are back on the network.
        </div>
      </div>

      <div class="flex gap-8 mt-16" style="justify-content:center">
        <button class="btn btn--outline" type="button" data-offline-back>Go back</button>
        <button class="btn btn--primary" type="button" data-offline-retry>Try again</button>
      </div>

    </div>
  </div>
</div>

<?php // Inline handlers are blocked by the CSP, so wire these with a nonce. ?>
<script nonce="<?= e(csp_nonce()) ?>">
  document.querySelector('[data-offline-back]')
    .addEventListener('click', function () { history.back(); });
  document.querySelector('[data-offline-retry]')
    .addEventListener('click', function () { location.reload(); });
</script>
