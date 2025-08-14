<?php
session_start();

// 1. --- SECURITY AND INITIALIZATION ---
// Ensure the user is logged in and has a warehouse assigned.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    // Using a flash message for better UX
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Error: You must be logged in to perform this action.'
    ];
    header('Location: warehouse_dashboard.php');
    exit();
}

// Ensure it's a POST request to prevent direct access.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $dbname = 'u789944046_suppliesdirect'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $pdo = new PDO($dsn, $user, $pass, $options); } catch (\PDOException $e) { die("Database connection failed: " . $e->getMessage()); }


// 2. --- DATA EXTRACTION AND VALIDATION ---
$action = $_POST['action'] ?? '';
$supplier_id = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
$delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
$reference_number = trim($_POST['reference_number'] ?? '');
$items = $_POST['items'] ?? [];

// Use the warehouse_id from the session for security, not from the form.
$warehouse_id = $_SESSION['warehouse_id'];
$user_id = $_SESSION['user_id'];

// Basic validation checks
if (empty($action) || empty($supplier_id) || empty($items)) {
    $_SESSION['flash_message'] = [
        'type' => 'warning',
        'message' => 'Invalid submission. Please ensure you have selected a supplier and added at least one item.'
    ];
    header('Location: warehouse_dashboard.php');
    exit();
}

// 3. --- ACTION HANDLING ---

// The "Save Draft" action is a placeholder for future functionality.
// For now, it will just redirect back with a notice.
if ($action === 'draft') {
    $_SESSION['flash_message'] = [
        'type' => 'info',
        'message' => 'Draft functionality is not yet implemented. Your GRN was not saved.'
    ];
    header('Location: warehouse_dashboard.php');
    exit();
}

// --- The main "Generate GRN" logic ---
if ($action === 'generate') {
    // Fetch supplier name for logging notes
    $stmt_supplier = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $stmt_supplier->execute([$supplier_id]);
    $supplier_name = $stmt_supplier->fetchColumn();

    // Begin a database transaction
    $pdo->beginTransaction();

    try {
        // Loop through each item submitted
        foreach ($items as $productId => $itemData) {
            $product_id = filter_var($itemData['product_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($itemData['quantity'], FILTER_VALIDATE_INT);

            if (!$product_id || !$quantity || $quantity <= 0) {
                // If any item is invalid, roll back and stop.
                throw new \Exception("Invalid data for product ID {$productId}.");
            }

            // --- STEP A: Update the central warehouse stock level ---
            // This query will INSERT a new row if the product/warehouse combo doesn't exist,
            // or UPDATE the quantity if it does. This is atomic and safe.
            $sql_update_stock = "INSERT INTO warehouse_stock (product_id, warehouse_id, quantity_in_stock, last_updated)
                                 VALUES (?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE 
                                 quantity_in_stock = quantity_in_stock + VALUES(quantity_in_stock),
                                 last_updated = NOW()";
            $stmt_update = $pdo->prepare($sql_update_stock);
            $stmt_update->execute([$product_id, $warehouse_id, $quantity]);

            // --- STEP B: Get the new total stock (the running balance) ---
            $sql_get_balance = "SELECT quantity_in_stock FROM warehouse_stock WHERE product_id = ? AND warehouse_id = ?";
            $stmt_balance = $pdo->prepare($sql_get_balance);
            $stmt_balance->execute([$product_id, $warehouse_id]);
            $running_balance = $stmt_balance->fetchColumn();
            
            if ($running_balance === false) {
                // This should never happen if the previous query succeeded, but it's a good safeguard.
                throw new \Exception("Could not retrieve new balance for product ID {$product_id}.");
            }

            // --- STEP C: Record the specific transaction in the log ---
            $sql_log_transaction = "INSERT INTO stock_transactions 
                                        (product_id, warehouse_id, transaction_type, quantity, running_balance, 
                                        reference_type, reference_number, scanned_by_user_id, notes, transaction_date)
                                    VALUES (?, ?, 'stock_in', ?, ?, 'GRN', ?, ?, ?, ?)";
            
            $stmt_log = $pdo->prepare($sql_log_transaction);
            
            $notes = "Received from supplier: " . ($supplier_name ?: "ID {$supplier_id}");

            // Use the delivery date from the form plus the current time
            $transaction_timestamp = $delivery_date . ' ' . date('H:i:s');
            
            $stmt_log->execute([
                $product_id,
                $warehouse_id,
                $quantity,
                $running_balance,
                $reference_number,
                $user_id,
                $notes,
                $transaction_timestamp
            ]);
        }

        // If all items were processed without error, commit the transaction.
        $pdo->commit();

        $_SESSION['flash_message'] = [
            'type' => 'success',
            'message' => 'GRN processed successfully! Stock levels and transaction logs have been updated.'
        ];

    } catch (Exception $e) {
        // If any error occurred, roll back the entire transaction.
        $pdo->rollBack();
        
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'message' => 'Failed to process GRN. An error occurred: ' . $e->getMessage() . '. No changes were saved.'
        ];
    }
    
    // Redirect back to the dashboard to see the result.
    header('Location: warehouse_dashboard.php');
    exit();
}

// Fallback for any other unknown action
$_SESSION['flash_message'] = ['type' => 'danger', 'message' => 'Unknown action specified.'];
header('Location: warehouse_dashboard.php');
exit();