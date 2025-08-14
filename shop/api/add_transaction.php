<?php
// api/add_transaction.php
header('Content-Type: application/json');
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- DB Connection ---
require_once __DIR__ . '/../config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
    exit();
}

// --- Get Data and Session Info ---
$shop_id = $_SESSION['shop_id'];
$user_id = $_SESSION['user_id'];
$data = $_POST;
$transaction_type = $data['transaction_type'] ?? '';

// In api/add_transaction.php

try {
    $pdo->beginTransaction();

    switch ($transaction_type) {
        case 'petty_cash_expense':
            $amount = (float)($data['amount'] ?? 0);
            if ($amount <= 0) throw new Exception("Amount must be positive.");

            // 1. Get the float_id for the shop
            $stmt = $pdo->prepare("SELECT id FROM petty_cash_floats WHERE shop_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$shop_id]);
            $float = $stmt->fetch();
            if (!$float) { throw new Exception("No active petty cash float found for this shop."); }
            
            // 2. Insert the transaction
            $sql = "INSERT INTO petty_cash_transactions (float_id, transaction_type, amount, description, category, transaction_date, recorded_by_user_id) VALUES (?, 'expense', ?, ?, 'Manual Entry', NOW(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$float['id'], $amount, $data['description'], $user_id]);

            break;

        case 'stock_adjustment_damage':
        case 'stock_adjustment_internal':
            $product_id = (int)($data['product_id'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 0);
            if ($product_id <= 0 || $quantity <= 0) throw new Exception("Product and quantity are required.");

            // 1. Update master stock and log transaction (your existing logic is good)
            $stmt = $pdo->prepare("SELECT quantity_in_stock FROM shop_stock WHERE shop_id = ? AND product_id = ?");
            $stmt->execute([$shop_id, $product_id]);
            $stock = $stmt->fetch();
            $current_qty = $stock ? (int)$stock['quantity_in_stock'] : 0;

            $adj_qty = -abs($quantity);
            $new_qty = $current_qty + $adj_qty;
            
            $sql_log = "INSERT INTO stock_transactions (product_id, shop_id, transaction_type, quantity, running_balance, scanned_by_user_id, notes, transaction_date) VALUES (?, ?, 'adjustment', ?, ?, ?, ?, NOW())";
            $stmt_log = $pdo->prepare($sql_log);
            $stmt_log->execute([$product_id, $shop_id, $adj_qty, $new_qty, $user_id, $data['notes']]);
            
            $sql_update_stock = "UPDATE shop_stock SET quantity_in_stock = ? WHERE shop_id = ? AND product_id = ?";
            $stmt_update_stock = $pdo->prepare($sql_update_stock);
            $stmt_update_stock->execute([$new_qty, $shop_id, $product_id]);
            
            break;
        
        default:
            throw new Exception("Invalid transaction type specified.");
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Transaction added successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}