<?php
header('Content-Type: application/json');
require_once 'includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect and sanitize data
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $full_name = $first_name . ' ' . $last_name;
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address1 = $_POST['address1'] ?? '';
        $address2 = $_POST['address2'] ?? '';
        $city = $_POST['city'] ?? '';
        $state_region = $_POST['state_region'] ?? '';
        $county = $_POST['county'] ?? '';
        $company = $_POST['company'] ?? '';
        $billing_contact = $_POST['billing_contact'] ?? '';
        $tax_id = $_POST['tax_id'] ?? '';
        $language = $_POST['language'] ?? 'English';
        $referral_source = $_POST['referral_source'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $package_name = $_POST['package_name'] ?? '';
        $package_price = $_POST['package_price'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        $bank_ref = $_POST['bank_ref'] ?? '';
        $mpesa_number = $_POST['mpesa_number'] ?? '';

        // 1. Check if user already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered. Please login to purchase.']);
            exit;
        }

        // 2. Register User
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_user = "INSERT INTO users (full_name, first_name, last_name, email, password, phone, company, address1, address2, city, state_region, county, billing_contact, language, tax_id, referral_source, role, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'client', 'active')";
        
        $stmt = $pdo->prepare($sql_user);
        $stmt->execute([
            $full_name, $first_name, $last_name, $email, $hashed_password, 
            $phone, $company, $address1, $address2, $city, 
            $state_region, $county, $billing_contact, $language, 
            $tax_id, $referral_source
        ]);
        
        $user_id = $pdo->lastInsertId();

        // 3. Create Pending Service
        $sql_service = "INSERT INTO services (user_id, service_name, status, billing_cycle, created_at) 
                        VALUES (?, ?, 'pending', 'yearly', CURRENT_TIMESTAMP)";
        $stmt = $pdo->prepare($sql_service);
        $stmt->execute([$user_id, $package_name]);

        // 4. Create Invoice (Unpaid)
        $invoice_ref = 'INV-' . strtoupper(substr(uniqid(), -8));
        // Remove currency and /yr from price string to get decimal
        $numeric_price = (float) preg_replace('/[^0-9.]/', '', $package_price);
        
        $sql_invoice = "INSERT INTO invoices (user_id, reference, amount, status, issue_date, due_date) 
                        VALUES (?, ?, ?, 'unpaid', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
        $stmt = $pdo->prepare($sql_invoice);
        $stmt->execute([$user_id, $invoice_ref, $numeric_price]);

        // Log Payment attempt (Simulated)
        // In a real scenario, you'd trigger STK push here if $payment_method == 'mpesa'

        echo json_encode(['success' => true, 'message' => 'Order placed successfully.', 'user_id' => $user_id]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
