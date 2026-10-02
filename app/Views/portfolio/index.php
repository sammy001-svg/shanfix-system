<?php
/**
 * The portfolio, as the website shows it.
 *
 * Three lists behind three tabs. Each one is a form on the left and what
 * is already there on the right, which is the shape the testimonials
 * screen uses — the same job, done the same way, so nobody has to learn
 * a second screen to do it.
 *
 * Anything switched off here is simply not on the website. A section
 * with nothing switched on does not appear at all, rather than falling
 * back to something invented, which is what the portfolio page used to
 * do with three case studies that were never ours.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$row = $editing ?? [];

$tabUrl = static fn(string $key): string => url('/portfolio?tab=' . $key);

/** How many of a list are actually on the website. */
$live = static fn(array $rows): int => count(array_filter(
    $rows,
    static fn(array $r): bool => (int) $r['is_active'] === 1
));

$liveProjects = $live($projects);
$liveWork     = $live($work);
$liveClients  = $live($clients);

/** The picture the website would lead with, if there is one. */
$lead = static function (array $all, int $id): ?array {
    return ($all[$id] ?? [])[0] ?? null;
};

$photo = static fn(string $kind, array $img): string =>
    url('/catalogue/photo/' . $kind . '/' . (int) $img['id']) . '?size=thumb';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Portfolio</h1>
    <div class="page-head__sub">
      What the website shows of our work, and who we have done it for.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="/portfolio.php" target="_blank" rel="noopener">
      <?= icon('globe') ?> See the page
    </a>
  </div>
</div>

<nav class="tabs mb-16">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>">
      <?= e($label) ?>
      <span class="badge badge--grey">
        <?= $key === 'projects' ? $liveProjects : ($key === 'work' ? $liveWork : $liveClients) ?>
      </span>
    </a>
  <?php endforeach; ?>
</nav>

<?php // ══════════════════════════ Websites & systems ══════════════════ ?>
<?php if ($tab === 'projects'): ?>

  <div class="alert alert--info mb-16">
    <?= icon('info') ?>
    <div class="alert__body">
      Name the client only where they are happy to be named. If a link is
      given, the website sends visitors straight to the running site —
      so check it still works before switching the entry on.
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= $row ? 'Edit project' : 'Add a project' ?></div>
        <?php if ($row): ?>
          <a class="btn btn--ghost btn--sm" href="<?= e($tabUrl('projects')) ?>">Cancel</a>
        <?php endif; ?>
      </div>

      <form class="card__body" method="post" action="<?= e(url('/portfolio/projects')) ?>"
            enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($row): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="p_title">What it is called <span class="req">*</span></label>
            <input class="input" id="p_title" name="title" maxlength="160" required
                   value="<?= e($row['title'] ?? '') ?>"
                   placeholder="e.g. Riverside Hotel booking site">
          </div>

          <div class="field">
            <label class="label" for="p_kind">Kind</label>
            <select class="select" id="p_kind" name="kind">
              <?php foreach ($kinds as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($row['kind'] ?? 'website') === $value ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">Websites and systems are listed separately on the page.</span>
          </div>

          <div class="field">
            <label class="label" for="p_client">Client</label>
            <input class="input" id="p_client" name="client_name" maxlength="160"
                   value="<?= e($row['client_name'] ?? '') ?>"
                   placeholder="Leave blank if they would rather not be named">
          </div>

          <div class="field">
            <label class="label" for="p_url">Link</label>
            <input class="input" id="p_url" name="live_url" maxlength="255"
                   value="<?= e($row['live_url'] ?? '') ?>"
                   placeholder="riversidehotel.co.ke">
            <span class="field-hint">https:// is added if you leave it off.</span>
          </div>
        </div>

        <div class="field">
          <label class="label" for="p_summary">In one line</label>
          <input class="input" id="p_summary" name="summary" maxlength="400"
                 value="<?= e($row['summary'] ?? '') ?>"
                 placeholder="Room booking, M-Pesa payment and a manager's dashboard.">
          <span class="field-hint">This is what shows on the card. Keep it short.</span>
        </div>

        <div class="field">
          <label class="label" for="p_desc">The longer version</label>
          <textarea class="textarea" id="p_desc" name="description" rows="4"
                    placeholder="What the job actually was, and what it solved for them."><?= e($row['description'] ?? '') ?></textarea>
        </div>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="p_built">Built with</label>
            <input class="input" id="p_built" name="built_with" maxlength="255"
                   value="<?= e($row['built_with'] ?? '') ?>"
                   placeholder="PHP, MySQL, M-Pesa">
            <span class="field-hint">Separate with commas. Shown as tags.</span>
          </div>

          <div class="field">
            <label class="label" for="p_done">Finished</label>
            <input class="input" type="date" id="p_done" name="completed_on"
                   value="<?= e($row['completed_on'] ?? '') ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="p_images">Screenshots</label>
          <input class="input" type="file" id="p_images" name="images[]" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp">
          <span class="field-hint">
            Up to <?= (int) $caps['project'] ?>. The first is the one the page leads with.
          </span>
        </div>

        <?php if ($row && !empty($images['project'][(int) $row['id']])): ?>
          <div class="thumb-grid mb-12">
            <?php foreach ($images['project'][(int) $row['id']] as $img): ?>
              <span class="thumb <?= (int) $img['is_primary'] === 1 ? 'thumb--primary' : '' ?>">
                <img src="<?= e($photo('project', $img)) ?>" alt="" loading="lazy">
                <?php if ((int) $img['is_primary'] === 1): ?>
                  <span class="thumb__badge">Lead</span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
          <p class="field-hint mb-12">Manage these below, under the entry.</p>
        <?php endif; ?>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="p_sort">Order</label>
            <input class="input" type="number" id="p_sort" name="sort_order" min="0" max="9999"
                   value="<?= (int) ($row['sort_order'] ?? 0) ?>">
            <span class="field-hint">Lower comes first.</span>
          </div>
        </div>

        <label class="check mb-8">
          <input type="checkbox" name="is_featured" value="1"
                 <?= (int) ($row['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Feature it</strong>
            <span>Given the large treatment at the top of the page.</span>
          </span>
        </label>

        <label class="check mb-16">
          <input type="checkbox" name="is_active" value="1"
                 <?= (int) ($row['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Show on the website</strong>
            <span>Leave off until you are happy for it to be public.</span>
          </span>
        </label>

        <button class="btn btn--primary" type="submit">
          <?= icon('save') ?> <?= $row ? 'Save changes' : 'Add project' ?>
        </button>
      </form>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">What is listed</div>
          <div class="card__sub">
            <?= $liveProjects ?> of <?= count($projects) ?> showing on the website
          </div>
        </div>
      </div>
      <div class="card__body">
        <?php if (!$projects): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('layers') ?></div>
            <div class="empty__title">Nothing here yet</div>
            <p class="text-sm text-muted">
              The website leaves this section out entirely until something is added.
            </p>
          </div>
        <?php else: ?>
          <?php foreach ($projects as $p): ?>
            <?php $pid = (int) $p['id']; $img = $lead($images['project'], $pid); ?>
            <div class="list-row <?= (int) $p['is_active'] === 0 ? 'is-muted' : '' ?>">
              <?php if ($img): ?>
                <img class="list-row__thumb" src="<?= e($photo('project', $img)) ?>" alt="" loading="lazy">
              <?php endif; ?>

              <div class="list-row__main">
                <div class="list-row__title">
                  <?= e($p['title']) ?>
                  <span class="badge badge--navy"><?= e($kinds[$p['kind']] ?? $p['kind']) ?></span>
                  <?php if ((int) $p['is_featured'] === 1): ?>
                    <span class="badge badge--amber">Featured</span>
                  <?php endif; ?>
                  <?php if ((int) $p['is_active'] === 0): ?>
                    <span class="badge badge--grey">Hidden</span>
                  <?php endif; ?>
                </div>
                <div class="list-row__sub">
                  <?= $p['client_name'] ? 'for ' . e($p['client_name']) . ' · ' : '' ?>
                  <?= e($p['summary'] ?: 'No summary') ?>
                </div>
              </div>

              <div class="list-row__actions">
                <a class="btn btn--ghost btn--sm"
                   href="<?= e(url('/portfolio?tab=projects&edit=' . $pid)) ?>">Edit</a>

                <form method="post" action="<?= e(url('/portfolio/projects/' . $pid . '/toggle')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn--ghost btn--sm" type="submit">
                    <?= (int) $p['is_active'] === 1 ? 'Hide' : 'Show' ?>
                  </button>
                </form>

                <?php if (can('records.delete')): ?>
                  <form method="post" action="<?= e(url('/portfolio/projects/' . $pid . '/delete')) ?>"
                        onsubmit="return confirm('Remove <?= e(addslashes($p['title'])) ?> and its pictures?');">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm text-red" type="submit"><?= icon('trash') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($row && (int) $row['id'] === $pid && !empty($images['project'][$pid])): ?>
              <div class="list-row__panel">
                <div class="thumb-grid">
                  <?php foreach ($images['project'][$pid] as $img): ?>
                    <span class="thumb <?= (int) $img['is_primary'] === 1 ? 'thumb--primary' : '' ?>">
                      <img src="<?= e($photo('project', $img)) ?>" alt="" loading="lazy">
                      <span class="thumb__tools">
                        <?php if ((int) $img['is_primary'] !== 1): ?>
                          <form method="post"
                                action="<?= e(url('/portfolio/projects/' . $pid . '/images/' . (int) $img['id'] . '/primary')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" title="Lead with this one"><?= icon('star') ?></button>
                          </form>
                        <?php endif; ?>
                        <form method="post"
                              action="<?= e(url('/portfolio/projects/' . $pid . '/images/' . (int) $img['id'] . '/delete')) ?>">
                          <?= csrf_field() ?>
                          <button type="submit" title="Remove"><?= icon('trash') ?></button>
                        </form>
                      </span>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php // ══════════════════════════ Printing & branding ══════════════════ ?>
<?php elseif ($tab === 'work'): ?>

  <div class="alert alert--info mb-16">
    <?= icon('info') ?>
    <div class="alert__body">
      This is a gallery, so the photographs do the work. A clear picture of
      a finished job says more than a paragraph about it. The category is
      what the filter buttons on the page are built from — keep the
      spelling the same and they group properly.
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= $row ? 'Edit job' : 'Add a job' ?></div>
        <?php if ($row): ?>
          <a class="btn btn--ghost btn--sm" href="<?= e($tabUrl('work')) ?>">Cancel</a>
        <?php endif; ?>
      </div>

      <form class="card__body" method="post" action="<?= e(url('/portfolio/work')) ?>"
            enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($row): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="w_title">What it was <span class="req">*</span></label>
            <input class="input" id="w_title" name="title" maxlength="160" required
                   value="<?= e($row['title'] ?? '') ?>"
                   placeholder="e.g. Full vehicle wrap, Toyota Hiace">
          </div>

          <div class="field">
            <label class="label" for="w_cat">Category</label>
            <input class="input" id="w_cat" name="category" maxlength="80"
                   list="work-categories"
                   value="<?= e($row['category'] ?? '') ?>"
                   placeholder="Vehicle branding">
            <datalist id="work-categories">
              <?php
                $seen = [];
                foreach ($work as $w) {
                    if ($w['category'] && !isset($seen[$w['category']])) {
                        $seen[$w['category']] = true;
                        echo '<option value="' . e($w['category']) . '">';
                    }
                }
              ?>
            </datalist>
            <span class="field-hint">Pick an existing one where it fits.</span>
          </div>

          <div class="field">
            <label class="label" for="w_client">Client</label>
            <input class="input" id="w_client" name="client_name" maxlength="160"
                   value="<?= e($row['client_name'] ?? '') ?>">
          </div>

          <div class="field">
            <label class="label" for="w_done">Finished</label>
            <input class="input" type="date" id="w_done" name="completed_on"
                   value="<?= e($row['completed_on'] ?? '') ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="w_desc">A line about it</label>
          <input class="input" id="w_desc" name="description" maxlength="400"
                 value="<?= e($row['description'] ?? '') ?>"
                 placeholder="Design, print, laminate and fitting, done overnight.">
        </div>

        <div class="field">
          <label class="label" for="w_images">Photographs</label>
          <input class="input" type="file" id="w_images" name="images[]" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp">
          <span class="field-hint">
            Up to <?= (int) $caps['work'] ?>. Several angles of the same job is the point.
          </span>
        </div>

        <div class="field">
          <label class="label" for="w_sort">Order</label>
          <input class="input" type="number" id="w_sort" name="sort_order" min="0" max="9999"
                 value="<?= (int) ($row['sort_order'] ?? 0) ?>">
        </div>

        <label class="check mb-8">
          <input type="checkbox" name="is_featured" value="1"
                 <?= (int) ($row['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Feature it</strong>
            <span>Shown larger, near the top of the gallery.</span>
          </span>
        </label>

        <label class="check mb-16">
          <input type="checkbox" name="is_active" value="1"
                 <?= (int) ($row['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Show on the website</strong>
            <span>A job with no photograph on it is worth leaving off.</span>
          </span>
        </label>

        <button class="btn btn--primary" type="submit">
          <?= icon('save') ?> <?= $row ? 'Save changes' : 'Add job' ?>
        </button>
      </form>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">The gallery</div>
          <div class="card__sub"><?= $liveWork ?> of <?= count($work) ?> showing</div>
        </div>
      </div>
      <div class="card__body">
        <?php if (!$work): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('image') ?></div>
            <div class="empty__title">No jobs yet</div>
            <p class="text-sm text-muted">The gallery section stays off the website until there are.</p>
          </div>
        <?php else: ?>
          <?php foreach ($work as $w): ?>
            <?php $wid = (int) $w['id']; $img = $lead($images['work'], $wid); ?>
            <div class="list-row <?= (int) $w['is_active'] === 0 ? 'is-muted' : '' ?>">
              <?php if ($img): ?>
                <img class="list-row__thumb" src="<?= e($photo('work', $img)) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="list-row__thumb list-row__thumb--empty"><?= icon('image') ?></span>
              <?php endif; ?>

              <div class="list-row__main">
                <div class="list-row__title">
                  <?= e($w['title']) ?>
                  <?php if ($w['category']): ?>
                    <span class="badge badge--navy"><?= e($w['category']) ?></span>
                  <?php endif; ?>
                  <?php if ((int) $w['is_active'] === 0): ?>
                    <span class="badge badge--grey">Hidden</span>
                  <?php endif; ?>
                  <?php if (!$img): ?>
                    <span class="badge badge--amber">No photo</span>
                  <?php endif; ?>
                </div>
                <div class="list-row__sub">
                  <?= $w['client_name'] ? 'for ' . e($w['client_name']) . ' · ' : '' ?>
                  <?= e($w['description'] ?: 'No description') ?>
                </div>
              </div>

              <div class="list-row__actions">
                <a class="btn btn--ghost btn--sm"
                   href="<?= e(url('/portfolio?tab=work&edit=' . $wid)) ?>">Edit</a>

                <form method="post" action="<?= e(url('/portfolio/work/' . $wid . '/toggle')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn--ghost btn--sm" type="submit">
                    <?= (int) $w['is_active'] === 1 ? 'Hide' : 'Show' ?>
                  </button>
                </form>

                <?php if (can('records.delete')): ?>
                  <form method="post" action="<?= e(url('/portfolio/work/' . $wid . '/delete')) ?>"
                        onsubmit="return confirm('Remove <?= e(addslashes($w['title'])) ?> and its photographs?');">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm text-red" type="submit"><?= icon('trash') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($row && (int) $row['id'] === $wid && !empty($images['work'][$wid])): ?>
              <div class="list-row__panel">
                <div class="thumb-grid">
                  <?php foreach ($images['work'][$wid] as $img): ?>
                    <span class="thumb <?= (int) $img['is_primary'] === 1 ? 'thumb--primary' : '' ?>">
                      <img src="<?= e($photo('work', $img)) ?>" alt="" loading="lazy">
                      <span class="thumb__tools">
                        <?php if ((int) $img['is_primary'] !== 1): ?>
                          <form method="post"
                                action="<?= e(url('/portfolio/work/' . $wid . '/images/' . (int) $img['id'] . '/primary')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" title="Lead with this one"><?= icon('star') ?></button>
                          </form>
                        <?php endif; ?>
                        <form method="post"
                              action="<?= e(url('/portfolio/work/' . $wid . '/images/' . (int) $img['id'] . '/delete')) ?>">
                          <?= csrf_field() ?>
                          <button type="submit" title="Remove"><?= icon('trash') ?></button>
                        </form>
                      </span>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php // ══════════════════════════ Clients ═════════════════════════════ ?>
<?php else: ?>

  <div class="alert alert--warning mb-16">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      A logo belongs to the client, and putting it on our website says in
      public that they are a customer of ours. Only add one where they
      have agreed to it, and note below who agreed and when — that note
      stays here and is never published.
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= $row ? 'Edit client' : 'Add a client' ?></div>
        <?php if ($row): ?>
          <a class="btn btn--ghost btn--sm" href="<?= e($tabUrl('clients')) ?>">Cancel</a>
        <?php endif; ?>
      </div>

      <form class="card__body" method="post" action="<?= e(url('/portfolio/clients')) ?>"
            enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($row): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>

        <div class="field">
          <label class="label" for="c_name">Client <span class="req">*</span></label>
          <input class="input" id="c_name" name="name" maxlength="160" required
                 value="<?= e($row['name'] ?? '') ?>" placeholder="Riverside Hotel">
        </div>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="c_url">Their website</label>
            <input class="input" id="c_url" name="website_url" maxlength="255"
                   value="<?= e($row['website_url'] ?? '') ?>">
          </div>

          <div class="field">
            <label class="label" for="c_sort">Order</label>
            <input class="input" type="number" id="c_sort" name="sort_order" min="0" max="9999"
                   value="<?= (int) ($row['sort_order'] ?? 0) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="c_did">What we did for them</label>
          <input class="input" id="c_did" name="what_we_did" maxlength="255"
                 value="<?= e($row['what_we_did'] ?? '') ?>"
                 placeholder="Booking website and signage">
          <span class="field-hint">One line, shown under the logo.</span>
        </div>

        <div class="field">
          <label class="label" for="c_logo">Logo</label>
          <input class="input" type="file" id="c_logo" name="images[]"
                 accept="image/jpeg,image/png,image/gif,image/webp">
          <span class="field-hint">
            One. A new one replaces what is there. PNG with a transparent
            background sits best on the page.
          </span>
        </div>

        <?php if ($row && !empty($images['client'][(int) $row['id']])): ?>
          <?php $logo = $images['client'][(int) $row['id']][0]; ?>
          <div class="thumb-grid mb-12">
            <span class="thumb">
              <img src="<?= e($photo('client', $logo)) ?>" alt="" loading="lazy">
            </span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="c_perm">Who agreed to us showing it</label>
          <input class="input" id="c_perm" name="permission_note" maxlength="255"
                 value="<?= e($row['permission_note'] ?? '') ?>"
                 placeholder="Jane Wanjiku, by email, 12 Sep 2026">
          <span class="field-hint">For our records. Never shown on the website.</span>
        </div>

        <label class="check mb-8">
          <input type="checkbox" name="is_featured" value="1"
                 <?= (int) ($row['is_featured'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Show on the home page too</strong>
            <span>In the strip of logos under "who we have worked for".</span>
          </span>
        </label>

        <label class="check mb-16">
          <input type="checkbox" name="is_active" value="1"
                 <?= (int) ($row['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Show on the website</strong>
            <span>Leave off until their permission is in hand.</span>
          </span>
        </label>

        <button class="btn btn--primary" type="submit">
          <?= icon('save') ?> <?= $row ? 'Save changes' : 'Add client' ?>
        </button>
      </form>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Who we have worked for</div>
          <div class="card__sub"><?= $liveClients ?> of <?= count($clients) ?> showing</div>
        </div>
      </div>
      <div class="card__body">
        <?php if (!$clients): ?>
          <div class="empty">
            <div class="empty__icon"><?= icon('users') ?></div>
            <div class="empty__title">No clients listed</div>
            <p class="text-sm text-muted">
              The home page and the portfolio page both leave this out until there are.
            </p>
          </div>
        <?php else: ?>
          <?php foreach ($clients as $c): ?>
            <?php $cid = (int) $c['id']; $logo = $lead($images['client'], $cid); ?>
            <div class="list-row <?= (int) $c['is_active'] === 0 ? 'is-muted' : '' ?>">
              <?php if ($logo): ?>
                <img class="list-row__thumb list-row__thumb--contain"
                     src="<?= e($photo('client', $logo)) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="list-row__thumb list-row__thumb--empty"><?= icon('users') ?></span>
              <?php endif; ?>

              <div class="list-row__main">
                <div class="list-row__title">
                  <?= e($c['name']) ?>
                  <?php if ((int) $c['is_featured'] === 1): ?>
                    <span class="badge badge--green">Home page</span>
                  <?php endif; ?>
                  <?php if ((int) $c['is_active'] === 0): ?>
                    <span class="badge badge--grey">Hidden</span>
                  <?php endif; ?>
                  <?php if (!$logo): ?>
                    <span class="badge badge--amber">No logo</span>
                  <?php endif; ?>
                </div>
                <div class="list-row__sub">
                  <?= e($c['what_we_did'] ?: 'Nothing noted') ?>
                  <?php if (!$c['permission_note']): ?>
                    <span class="text-amber"> · no permission recorded</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="list-row__actions">
                <a class="btn btn--ghost btn--sm"
                   href="<?= e(url('/portfolio?tab=clients&edit=' . $cid)) ?>">Edit</a>

                <form method="post" action="<?= e(url('/portfolio/clients/' . $cid . '/toggle')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn--ghost btn--sm" type="submit">
                    <?= (int) $c['is_active'] === 1 ? 'Hide' : 'Show' ?>
                  </button>
                </form>

                <?php if (can('records.delete')): ?>
                  <form method="post" action="<?= e(url('/portfolio/clients/' . $cid . '/delete')) ?>"
                        onsubmit="return confirm('Remove <?= e(addslashes($c['name'])) ?> and their logo?');">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm text-red" type="submit"><?= icon('trash') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>
