<?php
// api/get_daily_summary.php
header('Content-Type: application/json');
session_start();

// --- Security & Auth ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- Inputs ---
$shop_id = (int)$_SESSION['shop_id'];
$date = $_GET['date'] ?? date('Y-m-d');
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $name = 'u789944046_suppliesdirect';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { /* ... DB error handling ... */ http_response_code(500); echo json_encode(['error' => 'DB Connection Failed.']); exit(); }

$response = [
    'financial_summary' => null,
    'stock_summary' => null
];

// --- Part 1: Fetch Financial Summary (Always runs) ---
$stmt_financial = $pdo->prepare("SELECT id, opening_cash_balance, total_cash_in, total_cash_out, closing_cash_balance FROM daily_reconciliation WHERE shop_id = :shop_id AND reconciliation_date = :date");
$stmt_financial->execute(['shop_id' => $shop_id, 'date' => $date]);
$reconciliation_data = $stmt_financial->fetch();

if (!$reconciliation_data) {
    // No record for today, let's create one based on yesterday's closing balance
    $stmt_yesterday = $pdo->prepare("SELECT closing_cash_balance FROM daily_reconciliation WHERE shop_id = :shop_id AND reconciliation_date < :date ORDER BY reconciliation_date DESC LIMIT 1");
    $stmt_yesterday->execute(['shop_id' => $shop_id, 'date' => $date]);
    $yesterday_closing = $stmt_yesterday->fetchColumn();
    $opening_balance = $yesterday_closing ?: '0.00';

    // Insert new record for today
    $stmt_insert = $pdo->prepare("INSERT INTO daily_reconciliation (shop_id, reconciliation_date, opening_cash_balance, closing_cash_balance) VALUES (:shop_id, :date, :opening_balance, :opening_balance)");
    $stmt_insert->execute(['shop_id' => $shop_id, 'date' => $date, 'opening_balance' => $opening_balance]);
    
    $reconciliation_id = $pdo->lastInsertId();
    
    $response['financial_summary'] = [
        'opening_cash_balance' => $opening_balance,
        'total_cash_in' => '0.00',
        'total_cash_out' => '0.00',
        'closing_cash_balance' => $opening_balance
    ];
} else {
    $response['financial_summary'] = $reconciliation_data;
}


// --- Part 2: Fetch Stock Summary ---
// If a product_id is provided, fetch for that product. Otherwise, aggregate for all products.
$stock_summary_sql = "";
$params = ['shop_id' => $shop_id, 'date' => $date];

// Subquery to get previous day's closing quantity
$prev_day_closing_subquery = "
    SELECT dss_prev.closing_quantity
    FROM daily_stock_summary dss_prev
    JOIN daily_reconciliation dr_prev ON dss_prev.daily_reconciliation_id = dr_prev.id
    WHERE dr_prev.shop_id = :shop_id_prev AND dr_prev.reconciliation_date = DATE_SUB(:date_prev, INTERVAL 1 DAY) AND dss_prev.product_id = p.id
";

if ($product_id) {
    $stock_summary_sql = "
        SELECT 
            COALESCE((" . $prev_day_closing_subquery . "), 0) as opening_quantity,
            COALESCE(dss.quantity_sold, 0) as quantity_sold,
            COALESCE(dss.quantity_added, 0) as quantity_added,
            COALESCE(dss.quantity_adjusted, 0) as quantity_adjusted,
            ss.quantity_in_stock as closing_quantity
        FROM shop_stock ss
        JOIN products p ON ss.product_id = p.id
        LEFT JOIN daily_reconciliation dr ON dr.shop_id = ss.shop_id AND dr.reconciliation_date = :date
        LEFT JOIN daily_stock_summary dss ON dss.daily_reconciliation_id = dr.id AND dss.product_id = ss.product_id
        WHERE ss.shop_id = :shop_id AND ss.product_id = :product_id
    ";
    $params['product_id'] = $product_id;
    $params['shop_id_prev'] = $shop_id;
    $params['date_prev'] = $date;
} else {
    $stock_summary_sql = "
        SELECT 
            SUM(COALESCE((" . $prev_day_closing_subquery . "), 0)) as opening_quantity,
            SUM(COALESCE(dss.quantity_sold, 0)) as quantity_sold,
            SUM(COALESCE(dss.quantity_added, 0)) as quantity_added,
            SUM(COALESCE(dss.quantity_adjusted, 0)) as quantity_adjusted,
            SUM(ss.quantity_in_stock) as closing_quantity
        FROM shop_stock ss
        JOIN products p ON ss.product_id = p.id
        LEFT JOIN daily_reconciliation dr ON dr.shop_id = ss.shop_id AND dr.reconciliation_date = :date
        LEFT JOIN daily_stock_summary dss ON dss.daily_reconciliation_id = dr.id AND dss.product_id = ss.product_id
        WHERE ss.shop_id = :shop_id
    ";
    $params['shop_id_prev'] = $shop_id;
    $params['date_prev'] = $date;
}

$stmt_stock = $pdo->prepare($stock_summary_sql);
$stmt_stock->execute($params);
$stock_data = $stmt_stock->fetch();

if ($stock_data) {
    $stock_data['total_moved'] = (int)$stock_data['quantity_added'] + (int)$stock_data['quantity_sold'] + (int)$stock_data['quantity_adjusted'];
    $response['stock_summary'] = $stock_data;
} else {
    $response['stock_summary'] = ['opening_quantity' => 0, 'quantity_sold' => 0, 'quantity_added' => 0, 'quantity_adjusted' => 0, 'closing_quantity' => 0, 'total_moved' => 0];
}

echo json_encode($response);