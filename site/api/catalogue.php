<?php
/**
 * The printing catalogue, for the public pages that sell from it.
 *
 * This exists because the site's own admin has been removed — the system
 * behind this site is the only admin now, and the only client portal —
 * but two public pages were reading their catalogue from
 * admin/api/products.php and admin/api/categories.php. Those were open
 * to GET and locked to everything else, so what they really were was a
 * public read endpoint that happened to live in the admin folder.
 *
 * Deleting the folder without this would have taken the printing page's
 * M-Pesa checkout down with it, which is a live way of taking money.
 *
 * Read only, by construction rather than by a session check: there is no
 * branch here that writes anything.
 *
 *   GET api/catalogue.php                 both, in one response
 *   GET api/catalogue.php?only=products
 *   GET api/catalogue.php?only=categories
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

require_once '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'This only answers GET.']);
    exit;
}

$only = $_GET['only'] ?? '';
$out  = ['success' => true];

try {
    if ($only !== 'categories') {
        // Same query the admin endpoint used, so the pages that consume
        // it need no change beyond the address they ask.
        $products = $pdo
            ->query("SELECT p.*, c.name AS category_name
                       FROM products p
                       JOIN categories c ON c.id = p.category_id
                   ORDER BY c.name ASC, p.name ASC")
            ->fetchAll(PDO::FETCH_ASSOC);

        $images = $pdo->prepare('SELECT image_url FROM product_images WHERE product_id = ?');

        foreach ($products as &$product) {
            $images->execute([$product['id']]);
            $product['additional_images'] = $images->fetchAll(PDO::FETCH_COLUMN);
        }

        unset($product);

        $out['products'] = $products;
    }

    if ($only !== 'products') {
        $out['categories'] = $pdo
            ->query('SELECT * FROM categories ORDER BY name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($out);
} catch (PDOException $e) {
    error_log('Catalogue read failed: ' . $e->getMessage());

    // The shape the pages already handle: they check success and show
    // "currently being updated" rather than breaking.
    echo json_encode(['success' => false, 'message' => 'The catalogue is unavailable just now.']);
}
