<?php
/**
 * The Request a demo dialog, shared by every system we sell.
 *
 * It was written into the Sacco Pro page first. Adding it to the school,
 * till and ERP pages by copying it would have meant four copies of the
 * same form, the same validation and the same JavaScript — and the
 * first fix to any of them landing in one copy.
 *
 * A page calls demo_request_modal() once, anywhere after its content,
 * and puts data-demo-open on as many buttons as it likes. The dialog
 * opens from all of them.
 *
 * Each product asks its own size question, because a sacco counts
 * members, a school counts pupils and a shop counts tills. The answer
 * is sent as the words the page offered, so nothing has to agree with a
 * list kept somewhere else.
 */

if (!function_exists('demo_request_modal')) {

    /**
     * @param array{
     *   product:    string,              the key the system knows it by
     *   name:       string,              what it is called, for the heading
     *   org_label?: string,              "Sacco", "School", "Business"
     *   size_label?:string,              "Roughly how many members"
     *   sizes?:     array<int,string>,   the bands offered, as words
     *   source?:    string               which page this came from
     * } $o
     */
    function demo_request_modal(array $o): void
    {
        $product   = $o['product'];
        $name      = $o['name'];
        $orgLabel  = $o['org_label']  ?? 'Organisation';
        $sizeLabel = $o['size_label'] ?? 'Roughly how big are you';
        $sizes     = $o['sizes']      ?? [];
        $source    = $o['source']     ?? basename($_SERVER['SCRIPT_NAME'] ?? 'site');

        // The styles travel with the dialog rather than with any one
        // page's stylesheet, so a page that includes this gets a
        // working dialog and nothing else to remember.
        static $styled = false;

        if (!$styled) {
            $styled = true;
            echo '<link rel="stylesheet" href="' . htmlspecialchars(site_asset('demo-request.css')) . '">';
        }
        ?>
<div class="demo-modal" id="demoModal" hidden>
    <div class="demo-modal__backdrop" data-demo-close></div>

    <div class="demo-modal__panel" role="dialog" aria-modal="true"
         aria-labelledby="demoTitle" aria-describedby="demoBlurb">
        <button type="button" class="demo-modal__x" data-demo-close aria-label="Close">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                 stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div class="demo-modal__body" id="demoFormWrap">
            <h2 id="demoTitle">Request a <?= htmlspecialchars($name) ?> demo</h2>
            <p id="demoBlurb">
                We will set up an account with sample data and send you the link
                and login — by email and by text, so you have it either way.
            </p>

            <form id="demoForm" novalidate>
                <input type="hidden" name="product" value="<?= htmlspecialchars($product) ?>">
                <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">

                <?php // Robots fill in every field they find. People cannot
                      // see this one. ?>
                <div class="demo-hp" aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="demo-grid">
                    <div class="demo-field">
                        <label for="d_name">Your name <span>*</span></label>
                        <input id="d_name" name="name" required autocomplete="name">
                    </div>

                    <div class="demo-field">
                        <label for="d_role">Your role</label>
                        <input id="d_role" name="role" autocomplete="organization-title">
                    </div>

                    <div class="demo-field demo-field--wide">
                        <label for="d_org"><?= htmlspecialchars($orgLabel) ?></label>
                        <input id="d_org" name="organisation" autocomplete="organization">
                    </div>

                    <div class="demo-field">
                        <label for="d_email">Email <span>*</span></label>
                        <input id="d_email" name="email" type="email" required autocomplete="email">
                    </div>

                    <div class="demo-field">
                        <label for="d_phone">Phone <span>*</span></label>
                        <input id="d_phone" name="phone" type="tel" required autocomplete="tel"
                               placeholder="07xx xxx xxx">
                    </div>

                    <?php if ($sizes): ?>
                    <div class="demo-field demo-field--wide">
                        <label for="d_members"><?= htmlspecialchars($sizeLabel) ?></label>
                        <select id="d_members" name="members">
                            <option value="">Prefer not to say</option>
                            <?php foreach ($sizes as $band): ?>
                                <option value="<?= htmlspecialchars($band) ?>"><?= htmlspecialchars($band) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="demo-field demo-field--wide">
                        <label for="d_message">Anything you particularly want to see</label>
                        <textarea id="d_message" name="message" rows="3"></textarea>
                    </div>
                </div>

                <p class="demo-error" id="demoError" hidden></p>

                <button type="submit" class="demo-btn demo-btn--primary demo-btn--block" id="demoSubmit">
                    Send the request
                </button>

                <p class="demo-small">
                    We use these details to set up and send you the demo. Nothing else.
                </p>
            </form>
        </div>

        <div class="demo-modal__body demo-done" id="demoDone" hidden>
            <span class="demo-done__tick" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"
                     stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <h2>That is with us</h2>
            <p id="demoDoneMsg"></p>
            <button type="button" class="demo-btn demo-btn--ghost" data-demo-close>Close</button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(site_asset('demo-request.js')) ?>" defer></script>
        <?php
    }
}
