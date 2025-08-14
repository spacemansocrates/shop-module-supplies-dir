<?php
// 1. SETUP & SECURITY
// =================================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

require_once '../includes/db_connect.php';

try {
    $pdo = getDatabaseConnection();
} catch (\PDOException $e) { throw new \PDOException($e->getMessage(), (int)$e->getCode()); }
// --- End DB Connection ---

$user_id = $_SESSION['user_id'];
$shop_id = $_SESSION['shop_id'];

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_message = '';
$success_message = '';

// 2. HANDLE EXPENSE FORM SUBMISSION (POST Request)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['record_expense'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "CSRF token validation failed.";
    } else {
        $stmt_float = $pdo->prepare("SELECT id FROM petty_cash_floats WHERE shop_id = ? AND is_active = 1 LIMIT 1");
        $stmt_float->execute([$shop_id]);
        $float = $stmt_float->fetch();

        if ($float) {
            $float_id = $float['id'];
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
            $category = trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING));
            $transaction_date = $_POST['transaction_date'];

            if (!$amount || $amount <= 0) {
                $error_message = "Invalid amount.";
            } elseif (empty($description)) {
                $error_message = "Description cannot be empty.";
            } else {
                 $receipt_path = null;
                if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/receipts/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $file_name = uniqid() . '-' . basename($_FILES['receipt']['name']);
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target_file)) {
                        $receipt_path = $target_file;
                    } else {
                        $error_message = "Failed to upload receipt.";
                    }
                }
                
                if (empty($error_message)) {
                    try {
                        $sql = "INSERT INTO petty_cash_transactions (float_id, transaction_type, amount, description, category, transaction_date, recorded_by_user_id, reference_document_path) VALUES (?, 'expense', ?, ?, ?, ?, ?, ?)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$float_id, $amount, $description, $category, $transaction_date, $user_id, $receipt_path]);
                        header("Location: petty_cash_ledger.php?status=success");
                        exit();
                    } catch (PDOException $e) {
                        $error_message = "Database error: " . $e->getMessage();
                    }
                }
            }
        } else {
            $error_message = "No active petty cash float found for this shop.";
        }
    }
}
if (isset($_GET['status']) && $_GET['status'] == 'success') {
    $success_message = "Expense recorded successfully!";
}

// 3. FETCH DATA FOR DISPLAY (GET Request)
// =================================================================
$current_balance = 0;
$float_name = "N/A";
$transactions = [];
$total_records = 0;
$float_id = null;

// Find active float
$stmt_float = $pdo->prepare("SELECT id, account_name, current_balance FROM petty_cash_floats WHERE shop_id = ? AND is_active = 1 LIMIT 1");
$stmt_float->execute([$shop_id]);
$float = $stmt_float->fetch();

if ($float) {
    $float_id = $float['id'];
    $float_name = $float['account_name'];
    $current_balance = $float['current_balance'];

    // --- Filtering Logic ---
    $whereClauses = ['t.float_id = ?'];
    $params = [$float_id];

    $search = trim($_GET['search'] ?? '');
    if (!empty($search)) {
        $whereClauses[] = '(t.description LIKE ? OR t.category LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $date_filter = $_GET['date_filter'] ?? '30_days';
    if ($date_filter == '30_days') {
        $whereClauses[] = 't.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($date_filter == 'this_month') {
        $whereClauses[] = 'YEAR(t.transaction_date) = YEAR(CURDATE()) AND MONTH(t.transaction_date) = MONTH(CURDATE())';
    } // 'all_time' needs no extra clause

    $type_filter = $_GET['type_filter'] ?? 'all';
    if ($type_filter === 'expense' || $type_filter === 'top_up') {
        $whereClauses[] = 't.transaction_type = ?';
        $params[] = $type_filter;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    // --- Pagination Logic ---
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 10; // Records per page
    $offset = ($page - 1) * $per_page;

    // Count total filtered records
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_transactions t $whereSql");
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);

    // --- Fetch Paginated Data with Running Balance ---
    // This SQL query is the key. It uses a CTE and a window function to calculate the running balance.
    $sql = "
        WITH RankedTransactions AS (
            SELECT
                t.*,
                u.full_name as recorded_by_name,
                SUM(CASE WHEN t.transaction_type = 'expense' THEN -t.amount ELSE t.amount END)
                    OVER (PARTITION BY t.float_id ORDER BY t.transaction_date ASC, t.id ASC) as running_balance
            FROM petty_cash_transactions t
            JOIN users u ON t.recorded_by_user_id = u.id
            $whereSql
        )
        SELECT * FROM RankedTransactions
        ORDER BY transaction_date DESC, id DESC
        LIMIT ? OFFSET ?
    ";
    
    $data_params = array_merge($params, [$per_page, $offset]);
    $stmt_history = $pdo->prepare($sql);
    $stmt_history->execute($data_params);
    $transactions = $stmt_history->fetchAll();

} else {
    $error_message = $error_message ?: "No active petty cash float found. Please ask an administrator to set one up.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Ledger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .wrapper { display: flex; width: 100%; align-items: stretch; }
        /* Your sidebar.php should have its own styling. This is a placeholder. */
  
        #content { width: 100%; padding: 20px 40px; min-height: 100vh; transition: all 0.3s; }
        
        .card { border: none; border-radius: 0.5rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); }
        .balance-card { background-color: #fff; padding: 2rem; }
        .balance-card .label { color: #6c757d; font-size: 1rem; }
        .balance-card .amount { font-size: 2.5rem; font-weight: 700; color: #212529; }

        .btn-expense { background-color: #fbebeb; color: #dc3545; border: 1px solid #dc3545; }
        .btn-expense:hover { background-color: #dc3545; color: #fff; }

        .filters { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .search-form .form-control { border-radius: 20px; }
        
        .table thead th { border-bottom: 2px solid #dee2e6; font-weight: 600; background-color: #fff; }
        .table tbody tr { background-color: #fff; border-bottom: 1px solid #f1f1f1; }
        .table tbody tr:last-child { border-bottom: none; }
        .table td, .table th { vertical-align: middle; padding: 1rem; }
        
        .badge-pill { padding: 0.4em 0.8em; font-size: 0.8em; font-weight: 600;}
        .badge-expense { background-color: #fdeeee; color: #dc3545; }
        .badge-top-up { background-color: #eaf6ec; color: #28a745; }

        .amount-expense { color: #dc3545; font-weight: 500; }
        .amount-top-up { color: #28a745; font-weight: 500; }

        .pagination-info { color: #6c757d; }
        .pagination .page-link { border: none; color: #6c757d; border-radius: 50% !important; margin: 0 2px; }
        .pagination .page-item.active .page-link { background-color: #007bff; color: white; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div id="content">
            <h2 class="mb-4">Petty Cash Ledger</h2>

            <?php if ($error_message): ?><div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card balance-card">
                        <div class="label">Current Cash Balance</div>
                        <div class="amount">K <?= number_format($current_balance, 2) ?></div>
                    </div>
                </div>
                <div class="col-md-6 d-flex justify-content-end align-items-center">
                    <button class="btn btn-expense btn-lg" data-toggle="modal" data-target="#expenseModal" <?php if(!$float_id) echo 'disabled'; ?>>
                        <i class="fas fa-minus-circle mr-2"></i>Record Expense
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Transaction History</h4>
                    
                    <form method="get" action="petty_cash_ledger.php">
                        <div class="filters">
                            <div class="left-filters">
                                <select name="type_filter" class="form-control d-inline-block" style="width: auto;" onchange="this.form.submit()">
                                    <option value="all" <?= ($type_filter ?? 'all') == 'all' ? 'selected' : '' ?>>All Transactions</option>
                                    <option value="expense" <?= ($type_filter ?? '') == 'expense' ? 'selected' : '' ?>>Expenses</option>
                                    <option value="top_up" <?= ($type_filter ?? '') == 'top_up' ? 'selected' : '' ?>>Top-ups</option>
                                </select>
                                <select name="date_filter" class="form-control d-inline-block ml-2" style="width: auto;" onchange="this.form.submit()">
                                    <option value="30_days" <?= ($date_filter ?? '30_days') == '30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                                    <option value="this_month" <?= ($date_filter ?? '') == 'this_month' ? 'selected' : '' ?>>This Month</option>
                                    <option value="all_time" <?= ($date_filter ?? '') == 'all_time' ? 'selected' : '' ?>>All Time</option>
                                </select>
                            </div>
                            <div class="search-form">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Search transactions..." value="<?= htmlspecialchars($search) ?>">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>DATE</th>
                                    <th>TYPE</th>
                                    <th>DESCRIPTION</th>
                                    <th>CATEGORY</th>
                                    <th class="text-right">AMOUNT</th>
                                    <th class="text-right">BALANCE</th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="7" class="text-center p-5">No transactions found for the selected filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $tx): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($tx['transaction_date'])) ?></td>
                                        <td><span class="badge-pill <?= $tx['transaction_type'] == 'expense' ? 'badge-expense' : 'badge-top-up' ?>"><?= ucfirst($tx['transaction_type']) ?></span></td>
                                        <td><?= htmlspecialchars($tx['description']) ?></td>
                                        <td><?= htmlspecialchars($tx['category']) ?></td>
                                        <td class="text-right <?= $tx['transaction_type'] == 'expense' ? 'amount-expense' : 'amount-top-up' ?>">
                                            <?= $tx['transaction_type'] == 'expense' ? '-' : '+' ?>K <?= number_format($tx['amount'], 2) ?>
                                        </td>
                                        <td class="text-right">K <?= number_format($tx['running_balance'], 2) ?></td>
                                        <td>
                                            <a href="#" class="text-secondary" title="View Details"><i class="fas fa-eye"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="pagination-info">
                            Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_records) ?> of <?= $total_records ?> results
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">«</a></li>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">»</a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="expenseModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="petty_cash_ledger.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="record_expense" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Record New Expense</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group"><label for="amount">Amount (K)</label><input type="number" class="form-control" name="amount" step="0.01" required></div>
                        <div class="form-group"><label for="description">Description</label><textarea class="form-control" name="description" rows="3" required></textarea></div>
                        <div class="form-group"><label for="category">Category</label><input type="text" class="form-control" name="category"></div>
                        <div class="form-group"><label for="transaction_date">Transaction Date</label><input type="datetime-local" class="form-control" name="transaction_date" value="<?= date('Y-m-d\TH:i') ?>" required></div>
                        <div class="form-group"><label for="receipt">Upload Receipt (Optional)</label><input type="file" class="form-control-file" name="receipt"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>