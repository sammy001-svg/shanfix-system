<?php
/**
 * The printing catalogue, from the stockroom.
 *
 * The printing page used to sell from a product table of the site's own,
 * kept by hand in an admin that no longer exists. It sells from the
 * system's inventory now: one catalogue, one price, and a photograph
 * uploaded in one place rather than two.
 *
 * The shape is the one the page already renders — categories with an id
 * and a name, products with a category, a price and their pictures — so
 * nothing changed on the page except where it asks.
 *
 * Read only. There is no branch here that writes anything.
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=120');

require_once '../includes/system.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'This only answers GET.']);
    exit;
}

$catalogue = system_inventory();

// The page checks success and shows "our catalogue is being updated"
// rather than breaking, so an unreachable system reads as an empty shelf
// instead of a broken page.
if (!$catalogue['products'] && !system_ready()) {
    echo json_encode([
        'success' => false,
        'message' => 'The catalogue is unavailable just now.',
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'categories' => $catalogue['categories'],
    'products'   => $catalogue['products'],
]);
