<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    header('Location: /login.php');
    exit();
}

// --- DB Connection ---
require_once __DIR__ . '/config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}

$user_id = $_SESSION['user_id'];
$warehouse_id = $_SESSION['warehouse_id'];

// --- FETCH DATA FOR INITIAL PAGE LOAD ---

// 1. Get user and warehouse info
$stmt_header = $pdo->prepare("SELECT u.full_name as user_name, w.name as warehouse_name FROM users u, warehouses w WHERE u.id = ? AND w.id = ?");
$stmt_header->execute([$user_id, $warehouse_id]);
$header_info = $stmt_header->fetch();

// 2. Get today's transactions for the "Daily Transactions" card
$today = date('Y-m-d');
$sql_counts = "SELECT 
    SUM(CASE WHEN transaction_type = 'stock_in' THEN 1 ELSE 0 END) as receipts,
    SUM(CASE WHEN transaction_type = 'stock_out' THEN 1 ELSE 0 END) as issues,
    SUM(CASE WHEN transaction_type = 'adjustment' THEN 1 ELSE 0 END) as adjustments
    FROM stock_transactions 
    WHERE warehouse_id = ? AND DATE(transaction_date) = ?";
$stmt_counts = $pdo->prepare($sql_counts);
$stmt_counts->execute([$warehouse_id, $today]);
$daily_counts = $stmt_counts->fetch();

$sql_trans = "SELECT st.transaction_type, st.quantity, p.name as product_name, st.transaction_date 
    FROM stock_transactions st
    JOIN products p ON st.product_id = p.id
    WHERE st.warehouse_id = ? AND DATE(st.transaction_date) = ?
    ORDER BY st.transaction_date DESC LIMIT 10";
$stmt_trans = $pdo->prepare($sql_trans);
$stmt_trans->execute([$warehouse_id, $today]);
$daily_transactions = $stmt_trans->fetchAll();

// 3. Get data for forms
$all_products = $pdo->query("SELECT id, name, sku FROM products ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// 4. NEW: Fetch categories for the "Add Product" modal
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Management Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <style>
        body { background-color: #F3F4F6; font-family: 'Inter', sans-serif; }
        .sidebar { width: 260px; min-height: 100vh; background-color: #fff; padding: 20px; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
        .sidebar .nav-link { display: flex; align-items: center; padding: 12px 15px; border-radius: 8px; color: #555; font-weight: 500; margin-bottom: 5px; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background-color: #4F46E5; color: #fff; }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 10px; }
        .main-content { flex: 1; padding: 30px; }
        .dashboard-card { background-color: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; height: 100%; }
        .form-control, .form-select { border-radius: 8px; padding: 10px; border: 1px solid #D1D5DB; }
        .btn-primary { background-color: #4F46E5; border-color: #4F46E5; border-radius: 8px; padding: 12px; font-weight: 500; }
        .btn-primary:hover { background-color: #4338CA; border-color: #4338CA; }
        .transaction-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid transparent; }
        .transaction-receipt { background-color: #E0F2F1; border-color: #B2DFDB; }
        .transaction-issue { background-color: #FFEBEE; border-color: #FFCDD2; }
        .transaction-adjustment { background-color: #FFF3E0; border-color: #FFE0B2; }
        .item-to-receive-row { border-bottom: 1px solid #eee; padding: 0.75rem; display: flex; align-items: center; gap: 1rem; }
        .select2-container .select2-selection--single { height: calc(2.5rem + 2px); }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { line-height: 2.5rem; padding-left: 1rem; }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow { top: 0.75rem; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar">
        <h5 class="px-2 mb-4"><strong>Supplies Direct</strong></h5>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link active" href="warehouse_dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_requests.php"><i class="fas fa-dolly"></i> Stock Requests</a></li>
            <li class="nav-item"><a class="nav-link" href="warehouse_inventory.php"><i class="fas fa-random"></i> Stock Adjustments</a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0"><strong>Stock Management Dashboard</strong></h3>
                <p class="text-muted">Monitor and manage your warehouse inventory</p>
            </div>
            <div class="d-flex align-items-center">
                <div class="badge bg-light text-dark p-2 me-3">
                    <i class="fas fa-warehouse me-2"></i> <?= htmlspecialchars($header_info['warehouse_name'] ?? '') ?>
                </div>
                <div class="text-end">
                    <strong><?= htmlspecialchars($header_info['user_name'] ?? '') ?></strong>
                </div>
                <a href="daily_report.php?date=<?= date('Y-m-d') ?>" class="btn btn-primary ms-3">
                    <i class="fas fa-calendar-day me-2"></i> Full Report
                </a>
            </div>
        </header>
         <!-- NEW: Flash Message Display -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['flash_message']['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

        <!-- Dashboard Grid -->
        <div class="row g-4">
            <!-- Col 1: Item Balance Inquiry -->
            <div class="col-lg-3">
                <div class="dashboard-card">
                    <h5 class="mb-4"><i class="fas fa-search text-primary me-2"></i> Item Balance Inquiry</h5>
                    <form action="item_balance_report.php" method="GET" target="_blank">
                        <div class="mb-3">
                            <label class="form-label">Select Item</label>
                            <select class="form-select" id="product-search-select" name="product_id" required>
                                <option value="">Type to search...</option>
                                <?php foreach($all_products as $product): ?>
                                    <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['sku'] . ' - ' . $product['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select Date</label>
                            <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Check Balance</button>
                    </form>
                </div>
            </div>

            <!-- Col 2: Daily Transactions -->
            <div class="col-lg-4">
                <div class="dashboard-card">
                    <h5 class="mb-4"><i class="fas fa-list-ul text-primary me-2"></i> Daily Transactions</h5>
                    <input type="date" class="form-control mb-3" id="transaction-date-picker" value="<?= $today ?>">
                    <div class="d-flex justify-content-around text-center p-3 bg-light rounded mb-3">
                        <div><div class="fs-4 fw-bold" id="receipts-count"><?= $daily_counts['receipts'] ?? 0 ?></div><small class="text-muted">Receipts</small></div>
                        <div><div class="fs-4 fw-bold text-danger" id="issues-count"><?= $daily_counts['issues'] ?? 0 ?></div><small class="text-muted">Issues</small></div>
                        <div><div class="fs-4 fw-bold text-warning" id="adjustments-count"><?= $daily_counts['adjustments'] ?? 0 ?></div><small class="text-muted">Adjustments</small></div>
                    </div>
                    <div id="transaction-list" style="max-height: 250px; overflow-y: auto;">
                        <?php if (empty($daily_transactions)): ?>
                            <p class="text-center text-muted mt-4">No transactions for this date.</p>
                        <?php else: foreach($daily_transactions as $t): 
                            $class = ''; $prefix = '';
                            switch ($t['transaction_type']) {
                                case 'stock_in': $class = 'transaction-receipt'; $prefix = 'Receipt'; break;
                                case 'stock_out': $class = 'transaction-issue'; $prefix = 'Issue'; break;
                                case 'adjustment': $class = 'transaction-adjustment'; $prefix = 'Adjustment'; break;
                            }
                        ?>
                        <div class="transaction-item <?= $class ?>">
                            <div>
                                <strong><?= htmlspecialchars($t['product_name']) ?></strong><br>
                                <small><?= $prefix ?> - <?= htmlspecialchars($t['quantity']) ?> units</small>
                            </div>
                            <small class="text-muted"><?= date('h:i A', strtotime($t['transaction_date'])) ?></small>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Col 3: Receive Stock (UPDATED) -->
            <div class="col-lg-5">
                <div class="dashboard-card">
                    <h5 class="mb-4"><i class="fas fa-truck-loading text-primary me-2"></i> Receive Stock (GRN)</h5>
                    <form id="grn-form" action="process_grn.php" method="POST">
                        <input type="hidden" name="warehouse_id" value="<?= $warehouse_id ?>">
                        <!-- Supplier, Date, Ref# -->
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach($suppliers as $supplier): ?><option value="<?= $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3"><label class="form-label">Delivery Date</label><input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Reference Number (PO/etc)</label><input type="text" name="reference_number" class="form-control" placeholder="e.g., PO-2024-001"></div>
                        </div>
                        <hr>
                        
                        <!-- Item Adding Section -->
                        <h6>Add Items to GRN</h6>
                        <div class="d-flex align-items-end gap-2 mb-3">
                            <div class="flex-grow-1">
                                <label for="grn-product-search" class="form-label">Search Product</label>
                                <select id="grn-product-search" class="form-control"></select>
                            </div>
                            <div>
                                <label for="grn-item-quantity" class="form-label">Quantity</label>
                                <input type="number" id="grn-item-quantity" class="form-control" placeholder="Qty" style="width: 80px;">
                            </div>
                            <button type="button" class="btn btn-success" id="add-grn-item-btn"><i class="fas fa-plus"></i> Add</button>
                        </div>
                        
                        <!-- Items to Receive List -->
                        <h6>Items to Receive</h6>
                        <div id="items-to-receive-container" class="border rounded p-2" style="min-height: 100px; max-height: 250px; overflow-y: auto;">
                            <div class="text-center text-muted p-4" id="no-items-placeholder">
                                <i class="fas fa-box-open fa-2x mb-2"></i>
                                <p>No items added yet.</p>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" name="action" value="draft" class="btn btn-light me-2">Save Draft</button>
                            <button type="submit" name="action" value="generate" class="btn btn-primary">Generate GRN</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- MODAL FOR ADDING A NEW PRODUCT -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="add-product-form">
        <div class="modal-header">
          <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="add-product-error" class="alert alert-danger d-none"></div>
          <div class="mb-3">
            <label for="new_product_sku" class="form-label">Product SKU (Unique)</label>
            <input type="text" class="form-control" id="new_product_sku" name="new_product_sku" required>
          </div>
          <div class="mb-3">
            <label for="new_product_name" class="form-label">Product Name</label>
            <input type="text" class="form-control" id="new_product_name" name="new_product_name" required>
          </div>
          <div class="mb-3">
            <label for="new_product_description" class="form-label">Description</label>
            <textarea class="form-control" id="new_product_description" name="new_product_description" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label for="new_product_category" class="form-label">Category</label>
            <select class="form-select" id="new_product_category" name="new_product_category" required>
              <option value="">Select a category...</option>
              <?php foreach($categories as $category): ?>
                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // --- Initialize All Select2 Dropdowns ---
    $('#product-search-select').select2({ theme: 'bootstrap-5', placeholder: 'Select an item' });

    const addProductModal = new bootstrap.Modal(document.getElementById('addProductModal'));

    // --- GRN Product Search (Select2 with AJAX) ---
    const productSearch = $('#grn-product-search').select2({
        theme: 'bootstrap-5',
        placeholder: 'Type to search SKU or Name...',
        minimumInputLength: 2,
        ajax: {
            url: 'api/search_products.php',
            dataType: 'json',
            delay: 250,
            processResults: function (data) {
                return {
                    results: data.results
                };
            },
            cache: true
        },
        language: {
            noResults: function() {
                // Return a link to trigger the modal
                return $(`<span>No results found. <a href="#" class="text-decoration-underline" id="show-add-product-modal">Click here to add it.</a></span>`);
            }
        },
        escapeMarkup: function (markup) {
            return markup;
        }
    });
    
    // --- GRN Logic ---
    
    // Show "Add Product" modal from "no results" link
    $(document).on('click', '#show-add-product-modal', function(e) {
        e.preventDefault();
        productSearch.select2('close');
        addProductModal.show();
    });

    // Add selected item to the GRN list
    $('#add-grn-item-btn').on('click', function() {
        const selectedData = productSearch.select2('data')[0];
        const quantity = $('#grn-item-quantity').val();

        if (!selectedData || !selectedData.id) {
            alert('Please search and select a product.');
            return;
        }
        if (!quantity || parseInt(quantity) <= 0) {
            alert('Please enter a valid quantity greater than 0.');
            return;
        }

        const productId = selectedData.id;
        const productText = selectedData.text;

        if ($(`#items-to-receive-container`).find(`input[value="${productId}"]`).length > 0) {
            alert('This item is already in the list. Please remove it first to change the quantity.');
            return;
        }

        $('#no-items-placeholder').addClass('d-none');

        const itemRowHtml = `
            <div class="item-to-receive-row" id="grn-item-row-${productId}">
                <div class="flex-grow-1">
                    <strong>${productText}</strong>
                    <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                    <input type="hidden" name="items[${productId}][quantity]" value="${quantity}">
                </div>
                <div>Qty: <strong>${quantity}</strong></div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-grn-item-btn">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;

        $('#items-to-receive-container').append(itemRowHtml);

        productSearch.val(null).trigger('change');
        $('#grn-item-quantity').val('');
    });

    // Remove an item from the GRN list
    $('#items-to-receive-container').on('click', '.remove-grn-item-btn', function() {
        $(this).closest('.item-to-receive-row').remove();
        if ($('#items-to-receive-container .item-to-receive-row').length === 0) {
            $('#no-items-placeholder').removeClass('d-none');
        }
    });

    // --- "Add New Product" Modal Form Submission ---
    $('#add-product-form').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const errorDiv = $('#add-product-error');
        
        $.ajax({
            url: 'api/add_product.php',
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    addProductModal.hide();
                    form[0].reset();
                    errorDiv.addClass('d-none');
                    
                    var newOption = new Option(response.product.text, response.product.id, true, true);
                    productSearch.append(newOption).trigger('change');
                    
                    alert('Product added! It is now selected in the search bar. Please enter the quantity and click "Add".');
                }
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON ? xhr.responseJSON.error : 'An unknown error occurred.';
                errorDiv.text(errorMsg).removeClass('d-none');
            }
        });
    });

    // --- Daily transaction AJAX logic ---
    $('#transaction-date-picker').on('change', function() {
        const selectedDate = $(this).val();
        $('#transaction-list').html('<p class="text-center text-muted mt-4">Loading...</p>');
        $.ajax({
            url: 'ajax_get_daily_transactions.php', type: 'GET', data: { date: selectedDate }, dataType: 'json',
            success: function(response) {
                $('#receipts-count').text(response.counts.receipts || 0);
                $('#issues-count').text(response.counts.issues || 0);
                $('#adjustments-count').text(response.counts.adjustments || 0);
                const list = $('#transaction-list');
                list.empty();
                if (response.transactions && response.transactions.length > 0) {
                    response.transactions.forEach(function(t) {
                        let itemClass = '', prefix = '';
                        switch (t.transaction_type) {
                            case 'stock_in': itemClass = 'transaction-receipt'; prefix = 'Receipt'; break;
                            case 'stock_out': itemClass = 'transaction-issue'; prefix = 'Issue'; break;
                            case 'adjustment': itemClass = 'transaction-adjustment'; prefix = 'Adjustment'; break;
                        }
                        const time = new Date(t.transaction_date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
                        list.append(`<div class="transaction-item ${itemClass}"><div><strong>${t.product_name}</strong><br><small>${prefix} - ${t.quantity} units</small></div><small class="text-muted">${time}</small></div>`);
                    });
                } else {
                    list.html('<p class="text-center text-muted mt-4">No transactions for this date.</p>');
                }
            },
            error: function() { $('#transaction-list').html('<p class="text-center text-danger mt-4">Error loading data.</p>'); }
        });
    });
});
</script>

</body>
</html>