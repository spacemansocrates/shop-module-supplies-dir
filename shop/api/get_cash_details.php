<?php
// api/get_cash_details.php
header('Content-Type: application/json');
session_start();

// --- Security & Authentication ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- Input Gathering ---
$shop_id = (int)$_SESSION['shop_id'];
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

// --- Database Connection ---
require_once __DIR__ . '/../config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// --- Fetch Transaction Details ---
// This query combines payments and expenses into a single, ordered list.
$sql = "
    (
        -- Get all payments received (Cash In)
        SELECT 
            p.payment_date AS transaction_time,
            'Payment Received' AS type,
            p.amount_paid AS amount,
            CONCAT('Inv #', i.invoice_number, ' - ', c.name) AS details
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON p.customer_id = c.id
        WHERE i.shop_id = :shop_id_in AND p.payment_date = :date_in
    )
    UNION ALL
    (
        -- Get all petty cash expenses (Cash Out)
        SELECT 
            pct.transaction_date AS transaction_time,
            'Petty Cash Expense' AS type,
            pct.amount * -1 AS amount, -- Make the amount negative for cash out
            pct.description AS details
        FROM petty_cash_transactions pct
        JOIN petty_cash_floats pcf ON pct.float_id = pcf.id
        WHERE pcf.shop_id = :shop_id_out AND DATE(pct.transaction_date) = :date_out AND pct.transaction_type = 'expense'
    )
    ORDER BY transaction_time DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'shop_id_in' => $shop_id,
    'date_in' => $date,
    'shop_id_out' => $shop_id,
    'date_out' => $date
]);
$transactions = $stmt->fetchAll();

// Send the data back as JSON
echo json_encode($transactions);