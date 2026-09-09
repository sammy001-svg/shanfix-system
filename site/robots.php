<?php
/**
 * /robots.txt, generated.
 *
 * It was a static file, and the only thing in it that ever changes is
 * the last line: the sitemap has to be named by absolute URL, so the
 * file carried a hard-coded domain. Serving the site from another
 * address left it pointing crawlers at the old one.
 *
 * The rules below are the same rules, verbatim. Only the Sitemap line
 * is computed.
 */
require_once __DIR__ . '/includes/brand.php';

header('Content-Type: text/plain; charset=utf-8');
?>
# Shanfix Technology
#
# The company website and the business system share this domain. The
# website is meant to be found; the system is not.
#
# The system is kept out of search results by a noindex tag on every one
# of its layouts, NOT by blocking it here. That is deliberate and it is
# the way round people usually get wrong: a crawler told not to fetch a
# page never sees the noindex on it, so a URL somebody has linked to can
# still be listed — with no description, which looks worse than being
# indexed properly. Letting it fetch and read "noindex" is what actually
# removes it.
#
# So what follows blocks only what should never be fetched at all: files
# with no reader, and the working parts of the site itself.

User-agent: *
Allow: /

# The website's own working files. None of these is a page.
Disallow: /includes/
Disallow: /migrations/
Disallow: /debug.php
Disallow: /migrate.php
Disallow: /migrate_users.php
Disallow: /models.json
Disallow: /database.sql
Disallow: /seed.sql
Disallow: /api/cron-renewals.php

# Customer files and anything reached by a private link. These carry a
# token instead of a login, so a crawler that finds one would be reading
# somebody's invoice.
Disallow: /files/
Disallow: /uploads/
Disallow: /view/
Disallow: /v/
Disallow: /proof/
Disallow: /p/
Disallow: /brief/
Disallow: /b/
Disallow: /join/
Disallow: /statement/

# Nothing to index and a waste of a crawl either way.
Disallow: /offline
Disallow: /search
Disallow: /*?page=

# NOT blocked, on purpose: the system's own pages — /login, /signin,
# /dashboard, /portal, /partners and the rest. They carry noindex, and
# blocking them here would stop a crawler ever reading it.
#
# NOT blocked either: /services.php, which is one of ours. A line
# reading "Disallow: /services" would match it as a prefix and quietly
# remove a real page from search, because the system happens to have a
# /services of its own.

Sitemap: <?= site_url("sitemap.xml") ?>
