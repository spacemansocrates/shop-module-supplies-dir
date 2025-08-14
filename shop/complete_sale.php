<?php
// --- complete_sale.php (FULL AND FINAL VERSION) ---

// --- 1. SETUP AND HEADERS ---
header('Content-Type: application/json');
session_start();

// --- 2. DATABASE CONNECTION ---
require_once __DIR__ . '/config.php';

// Enable error reporting to catch all issues during the transaction
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = getMySQLi();

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

        // *** NEW LOGIC PART 2: Update or Insert daily stock summary for this item ***
        // First, check if a daily_stock_summary record exists for this product and date
        $stmt_check_dss = $mysqli->prepare(
            "SELECT dss.id, dss.opening_quantity FROM daily_stock_summary dss
             JOIN daily_reconciliation dr ON dss.daily_reconciliation_id = dr.id
             WHERE dr.shop_id = ? AND dr.reconciliation_date = ? AND dss.product_id = ?"
        );
        $stmt_check_dss->bind_param("isi", $shopId, $invoice_date, $productId);
        $stmt_check_dss->execute();
        $result_check_dss = $stmt_check_dss->get_result();
        $dss_record = $result_check_dss->fetch_assoc();
        $stmt_check_dss->close();

        if ($dss_record) {
            // Record exists, update quantity_sold
            $stmt_update_dss = $mysqli->prepare(
                "UPDATE daily_stock_summary SET quantity_sold = quantity_sold + ? WHERE id = ?"
            );
            $stmt_update_dss->bind_param("ii", $quantity_integer, $dss_record['id']);
            $stmt_update_dss->execute();
            $stmt_update_dss->close();
        } else {
            // No record for today, insert a new one
            // Get previous day's closing quantity for opening_quantity
            $stmt_prev_day_closing = $mysqli->prepare(
                "SELECT dss.closing_quantity FROM daily_stock_summary dss
                 JOIN daily_reconciliation dr ON dss.daily_reconciliation_id = dr.id
                 WHERE dr.shop_id = ? AND dr.reconciliation_date < ? AND dss.product_id = ?
                 ORDER BY dr.reconciliation_date DESC LIMIT 1"
            );
            $stmt_prev_day_closing->bind_param("isi", $shopId, $invoice_date, $productId);
            $stmt_prev_day_closing->execute();
            $prev_day_closing_qty = $stmt_prev_day_closing->get_result()->fetch_column();
            $stmt_prev_day_closing->close();

            $opening_qty_for_new_dss = $prev_day_closing_qty ?: 0;

            // Get daily_reconciliation_id for today
            $stmt_get_reconciliation_id = $mysqli->prepare(
                "SELECT id FROM daily_reconciliation WHERE shop_id = ? AND reconciliation_date = ?"
            );
            $stmt_get_reconciliation_id->bind_param("is", $shopId, $invoice_date);
            $stmt_get_reconciliation_id->execute();
            $reconciliation_id = $stmt_get_reconciliation_id->get_result()->fetch_column();
            $stmt_get_reconciliation_id->close();

            if (!$reconciliation_id) {
                // This should ideally not happen if daily_reconciliation is handled first, but as a fallback
                throw new Exception("Daily reconciliation record not found for today.");
            }

            $stmt_insert_dss = $mysqli->prepare(
                "INSERT INTO daily_stock_summary (daily_reconciliation_id, product_id, opening_quantity, quantity_sold, quantity_added, quantity_adjusted, closing_quantity) 
                 VALUES (?, ?, ?, ?, 0, 0, ?)"
            );
            // For a new record, closing_quantity will be opening_quantity - quantity_sold initially
            $initial_closing_qty = $opening_qty_for_new_dss - $quantity_integer;
            $stmt_insert_dss->bind_param("iiiii", $reconciliation_id, $productId, $opening_qty_for_new_dss, $quantity_integer, $initial_closing_qty);
            $stmt_insert_dss->execute();
            $stmt_insert_dss->close();
        }

        // After updating/inserting daily_stock_summary, ensure shop_stock is updated to reflect the current live stock
        // This part remains the same as it updates the live stock for the product
        $stmt_stock->bind_param("iiii", $quantity_integer, $productId, $shopId, $quantity_integer);
        $stmt_stock->execute();
        if ($stmt_stock->affected_rows === 0) {
            throw new Exception("Insufficient stock for '{$name}'. Sale cannot be completed.");
        }
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