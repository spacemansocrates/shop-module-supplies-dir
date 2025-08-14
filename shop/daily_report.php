<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    header('Location: /login.php');
    exit();
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $dbname = 'u789944046_suppliesdirect'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $pdo = new PDO($dsn, $user, $pass, $options); } catch (\PDOException $e) { throw new \PDOException($e->getMessage(), (int)$e->getCode()); }

$user_id = $_SESSION['user_id'];
$warehouse_id = $_SESSION['warehouse_id'];

// --- GET INPUTS ---
$report_date_str = $_GET['date'] ?? date('Y-m-d');

// --- STEP 1: MODIFIED SQL QUERY TO INCLUDE USER NAME ---
$sql_all_transactions = "SELECT 
                            t.*, 
                            p.name as product_name, 
                            p.sku as product_sku,
                            u.full_name as user_full_name
                        FROM stock_transactions t
                        JOIN products p ON t.product_id = p.id
                        LEFT JOIN users u ON t.scanned_by_user_id = u.id
                        WHERE t.warehouse_id = ? 
                          AND DATE(t.transaction_date) = ? 
                        ORDER BY t.transaction_date ASC, t.id ASC"; // Order by time first
$stmt = $pdo->prepare($sql_all_transactions);
$stmt->execute([$warehouse_id, $report_date_str]);
$all_daily_transactions = $stmt->fetchAll();

// --- PROCESS DATA FOR THE SUMMARY TABLE (No changes here) ---
$product_summaries = [];
// Create a temporary copy to sort for the summary table
$summary_transactions = $all_daily_transactions;
// Sort by product name for the summary view
usort($summary_transactions, function($a, $b) {
    return strcmp($a['product_name'], $b['product_name']);
});

foreach ($summary_transactions as $t) {
    $product_id = $t['product_id'];
    $quantity = (int)$t['quantity'];

    if (!isset($product_summaries[$product_id])) {
        $product_summaries[$product_id] = [
            'name' => $t['product_name'],
            'sku' => $t['product_sku'],
            'total_in' => 0,
            'total_out' => 0,
            'closing_balance' => 0,
            'opening_balance' => 0 
        ];
    }
    
    if ($t['transaction_type'] == 'stock_out') {
        $product_summaries[$product_id]['total_out'] += abs($quantity);
    } elseif ($t['transaction_type'] == 'adjustment') {
        if ($quantity > 0) $product_summaries[$product_id]['total_in'] += $quantity;
        else $product_summaries[$product_id]['total_out'] += abs($quantity);
    } else { 
        $product_summaries[$product_id]['total_in'] += $quantity;
    }

    $product_summaries[$product_id]['closing_balance'] = (int)$t['running_balance'];
}

foreach ($product_summaries as $id => &$summary) {
    $summary['opening_balance'] = $summary['closing_balance'] - $summary['total_in'] + $summary['total_out'];
}
unset($summary);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Transaction Report</title>
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
        .dashboard-card { background-color: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; margin-bottom: 25px; }
        .table { vertical-align: middle; }
        .modal-body .detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .modal-body .detail-row:last-child { border-bottom: none; }
        .modal-body .detail-label { color: #6c757d; }
    </style>
</head>
<body>
<div class="d-flex">
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
                <h3 class="mb-0"><strong>Daily Master Report</strong></h3>
                <p class="text-muted">All warehouse movements for <?= date("l, F j, Y", strtotime($report_date_str)) ?></p>
            </div>

            <div class="d-flex align-items-center">
                <form action="daily_report.php" method="GET" class="d-flex align-items-center me-3">
                    <label for="date" class="form-label me-2 mb-0">Select Date:</label>
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($report_date_str) ?>" class="form-control" onchange="this.form.submit()">
                </form>
                <a href="warehouse_dashboard.php" class="btn btn-outline-secondary flex-shrink-0"><i class="fas fa-arrow-left me-2"></i> Back</a>
            </div>
        </header>

        <div class="dashboard-card">
            <h4 class="mb-3">Daily Summary by Item</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Item Name</th><th>SKU</th>
                            <th class="text-center">Opening Balance</th><th class="text-center">Total IN</th>
                            <th class="text-center">Total OUT</th><th class="text-center">Closing Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($product_summaries)): ?>
                            <tr><td colspan="6" class="text-center p-4 text-muted">No products were moved on this date.</td></tr>
                        <?php else: ?>
                            <?php foreach($product_summaries as $summary): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($summary['name']) ?></strong></td><td><?= htmlspecialchars($summary['sku']) ?></td>
                                <td class="text-center fs-5"><?= $summary['opening_balance'] ?></td>
                                <td class="text-center fs-5 text-success">+<?= $summary['total_in'] ?></td>
                                <td class="text-center fs-5 text-danger">-<?= $summary['total_out'] ?></td>
                                <td class="text-center fs-5 fw-bold bg-light"><?= $summary['closing_balance'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="dashboard-card">
            <h4 class="mb-3">Full Transaction Log</h4>
            <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                <table class="table table-striped table-sm">
                    <thead class="table-light" style="position: sticky; top: 0;">
                        <tr>
                            <th>Time</th><th>Item</th><th>Type</th><th>Ref #</th>
                            <th class="text-end">In</th><th class="text-end">Out</th>
                            <th class="text-end">Balance</th><th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_daily_transactions)): ?>
                            <tr><td colspan="8" class="text-center p-4 text-muted">No transactions found for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach($all_daily_transactions as $t): 
                                $qty_in = ''; $qty_out = '';
                                $quantity = (int)$t['quantity'];
                                $badge_class = 'bg-secondary';
                                if ($t['transaction_type'] == 'stock_out') {
                                    $qty_out = $quantity; $badge_class = 'bg-danger';
                                } elseif ($t['transaction_type'] == 'adjustment') {
                                    if ($quantity > 0) { $qty_in = '+'.$quantity; $badge_class = 'bg-warning text-dark'; } 
                                    else { $qty_out = $quantity; $badge_class = 'bg-warning text-dark'; }
                                } else { // stock_in, return
                                    $qty_in = '+'.$quantity; $badge_class = 'bg-success';
                                }
                            ?>
                            <tr>
                                <td><?= date("h:i:s A", strtotime($t['transaction_date'])) ?></td>
                                <td><strong><?= htmlspecialchars($t['product_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($t['product_sku']) ?></small></td>
                                <td><span class="badge <?= $badge_class ?>"><?= ucwords(str_replace('_', ' ', $t['transaction_type'])) ?></span></td>
                                <td><?= htmlspecialchars($t['reference_number'] ?? 'N/A') ?></td>
                                <td class="text-end text-success fw-bold"><?= $qty_in ?></td>
                                <td class="text-end text-danger fw-bold"><?= $qty_out ?></td>
                                <td class="text-end fw-bold"><?= (int)$t['running_balance'] ?></td>
                                <td class="text-center">
                                    <!-- STEP 3: THE DETAILS BUTTON WITH DATA ATTRIBUTES -->
                                    <button class="btn btn-sm btn-outline-info" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#transactionDetailModal"
                                            data-product-name="<?= htmlspecialchars($t['product_name']) ?>"
                                            data-product-sku="<?= htmlspecialchars($t['product_sku']) ?>"
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

<!-- STEP 2: MODAL HTML STRUCTURE -->
<div class="modal fade" id="transactionDetailModal" tabindex="-1" aria-labelledby="transactionDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transactionDetailModalLabel">Transaction Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h5 class="mb-3" id="modalProductName"></h5>
        
        <div class="detail-row">
            <span class="detail-label">Transaction ID</span>
            <span class="fw-bold" id="modalTransactionId"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Item SKU</span>
            <span id="modalProductSku"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Date & Time</span>
            <span id="modalTimestamp"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Performed By</span>
            <span class="fw-bold" id="modalUserName"></span>
        </div>
        <hr class="my-2">
        <div class="detail-row">
            <span class="detail-label">Transaction Type</span>
            <span class="fw-bold" id="modalTransactionType"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Quantity Moved</span>
            <span class="fw-bold" id="modalQuantity"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Resulting Balance</span>
            <span class="fw-bold" id="modalRunningBalance"></span>
        </div>
        <hr class="my-2">
        <div class="detail-row">
            <span class="detail-label">Reference Type</span>
            <span id="modalRefType"></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Reference Number</span>
            <span id="modalRefNum"></span>
        </div>
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

<!-- STEP 3: JAVASCRIPT TO POPULATE THE MODAL -->
<script>
$(document).ready(function() {
    var detailModal = document.getElementById('transactionDetailModal');
    detailModal.addEventListener('show.bs.modal', function (event) {
        // Button that triggered the modal
        var button = event.relatedTarget;

        // Extract info from data-* attributes
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

        // Update the modal's content.
        var modal = $(this);
        modal.find('#modalProductName').text(productName);
        modal.find('#modalTransactionId').text(transactionId);
        modal.find('#modalProductSku').text(productSku);
        modal.find('#modalTimestamp').text(timestamp);
        modal.find('#modalUserName').text(userName);
        
        modal.find('#modalTransactionType').text(transactionType);
        modal.find('#modalRunningBalance').text(runningBalance);
        
        // Color the quantity based on positive/negative
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