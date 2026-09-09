<?php

/**
 * The way from the website into the business system.
 *
 * The two are separate applications that share a domain: the site keeps
 * its own database, its own admin and its own client area, and the
 * system keeps everything else. This is the one seam between them, and
 * it is deliberately narrow — the site may ask what we sell and what it
 * costs, and it may put an enquiry at the front of the sales pipeline.
 * Nothing here lets the site write anywhere else.
 *
 * EVERYTHING HERE IS BEST EFFORT, and that is the important part. If the
 * system's configuration is missing, or its database is down, or it has
 * not been deployed beside the site at all, the marketing pages must
 * still serve and the contact form must still accept a message. An
 * enquiry saved only to the site's own table is worth incomparably more
 * than a 500 on the page somebody was about to enquire from.
 *
 * So every function here returns something harmless on failure and says
 * so in its return type: an empty list, or null. None of them throws.
 */

/**
 * Bring the system up, once.
 *
 * @return bool whether it is there and its database answers
 */
function system_ready(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $ready = false;

    try {
        $base = dirname(__DIR__, 2);

        if (!is_file($base . '/app/bootstrap.php') || !is_file($base . '/config/config.php')) {
            return $ready = false;
        }

        // Safe to include from here: the bootstrap defines paths, registers
        // an autoloader and loads helpers. It starts no session, installs
        // no error handler and prints nothing, and its helpers are all
        // guarded with function_exists, so nothing of the site's is
        // trodden on.
        require_once $base . '/app/bootstrap.php';

        if (!class_exists(\App\Core\Config::class)) {
            return $ready = false;
        }

        \App\Core\Config::load($base . '/config/config.php');
        \App\Core\Database::connect(\App\Core\Config::get('db'));

        // Prove it rather than assume it. connect() may be lazy, and a
        // page that discovers the database is down halfway through
        // rendering has already sent half a page.
        \App\Core\Database::scalar('SELECT 1', [], null);

        return $ready = true;
    } catch (\Throwable) {
        return $ready = false;
    }
}

/**
 * What we actually sell, at today's prices.
 *
 * Read live so the website can never quote a price the system stopped
 * charging months ago — which is the whole reason for reading it from
 * here rather than keeping a second copy on the site.
 *
 * @return list<array{name: string, description: string, price: float,
 *                    pricing_type: string, unit: string, lead_time: string,
 *                    category: string}>
 */
function system_services(int $limit = 0): array
{
    if (!system_ready()) {
        return [];
    }

    try {
        $sql = "SELECT s.name, s.description, s.price, s.pricing_type,
                       s.unit_label, s.lead_time,
                       COALESCE(c.name, 'Other') AS category
                  FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
                 WHERE s.is_active = 1
              ORDER BY category, s.name";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = \App\Core\Database::all($sql);
    } catch (\Throwable) {
        return [];
    }

    return array_map(static fn(array $r): array => [
        'name'         => (string) $r['name'],
        'description'  => (string) ($r['description'] ?? ''),
        'price'        => (float) $r['price'],
        'pricing_type' => (string) ($r['pricing_type'] ?? 'fixed'),
        'unit'         => (string) ($r['unit_label'] ?? ''),
        'lead_time'    => (string) ($r['lead_time'] ?? ''),
        'category'     => (string) $r['category'],
    ], $rows);
}

/**
 * What we have on the shelf, with its photographs.
 *
 * The printing page used to sell from a product table of the site's own,
 * kept by hand in an admin that no longer exists. It sells from the
 * stockroom now: one catalogue, one price, one place a photograph is
 * uploaded.
 *
 * Only active items, and prices as the system holds them — the page is
 * not allowed a copy of either.
 *
 * @return array{categories: list<array{id:int,name:string}>,
 *               products: list<array<string,mixed>>}
 */
function system_inventory(): array
{
    $empty = ['categories' => [], 'products' => []];

    if (!system_ready()) {
        return $empty;
    }

    try {
        $items = \App\Core\Database::all(
            "SELECT i.id, i.sku, i.name, i.description, i.unit,
                    i.selling_price, i.quantity, i.category_id,
                    COALESCE(c.name, 'Other') AS category_name
               FROM inventory_items i
          LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1
           ORDER BY category_name, i.name"
        );

        // Every picture at once rather than one query per item, and in the
        // order the stockroom put them in, so the primary one leads.
        $pictures = \App\Core\Database::all(
            "SELECT im.id, im.item_id, im.alt_text, im.is_primary
               FROM inventory_images im
               JOIN inventory_items i ON i.id = im.item_id
              WHERE i.is_active = 1
           ORDER BY im.item_id, im.is_primary DESC, im.sort_order, im.id"
        );
    } catch (\Throwable) {
        return $empty;
    }

    $byItem = [];

    foreach ($pictures as $picture) {
        // The public photograph route: it takes an image row's id, not a
        // path, and refuses unless the item is still active.
        $byItem[(int) $picture['item_id']][] = '/catalogue/photo/product/' . (int) $picture['id'];
    }

    $categories = [];
    $products   = [];

    foreach ($items as $item) {
        $id     = (int) $item['id'];
        $images = $byItem[$id] ?? [];

        // Every item contributes the heading it will be grouped under,
        // including the ones with no category at all. The page builds its
        // groups from this list and skips anything whose heading is
        // missing from it — so registering only the categorised ones
        // meant an item that had never been filed simply vanished from
        // the website while sitting active and priced in the stockroom.
        $categoryId = $item['category_id'] === null ? 0 : (int) $item['category_id'];

        $categories[$categoryId] = [
            'id'   => $categoryId,
            'name' => (string) $item['category_name'],
        ];

        $products[] = [
            'id'            => $id,
            'sku'           => (string) $item['sku'],
            'category_id'   => (int) ($item['category_id'] ?? 0),
            'category_name' => (string) $item['category_name'],
            'name'          => (string) $item['name'],
            'description'   => (string) ($item['description'] ?? ''),
            'price'         => (float) $item['selling_price'],
            'unit'          => (string) ($item['unit'] ?? ''),

            // The first picture leads the card; the rest open in the
            // lightbox. Empty where nothing has been uploaded, and the
            // page already falls back to its placeholder for that.
            'image_url'         => $images[0] ?? '',
            'additional_images' => array_slice($images, 1),

            // The site's cards render these two; the stockroom has no
            // equivalent of either, so they are answered honestly rather
            // than invented.
            'features'    => '',
            'is_featured' => 0,
        ];
    }

    return [
        'categories' => array_values($categories),
        'products'   => $products,
    ];
}

/**
 * What one item costs, straight from the system.
 *
 * Used to check an order rather than to show a price: the basket arrives
 * from a browser and every figure in it is a suggestion.
 */
function system_item_price(int $itemId): ?float
{
    if (!system_ready()) {
        return null;
    }

    try {
        $price = \App\Core\Database::scalar(
            'SELECT selling_price FROM inventory_items WHERE id = :id AND is_active = 1',
            ['id' => $itemId],
            null
        );

        return $price === null ? null : (float) $price;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * How a price should read on a public page.
 *
 * A service priced per square metre and one priced as a flat fee are not
 * the same offer, and "KES 1,200" against both is how a customer arrives
 * expecting the wrong number.
 */
function system_price_label(array $service): string
{
    $price = (float) $service['price'];

    if ($price <= 0.009) {
        return 'On request';
    }

    $shown  = 'KES ' . number_format($price, 0);
    $prefix = $service['pricing_type'] === 'from' ? 'From ' : '';

    // unit_label is already a whole phrase — the values in there are "per
    // vehicle", "per brand", "per site" — so it is used as written.
    // Building "per " onto it produced "From KES 65,000 per per vehicle".
    if ($service['unit'] !== '') {
        return $prefix . $shown . ' ' . $service['unit'];
    }

    // With no unit, the pricing type supplies the wording. The enum is
    // the system's own: fixed, hourly, daily, monthly, project, from.
    return $prefix . $shown . match ($service['pricing_type']) {
        'hourly'  => ' per hour',
        'daily'   => ' per day',
        'monthly' => ' per month',
        'project' => ' per project',
        default   => '',
    };
}

/**
 * Put an enquiry in front of the sales team.
 *
 * Raised as a lead, in the same shape and with the same numbering the
 * system uses for one taken over the counter, so it lands in the pipeline
 * people already work rather than in a table somebody has to remember to
 * open.
 *
 * @param  array{name: string, email?: string, phone?: string,
 *               company?: string, subject?: string, message: string,
 *               service?: string} $enquiry
 * @return int|null the lead id, or null if the system could not take it
 */
function system_capture_enquiry(array $enquiry): ?int
{
    if (!system_ready()) {
        return null;
    }

    $name    = trim((string) ($enquiry['name'] ?? ''));
    $message = trim((string) ($enquiry['message'] ?? ''));

    if ($name === '' || $message === '') {
        return null;
    }

    try {
        // What they asked about goes at the top of the requirement rather
        // than into a field of its own: whoever picks this up wants the
        // subject and the message together, in the order they were meant
        // to be read.
        $subject = trim((string) ($enquiry['subject'] ?? ''));
        $service = trim((string) ($enquiry['service'] ?? ''));
        $heading = $service !== '' ? $service : $subject;

        $requirement = ($heading !== '' ? $heading . "\n\n" : '') . $message;

        $leadId = \App\Core\Database::insert('leads', [
            'lead_number' => \App\Core\Numbering::next('lead'),
            'name'        => mb_substr($name, 0, 180),
            'company'     => trim((string) ($enquiry['company'] ?? '')) ?: null,
            'email'       => trim((string) ($enquiry['email'] ?? '')) ?: null,
            'phone'       => trim((string) ($enquiry['phone'] ?? '')) ?: null,
            // The enum already had a word for this.
            'source'      => 'website',
            'requirement' => $requirement,
            'stage'       => 'new',
        ]);

        \App\Core\ActivityLog::record(
            'website_enquiry',
            'lead',
            $leadId,
            'Enquiry from the website: ' . $name . ($heading !== '' ? ' — ' . $heading : '')
        );

        // Somebody has to be told, or it sits in the pipeline unread.
        // withRole is the system's own answer to "who handles this", and
        // it counts a role held in either place it can be recorded.
        $staff = \App\Services\StaffNotifier::withRole(['admin', 'manager', 'sales']);

        if ($staff) {
            \App\Services\StaffNotifier::notify($staff, [
                'event'       => 'website_enquiry',
                'title'       => 'An enquiry from the website',
                'body'        => $name . ($heading !== '' ? ' asked about ' . $heading : ' sent a message') . '.',
                'link'        => '/leads/' . $leadId,
                'entity_type' => 'lead',
                'entity_id'   => $leadId,
            ], ['email' => true, 'sms' => false]);
        }

        return $leadId;
    } catch (\Throwable $e) {
        // Logged where the system's own errors go if it is up enough to
        // have a log, and swallowed either way.
        if (class_exists(\App\Core\Logger::class)) {
            \App\Core\Logger::warning('Website enquiry could not be raised as a lead: ' . $e->getMessage());
        }

        return null;
    }
}
