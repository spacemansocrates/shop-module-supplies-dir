<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) { header('Location: /login.php'); exit(); }

// --- DB Connection ---
require_once __DIR__ . '/config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}

$user_id = $_SESSION['user_id'];
$warehouse_id = $_SESSION['warehouse_id'];

// --- GET INPUTS ---
$product_id = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
$report_date_str = $_GET['date'] ?? date('Y-m-d');

if (!$product_id) {
    die("No product selected. Please go back and select a product.");
}

// --- FETCH DATA FOR REPORT ---
// Get product details
$stmt_product = $pdo->prepare("SELECT name, sku FROM products WHERE id = ?");
$stmt_product->execute([$product_id]);
$product = $stmt_product->fetch();

// --- STEP 1: MODIFIED SQL QUERY TO INCLUDE USER NAME ---
// Get all transactions FOR the selected report date, now with user's name.
$sql_daily = "SELECT 
                t.*,
                u.full_name as user_full_name
              FROM stock_transactions t
              LEFT JOIN users u ON t.scanned_by_user_id = u.id
              WHERE t.product_id = ? 
                AND t.warehouse_id = ? 
                AND DATE(t.transaction_date) = ? 
              ORDER BY t.transaction_date ASC, t.id ASC";
$stmt_daily = $pdo->prepare($sql_daily);
$stmt_daily->execute([$product_id, $warehouse_id, $report_date_str]);
$daily_transactions = $stmt_daily->fetchAll();

// --- CALCULATION LOGIC (No changes needed here) ---
$closing_balance = 0;
if (!empty($daily_transactions)) {
    $closing_balance = (int) end($daily_transactions)['running_balance'];
} else {
    $sql_last_balance = "SELECT running_balance FROM stock_transactions 
                         WHERE product_id = ? AND warehouse_id = ? AND transaction_date <= ?
                         ORDER BY transaction_date DESC, id DESC LIMIT 1";
    $stmt_last_balance = $pdo->prepare($sql_last_balance);
    $stmt_last_balance->execute([$product_id, $warehouse_id, $report_date_str . ' 23:59:59']);
    $closing_balance = (int) ($stmt_last_balance->fetchColumn() ?? 0);
}

$total_in = 0;
$total_out = 0;
foreach ($daily_transactions as $t) {
    $quantity = (int)$t['quantity'];
    if ($t['transaction_type'] == 'stock_out') {
        $total_out += abs($quantity);
    } elseif ($t['transaction_type'] == 'adjustment') {
         if ($quantity > 0) $total_in += $quantity; else $total_out += abs($quantity);
    } else {
        $total_in += $quantity;
    }
}

$opening_balance = $closing_balance - $total_in + $total_out;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Item Balance: <?= htmlspecialchars($product['name'] ?? '') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #F3F4F6; font-family: 'Inter', sans-serif; }
        .main-content { flex: 1; padding: 30px; }
        .dashboard-card { background-color: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; }
        .table { vertical-align: middle; }
        .modal-body .detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .modal-body .detail-row:last-child { border-bottom: none; }
        .modal-body .detail-label { color: #6c757d; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar can be included here -->
    <nav class="sidebar">
        <h5 class="px-2 mb-4"><strong>Supplies Direct</strong></h5>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="warehouse_dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_requests.php"><i class="fas fa-dolly"></i> Stock Requests</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_inventory.php"><i class="fas fa-random"></i> Stock Adjustments</a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>
    
    <main class="main-content">
        <header class="d-flex justify-content-between align-items-center mb-4">
             <div>
                <h3 class="mb-0"><strong>Item Balance Report (Stock Card)</strong></h3>
                <p class="text-muted">For date: <?= date("l, F j, Y", strtotime($report_date_str)) ?></p>
            </div>
            <a href="warehouse_dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
        </header>

        <div class="dashboard-card">
            <h4 class="mb-1"><?= htmlspecialchars($product['name'] ?? 'N/A') ?></h4>
            <p class="text-muted mb-4">SKU: <?= htmlspecialchars($product['sku'] ?? 'N/A') ?></p>
            
            <div class="row g-3 mb-4">
                <div class="col"><div class="card bg-light p-3 text-center"><div class="fs-6 text-muted">Opening Balance</div><div class="fs-3 fw-bold"><?= $opening_balance ?></div></div></div>
                <div class="col"><div class="card bg-light p-3 text-center"><div class="fs-6 text-success">Total IN</div><div class="fs-3 fw-bold text-success">+<?= $total_in ?></div></div></div>
                <div class="col"><div class="card bg-light p-3 text-center"><div class="fs-6 text-danger">Total OUT</div><div class="fs-3 fw-bold text-danger">-<?= $total_out ?></div></div></div>
                <div class="col"><div class="card bg-primary text-white p-3 text-center"><div class="fs-6">Closing Balance</div><div class="fs-3 fw-bold"><?= $closing_balance ?></div></div></div>
            </div>

            <h5 class="mb-3">Daily Movements</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th><th>Type</th><th>Reference</th><th>Notes</th>
                            <th class="text-end">In</th><th class="text-end">Out</th>
                            <th class="text-end">Balance</th><th class="text-center">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daily_transactions)): ?>
                            <tr><td colspan="8" class="text-center p-4 text-muted">No transactions found for this product on this date.</td></tr>
                        <?php else: ?>
                            <?php foreach($daily_transactions as $t): 
                                $qty_in = ''; $qty_out = '';
                                $quantity = (int)$t['quantity'];
                                $badge_class = 'bg-secondary';
                                if ($t['transaction_type'] == 'stock_out') {
                                    $qty_out = $quantity; $badge_class = 'bg-danger';
                                } elseif ($t['transaction_type'] == 'adjustment') {
                                    $badge_class = 'bg-warning text-dark';
                                    if ($quantity > 0) $qty_in = '+'.$quantity; else $qty_out = $quantity;
                                } else {
                                    $qty_in = '+'.$quantity; $badge_class = 'bg-success';
                                }
                            ?>
                            <tr>
                                <td><?= date("h:i:s A", strtotime($t['transaction_date'])) ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?= ucwords(str_replace('_', ' ', $t['transaction_type'])) ?></span></td>
                                <td><?= htmlspecialchars($t['reference_number'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                                <td class="text-end text-success fw-bold"><?= $qty_in ?></td>
                                <td class="text-end text-danger fw-bold"><?= $qty_out ?></td>
                                <td class="text-end fw-bold"><?= (int)$t['running_balance'] ?></td>
                                <td class="text-center">
                                    <!-- STEP 2: THE DETAILS BUTTON WITH DATA ATTRIBUTES -->
                                    <button class="btn btn-sm btn-outline-info" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#transactionDetailModal"
                                            data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                            data-product-sku="<?= htmlspecialchars($product['sku']) ?>"
                                            data-transaction-id="<?= $t['id'] ?>"
                                            data-transaction-type="<?= ucwords(str_replace('_', ' ', $t['transaction_type'])) ?>"
                                            data-quantity="<?= $t['quantity'] ?>"
                                            data-running-balance="<?= $t['running_balance'] ?>"
                                            data-user-name="<?= htmlspecialchars($t['user_full_name'] ?? 'System/Unknown User') ?>"
                                            data-timestamp="<?= date("F j, Y, g:i:s a", strtotime($t['transaction_date'])) ?>"
                                            data-ref-type="<?= htmlspecialchars($t['reference_type'] ?? 'N/A') ?>"
                                            data-ref-num="<?= htmlspecialchars($t['reference_number'] ?? 'N/A') ?>"
                                            data-notes="<?= htmlspecialchars($t['notes'] ?? 'No notes provided.') ?>">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- STEP 3: MODAL HTML STRUCTURE (Copied from previous solution) -->
<div class="modal fade" id="transactionDetailModal" tabindex="-1" aria-labelledby="transactionDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transactionDetailModalLabel">Transaction Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h5 class="mb-3" id="modalProductName"></h5>
        
        <div class="detail-row"><span class="detail-label">Transaction ID</span><span class="fw-bold" id="modalTransactionId"></span></div>
        <div class="detail-row"><span class="detail-label">Item SKU</span><span id="modalProductSku"></span></div>
        <div class="detail-row"><span class="detail-label">Date & Time</span><span id="modalTimestamp"></span></div>
        <div class="detail-row"><span class="detail-label">Performed By</span><span class="fw-bold" id="modalUserName"></span></div>
        <hr class="my-2">
        <div class="detail-row"><span class="detail-label">Transaction Type</span><span class="fw-bold" id="modalTransactionType"></span></div>
        <div class="detail-row"><span class="detail-label">Quantity Moved</span><span class="fw-bold" id="modalQuantity"></span></div>
        <div class="detail-row"><span class="detail-label">Resulting Balance</span><span class="fw-bold" id="modalRunningBalance"></span></div>
        <hr class="my-2">
        <div class="detail-row"><span class="detail-label">Reference Type</span><span id="modalRefType"></span></div>
        <div class="detail-row"><span class="detail-label">Reference Number</span><span id="modalRefNum"></span></div>
        <div class="mt-3">
            <p class="detail-label mb-1">Notes:</p>
            <p class="bg-light p-2 rounded" id="modalNotes" style="white-space: pre-wrap;"></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- STEP 4: JAVASCRIPT TO POPULATE THE MODAL (Copied from previous solution) -->
<script>
$(document).ready(function() {
    var detailModal = document.getElementById('transactionDetailModal');
    detailModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;

        var productName = button.getAttribute('data-product-name');
        var productSku = button.getAttribute('data-product-sku');
        var transactionId = button.getAttribute('data-transaction-id');
        var transactionType = button.getAttribute('data-transaction-type');
        var quantity = parseInt(button.getAttribute('data-quantity'));
        var runningBalance = button.getAttribute('data-running-balance');
        var userName = button.getAttribute('data-user-name');
        var timestamp = button.getAttribute('data-timestamp');
        var refType = button.getAttribute('data-ref-type');
        var refNum = button.getAttribute('data-ref-num');
        var notes = button.getAttribute('data-notes');

        var modal = $(this);
        modal.find('#modalProductName').text(productName);
        modal.find('#modalTransactionId').text(transactionId);
        modal.find('#modalProductSku').text(productSku);
        modal.find('#modalTimestamp').text(timestamp);
        modal.find('#modalUserName').text(userName);
        modal.find('#modalTransactionType').text(transactionType);
        modal.find('#modalRunningBalance').text(runningBalance);
        
        var quantitySpan = modal.find('#modalQuantity');
        quantitySpan.text(quantity > 0 ? '+' + quantity : quantity);
        quantitySpan.removeClass('text-success text-danger').addClass(quantity > 0 ? 'text-success' : 'text-danger');

        modal.find('#modalRefType').text(refType);
        modal.find('#modalRefNum').text(refNum);
        modal.find('#modalNotes').text(notes);
    });
});
</script>

</body>
</html>