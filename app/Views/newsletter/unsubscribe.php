<?php
/**
 * Leaving the newsletter.
 *
 * Asks for one click rather than unsubscribing on arrival: mail scanners
 * and link previews open every link in a message, and would otherwise
 * take people off the list who never asked to go.
 *
 * Deliberately says very little about the subscriber. Whoever holds the
 * link already knows whose it is, and a page that printed the address
 * would show it to anybody the email was forwarded to.
 */
?>

<?php if ($done): ?>

  <h1 class="login__title">You are unsubscribed</h1>
  <p class="login__intro">
    You will not receive our newsletter again. If you change your mind, you
    can subscribe from the foot of our website at any time.
  </p>
  <a class="btn btn--outline btn--block" href="/">Back to the website</a>

<?php elseif ($subscriber === null): ?>

  <h1 class="login__title">That link has expired</h1>
  <p class="login__intro">
    We could not find a subscription for this link. It may have been
    copied incompletely. If you are still receiving our newsletter, reply
    to it and ask to be removed, and we will do it by hand.
  </p>
  <a class="btn btn--outline btn--block" href="/">Back to the website</a>

<?php elseif ($subscriber['status'] === 'unsubscribed'): ?>

  <h1 class="login__title">Already unsubscribed</h1>
  <p class="login__intro">
    This address is not on our newsletter list, so there is nothing more
    to do.
  </p>
  <a class="btn btn--outline btn--block" href="/">Back to the website</a>

<?php else: ?>

  <h1 class="login__title">Unsubscribe?</h1>
  <p class="login__intro">
    You will stop receiving our newsletter. Nothing else changes: if you
    are a customer, your quotations, invoices and messages about your
    orders still come to you as normal.
  </p>

  <form method="post" action="<?= e(url('/newsletter/unsubscribe')) ?>">
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <button class="btn btn--primary btn--block login__submit" type="submit">Unsubscribe me</button>
  </form>

  <p class="login__help"><a href="/">No, take me back to the website</a></p>

<?php endif; ?>
