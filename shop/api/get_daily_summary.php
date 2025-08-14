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

if ($product_id) {
    $stock_summary_sql = "
        SELECT 
            COALESCE(dss.quantity_sold, 0) as quantity_sold,
            COALESCE(dss.quantity_added, 0) as quantity_added,
            COALESCE(dss.quantity_adjusted, 0) as quantity_adjusted,
            COALESCE(dss.closing_quantity, ss.quantity_in_stock) as closing_quantity
        FROM shop_stock ss
        LEFT JOIN daily_reconciliation dr ON dr.shop_id = ss.shop_id AND dr.reconciliation_date = :date
        LEFT JOIN daily_stock_summary dss ON dss.daily_reconciliation_id = dr.id AND dss.product_id = ss.product_id
        WHERE ss.shop_id = :shop_id AND ss.product_id = :product_id
    ";
    $params['product_id'] = $product_id;
} else {
    $stock_summary_sql = "
        SELECT 
            SUM(COALESCE(dss.quantity_sold, 0)) as quantity_sold,
            SUM(COALESCE(dss.quantity_added, 0)) as quantity_added,
            SUM(COALESCE(dss.quantity_adjusted, 0)) as quantity_adjusted,
            SUM(COALESCE(dss.closing_quantity, ss.quantity_in_stock)) as closing_quantity
        FROM shop_stock ss
        LEFT JOIN daily_reconciliation dr ON dr.shop_id = ss.shop_id AND dr.reconciliation_date = :date
        LEFT JOIN daily_stock_summary dss ON dss.daily_reconciliation_id = dr.id AND dss.product_id = ss.product_id
        WHERE ss.shop_id = :shop_id
    ";
}

$stmt_stock = $pdo->prepare($stock_summary_sql);
$stmt_stock->execute($params);
$stock_data = $stmt_stock->fetch();

if ($stock_data) {
    // Calculate opening_quantity based on current closing_quantity and movements
    $closing_quantity = (int)$stock_data['closing_quantity'];
    $quantity_added = (int)$stock_data['quantity_added'];
    $quantity_sold = (int)$stock_data['quantity_sold'];
    $quantity_adjusted = (int)$stock_data['quantity_adjusted'];

    $stock_data['opening_quantity'] = $closing_quantity - ($quantity_added - $quantity_sold - $quantity_adjusted);
    $stock_data['total_moved'] = $quantity_added + $quantity_sold + $quantity_adjusted;
    $response['stock_summary'] = $stock_data;
} else {
        $response['stock_summary'] = ['opening_quantity' => 0, 'quantity_sold' => 0, 'quantity_added' => 0, 'quantity_adjusted' => 0, 'closing_quantity' => 0, 'total_moved' => 0];
}

// --- Part 3: Fetch Transaction List for the table at the bottom ---
$sql_transactions = "
    (
        SELECT p.payment_date AS transaction_date, 'Payment Received' AS transaction_type, CONCAT('Inv #', i.invoice_number) AS category, p.amount_paid AS amount, c.name AS customer_name
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON p.customer_id = c.id
        WHERE i.shop_id = ? AND p.payment_date = ?
    )
    UNION ALL
    (
        SELECT pct.transaction_date, 'Petty Cash Expense', pct.category, pct.amount, pct.description
        FROM petty_cash_transactions pct
        JOIN petty_cash_floats pcf ON pct.float_id = pcf.id
        WHERE pcf.shop_id = ? AND DATE(pct.transaction_date) = ? AND pct.transaction_type = 'expense'
    )
    ORDER BY transaction_date DESC
";
$stmt_list = $pdo->prepare($sql_transactions);
$stmt_list->execute([$shop_id, $date, $shop_id, $date]);
$response['transaction_list'] = $stmt_list->fetchAll();

echo json_encode($response);