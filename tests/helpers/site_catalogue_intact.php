<?php
/**
 * Does every product the website is given land in a group it will draw?
 *
 * The printing page builds its groups from the category list and skips
 * anything whose heading is not in that list. So a product whose heading
 * was never registered is not shown as "uncategorised" — it is silently
 * dropped, while sitting active and priced in the stockroom.
 *
 * One item was doing exactly that: the only one nobody had filed under a
 * category. This replays the page's own grouping against the live
 * response and prints "all" only when nothing falls through.
 *
 * Usage:  php tests/helpers/site_catalogue_intact.php <base-url>
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$body = @file_get_contents($base . '/api/inventory.php');

if ($body === false) {
    echo 'unreachable';
    exit(1);
}

$data = json_decode($body, true);

if (!is_array($data) || empty($data['success'])) {
    echo 'failed';
    exit(1);
}

$headings = array_map(
    static fn(array $c): string => (string) $c['name'],
    $data['categories'] ?? []
);

$drawn = 0;

foreach ($data['products'] ?? [] as $product) {
    if (in_array((string) $product['category_name'], $headings, true)) {
        $drawn++;
    }
}

echo $drawn === count($data['products'] ?? []) ? 'all' : 'dropped';
