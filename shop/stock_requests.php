<?php
// 1. SETUP & SECURITY
// =================================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

// --- Database Connection ---
$host = 'srv582.hstgr.io'; $dbname = 'u789944046_suppliesdirect'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) { throw new \PDOException($e->getMessage(), (int)$e->getCode()); }
// --- End DB Connection ---

$user_id = $_SESSION['user_id'];
$shop_id = $_SESSION['shop_id'];
$shop_name = $_SESSION['shop_name'];

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_message = '';
$success_message = '';

// 2. HANDLE NEW STOCK REQUEST SUBMISSION (POST)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "CSRF token validation failed.";
    } else {
        if (isset($_POST['create_request'])) {
            $from_warehouse_id = filter_input(INPUT_POST, 'from_warehouse_id', FILTER_VALIDATE_INT);
            $notes = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING));
            $product_ids = $_POST['product_ids'] ?? [];
            $quantities = $_POST['quantities'] ?? [];

            if (!$from_warehouse_id) {
                $error_message = "Please select a source warehouse.";
            } elseif (empty($product_ids) || count($product_ids) != count($quantities)) {
                $error_message = "Please add at least one product to the request.";
            } else {
                $pdo->beginTransaction();
                try {
                    // a. Create the main transfer record
                    $transfer_reference = 'REQ-' . $shop_id . '-' . time();
                    // Status is 'Pending' initially
                    $sql_transfer = "INSERT INTO stock_transfers (transfer_reference, from_warehouse_id, to_shop_id, status, notes, requested_by_user_id, created_at) 
                                     VALUES (?, ?, ?, 'Pending', ?, ?, NOW())";
                    $stmt_transfer = $pdo->prepare($sql_transfer);
                    $stmt_transfer->execute([$transfer_reference, $from_warehouse_id, $shop_id, $notes, $user_id]);
                    $stock_transfer_id = $pdo->lastInsertId();

                    // b. Create the item records
                    $sql_items = "INSERT INTO stock_transfer_items (stock_transfer_id, product_id, quantity_requested) VALUES (?, ?, ?)";
                    $stmt_items = $pdo->prepare($sql_items);

                    foreach ($product_ids as $index => $product_id) {
                        $quantity = filter_var($quantities[$index], FILTER_VALIDATE_INT);
                        if ($product_id && $quantity > 0) {
                            $stmt_items->execute([$stock_transfer_id, $product_id, $quantity]);
                        }
                    }
                    
                    $pdo->commit();
                    header("Location: stock_requests.php?status=success_create");
                    exit();

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Failed to create request: " . $e->getMessage();
                }
            }
        } elseif (isset($_POST['mark_as_received'])) {
            $transfer_id = filter_input(INPUT_POST, 'transfer_id', FILTER_VALIDATE_INT);

            if (!$transfer_id) {
                $error_message = "Invalid transfer ID.";
            } else {
                $pdo->beginTransaction();
                try {
                    // 1. Get transfer details and items
                    $stmt_transfer = $pdo->prepare("SELECT status, to_shop_id FROM stock_transfers WHERE id = ?");
                    $stmt_transfer->execute([$transfer_id]);
                    $transfer = $stmt_transfer->fetch();

                    if (!$transfer || $transfer['to_shop_id'] != $shop_id || $transfer['status'] !== 'In-Transit') {
                        throw new Exception("Transfer not found, not intended for this shop, or not in 'In-Transit' status.");
                    }

                    $stmt_items = $pdo->prepare("SELECT product_id, quantity_shipped FROM stock_transfer_items WHERE stock_transfer_id = ?");
                    $stmt_items->execute([$transfer_id]);
                    $items = $stmt_items->fetchAll();

                    if (empty($items)) {
                        throw new Exception("No items found for this transfer.");
                    }

                    // 2. Update stock_transfer status to 'Completed' and set received_at/received_by_user_id
                    // Note: 'Completed' status is used as 'Received' is not in the ENUM for stock_transfers
                    $sql_update_transfer = "UPDATE stock_transfers SET status = 'Completed', received_at = NOW(), received_by_user_id = ? WHERE id = ?";
                    $stmt_update_transfer = $pdo->prepare($sql_update_transfer);
                    $stmt_update_transfer->execute([$user_id, $transfer_id]);

                    // 3. Update quantity_received in stock_transfer_items
                    $sql_update_item = "UPDATE stock_transfer_items SET quantity_received = quantity_shipped WHERE stock_transfer_id = ? AND product_id = ?";
                    $stmt_update_item = $pdo->prepare($sql_update_item);

                    // 4. Update shop_stock (quantity_in_stock) AND daily_stock_summary
                    $sql_update_shop_stock = "
                        INSERT INTO shop_stock (shop_id, product_id, quantity_in_stock)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity_in_stock = quantity_in_stock + VALUES(quantity_in_stock)
                    ";
                    $stmt_update_shop_stock = $pdo->prepare($sql_update_shop_stock);

                    // Prepare statements for daily_stock_summary
                    $stmt_check_dss = $pdo->prepare(
                        "SELECT dss.id, dss.opening_quantity FROM daily_stock_summary dss
                         JOIN daily_reconciliation dr ON dss.daily_reconciliation_id = dr.id
                         WHERE dr.shop_id = ? AND dr.reconciliation_date = ? AND dss.product_id = ?"
                    );
                    $stmt_update_dss = $pdo->prepare(
                        "UPDATE daily_stock_summary SET quantity_added = quantity_added + ? WHERE id = ?"
                    );
                    $stmt_insert_dss = $pdo->prepare(
                        "INSERT INTO daily_stock_summary (daily_reconciliation_id, product_id, opening_quantity, quantity_sold, quantity_added, quantity_adjusted, closing_quantity) 
                         VALUES (?, ?, ?, 0, ?, 0, ?)"
                    );
                    $stmt_prev_day_closing = $pdo->prepare(
                        "SELECT dss.closing_quantity FROM daily_stock_summary dss
                         JOIN daily_reconciliation dr ON dss.daily_reconciliation_id = dr.id
                         WHERE dr.shop_id = ? AND dr.reconciliation_date < ? AND dss.product_id = ?
                         ORDER BY dr.reconciliation_date DESC LIMIT 1"
                    );
                    $stmt_get_reconciliation_id = $pdo->prepare(
                        "SELECT id FROM daily_reconciliation WHERE shop_id = ? AND reconciliation_date = ?"
                    );

                    foreach ($items as $item) {
                        if ($item['quantity_shipped'] > 0) { // Only process items that were actually shipped
                            $product_id = $item['product_id'];
                            $quantity_shipped = $item['quantity_shipped'];
                            $received_date = date('Y-m-d'); // Use current date for daily_stock_summary

                            // Update shop_stock
                            $stmt_update_shop_stock->execute([$shop_id, $product_id, $quantity_shipped]);

                            // Update quantity_received in stock_transfer_items
                            $stmt_update_item->execute([$transfer_id, $product_id]);

                            // Handle daily_stock_summary
                            $stmt_check_dss->execute([$shop_id, $received_date, $product_id]);
                            $dss_record = $stmt_check_dss->fetch();

                            if ($dss_record) {
                                // Record exists, update quantity_added
                                $stmt_update_dss->execute([$quantity_shipped, $dss_record['id']]);
                            } else {
                                // No record for today, insert a new one
                                // Get previous day's closing quantity for opening_quantity
                                $stmt_prev_day_closing->execute([$shop_id, $received_date, $product_id]);
                                $prev_day_closing_qty = $stmt_prev_day_closing->fetchColumn();
                                $opening_qty_for_new_dss = $prev_day_closing_qty ?: 0;

                                // Get daily_reconciliation_id for today
                                $stmt_get_reconciliation_id->execute([$shop_id, $received_date]);
                                $reconciliation_id = $stmt_get_reconciliation_id->fetchColumn();

                                if (!$reconciliation_id) {
                                    // This should ideally not happen if daily_reconciliation is handled first
                                    // but as a fallback, create it if missing
                                    $stmt_insert_reconciliation = $pdo->prepare("INSERT INTO daily_reconciliation (shop_id, reconciliation_date, opening_cash_balance, closing_cash_balance) VALUES (?, ?, '0.00', '0.00')");
                                    $stmt_insert_reconciliation->execute([$shop_id, $received_date]);
                                    $reconciliation_id = $pdo->lastInsertId();
                                }

                                // For a new record, closing_quantity will be opening_quantity + quantity_added initially
                                $initial_closing_qty = $opening_qty_for_new_dss + $quantity_shipped;
                                $stmt_insert_dss->execute([$reconciliation_id, $product_id, $opening_qty_for_new_dss, $quantity_shipped, $initial_closing_qty]);
                            }
                        }
                    }

                    $pdo->commit();
                    header("Location: stock_requests.php?status=success_receive");
                    exit();

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Failed to mark as received: " . $e->getMessage();
                }
            }
        }
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success_create') {
        $success_message = "Stock request created successfully!";
    } elseif ($_GET['status'] == 'success_receive') {
        $success_message = "Stock request marked as received and shop stock updated!";
    }
}


// 3. FETCH DATA FOR DISPLAY (GET)
// =================================================================

// --- Fetch data for the 'Create' modal dropdowns ---
$warehouses = $pdo->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, sku FROM products ORDER BY name")->fetchAll();

// --- Fetch low stock products for the current shop ---
$sql_low_stock = "
    SELECT 
        ss.product_id, 
        ss.quantity_in_stock, 
        ss.minimum_stock_level,
        p.name as product_name, 
        p.sku
    FROM shop_stock ss
    JOIN products p ON ss.product_id = p.id
    WHERE ss.shop_id = ? AND ss.quantity_in_stock < ss.minimum_stock_level
    ORDER BY p.name
";
$stmt_low_stock = $pdo->prepare($sql_low_stock);
$stmt_low_stock->execute([$shop_id]);
$low_stock_products = $stmt_low_stock->fetchAll();


// --- Filtering & Pagination Logic ---
$whereClauses = ['st.to_shop_id = ?'];
$params = [$shop_id];

$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $whereClauses[] = '(st.transfer_reference LIKE ?)';
    $params[] = "%$search%";
}

$status_filter = $_GET['status_filter'] ?? 'all';
if ($status_filter != 'all') {
    $whereClauses[] = 'st.status = ?';
    $params[] = $status_filter;
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers st $whereSql");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// --- Fetch main list of stock requests for the table ---
$sql_list = "
    SELECT 
        st.id, st.transfer_reference, st.status, st.notes, st.created_at, st.shipped_at, st.received_at,
        w.name as from_warehouse_name,
        requester.full_name as requester_name
    FROM stock_transfers st
    JOIN warehouses w ON st.from_warehouse_id = w.id
    JOIN users requester ON st.requested_by_user_id = requester.id
    $whereSql
    ORDER BY st.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute(array_merge($params, [$per_page, $offset]));
$transfers = $stmt_list->fetchAll();

// --- Pre-fetch items for the 'View Details' and 'Receive' modals ---
$transfer_ids = array_column($transfers, 'id');
$transfer_items = [];
if (!empty($transfer_ids)) {
    $in_clause = str_repeat('?,', count($transfer_ids) - 1) . '?';
    $sql_items_details = "
        SELECT 
            sti.stock_transfer_id, sti.quantity_requested, sti.quantity_shipped, sti.quantity_received,
            p.name as product_name, p.sku
        FROM stock_transfer_items sti
        JOIN products p ON sti.product_id = p.id
        WHERE sti.stock_transfer_id IN ($in_clause)
    ";
    $stmt_items_details = $pdo->prepare($sql_items_details);
    $stmt_items_details->execute($transfer_ids);
    $items_result = $stmt_items_details->fetchAll();
    foreach ($items_result as $item) {
        $transfer_items[$item['stock_transfer_id']][] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Requests</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .wrapper { display: flex; width: 100%; align-items: stretch; }
        #sidebar { min-width: 250px; max-width: 250px; background: #343a40; color: #fff; }
        #content { width: 100%; padding: 20px 40px; }
        .card { border: none; border-radius: 0.5rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); }
        .table thead th { border-bottom: 2px solid #dee2e6; font-weight: 600; background-color: #fff; }
        .table tbody tr { background-color: #fff; border-bottom: 1px solid #f1f1f1; }
        .table td, .table th { vertical-align: middle; padding: 1rem; }
        .filters { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .status-badge { font-size: 0.8em; font-weight: 600; padding: 0.4em 0.8em; border-radius: 50rem; }
        .status-Pending { background-color: #fff3cd; color: #856404; }
        .status-In-Transit { background-color: #d1ecf1; color: #0c5460; }
        .status-Completed { background-color: #d4edda; color: #155724; } /* This now covers the 'Received' state visually */
        .status-Cancelled { background-color: #f8d7da; color: #721c24; }
        .pagination-info { color: #6c757d; }
        .pagination .page-link { border: none; color: #6c757d; border-radius: 50% !important; margin: 0 2px; }
        .pagination .page-item.active .page-link { background-color: #007bff; color: white; }
        /* Style for Select2 to match Bootstrap */
        .select2-container .select2-selection--single { height: calc(1.5em + .75rem + 2px); }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 2.25rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: calc(1.5em + .75rem); }
        .item-row { display: flex; align-items: center; margin-bottom: 10px; }
        .item-row .form-control { margin-right: 10px; }
        .item-row > div { padding-right: 10px; } /* Add padding for spacing */
        .item-row .remove-item-btn { margin-left: 10px; } /* Space out the remove button */
        /* Styling for low stock items */
        .low-stock-item { 
            display: flex; 
            align-items: center; 
            margin-bottom: 8px; 
            padding: 8px 12px; 
            border: 1px solid #ffeeba; 
            background-color: #fff3cd; 
            border-radius: .25rem;
        }
        .low-stock-item input[type="checkbox"] { margin-right: 10px; }
        .low-stock-item .product-info { flex-grow: 1; }
        .low-stock-item .current-stock { font-size: 0.9em; color: #666; }
        .low-stock-item input[type="number"] { width: 80px; text-align: right; }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>

        <div id="content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Stock Requests</h2>
                <button class="btn btn-primary btn-lg" data-toggle="modal" data-target="#createRequestModal">
                    <i class="fas fa-plus-circle mr-2"></i>Create New Request
                </button>
            </div>

            <?php if ($error_message): ?><div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="get" action="stock_requests.php">
                        <div class="filters">
                            <select name="status_filter" class="form-control" style="width: auto;" onchange="this.form.submit()">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="In-Transit" <?= $status_filter == 'In-Transit' ? 'selected' : '' ?>>In-Transit</option>
                                <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <div class="input-group" style="width: 300px;">
                                <input type="text" name="search" class="form-control" placeholder="Search by reference..." value="<?= htmlspecialchars($search) ?>">
                                <div class="input-group-append"><button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button></div>
                            </div>
                        </div>
                    </form>
                    
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>REFERENCE #</th>
                                    <th>FROM WAREHOUSE</th>
                                    <th>DATE REQUESTED</th>
                                    <th>STATUS</th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transfers)): ?>
                                    <tr><td colspan="5" class="text-center p-5">No stock requests found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transfers as $transfer): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($transfer['transfer_reference']) ?></strong></td>
                                        <td><?= htmlspecialchars($transfer['from_warehouse_name']) ?></td>
                                        <td><?= date('M j, Y H:i', strtotime($transfer['created_at'])) ?></td>
                                        <td><span class="status-badge status-<?= str_replace(' ', '-', $transfer['status']) ?>"><?= htmlspecialchars($transfer['status']) ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary view-details-btn" 
                                                    data-toggle="modal" 
                                                    data-target="#viewDetailsModal"
                                                    data-details='<?= htmlspecialchars(json_encode($transfer)) ?>'
                                                    data-items='<?= isset($transfer_items[$transfer['id']]) ? htmlspecialchars(json_encode($transfer_items[$transfer['id']])) : "[]" ?>'>
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if ($transfer['status'] == 'In-Transit'): ?>
                                                <button class="btn btn-sm btn-success mark-received-btn ml-2" 
                                                        data-toggle="modal" 
                                                        data-target="#receiveTransferModal"
                                                        data-transfer-id="<?= $transfer['id'] ?>"
                                                        data-transfer-reference="<?= htmlspecialchars($transfer['transfer_reference']) ?>"
                                                        data-items='<?= isset($transfer_items[$transfer['id']]) ? htmlspecialchars(json_encode($transfer_items[$transfer['id']])) : "[]" ?>'>
                                                    <i class="fas fa-check-circle"></i> Mark as Received
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_records) ?> of <?= $total_records ?> results</div>
                        <nav><ul class="pagination">
                            <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">«</a></li><?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a></li><?php endfor; ?>
                            <?php if ($page < $total_pages): ?><li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">»</a></li><?php endif; ?>
                        </ul></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createRequestModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form action="stock_requests.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="create_request" value="1">
                    <div class="modal-header"><h5 class="modal-title">Create New Stock Request</h5><button type="button" class="close" data-dismiss="modal">×</button></div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>From Warehouse</label>
                            <select name="from_warehouse_id" class="form-control" required>
                                <option value="">-- Select a Warehouse --</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= $warehouse['id'] ?>"><?= htmlspecialchars($warehouse['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <hr>
                        <h6>Low Stock Products in Your Shop</h6>
                        <div id="low-stock-products-container">
                            <?php if (empty($low_stock_products)): ?>
                                <p class="alert alert-info">No products currently below minimum stock levels in your shop.</p>
                            <?php else: ?>
                                <?php foreach ($low_stock_products as $lsp): ?>
                                <div class="low-stock-item" data-product-id="<?= $lsp['product_id'] ?>" data-product-name="<?= htmlspecialchars($lsp['product_name']) ?>" data-product-sku="<?= htmlspecialchars($lsp['sku']) ?>">
                                    <input type="checkbox" class="low-stock-checkbox">
                                    <div class="product-info">
                                        <strong><?= htmlspecialchars($lsp['product_name']) ?></strong> (SKU: <?= htmlspecialchars($lsp['sku']) ?>)<br>
                                        <span class="current-stock">Current Stock: <?= $lsp['quantity_in_stock'] ?>, Min Level: <?= $lsp['minimum_stock_level'] ?></span>
                                    </div>
                                    <input type="number" class="form-control form-control-sm low-stock-qty" placeholder="Request Qty" min="1" value="<?= max(1, $lsp['minimum_stock_level'] - $lsp['quantity_in_stock']) ?>">
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mt-2">Select items above to automatically add them to your request. You can also add other products below.</p>
                        <hr>
                        <h6>Other Products to Request</h6>
                        <div id="items-container">
                            </div>
                        <button type="button" id="add-item-btn" class="btn btn-sm btn-outline-success mt-2"><i class="fas fa-plus"></i> Add Another Product</button>
                        <hr>
                        <div class="form-group"><label>Notes (Optional)</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewDetailsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Request Details: <span id="details-ref"></span></h5><button type="button" class="close" data-dismiss="modal">×</button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>From Warehouse:</strong> <span id="details-warehouse"></span></p>
                            <p><strong>To Shop:</strong> <?= htmlspecialchars($shop_name) ?></p>
                            <p><strong>Requested By:</strong> <span id="details-requester"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Status:</strong> <span id="details-status"></span></p>
                            <p><strong>Date Requested:</strong> <span id="details-date-req"></span></p>
                            <p><strong>Notes:</strong> <span id="details-notes"></span></p>
                        </div>
                    </div>
                    <hr>
                    <h6>Requested Items</h6>
                    <table class="table table-sm">
                        <thead><tr><th>Product</th><th>SKU</th><th class="text-right">Qty Requested</th><th class="text-right">Qty Shipped</th><th class="text-right">Qty Received</th></tr></thead>
                        <tbody id="details-items-tbody"></tbody>
                    </table>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="receiveTransferModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form action="stock_requests.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="mark_as_received" value="1">
                    <input type="hidden" name="transfer_id" id="receive-transfer-id">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Stock Receipt for <span id="receive-ref"></span></h5>
                        <button type="button" class="close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to mark this stock request as **received**? All shipped quantities will be added to your shop's stock.</p>
                        <h6>Items to be Received:</h6>
                        <table class="table table-sm">
                            <thead><tr><th>Product</th><th>SKU</th><th class="text-right">Qty Shipped</th></tr></thead>
                            <tbody id="receive-items-tbody"></tbody>
                        </table>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle mr-2"></i>By confirming, you are verifying that all the above items have been received into your shop's inventory.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Receive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div id="item-row-template" style="display: none;">
        <div class="item-row">
            <div style="flex: 4;">
                <select name="product_ids[]" class="form-control product-select" required>
                    <option value="">-- Select a Product --</option>
                    <?php foreach ($products as $product): ?>
                    <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1;">
                <input type="number" name="quantities[]" class="form-control" placeholder="Qty" min="1" required>
            </div>
            <button type="button" class="btn btn-sm btn-danger remove-item-btn"><i class="fas fa-trash"></i></button>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    $(document).ready(function() {
        // --- Create Request Modal Logic ---
        function initializeSelect2(element) {
            $(element).select2({
                dropdownParent: $('#createRequestModal') // Important for modals
            });
        }

        function addItemRow(productId = '', productName = '', productSku = '', quantity = '') {
            var newItemRow = $('#item-row-template').html();
            var $newItemRow = $(newItemRow);

            if (productId && productName) {
                var optionText = productName + (productSku ? ' (' + productSku + ')' : '');
                var newOption = new Option(optionText, productId, true, true); // text, value, defaultSelected, selected
                $newItemRow.find('.product-select').append(newOption).val(productId).trigger('change');
                $newItemRow.find('input[name="quantities[]"]').val(quantity || 1);
            }
            
            $('#items-container').append($newItemRow);
            initializeSelect2($newItemRow.find('.product-select'));
        }

        $('#add-item-btn').click(function() {
            addItemRow(); // Add an empty row for manual selection
        });

        // Add one row by default when opening the modal, if no low stock items are present
        $('#createRequestModal').on('show.bs.modal', function() {
            // Clear previous items from manual section
            $('#items-container').empty(); 

            // Automatically add low stock items if any, and check their boxes
            $('.low-stock-item').each(function() {
                var $item = $(this);
                $item.find('.low-stock-checkbox').prop('checked', false); // Uncheck initially
            });

            if ($('#low-stock-products-container p.alert-info').length === 0) { // If there are low stock products
                // Pre-check all low-stock items and add them to the request
                $('.low-stock-item').each(function() {
                    var $item = $(this);
                    $item.find('.low-stock-checkbox').prop('checked', true);
                    var productId = $item.data('product-id');
                    var productName = $item.data('product-name');
                    var productSku = $item.data('product-sku');
                    var quantity = $item.find('.low-stock-qty').val();
                    addItemRow(productId, productName, productSku, quantity);
                });
            } else {
                 // If no low stock items, add one empty row for manual entry
                 if ($('#items-container .item-row').length === 0) {
                     addItemRow();
                 }
            }
        });

        // Handle checkbox change for low stock items
        $('#low-stock-products-container').on('change', '.low-stock-checkbox', function() {
            var $item = $(this).closest('.low-stock-item');
            var productId = $item.data('product-id');
            var productName = $item.data('product-name');
            var productSku = $item.data('product-sku');
            var quantity = $item.find('.low-stock-qty').val();

            if (this.checked) {
                // Add the item to the main request list if not already there
                if ($('#items-container .item-row select[name="product_ids[]"][value="' + productId + '"]').length === 0) {
                    addItemRow(productId, productName, productSku, quantity);
                } else {
                    // If it exists, update its quantity if needed (optional, current logic simply adds)
                    // You might want to prevent adding duplicates or update existing quantities
                }
            } else {
                // Remove the item from the main request list
                $('#items-container .item-row').has('select[name="product_ids[]"][value="' + productId + '"]').remove();
            }
        });

        // Remove item row
        $('#items-container').on('click', '.remove-item-btn', function() {
            var $rowToRemove = $(this).closest('.item-row');
            var removedProductId = $rowToRemove.find('.product-select').val();

            // Uncheck the corresponding low-stock checkbox if it exists
            if (removedProductId) {
                $('.low-stock-item[data-product-id="' + removedProductId + '"] .low-stock-checkbox').prop('checked', false);
            }
            $rowToRemove.remove();
        });


        // --- View Details Modal Logic ---
        $('.view-details-btn').click(function() {
            var details = $(this).data('details');
            var items = $(this).data('items');

            $('#details-ref').text(details.transfer_reference);
            $('#details-warehouse').text(details.from_warehouse_name);
            $('#details-requester').text(details.requester_name);
            $('#details-status').html('<span class="status-badge status-' + details.status.replace(' ', '-') + '">' + details.status + '</span>');
            $('#details-date-req').text(new Date(details.created_at).toLocaleString());
            $('#details-notes').text(details.notes || 'N/A');

            var itemsTbody = $('#details-items-tbody');
            itemsTbody.empty();
            if (items.length > 0) {
                items.forEach(function(item) {
                    var row = '<tr>' +
                        '<td>' + item.product_name + '</td>' +
                        '<td>' + item.sku + '</td>' +
                        '<td class="text-right">' + (item.quantity_requested || 0) + '</td>' +
                        '<td class="text-right">' + (item.quantity_shipped || 0) + '</td>' +
                        '<td class="text-right">' + (item.quantity_received || 0) + '</td>' +
                        '</tr>';
                    itemsTbody.append(row);
                });
            } else {
                itemsTbody.append('<tr><td colspan="5" class="text-center">No items in this request.</td></tr>');
            }
        });

        // --- Mark as Received Modal Logic ---
        $('.mark-received-btn').click(function() {
            var transferId = $(this).data('transfer-id');
            var transferRef = $(this).data('transfer-reference');
            var items = $(this).data('items');

            $('#receive-transfer-id').val(transferId);
            $('#receive-ref').text(transferRef);

            var itemsTbody = $('#receive-items-tbody');
            itemsTbody.empty();
            if (items.length > 0) {
                items.forEach(function(item) {
                    var row = '<tr>' +
                        '<td>' + item.product_name + '</td>' +
                        '<td>' + item.sku + '</td>' +
                        '<td class="text-right">' + (item.quantity_shipped || 0) + '</td>' +
                        '</tr>';
                    itemsTbody.append(row);
                });
            } else {
                itemsTbody.append('<tr><td colspan="3" class="text-center">No items to receive for this transfer.</td></tr>');
            }
        });

    });
    </script>
</body>
</html>