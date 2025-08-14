<?php
// api/add_petty_cash_expense.php
header('Content-Type: application/json');
session_start();

// --- 1. Security & Input Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}
if (!isset($_SESSION['user_id'], $_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$description = trim($_POST['description'] ?? '');
$date = $_POST['date'] ?? date('Y-m-d'); // The date from the counter sales page

if ($amount === false || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount. Must be a positive number.']);
    exit();
}
if (empty($description)) {
    http_response_code(400);
    echo json_encode(['error' => 'Description is required.']);
    exit();
}

// --- 2. Database Connection ---
$host = 'srv582.hstgr.io'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $name = 'u789944046_suppliesdirect';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// --- 3. Core Logic in a Transaction ---
$user_id = (int)$_SESSION['user_id'];
$shop_id = (int)$_SESSION['shop_id'];

try {
    $pdo->beginTransaction();

    // Step A: Find the shop's active petty cash float ID
    $stmt_float = $pdo->prepare("SELECT id FROM petty_cash_floats WHERE shop_id = ? AND is_active = 1 LIMIT 1");
    $stmt_float->execute([$shop_id]);
    $float = $stmt_float->fetch(PDO::FETCH_ASSOC);

    if (!$float) {
        throw new Exception("No active petty cash float found for this shop.");
    }
    $float_id = $float['id'];

    // Step B: Insert the transaction record
    $stmt_insert = $pdo->prepare(
        "INSERT INTO petty_cash_transactions (float_id, transaction_type, amount, description, category, transaction_date, recorded_by_user_id) 
         VALUES (?, 'expense', ?, ?, 'Manual Entry', NOW(), ?)"
    );
    $stmt_insert->execute([$float_id, $amount, $description, $user_id]);

    // Step C: UPDATE the daily reconciliation summary. This is the key step.
    $stmt_update = $pdo->prepare(
        "UPDATE daily_reconciliation SET total_cash_out = total_cash_out + ? WHERE shop_id = ? AND reconciliation_date = ?"
    );
    $stmt_update->execute([$amount, $shop_id, $date]);

    // Check if a record was updated. If not, it means no summary exists for that day.
    if ($stmt_update->rowCount() === 0) {
        throw new Exception("Reconciliation for $date has not been started. Cannot record expense.");
    }
    
    $pdo->commit();

    // --- 4. Send Success Response ---
    echo json_encode([
        'success' => true,
        'message' => 'Petty cash expense recorded successfully.'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}