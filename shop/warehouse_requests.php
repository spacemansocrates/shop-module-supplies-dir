<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    header('Location: /login.php');
    exit();
}

// --- DB Connection ---
// (Your existing DB connection code remains here)
$host = 'srv582.hstgr.io';
$dbname = 'u789944046_suppliesdirect';
$user = 'u789944046_socrates';
$pass = 'Naho1386'; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
// --- End of DB Connection ---

$user_id = $_SESSION['user_id'];
$warehouse_id = $_SESSION['warehouse_id'];

// --- HANDLE PROCESSING A REQUEST (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_request'])) {
    $stock_transfer_id = filter_input(INPUT_POST, 'stock_transfer_id', FILTER_VALIDATE_INT);
    $item_ids = $_POST['item_ids'] ?? [];
    $quantities_shipped = $_POST['quantities_shipped'] ?? [];
    
    $stmt_check = $pdo->prepare("SELECT id FROM stock_transfers WHERE id = ? AND from_warehouse_id = ?");
    $stmt_check->execute([$stock_transfer_id, $warehouse_id]);
    if ($stmt_check->fetchColumn() === false) {
        $error_message = "Error: Invalid request ID or permission denied.";
    } else {
        $pdo->beginTransaction();
        try {
            $sql_get_balance = "SELECT quantity_in_stock FROM warehouse_stock WHERE warehouse_id = ? AND product_id = ?";
            $stmt_get_balance = $pdo->prepare($sql_get_balance);
            
            $sql_update_item = "UPDATE stock_transfer_items SET quantity_shipped = ? WHERE id = ?";
            $stmt_update_item = $pdo->prepare($sql_update_item);

            $sql_update_stock = "UPDATE warehouse_stock SET quantity_in_stock = quantity_in_stock - ? WHERE warehouse_id = ? AND product_id = ?";
            $stmt_update_stock = $pdo->prepare($sql_update_stock);
            
            // IMPORTANT: Adding `warehouse_id` to the log insert query
            $sql_log_trans = "INSERT INTO stock_transactions (warehouse_id, product_id, transaction_type, quantity, running_balance, reference_type, reference_id, reference_number, scanned_by_user_id, notes) VALUES (?, ?, 'stock_out', ?, ?, 'stock_transfer', ?, (SELECT transfer_reference FROM stock_transfers WHERE id = ?), ?, ?)";
            $stmt_log_trans = $pdo->prepare($sql_log_trans);

            foreach ($item_ids as $index => $item_id) {
                $shipped_qty = (int)($quantities_shipped[$index] ?? 0);
                if ($shipped_qty > 0) {
                    $product_id_stmt = $pdo->prepare("SELECT product_id FROM stock_transfer_items WHERE id = ?");
                    $product_id_stmt->execute([$item_id]);
                    $product_id = $product_id_stmt->fetchColumn();
                    
                    if ($product_id) {
                        $stmt_get_balance->execute([$warehouse_id, $product_id]);
                        $current_stock = $stmt_get_balance->fetchColumn();
                        $running_balance = $current_stock - $shipped_qty;
                        
                        $stmt_update_item->execute([$shipped_qty, $item_id]);
                        $stmt_update_stock->execute([$shipped_qty, $warehouse_id, $product_id]);
                        // Execute log with warehouse_id
                        $stmt_log_trans->execute([$warehouse_id, $product_id, $shipped_qty, $running_balance, $stock_transfer_id, $stock_transfer_id, $user_id, "Shipped for request ID $stock_transfer_id"]);
                    }
                }
            }

            $sql_update_transfer = "UPDATE stock_transfers SET status = 'In-Transit', shipped_by_user_id = ?, shipped_at = NOW() WHERE id = ?";
            $stmt_update_transfer = $pdo->prepare($sql_update_transfer);
            $stmt_update_transfer->execute([$user_id, $stock_transfer_id]);

            $pdo->commit();
            header("Location: warehouse_requests.php?status=processed");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Failed to process request: " . $e->getMessage();
        }
    }
}
if(isset($_GET['status']) && $_GET['status'] == 'processed') $success_message = "Request processed and stock updated successfully.";

// --- FETCH DATA FOR DISPLAY ---

// Get user and warehouse info for the header
$stmt_header = $pdo->prepare("SELECT u.full_name as user_name, w.name as warehouse_name FROM users u, warehouses w WHERE u.id = ? AND w.id = ?");
$stmt_header->execute([$user_id, $warehouse_id]);
$header_info = $stmt_header->fetch();

// 1. PENDING requests
$sql_list = "SELECT st.*, s.name as to_shop_name, u.full_name as requester_name FROM stock_transfers st JOIN shops s ON st.to_shop_id = s.id JOIN users u ON st.requested_by_user_id = u.id WHERE st.from_warehouse_id = ? AND st.status = 'Pending' ORDER BY st.created_at ASC";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute([$warehouse_id]);
$pending_requests = $stmt_list->fetchAll();

// 2. PROCESSED/HISTORY requests
$sql_history = "SELECT st.*, s.name as to_shop_name, u_req.full_name as requester_name, u_ship.full_name as shipped_by_name FROM stock_transfers st JOIN shops s ON st.to_shop_id = s.id JOIN users u_req ON st.requested_by_user_id = u_req.id LEFT JOIN users u_ship ON st.shipped_by_user_id = u_ship.id WHERE st.from_warehouse_id = ? AND st.status IN ('In-Transit', 'Completed', 'Cancelled') ORDER BY st.shipped_at DESC, st.created_at DESC";
$stmt_history = $pdo->prepare($sql_history);
$stmt_history->execute([$warehouse_id]);
$processed_requests = $stmt_history->fetchAll();

// 3. Pre-fetch items for PENDING requests modals
$request_ids = array_column($pending_requests, 'id');
$request_items = [];
if (!empty($request_ids)) {
    $in_clause = str_repeat('?,', count($request_ids) - 1) . '?';
    $sql_items = "SELECT sti.id, sti.stock_transfer_id, sti.quantity_requested, p.name as product_name, p.sku, p.id as product_id, ws.quantity_in_stock FROM stock_transfer_items sti JOIN products p ON sti.product_id = p.id LEFT JOIN warehouse_stock ws ON p.id = ws.product_id AND ws.warehouse_id = ? WHERE sti.stock_transfer_id IN ($in_clause)";
    $stmt_items = $pdo->prepare($sql_items);
    $params = array_merge([$warehouse_id], $request_ids);
    $stmt_items->execute($params);
    $items_result = $stmt_items->fetchAll();
    foreach ($items_result as $item) { $request_items[$item['stock_transfer_id']][] = $item; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Requests</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Reusing the same CSS from the dashboard -->
    <style>
        body { background-color: #F3F4F6; font-family: 'Inter', sans-serif; }
        .sidebar { width: 260px; min-height: 100vh; background-color: #fff; padding: 20px; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
        .sidebar .nav-link { display: flex; align-items: center; padding: 12px 15px; border-radius: 8px; color: #555; font-weight: 500; margin-bottom: 5px; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background-color: #4F46E5; color: #fff; }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 10px; }
        .main-content { flex: 1; padding: 30px; }
        .dashboard-card { background-color: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; height: 100%; }
        .table { vertical-align: middle; }
        .btn-primary { background-color: #4F46E5; border-color: #4F46E5; border-radius: 8px; font-weight: 500; }
        .btn-primary:hover { background-color: #4338CA; border-color: #4338CA; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar">
        <h5 class="px-2 mb-4"><strong>Supplies Direct</strong></h5>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="warehouse_dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li class="nav-item"><a class="nav-link active" href="warehouse_requests.php"><i class="fas fa-dolly"></i> Stock Requests</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_inventory.php"><i class="fas fa-random"></i> Stock Adjustments</a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0"><strong>Stock Requests</strong></h3>
                <p class="text-muted">Process pending requests and view transfer history.</p>
            </div>
            <div class="d-flex align-items-center">
                <div class="badge bg-light text-dark p-2 me-3">
                    <i class="fas fa-warehouse me-2"></i> <?= htmlspecialchars($header_info['warehouse_name'] ?? '') ?>
                </div>
                <div class="text-end"><strong><?= htmlspecialchars($header_info['user_name'] ?? '') ?></strong></div>
            </div>
        </header>

        <?php if (!empty($error_message)): ?><div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
        <?php if (!empty($success_message)): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

        <!-- Main Card with Tabs -->
        <div class="dashboard-card">
            <ul class="nav nav-tabs" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-tab-pane" type="button" role="tab">
                        Pending Requests <span class="badge rounded-pill bg-primary ms-1"><?= count($pending_requests) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-tab-pane" type="button" role="tab">
                        Processed History
                    </button>
                </li>
            </ul>
            <div class="tab-content pt-3" id="myTabContent">
                <!-- PENDING TAB PANE -->
                <div class="tab-pane fade show active" id="pending-tab-pane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>REQUEST REF #</th><th>TO SHOP</th><th>REQUESTER</th><th>DATE</th><th>ACTIONS</th></tr></thead>
                            <tbody>
                            <?php if (empty($pending_requests)): ?>
                                <tr><td colspan="5" class="text-center p-5 text-muted">No pending requests. Great work!</td></tr>
                            <?php else: ?>
                                <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($request['transfer_reference']) ?></strong></td>
                                    <td><?= htmlspecialchars($request['to_shop_name']) ?></td>
                                    <td><?= htmlspecialchars($request['requester_name']) ?></td>
                                    <td><?= date('M j, Y H:i', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary process-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#processRequestModal"
                                                data-request='<?= htmlspecialchars(json_encode($request)) ?>'
                                                data-items='<?= isset($request_items[$request['id']]) ? htmlspecialchars(json_encode($request_items[$request['id']])) : "[]" ?>'>
                                            <i class="fas fa-dolly-flatbed me-1"></i> Process
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- HISTORY TAB PANE -->
                <div class="tab-pane fade" id="history-tab-pane" role="tabpanel">
                     <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>REF #</th><th>TO SHOP</th><th>STATUS</th><th>PROCESSED BY</th><th>DATE PROCESSED</th><th>ACTIONS</th></tr></thead>
                            <tbody>
                                <?php if (empty($processed_requests)): ?>
                                    <tr><td colspan="6" class="text-center p-5 text-muted">No processed requests found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($processed_requests as $request): 
                                        $status_color = 'secondary';
                                        if ($request['status'] == 'In-Transit') $status_color = 'info';
                                        if ($request['status'] == 'Completed') $status_color = 'success';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($request['transfer_reference']) ?></strong></td>
                                        <td><?= htmlspecialchars($request['to_shop_name']) ?></td>
                                        <td><span class="badge bg-<?= $status_color ?>"><?= htmlspecialchars($request['status']) ?></span></td>
                                        <td><?= htmlspecialchars($request['shipped_by_name'] ?? 'N/A') ?></td>
                                        <td><?= $request['shipped_at'] ? date('M j, Y H:i', strtotime($request['shipped_at'])) : 'N/A' ?></td>
                                        <td>
                                            <?php if($request['status'] != 'Cancelled'): ?>
                                            <a href="generate_transfer_note.php?id=<?= $request['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                                <i class="fas fa-print"></i> Print Note
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Process Request Modal (Updated for Bootstrap 5) -->
<div class="modal fade" id="processRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="stock_transfer_id" id="modal-transfer-id">
                <div class="modal-header">
                    <h5 class="modal-title">Process Request: <span id="modal-ref"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>For shop: <strong id="modal-shop-name"></strong></p>
                    <table class="table table-sm">
                        <thead><tr><th>Product</th><th class="text-end">In Stock</th><th class="text-end">Requested</th><th>Qty to Ship</th></tr></thead>
                        <tbody id="modal-items-tbody"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="process_request" class="btn btn-primary"><i class="fas fa-check me-2"></i>Confirm and Ship Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Activate tab if returning from a process action
    if(window.location.search.includes('status=processed')) {
        const tab = new bootstrap.Tab('#history-tab');
        tab.show();
    }

    $('.process-btn').click(function() {
        var request = $(this).data('request');
        var items = $(this).data('items');

        $('#modal-transfer-id').val(request.id);
        $('#modal-ref').text(request.transfer_reference);
        $('#modal-shop-name').text(request.to_shop_name);
        
        var itemsTbody = $('#modal-items-tbody');
        itemsTbody.empty();
        items.forEach(function(item) {
            var inStock = item.quantity_in_stock || 0;
            var qtyToShip = Math.min(inStock, item.quantity_requested);
            
            var row = `
                <tr>
                    <td>${item.product_name}<br><small class="text-muted">${item.sku}</small></td>
                    <td class="text-end fw-bold">${inStock}</td>
                    <td class="text-end">${item.quantity_requested}</td>
                    <td>
                        <input type="hidden" name="item_ids[]" value="${item.id}">
                        <input type="number" name="quantities_shipped[]" class="form-control form-control-sm" value="${qtyToShip}" min="0" max="${inStock}" required>
                    </td>
                </tr>`;
            itemsTbody.append(row);
        });
    });
});
</script>
</body>
</html>