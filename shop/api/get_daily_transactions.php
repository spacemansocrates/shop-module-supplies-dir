<?php
// api/get_daily_summary.php (REVISED AND CORRECTED)
header('Content-Type: application/json');
session_start();

// Security Check: Must be logged in and have a shop.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

$shop_id = (int)$_SESSION['shop_id'];
$date = $_GET['date'] ?? date('Y-m-d');
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

// Basic validation for the date format
if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

// --- DB Connection ---
require_once __DIR__ . '/../config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

$response = [];

// --- 1. Fetch Financial Summary (LIVE CALCULATION) ---
// This query calculates the totals on-the-fly instead of relying on stale data.
$sql_financial = "
    WITH DailyTotals AS (
        -- Calculate total cash in from payments on the given day
        SELECT 
            COALESCE(SUM(p.amount_paid), 0) AS total_cash_in
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        WHERE i.shop_id = :shop_id AND p.payment_date = :date_in,

        -- Calculate total cash out from petty cash expenses on the given day
        SELECT 
            COALESCE(SUM(pct.amount), 0) AS total_cash_out
        FROM petty_cash_transactions pct
        JOIN petty_cash_floats pcf ON pct.float_id = pcf.id
        WHERE pcf.shop_id = :shop_id AND DATE(pct.transaction_date) = :date_out AND pct.transaction_type = 'expense'
    )
    -- Combine with the opening balance from the reconciliation record
    SELECT
        dr.opening_cash_balance,
        dt.total_cash_in,
        dt.total_cash_out,
        (dr.opening_cash_balance + dt.total_cash_in - dt.total_cash_out) AS closing_cash_balance
    FROM
        daily_reconciliation dr
    CROSS JOIN DailyTotals dt
    WHERE
        dr.shop_id = :shop_id AND dr.reconciliation_date = :date_recon;
";

$stmt_financial = $pdo->prepare($sql_financial);
$stmt_financial->execute([
    'shop_id' => $shop_id,
    'date_in' => $date,
    'date_out' => $date,
    'date_recon' => $date
]);
$financial_summary = $stmt_financial->fetch();

// If no reconciliation record exists for the day, create a default zeroed-out response
if (!$financial_summary) {
    $financial_summary = [
        'opening_cash_balance' => '0.00', 'total_cash_in' => '0.00',
        'total_cash_out' => '0.00', 'closing_cash_balance' => '0.00',
    ];
}
$response['financial_summary'] = $financial_summary;


// --- 2. Fetch Stock Summary (LIVE CALCULATION, if product is selected) ---
$response['stock_summary'] = null;
if ($product_id) {
    // This query calculates stock movements for the selected product and day
    $sql_stock = "
      WITH Movements AS (
          -- Calculate total sold from invoice items for this day
          SELECT 
              COALESCE(SUM(ii.quantity), 0) AS sold
          FROM invoice_items ii
          JOIN invoices i ON ii.invoice_id = i.id
          WHERE i.shop_id = :shop_id AND ii.product_id = :p_id_sold AND i.invoice_date = :date_sold,

          -- Calculate total adjustments from stock transactions
          SELECT 
              COALESCE(SUM(st.quantity), 0) AS adjusted
          FROM stock_transactions st
          WHERE st.shop_id = :shop_id AND st.product_id = :p_id_adj AND DATE(st.transaction_date) = :date_adj AND st.transaction_type = 'adjustment',

          -- Calculate total added (from returns or transfers)
          SELECT
             COALESCE(SUM(st.quantity), 0) AS added
          FROM stock_transactions st
          WHERE st.shop_id = :shop_id AND st.product_id = :p_id_add AND DATE(st.transaction_date) = :date_add AND st.transaction_type IN ('return', 'stock_in')
      )
      -- Combine with the opening quantity from the daily summary table
      SELECT
          dss.opening_quantity,
          m.sold AS quantity_sold,
          m.added AS quantity_added,
          ABS(m.adjusted) AS quantity_adjusted, -- Adjustments are negative, so we take the absolute value for display
          (dss.opening_quantity + m.added - m.sold + m.adjusted) AS closing_quantity
      FROM 
        daily_stock_summary dss
      CROSS JOIN Movements m
      WHERE 
        dss.product_id = :p_id_final
        AND dss.daily_reconciliation_id = (SELECT id FROM daily_reconciliation WHERE shop_id = :shop_id AND reconciliation_date = :date_final LIMIT 1);
    ";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([
        'shop_id' => $shop_id,
        'p_id_sold' => $product_id, 'date_sold' => $date,
        'p_id_adj' => $product_id, 'date_adj' => $date,
        'p_id_add' => $product_id, 'date_add' => $date,
        'p_id_final' => $product_id, 'date_final' => $date
    ]);
    $stock_summary = $stmt_stock->fetch();
    if ($stock_summary) {
        $response['stock_summary'] = $stock_summary;
    }
}

// --- 3. Fetch Transaction List for the table at the bottom (This part was okay) ---
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