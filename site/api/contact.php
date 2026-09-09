<?php
/**
 * Public API: Contact Form Submission
 * No authentication required — rate-limited by honeypot field.
 */

header('Content-Type: application/json');
require_once '../includes/db_connect.php';
require_once '../includes/mailer.php';
require_once '../includes/system.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

// Honeypot anti-spam: if filled in, silently accept but don't save
if (!empty($input['website'])) {
    echo json_encode(['success' => true, 'message' => 'Message received. We will get back to you shortly.']);
    exit;
}

$name    = trim($input['name']    ?? '');
$email   = trim($input['email']   ?? '');
$subject = trim($input['subject'] ?? '');
$message = trim($input['message'] ?? '');

if (empty($name) || empty($email) || empty($subject) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($message) < 10) {
    echo json_encode(['success' => false, 'message' => 'Message is too short.']);
    exit;
}

// Which service they were reading about when they clicked, where the
// page passed it through. It is the difference between "an enquiry" and
// "an enquiry about vehicle branding" on somebody's screen.
$service = trim($input['service'] ?? '');

try {
    $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $subject, $message]);

    // Send auto-reply to the sender
    Mailer::contactAutoReply($name, $email, $subject);

    // Notify admin
    $adminEmail = $_ENV['ADMIN_EMAIL'] ?? '';
    if ($adminEmail) {
        Mailer::contactAdminNotify($adminEmail, $name, $email, $subject, $message);
    }

    // And into the sales pipeline, as a lead, with the same numbering as
    // one taken over the counter.
    //
    // This is no longer a nicety. The site's own admin has been removed,
    // so the contact_messages row written above has nothing left to read
    // it — an enquiry that stopped there would simply never be seen.
    // Best effort all the same: if the system is down, the message is
    // still saved and the auto-reply has still gone, and telling the
    // sender their enquiry failed would be a lie.
    system_capture_enquiry([
        'name'    => $name,
        'email'   => $email,
        'phone'   => trim($input['phone'] ?? ''),
        'company' => trim($input['company'] ?? ''),
        'subject' => $subject,
        'service' => $service,
        'message' => $message,
    ]);

    echo json_encode(['success' => true, 'message' => 'Your message has been received. We\'ll get back to you within 24 hours.']);
} catch (PDOException $e) {
    error_log('Contact form error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save your message. Please try again or email us directly.']);
}
