<?php
// api/update_opening_balance.php (NEW SECURE ADMIN APPROVAL VERSION)
header('Content-Type: application/json');
session_start();

// --- 1. Security & Input Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'This endpoint only accepts POST requests.']);
    exit();
}

// The logged-in shopkeeper must have a session.
if (!isset($_SESSION['user_id'], $_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Shop session not found. Please log in.']);
    exit();
}

// Get POST data for admin approval
$new_balance = filter_input(INPUT_POST, 'new_balance', FILTER_VALIDATE_FLOAT);
$admin_username = $_POST['admin_username'] ?? '';
$admin_password = $_POST['admin_password'] ?? '';
$date = $_POST['date'] ?? '';

if ($new_balance === false || $new_balance < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid balance amount provided.']);
    exit();
}
if (empty($admin_username) || empty($admin_password) || empty($date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Admin credentials and date are required.']);
    exit();
}

// --- 2. Database Connection ---
require_once __DIR__ . '/../config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// --- 3. Core Logic in a Transaction ---
$shop_id = (int)$_SESSION['shop_id']; // The shop is from the logged-in user's session.

try {
    $pdo->beginTransaction();

    // --- Step A: Find the authorizing user by USERNAME ---
    $stmt_user = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt_user->execute([$admin_username]);
    $authorizing_user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$authorizing_user) {
        throw new Exception('Admin username not found.');
    }

    // --- Step B: Verify their password ---
    if (!password_verify($admin_password, $authorizing_user['password_hash'])) {
        throw new Exception('Incorrect admin password.');
    }
    
    // --- Step C: Verify their ROLE ---
    $allowed_roles = ['admin', 'manager', 'supervisor'];
    if (!in_array($authorizing_user['role'], $allowed_roles)) {
        throw new Exception('The provided user does not have permission to authorize this action.');
    }

    // --- Step D: Find or create the reconciliation record (from previous logic) ---
    $stmt_recon_check = $pdo->prepare("SELECT id, opening_cash_balance FROM daily_reconciliation WHERE shop_id = ? AND reconciliation_date = ?");
    $stmt_recon_check->execute([$shop_id, $date]);
    $recon_data = $stmt_recon_check->fetch(PDO::FETCH_ASSOC);
    
    $action_type = '';
    $old_balance = 0.00;
    $recon_id = null;
    
    if ($recon_data) {
        // Record exists, UPDATE it.
        $recon_id = $recon_data['id'];
        $old_balance = $recon_data['opening_cash_balance'];
        $action_type = 'UPDATE';
        $stmt_exec = $pdo->prepare("UPDATE daily_reconciliation SET opening_cash_balance = ? WHERE id = ?");
        $stmt_exec->execute([$new_balance, $recon_id]);
    } else {
        // Record does not exist, INSERT it.
        $action_type = 'CREATE';
        $stmt_exec = $pdo->prepare("INSERT INTO daily_reconciliation (shop_id, reconciliation_date, opening_cash_balance) VALUES (?, ?, ?)");
        $stmt_exec->execute([$shop_id, $date, $new_balance]);
        $recon_id = $pdo->lastInsertId();
    }

    // --- Step E: Log the change under the ADMIN's name ---
    $description = "Admin '{$authorizing_user['username']}' set opening balance for shop_id {$shop_id} on {$date}.";
    $details = json_encode(['old_balance' => $old_balance, 'new_balance' => $new_balance, 'approved_by_user_id' => $authorizing_user['id'], 'session_user_id' => $_SESSION['user_id']]);
    
    $stmt_log = $pdo->prepare(
        "INSERT INTO activity_log (user_id, username_snapshot, action_type, target_entity, target_entity_id, description, details, ip_address) 
         VALUES (?, ?, 'ADMIN_APPROVAL', 'daily_reconciliation', ?, ?, ?, ?)"
    );
    $stmt_log->execute([$authorizing_user['id'], $authorizing_user['username'], $recon_id, $description, $details, $_SERVER['REMOTE_ADDR']]);

    $pdo->commit();

    // --- 4. Send Success Response ---
    echo json_encode(['success' => true, 'message' => 'Opening balance updated successfully.', 'new_balance' => $new_balance]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}