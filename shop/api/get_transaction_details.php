<?php
// api/get_transaction_details.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $dbname = 'u789944046_suppliesdirect'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $pdo = new PDO($dsn, $user, $pass, $options); } catch (\PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Database error.']); exit(); }

// --- Get Parameters ---
$shop_id = $_SESSION['shop_id'];
$date = $_GET['date'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'cash';
$search = $_GET['search'] ?? '';
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

$params = [];
$search_term = '%' . $search . '%';

if ($type === 'cash') {
    $sql = "
        (SELECT p.created_at as date, 'Payment Received' as type, p.amount_paid as amount, c.name as details FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON p.customer_id = c.id WHERE i.shop_id = ? AND DATE(p.payment_date) = ? AND (c.name LIKE ? OR i.invoice_number LIKE ?))
        UNION ALL
        (SELECT pct.transaction_date as date, 'Petty Cash Expense' as type, pct.amount, pct.description as details FROM petty_cash_transactions pct JOIN petty_cash_floats pcf ON pct.float_id = pcf.id WHERE pcf.shop_id = ? AND DATE(pct.transaction_date) = ? AND pct.description LIKE ? AND pct.transaction_type = 'expense')
        ORDER BY date DESC
    ";
    $params = [$shop_id, $date, $search_term, $search_term, $shop_id, $date, $search_term];

} elseif ($type === 'stock' && $product_id) {
    $sql = "
        SELECT st.transaction_date as date, st.transaction_type as type, st.quantity, u.full_name as user, st.notes
        FROM stock_transactions st
        JOIN users u ON st.scanned_by_user_id = u.id
        WHERE st.shop_id = ? AND st.product_id = ? AND DATE(st.transaction_date) = ? AND (st.notes LIKE ? OR u.full_name LIKE ?)
        ORDER BY st.transaction_date DESC
    ";
    // NOTE: Your 'stock_transactions' table needs a 'shop_id' column for this to work perfectly.
    // If it only has warehouse_id, the logic needs to adapt. Assuming shop_id exists.
    $params = [$shop_id, $product_id, $date, $search_term, $search_term];
    
} else {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

echo json_encode($results);