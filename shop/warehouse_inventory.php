<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    header('Location: /login.php');
    exit();
}

// DB Connection...
require_once __DIR__ . '/config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}

$user_id = $_SESSION['user_id'];
$warehouse_id = $_SESSION['warehouse_id'];

// --- HANDLE STOCK ADJUSTMENT (POST) - CORRECTED AND IMPROVED ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['adjust_stock'])) {
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $current_qty = filter_input(INPUT_POST, 'current_qty', FILTER_VALIDATE_INT);
    $new_physical_count = filter_input(INPUT_POST, 'new_physical_count', FILTER_VALIDATE_INT);
    $notes = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING));
    
    if ($product_id && $new_physical_count !== false && $new_physical_count >= 0) {
        $adjustment_qty = $new_physical_count - $current_qty;
        
        $pdo->beginTransaction();
        try {
            // 1. Update the warehouse_stock table
            $sql_update = "UPDATE warehouse_stock SET quantity_in_stock = ? WHERE product_id = ? AND warehouse_id = ?";
            $pdo->prepare($sql_update)->execute([$new_physical_count, $product_id, $warehouse_id]);

            // 2. Log the adjustment in stock_transactions (if there's a change)
            if ($adjustment_qty != 0) {
                // The running_balance after an adjustment IS the new physical count.
                $running_balance = $new_physical_count;
                $reference_number = "STK-".date("Ymd-His");
                $full_notes = "Stock take adjustment. " . ($notes ?? 'No reason given.');

                $sql_log = "INSERT INTO stock_transactions (warehouse_id, product_id, transaction_type, quantity, running_balance, reference_type, reference_number, scanned_by_user_id, notes) VALUES (?, ?, 'adjustment', ?, ?, 'stock_take', ?, ?, ?)";
                $pdo->prepare($sql_log)->execute([$warehouse_id, $product_id, $adjustment_qty, $running_balance, $reference_number, $user_id, $full_notes]);
            }
            
            $pdo->commit();
            header("Location: warehouse_inventory.php?status=adjusted");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Failed to adjust stock: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid physical count or product ID provided.";
    }
}
if(isset($_GET['status']) && $_GET['status'] == 'adjusted') $success_message = "Stock count adjusted successfully.";

// --- FETCH DATA FOR DISPLAY ---

// Get user and warehouse info for the header
$stmt_header = $pdo->prepare("SELECT u.full_name as user_name, w.name as warehouse_name FROM users u, warehouses w WHERE u.id = ? AND w.id = ?");
$stmt_header->execute([$user_id, $warehouse_id]);
$header_info = $stmt_header->fetch();

// Paginated list of all products in this warehouse
$search = trim($_GET['search'] ?? '');
$whereSql = 'ws.warehouse_id = ?';
$params = [$warehouse_id];
if(!empty($search)){
    $whereSql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "SELECT p.id, p.name, p.sku, ws.quantity_in_stock, ws.minimum_stock_level FROM products p JOIN warehouse_stock ws ON p.id = ws.product_id WHERE $whereSql ORDER BY p.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventory = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Warehouse Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .low-stock { background-color: #FFEBEE !important; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar">
        <h5 class="px-2 mb-4"><strong>Supplies Direct</strong></h5>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="warehouse_dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_requests.php"><i class="fas fa-dolly"></i> Stock Requests</a></li>
            <li class="nav-item"><a class="nav-link active" href="warehouse_inventory.php"><i class="fas fa-random"></i> Stock Adjustments</a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0"><strong>Inventory & Adjustments</strong></h3>
                <p class="text-muted">View current stock levels and perform stock takes.</p>
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

        <!-- Main Card with Inventory Table -->
        <div class="dashboard-card">
            <form class="mb-4" method="GET">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Search by product name or SKU..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>PRODUCT</th><th>SKU</th><th class="text-end">QTY IN STOCK</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                    <?php foreach ($inventory as $item): 
                        $is_low_stock = $item['quantity_in_stock'] < $item['minimum_stock_level'] && $item['minimum_stock_level'] > 0;
                    ?>
                        <tr class="<?= $is_low_stock ? 'low-stock' : '' ?>">
                            <td><?= htmlspecialchars($item['name']) ?> <?= $is_low_stock ? '<span class="badge bg-danger">Low</span>' : '' ?></td>
                            <td><?= htmlspecialchars($item['sku']) ?></td>
                            <td class="text-end fw-bold"><?= $item['quantity_in_stock'] ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary adjust-btn" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#adjustStockModal"
                                        data-product-id="<?= $item['id'] ?>"
                                        data-product-name="<?= htmlspecialchars($item['name']) ?>"
                                        data-current-qty="<?= $item['quantity_in_stock'] ?>">
                                    <i class="fas fa-calculator me-1"></i> Adjust
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                     <?php if (empty($inventory)): ?>
                        <tr><td colspan="4" class="text-center p-5 text-muted">No products found matching your search.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Adjust Stock Modal (Updated for Bootstrap 5) -->
<div class="modal fade" id="adjustStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="product_id" id="adj-product-id">
                <input type="hidden" name="current_qty" id="adj-current-qty">
                <div class="modal-header">
                    <h5 class="modal-title">Stock Take</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6>Product: <span id="adj-product-name" class="fw-bold"></span></h6>
                    <p>Current system quantity: <strong id="adj-current-qty-display"></strong></p>
                    <hr>
                    <div class="mb-3">
                        <label for="new-physical-count" class="form-label"><strong>New Physical Count</strong></label>
                        <input type="number" name="new_physical_count" id="new-physical-count" class="form-control form-control-lg" required min="0">
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Reason / Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g., Damaged goods, found extra stock"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="adjust_stock" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    $('.adjust-btn').click(function() {
        $('#adj-product-id').val($(this).data('product-id'));
        $('#adj-product-name').text($(this).data('product-name'));
        $('#adj-current-qty').val($(this).data('current-qty'));
        $('#adj-current-qty-display').text($(this).data('current-qty'));
        // Pre-fill the new count with the current count for user convenience
        $('#new-physical-count').val($(this).data('current-qty')).focus();
    });
});
</script>
</body>
</html>