<?php
// api/get_financial_summary.php
header('Content-Type: application/json');
session_start();

// --- Security & Authentication ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- Input Gathering and Validation ---
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

// --- Fetch the financial data ---
$stmt = $pdo->prepare(
    "SELECT opening_cash_balance, total_cash_in, total_cash_out, closing_cash_balance 
     FROM daily_reconciliation 
     WHERE shop_id = :shop_id AND reconciliation_date = :date"
);
$stmt->execute(['shop_id' => $shop_id, 'date' => $date]);
$summary = $stmt->fetch();

// If no record exists for that day, return a default zeroed-out array
if (!$summary) {
    $summary = [
        'opening_cash_balance' => '0.00', 
        'total_cash_in' => '0.00', 
        'total_cash_out' => '0.00', 
        'closing_cash_balance' => '0.00'
    ];
}

// Send the data back as JSON
echo json_encode($summary);