<?php
// --- start_of_day.php ---
// This script should be run once every morning for each shop.
// It creates the daily summary records needed for the Counter Sales page.
session_start();
header('Content-Type: text/plain'); // Output as plain text for clarity

// --- 1. CONFIGURATION AND CONNECTION ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    die("ERROR: Authentication required. Please log in.");
}
$shopId = (int)$_SESSION['shop_id'];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// DB Connection
require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = getMySQLi();
echo "Connected to database successfully.\n";

// --- 2. START THE TRANSACTION ---
$mysqli->begin_transaction();
try {
    // --- Step 1: Create Today's Financial Reconciliation Record ---
    echo "Processing financial reconciliation for today ($today)...\n";

    // Get yesterday's closing cash balance to use as today's opening balance
    $stmt_prev_cash = $mysqli->prepare("SELECT closing_cash_balance FROM daily_reconciliation WHERE shop_id = ? AND reconciliation_date = ?");
    $stmt_prev_cash->bind_param("is", $shopId, $yesterday);
    $stmt_prev_cash->execute();
    $result_cash = $stmt_prev_cash->get_result();
    $prev_day = $result_cash->fetch_assoc();
    $opening_cash_balance = $prev_day['closing_cash_balance'] ?? 0.00;
    $stmt_prev_cash->close();
    echo " - Yesterday's closing cash was: $opening_cash_balance. This is today's opening balance.\n";

    // Use INSERT IGNORE to safely create the record without errors if it already exists.
    $stmt_recon = $mysqli->prepare("INSERT IGNORE INTO daily_reconciliation (shop_id, reconciliation_date, opening_cash_balance) VALUES (?, ?, ?)");
    $stmt_recon->bind_param("isd", $shopId, $today, $opening_cash_balance);
    $stmt_recon->execute();
    if ($mysqli->affected_rows > 0) {
        echo " - Successfully created today's financial record.\n";
    } else {
        echo " - Today's financial record already exists. No changes made.\n";
    }
    $reconciliation_id = $mysqli->insert_id ?: $mysqli->query("SELECT id FROM daily_reconciliation WHERE shop_id = $shopId AND reconciliation_date = '$today'")->fetch_object()->id;
    $stmt_recon->close();

    // --- Step 2: Create Today's Stock Summary Records ---
    echo "Processing stock summaries for today...\n";
    
    // This query fetches every product in the shop.
    // For each product, it tries to get yesterday's closing stock.
    // If yesterday's record doesn't exist (e.g., for a new product or first day), it uses the current live stock as the opening quantity.
    $sql_stock_summary = "
        INSERT IGNORE INTO daily_stock_summary (daily_reconciliation_id, product_id, opening_quantity)
        SELECT
            ? AS current_recon_id,
            ss.product_id,
            COALESCE(
                (SELECT dss_prev.closing_quantity 
                 FROM daily_stock_summary dss_prev
                 JOIN daily_reconciliation dr_prev ON dss_prev.daily_reconciliation_id = dr_prev.id
                 WHERE dr_prev.shop_id = ss.shop_id AND dr_prev.reconciliation_date = ? AND dss_prev.product_id = ss.product_id),
                ss.quantity_in_stock
            ) AS opening_qty
        FROM shop_stock ss
        WHERE ss.shop_id = ?
    ";
    
    $stmt_stock = $mysqli->prepare($sql_stock_summary);
    $stmt_stock->bind_param("isi", $reconciliation_id, $yesterday, $shopId);
    $stmt_stock->execute();
    
    echo " - Created " . $stmt_stock->affected_rows . " new stock summary records for today.\n";
    $stmt_stock->close();

    // --- 3. COMMIT AND FINISH ---
    $mysqli->commit();
    echo "\nStart of Day process completed successfully!";

} catch (Exception $e) {
    $mysqli->rollback();
    die("\nAn error occurred: " . $e->getMessage());
} finally {
    $mysqli->close();
}
?>