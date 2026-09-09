<?php
/**
 * Public Events API — no auth required
 * GET /api/events.php              → list published events
 * GET /api/events.php?id=X         → single event with ticket types
 * GET /api/events.php?slug=X       → single event by slug
 */
header('Content-Type: application/json');
require_once '../includes/db_connect.php';

// Auto-create tables if they don't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `events` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(200) NOT NULL,
        `slug` varchar(220) NOT NULL,
        `description` text DEFAULT NULL,
        `event_date` datetime NOT NULL,
        `end_date` datetime DEFAULT NULL,
        `venue` varchar(200) DEFAULT NULL,
        `venue_address` varchar(300) DEFAULT NULL,
        `image_url` varchar(255) DEFAULT NULL,
        `organizer` varchar(100) DEFAULT 'Shanfix Technology',
        `status` enum('draft','published','cancelled','completed') DEFAULT 'published',
        `is_featured` tinyint(1) DEFAULT 0,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `event_ticket_types` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `event_id` int(11) NOT NULL,
        `name` varchar(100) NOT NULL,
        `description` varchar(255) DEFAULT NULL,
        `price` decimal(10,2) NOT NULL DEFAULT 0,
        `capacity` int(11) DEFAULT NULL,
        `sold_count` int(11) NOT NULL DEFAULT 0,
        `sale_ends` datetime DEFAULT NULL,
        `status` enum('active','paused','sold_out') DEFAULT 'active',
        PRIMARY KEY (`id`), KEY `event_id` (`event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$id   = isset($_GET['id'])   ? (int)$_GET['id']           : 0;
$slug = isset($_GET['slug']) ? trim($_GET['slug'])         : '';

// Single event
if ($id || $slug) {
    try {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND status = 'published'");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM events WHERE slug = ? AND status = 'published'");
            $stmt->execute([$slug]);
        }
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) { echo json_encode(['success' => false, 'message' => 'Event not found.']); exit; }

        $ttStmt = $pdo->prepare("SELECT * FROM event_ticket_types WHERE event_id = ? AND status = 'active' ORDER BY price ASC");
        $ttStmt->execute([$event['id']]);
        $event['ticket_types'] = $ttStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'event' => $event]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// List events
try {
    $events = $pdo->query(
        "SELECT e.*,
                MIN(tt.price) as min_price,
                MAX(tt.price) as max_price,
                COALESCE(SUM(tt.sold_count),0) as total_sold,
                COUNT(DISTINCT tt.id) as ticket_type_count
         FROM events e
         LEFT JOIN event_ticket_types tt ON tt.event_id = e.id AND tt.status = 'active'
         WHERE e.status = 'published' AND e.event_date >= CURDATE()
         GROUP BY e.id
         ORDER BY e.is_featured DESC, e.event_date ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'events' => $events]);
} catch (Exception $e) {
    echo json_encode(['success' => true, 'events' => []]);
}
