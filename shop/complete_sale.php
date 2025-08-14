<?php
// --- complete_sale.php (FULL AND FINAL VERSION) ---

// --- 1. SETUP AND HEADERS ---
header('Content-Type: application/json');
session_start();

// --- 2. DATABASE CONNECTION ---
// Make sure these credentials are correct for your environment
// --- 1. DATABASE CONNECTION ---
$db_host = 'srv582.hstgr.io';
$db_user = 'u789944046_socrates';
$db_pass = 'Naho1386'; // Your password
$db_name = 'u789944046_suppliesdirect';
$db_port = 3306;

// Enable error reporting to catch all issues during the transaction
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

// --- 3. GET AND VALIDATE INPUT ---
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Security Check: User must be logged in with a shop ID
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication error. Please log in.']);
    exit();
}

// Data from the POS interface
$cart = $data['cart'] ?? [];
$paymentMethod = $data['paymentMethod'] ?? 'Cash';
$discount = $data['discount'] ?? ['type' => 'none', 'value' => 0];

// Validate that the cart is not empty
if (empty($cart)) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot complete sale. The cart is empty.']);
    exit();
}

// User, Shop, and default Customer IDs from session and configuration
$userId = (int)$_SESSION['user_id'];
$shopId = (int)$_SESSION['shop_id'];
$walkInCustomerId = 7; // Your "Walk-in Customer" ID
$invoice_date = date('Y-m-d'); // Use a single, consistent date for the entire transaction

// --- 4. SERVER-SIDE CALCULATIONS (FOR SECURITY AND INTEGRITY) ---
$gross_total = 0;
foreach ($cart as $item) {
    // Ensure price and quantity are treated as the correct numeric types
    $gross_total += (float)$item['price'] * (int)$item['quantity'];
}

$discount_amount = 0;
if ($discount['type'] === 'percentage' && (float)$discount['value'] > 0) {
    $discount_amount = $gross_total * ((float)$discount['value'] / 100);
} elseif ($discount['type'] === 'fixed' && (float)$discount['value'] > 0) {
    $discount_amount = (float)$discount['value'];
}
// Ensure discount doesn't exceed the total
if ($discount_amount > $gross_total) {
    $discount_amount = $gross_total;
}

$total_after_discount = $gross_total - $discount_amount;
$vat_percentage = 16.5;
$vat_amount = $total_after_discount * ($vat_percentage / 100);
$total_net_amount = $total_after_discount + $vat_amount;

// --- 5. DATABASE TRANSACTION ---
// Start a transaction to ensure all operations succeed or none do.
$mysqli->begin_transaction();

try {
    // --- Step A: Create the main Invoice record ---
    $invoice_number = 'POS-' . $shopId . '-' . time();
    $stmt_invoice = $mysqli->prepare(
        "INSERT INTO invoices (invoice_number, shop_id, customer_id, invoice_date, gross_total_amount, discount_type, discount_value, discount_amount, vat_percentage, vat_amount, total_net_amount, total_paid, status, created_by_user_id, payment_terms) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?)"
    );
    $discountTypeForDb = ($discount['type'] === 'none') ? null : $discount['type'];
    $discountValueForDb = ($discount['type'] === 'none') ? 0 : (float)$discount['value'];
    $stmt_invoice->bind_param("siisdsddddddis", $invoice_number, $shopId, $walkInCustomerId, $invoice_date, $gross_total, $discountTypeForDb, $discountValueForDb, $discount_amount, $vat_percentage, $vat_amount, $total_net_amount, $total_net_amount, $userId, $paymentMethod);
    $stmt_invoice->execute();
    $invoice_id = $mysqli->insert_id;
    $stmt_invoice->close();

    // --- Step B: Prepare all needed statements outside the loop for efficiency ---
    $stmt_items = $mysqli->prepare("INSERT INTO invoice_items (invoice_id, product_id, description, quantity, rate_per_unit, total_amount, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt_stock = $mysqli->prepare("UPDATE shop_stock SET quantity_in_stock = quantity_in_stock - ? WHERE product_id = ? AND shop_id = ? AND quantity_in_stock >= ?");
    
    // *** NEW LOGIC PART 1: Prepare statement for daily stock summary update ***
    $stmt_stock_summary = $mysqli->prepare(
        "UPDATE daily_stock_summary dss
         JOIN daily_reconciliation dr ON dss.daily_reconciliation_id = dr.id
         SET dss.quantity_sold = dss.quantity_sold + ?
         WHERE dr.shop_id = ? AND dr.reconciliation_date = ? AND dss.product_id = ?"
    );

    // --- Step C: Loop through cart items to process each one ---
    foreach ($cart as $item) {
        $productId = (int)$item['id'];
        $name = (string)$item['name'];
        $price = (float)$item['price'];
        $quantity_decimal = (float)$item['quantity'];
        $quantity_integer = (int)$item['quantity'];
        $line_total = $price * $quantity_decimal;

        // Insert the item into the invoice_items table
        $stmt_items->bind_param("iisdddi", $invoice_id, $productId, $name, $quantity_decimal, $price, $line_total, $userId);
        $stmt_items->execute();

        // Update the main stock level, ensuring there is enough stock
        $stmt_stock->bind_param("iiii", $quantity_integer, $productId, $shopId, $quantity_integer);
        $stmt_stock->execute();
        if ($stmt_stock->affected_rows === 0) {
            throw new Exception("Insufficient stock for '{$name}'. Sale cannot be completed.");
        }

        // *** NEW LOGIC PART 2: Execute the daily stock summary update for this item ***
        $stmt_stock_summary->bind_param("iisi", $quantity_integer, $shopId, $invoice_date, $productId);
        $stmt_stock_summary->execute();
    }
    // Close the prepared statements that were used inside the loop
    $stmt_items->close();
    $stmt_stock->close();
    $stmt_stock_summary->close();
    
    // --- Step D: Record the Payment for the entire invoice ---
    $stmt_payment = $mysqli->prepare("INSERT INTO payments (invoice_id, customer_id, payment_date, amount_paid, payment_method, recorded_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
    // Use the consistent $invoice_date variable here
    $stmt_payment->bind_param("iisdsi", $invoice_id, $walkInCustomerId, $invoice_date, $total_net_amount, $paymentMethod, $userId);
    $stmt_payment->execute();
    $stmt_payment->close();

    // *** NEW LOGIC PART 3: Update the daily financial summary with the total cash in ***
    $stmt_financial_summary = $mysqli->prepare("UPDATE daily_reconciliation SET total_cash_in = total_cash_in + ? WHERE shop_id = ? AND reconciliation_date = ?");
    $stmt_financial_summary->bind_param("dis", $total_net_amount, $shopId, $invoice_date);
    $stmt_financial_summary->execute();
    $stmt_financial_summary->close();

    // If we reach here, all database operations were successful. Commit them permanently.
    $mysqli->commit();

    // --- 6. SEND SUCCESS RESPONSE ---
    http_response_code(200);
    echo json_encode([
        'message' => 'Sale completed successfully!',
        'invoice_number' => $invoice_number,
        'invoice_id' => $invoice_id
    ]);

} catch (Exception $e) {
    // An error occurred in the `try` block. Roll back ALL changes from this transaction.
    $mysqli->rollback();
    
    http_response_code(500);
    // Send back the specific error message for easier debugging on the frontend.
    echo json_encode(['error' => $e->getMessage()]);

} finally {
    // This block runs whether the transaction succeeded or failed. Always close the connection.
    $mysqli->close();
}