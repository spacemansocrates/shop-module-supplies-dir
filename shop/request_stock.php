<?php
// request_stock.php (All-in-One)
session_start();

// --- Security Check & Global Variables ---
// Redirect to login if user is not authenticated or has no shop assigned.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    header('Location: login.php');
    exit();
}
$current_user_id = (int)$_SESSION['user_id'];
$current_shop_id = (int)$_SESSION['shop_id'];

require_once '../includes/db_connect.php';

try {
    $pdo = getDatabaseConnection();
} catch (PDOException $e) {
    // For API calls, show JSON error. For page load, die gracefully.
    if (!empty($_GET['action'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed.']);
    } else {
        die("Database connection failed: " . $e->getMessage());
    }
    exit();
}


// ==================================================================
// --- API ROUTER: Handle AJAX requests for search and submit ---
// ==================================================================
if (!empty($_GET['action'])) {
    
    // --- ACTION: SEARCH PRODUCTS ---
    if ($_GET['action'] === 'search' && isset($_GET['term'])) {
        header('Content-Type: application/json');
        
        $term = '%' . $_GET['term'] . '%';
        
        // Using a query similar to your provided example.
        $sql = "SELECT p.id, p.name, p.sku, ss.quantity_in_stock as stock
                FROM products p
                LEFT JOIN shop_stock ss ON p.id = ss.product_id AND ss.shop_id = ?
                WHERE (p.name LIKE ? OR p.sku LIKE ?)
                LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_shop_id, $term, $term]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($products);
        exit(); // Stop execution after sending JSON
    }
    
    // --- ACTION: SUBMIT REQUEST ---
    if ($_GET['action'] === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['warehouse_id']) || empty($data['products'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Warehouse and at least one product are required.']);
            exit();
        }

        $warehouse_id = (int)$data['warehouse_id'];
        $notes = trim($data['notes'] ?? '');
        $products = $data['products'];

        $pdo->beginTransaction();
        try {
            $ref_prefix = "TRF-SH{$current_shop_id}-WH{$warehouse_id}-";
            $transfer_reference = $ref_prefix . date('Ymd-His');

            $stmt = $pdo->prepare("INSERT INTO stock_transfers (transfer_reference, from_warehouse_id, to_shop_id, status, notes, requested_by_user_id) VALUES (?, ?, ?, 'Pending', ?, ?)");
            $stmt->execute([$transfer_reference, $warehouse_id, $current_shop_id, $notes, $current_user_id]);
            $transfer_id = $pdo->lastInsertId();

            $item_stmt = $pdo->prepare("INSERT INTO stock_transfer_items (stock_transfer_id, product_id, quantity_requested) VALUES (?, ?, ?)");
            foreach ($products as $product) {
                $item_stmt->execute([(int)$transfer_id, (int)$product['productId'], (int)$product['quantity']]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Stock request submitted successfully!', 'reference' => $transfer_reference]);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit(); // Stop execution after sending JSON
    }
}

// ==================================================================
// --- NORMAL PAGE LOAD: Fetch initial data and render HTML ---
// ==================================================================

// Fetch warehouses for the dropdown
$warehouses = $pdo->query("SELECT id, name, warehouse_code FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Stock Request</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* All CSS from request_stock.css is placed here */
        :root {
            --primary-blue: #007bff;
            --bg-color: #f4f7fa;
            --card-bg: #ffffff;
            --text-primary: #343a40;
            --text-secondary: #6c757d;
            --border-color: #e9ecef;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-primary); margin: 0; display: flex; /* ADD THIS for sidebar layout */ }
        .page-container { display: flex; flex-direction: column; min-height: 100vh; flex-grow: 1; /* ADD THIS for main content to take remaining space */ }
        .page-header { display: flex; align-items: center; background-color: var(--card-bg); padding: 15px 30px; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 100; }
        .page-header h1 { font-size: 20px; margin: 0; flex-grow: 1; text-align: center; }
        .back-link { font-size: 18px; color: var(--text-primary); text-decoration: none; }
        .submit-button, .submit-button-main { background-color: var(--primary-blue); color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; }
        .submit-button:hover, .submit-button-main:hover { background-color: #0056b3; }
        .submit-button:disabled, .submit-button-main:disabled { background-color: #a0c7ff; cursor: not-allowed; }
        .form-container { max-width: 800px; margin: 30px auto; padding: 0 20px; flex-grow: 1; }
        .card { background-color: var(--card-bg); border-radius: 8px; border: 1px solid var(--border-color); padding: 25px; margin-bottom: 25px; }
        .card-title { font-size: 18px; margin: 0 0 5px 0; }
        .card-subtitle { font-size: 14px; color: var(--text-secondary); margin: 0 0 20px 0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 8px; }
        select, input[type="text"], textarea { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 14px; box-sizing: border-box; }
        select:focus, input:focus, textarea:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25); }
        textarea { min-height: 100px; resize: vertical; }
        .search-box { position: relative; }
        .search-box .fa-search { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); }
        .search-box .fa-barcode { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); cursor: pointer; }
        .search-box input { padding-left: 40px; padding-right: 40px; }
        .search-results-container, .request-list-container { border: 1px dashed #ced4da; border-radius: 6px; margin-top: 15px; padding: 15px; min-height: 100px; }
        .placeholder-box { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-secondary); padding: 20px; text-align: center; }
        .placeholder-box i { font-size: 32px; margin-bottom: 10px; }
        .search-item, .request-item { display: flex; align-items: center; padding: 10px; border-bottom: 1px solid var(--border-color); }
        .search-item:last-child, .request-item:last-child { border-bottom: none; }
        .item-info { flex-grow: 1; }
        .item-info .item-name { font-weight: 500; }
        .item-info .item-sku { font-size: 12px; color: var(--text-secondary); }
        .add-btn { background-color: #e7f2ff; color: var(--primary-blue); border: 1px solid var(--primary-blue); padding: 5px 12px; border-radius: 20px; cursor: pointer; font-weight: 500; font-size: 12px; }
        .add-btn:disabled { background-color: #e9ecef; color: #adb5bd; border-color: #ced4da; cursor: not-allowed; }
        .quantity-control { display: flex; align-items: center; }
        .qty-btn { width: 28px; height: 28px; border: 1px solid #ced4da; background-color: #f8f9fa; cursor: pointer; font-size: 16px; line-height: 26px; }
        .qty-input { width: 50px; text-align: center; border-left: none; border-right: none; border-radius: 0; padding: 6px; height: 28px; }
        .qty-btn.minus { border-radius: 4px 0 0 4px; }
        .qty-btn.plus { border-radius: 0 4px 4px 0; }
        .remove-btn { color: var(--text-secondary); background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 15px; }
        .page-footer { position: sticky; bottom: 0; background-color: var(--card-bg); border-top: 1px solid var(--border-color); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 100; }
        .totals { display: flex; gap: 25px; font-weight: 500; }
        .totals span { background-color: var(--bg-color); padding: 2px 8px; border-radius: 4px; font-weight: 600; }
        .submit-button-main i { margin-right: 8px; }
    </style>
</head>
<body>
    <?php require_once 'sidebar.php'; // Include the sidebar here ?>

    <div class="page-container">
        <header class="page-header">
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i></a>
            <h1>New Stock Request</h1>
<button class="submit-button" id="submit-request-header" type="button">Your requests</button>

<script>
document.getElementById('submit-request-header').addEventListener('click', function() {
    window.location.href = 'stock_requests.php';
});
</script>

        </header>

        <main class="form-container">
            
            <form id="stock-request-form" novalidate>
                <div class="card">
                    <h2 class="card-title">Warehouse Selection</h2>
                    <p class="card-subtitle">Select the warehouse you want to request stock from.</p>
                    <div class="form-group">
                        <label for="warehouse-select">Warehouse</label>
                        <select id="warehouse-select" name="warehouse_id" required>
                            <option value="">Select a warehouse</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?php echo $warehouse['id']; ?>">
                                    <?php echo htmlspecialchars($warehouse['name']) . ' (' . htmlspecialchars($warehouse['warehouse_code']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="card">
                    <h2 class="card-title">Add Products</h2>
                    <p class="card-subtitle">Search for products or scan barcodes to add to your request.</p>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="product-search-input" placeholder="Search products by name or code">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <div class="search-results-container" id="search-results">
                        <div class="placeholder-box">
                            <i class="fas fa-shopping-cart"></i>
                            <p>Search for products to add to your request</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2 class="card-title">Request List</h2>
                    <p class="card-subtitle">Products added to your request (<span id="unique-products-count">0</span>)</p>
                    <div class="request-list-container" id="request-list">
                        <div class="placeholder-box">
                            <i class="fas fa-box-open"></i>
                            <p>No products added to your request yet</p>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h2 class="card-title">Additional Notes</h2>
                    <p class="card-subtitle">Add any special instructions or notes for the warehouse team.</p>
                    <textarea id="request-notes" name="notes" placeholder="Optional notes for the warehouse team"></textarea>
                </div>
            </form>
        </main>

        <footer class="page-footer">
            <div class="totals">
                <div>Total Items <span id="total-items-count">0</span></div>
                <div>Products <span id="footer-products-count">0</span></div>
            </div>
            <button class="submit-button-main" id="submit-request-footer" form="stock-request-form">
                <i class="fas fa-paper-plane"></i> Submit Stock Request
            </button>
        </footer>
    </div>
    
    <script>
        // All JavaScript from request_stock.js is placed here
        document.addEventListener('DOMContentLoaded', () => {

            const searchInput = document.getElementById('product-search-input');
            const searchResultsContainer = document.getElementById('search-results');
            const requestListContainer = document.getElementById('request-list');
            const warehouseSelect = document.getElementById('warehouse-select');
            const notesInput = document.getElementById('request-notes');
            const submitHeaderBtn = document.getElementById('submit-request-header');
            const submitFooterBtn = document.getElementById('submit-request-footer');
            
            const uniqueProductsCount = document.getElementById('unique-products-count');
            const totalItemsCount = document.getElementById('total-items-count');
            const footerProductsCount = document.getElementById('footer-products-count');

            const searchPlaceholder = searchResultsContainer.innerHTML;
            const listPlaceholder = requestListContainer.innerHTML;

            let searchTimeout;

            // --- Event Listeners ---
            searchInput.addEventListener('keyup', (e) => {
                clearTimeout(searchTimeout);
                const searchTerm = e.target.value.trim();
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        searchProducts(searchTerm);
                    }, 300);
                } else {
                    searchResultsContainer.innerHTML = searchPlaceholder;
                }
            });

            searchResultsContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('add-btn')) {
                    const product = {
                        id: e.target.dataset.id,
                        name: e.target.dataset.name,
                        sku: e.target.dataset.sku
                    };
                    addProductToRequest(product);
                    e.target.textContent = 'Added';
                    e.target.disabled = true;
                }
            });

            requestListContainer.addEventListener('click', (e) => {
                const target = e.target;
                if (target.classList.contains('qty-btn')) {
                    const itemRow = target.closest('.request-item');
                    const qtyInput = itemRow.querySelector('.qty-input');
                    let currentValue = parseInt(qtyInput.value, 10);
                    if (target.classList.contains('plus')) {
                        currentValue++;
                    } else if (target.classList.contains('minus')) {
                        currentValue = Math.max(1, currentValue - 1);
                    }
                    qtyInput.value = currentValue;
                    updateTotals();
                } else if (target.classList.contains('remove-btn')) {
                    const itemRow = target.closest('.request-item');
                    const productId = itemRow.dataset.productId;
                    itemRow.remove();
                    
                    const addBtn = searchResultsContainer.querySelector(`.add-btn[data-id='${productId}']`);
                    if (addBtn) {
                        addBtn.textContent = 'Add';
                        addBtn.disabled = false;
                    }
                    updateTotals();
                }
            });
            
            requestListContainer.addEventListener('change', (e) => {
                if (e.target.classList.contains('qty-input')) {
                    if(parseInt(e.target.value, 10) < 1 || isNaN(parseInt(e.target.value, 10))) {
                        e.target.value = 1;
                    }
                    updateTotals();
                }
            });
            
            submitHeaderBtn.addEventListener('click', handleSubmit);
            submitFooterBtn.addEventListener('click', handleSubmit);

            // --- Functions ---
            async function searchProducts(term) {
                try {
                    // **MODIFIED URL for single-file structure**
                    const response = await fetch(`request_stock.php?action=search&term=${encodeURIComponent(term)}`);
                    if (!response.ok) throw new Error('Network response was not ok.');
                    const products = await response.json();
                    renderSearchResults(products);
                } catch (error) {
                    console.error('Search failed:', error);
                    searchResultsContainer.innerHTML = `<div class="placeholder-box"><i class="fas fa-exclamation-triangle"></i><p>Error loading products.</p></div>`;
                }
            }

            function renderSearchResults(products) {
                if (products.length === 0) {
                    searchResultsContainer.innerHTML = `<div class="placeholder-box"><i class="fas fa-search"></i><p>No products found.</p></div>`;
                    return;
                }
                const requestedProductIds = getRequestedProductIds();
                const html = products.map(product => {
                    const isAdded = requestedProductIds.includes(product.id.toString());
                    return `
                        <div class="search-item">
                            <div class="item-info">
                                <div class="item-name">${product.name}</div>
                                <div class="item-sku">SKU: ${product.sku}</div>
                            </div>
                            <button class="add-btn" 
                                    data-id="${product.id}" 
                                    data-name="${product.name}" 
                                    data-sku="${product.sku}" 
                                    ${isAdded ? 'disabled' : ''}>
                                ${isAdded ? 'Added' : 'Add'}
                            </button>
                        </div>
                    `;
                }).join('');
                searchResultsContainer.innerHTML = html;
            }
            
            function addProductToRequest(product) {
                if (requestListContainer.querySelector('.placeholder-box')) {
                    requestListContainer.innerHTML = '';
                }
                const html = `
                    <div class="request-item" data-product-id="${product.id}">
                        <div class="item-info">
                            <div class="item-name">${product.name}</div>
                            <div class="item-sku">SKU: ${product.sku}</div>
                        </div>
                        <div class="quantity-control">
                            <button type="button" class="qty-btn minus">-</button>
                            <input type="number" class="qty-input" value="1" min="1">
                            <button type="button" class="qty-btn plus">+</button>
                        </div>
                        <button type="button" class="remove-btn">×</button>
                    </div>
                `;
                requestListContainer.insertAdjacentHTML('beforeend', html);
                updateTotals();
            }
            
            function updateTotals() {
                const items = requestListContainer.querySelectorAll('.request-item');
                if(items.length === 0) {
                    requestListContainer.innerHTML = listPlaceholder;
                }
                const uniqueCount = items.length;
                let totalQty = 0;
                items.forEach(item => {
                    totalQty += parseInt(item.querySelector('.qty-input').value, 10);
                });
                uniqueProductsCount.textContent = uniqueCount;
                totalItemsCount.textContent = totalQty;
                footerProductsCount.textContent = uniqueCount;
            }
            
            function getRequestedProductIds() {
                const items = requestListContainer.querySelectorAll('.request-item');
                return Array.from(items).map(item => item.dataset.productId);
            }

            async function handleSubmit(e) {
                e.preventDefault();
                const warehouseId = warehouseSelect.value;
                if (!warehouseId) {
                    alert('Please select a warehouse.');
                    warehouseSelect.focus();
                    return;
                }
                const requestedItems = requestListContainer.querySelectorAll('.request-item');
                if (requestedItems.length === 0) {
                    alert('Please add at least one product to the request.');
                    searchInput.focus();
                    return;
                }
                
                submitHeaderBtn.disabled = true;
                submitFooterBtn.disabled = true;
                submitFooterBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

                const productsPayload = Array.from(requestedItems).map(item => ({
                    productId: item.dataset.productId,
                    quantity: item.querySelector('.qty-input').value
                }));
                const submissionData = {
                    warehouse_id: warehouseId,
                    notes: notesInput.value,
                    products: productsPayload
                };
                
                try {
                    // **MODIFIED URL for single-file structure**
                    const response = await fetch('request_stock.php?action=submit', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(submissionData)
                    });
                    const result = await response.json();
                    if (response.ok && result.success) {
                        alert(`Success! Your request has been submitted.\nReference: ${result.reference}`);
                        window.location.href = 'dashboard.php';
                    } else {
                        throw new Error(result.message || 'An unknown error occurred.');
                    }
                } catch (error) {
                    alert(`Submission failed: ${error.message}`);
                    submitHeaderBtn.disabled = false;
                    submitFooterBtn.disabled = false;
                    submitFooterBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';
                }
            }
        });
    </script>
</body>
</html>