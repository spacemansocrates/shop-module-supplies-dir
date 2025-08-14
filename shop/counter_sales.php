<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['full_name'] ?? 'User';
$shop_name = $_SESSION['shop_name'] ?? 'My Shop';
$currentDate = date("F j, Y");
$current_page = 'counter_sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Counter Sales - Supplies Direct</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        .main-content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .main-content-header h1 { font-size: 1.8rem; font-weight: 600; }
        .action-button-sm { background-color: var(--text-dark); color: white; border: none; border-radius: 8px; width: 40px; height: 40px; font-size: 1.2rem; cursor: pointer; transition: background-color 0.2s; }
        .action-button-sm:hover { background-color: #555; }
        .date-filter { margin-bottom: 24px; }
        .date-filter input[type="date"] { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.95rem; font-family: 'Poppins', sans-serif; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .card .sub-stats { display: flex; gap: 16px; margin-top: 8px; font-size: 1rem; font-weight: 500; }
        .sub-stats .cash-in, .sub-stats .stock-added { color: var(--primary-green); }
        .sub-stats .cash-out, .sub-stats .stock-sold, .sub-stats .stock-adjusted { color: var(--primary-red); }
        .transactions-table-container { background-color: var(--bg-white); border-radius: 12px; border: 1px solid var(--border-color); padding: 20px; }
        .transactions-table-container h2 { font-size: 1.2rem; font-weight: 600; margin-bottom: 16px; }
        .transactions-table { width: 100%; border-collapse: collapse; }
        .transactions-table th, .transactions-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .transactions-table thead th { font-weight: 600; color: var(--text-light); font-size: 0.9rem; text-transform: uppercase; }
        .transactions-table tbody td { font-size: 0.95rem; }
        .transactions-table tbody tr:last-child td { border-bottom: none; }
        .card.clickable { cursor: pointer; transition: background-color 0.2s; }
        .card.clickable:hover { background-color: #f0f4f8; }
        .card-header .actions-trigger { cursor: pointer; color: #999; padding: 5px; font-size: 1.1rem; }
        .card-header .actions-trigger:hover { color: #333; }
        .select2-container .select2-selection--single { height: 45px; border: 1px solid var(--border-color); border-radius: 8px; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 45px; padding-left: 15px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 43px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fefefe; padding: 25px; border-radius: 12px; width: 90%; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.3); animation: fadeIn 0.3s; }
        .modal-content-sm { max-width: 500px; }
        .modal-content-lg { max-width: 700px; }
        @keyframes fadeIn { from {opacity: 0; transform: scale(0.95);} to {opacity: 1; transform: scale(1);} }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px;}
        .close-button { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-body .form-group { margin-bottom: 15px; }
        .modal-body label { display: block; margin-bottom: 8px; font-weight: 500; }
        .modal-body input, .modal-body select, .modal-body textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; }
        .modal-body button[type="submit"] { width: 100%; padding: 12px 20px; background-color: var(--primary-blue); color: white; border:none; border-radius: 8px; cursor: pointer; font-size: 1.1rem; margin-top: 10px; }
        .modal-error { color: var(--primary-red); font-size: 0.9rem; margin-top: 10px; }
        .modal-body .transactions-table td.cash-in { color: var(--primary-green); font-weight: 500; }
        .modal-body .transactions-table td.cash-out { color: var(--primary-red); }
        .hidden-section { display: none; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <header class="main-header">
                <div class="welcome-message"><h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $username)[0]); ?></h1><p><?php echo htmlspecialchars($shop_name); ?> | Daily Summary</p></div>
                <div class="header-actions"><span class="date"><?php echo date("F j, Y"); ?></span><i class="fas fa-bell notification-bell"></i></div>
            </header>
            <div class="main-content-header"><h1>Counter Sales</h1><button id="add-transaction-btn" class="action-button-sm" title="Add New Transaction"><i class="fas fa-plus"></i></button></div>
            <div class="date-filter"><input type="date" id="date-input" value="<?php echo date('Y-m-d'); ?>"></div>
            
            <section class="stats-grid">
                <div class="card">
                    <div class="card-header"><h3>Opening Balance</h3><i id="edit-balance-trigger" class="fas fa-ellipsis-v actions-trigger" title="Edit Opening Balance"></i></div>
                    <div class="card-body"><h2>MWK <span id="opening-balance">...</span></h2></div>
                </div>
                <div id="gross-transactions-card" class="card clickable">
                    <div class="card-header"><h3>Gross Transactions</h3></div>
                    <div class="card-body"><h2>MWK <span id="gross-transactions">...</span></h2></div>
                    <div class="sub-stats"><span class="cash-in">+<span id="cash-in">...</span></span><span class="cash-out">-<span id="cash-out">...</span></span></div>
                </div>
                <div class="card">
                    <div class="card-header"><h3>Closing Balance</h3></div>
                    <div class="card-body"><h2>MWK <span id="closing-balance">...</span></h2></div>
                </div>
                <div class="card"><div class="card-header"><h3>Stock Opening</h3></div><select id="product-search" style="width: 100%;"></select><div class="card-body" style="margin-top: 10px;"><h2><span id="stock-opening">--</span> <small>PCS</small></h2></div></div>
                <div class="card" id="stock-total-moved-card"><div class="card-header"><h3>Total Moved</h3></div><div class="card-body"><h2><span id="stock-total-moved">--</span> <small>PCS</small></h2></div><div class="sub-stats"><span class="stock-added">+<span id="stock-added">--</span></span><span class="stock-sold">-<span id="stock-sold">--</span></span><span class="stock-adjusted">-<span id="stock-adjusted">--</span></span></div></div>
                <div class="card"><div class="card-header"><h3>Stock Closing</h3></div><div class="card-body"><h2><span id="stock-closing">--</span> <small>PCS</small></h2></div></div>
            </section>
            
            <section class="transactions-table-container"><h2>Daily Transaction Log</h2><table class="transactions-table"><thead><tr><th>Time</th><th>Transaction</th><th>Category</th><th>Amount</th><th>Customer/Note</th></tr></thead><tbody id="transactions-tbody"><tr><td colspan="5" style="text-align:center;">No data loaded.</td></tr></tbody></table></section>
        </main>
    </div>

    <!-- All Modals -->
    <div id="detail-modal" class="modal"><div class="modal-content modal-content-lg"><div class="modal-header"><h2 id="detail-modal-title">Cash Transaction Details</h2><span class="close-button">&times;</span></div><div class="modal-body"><div style="max-height: 400px; overflow-y: auto;"><table class="transactions-table"><thead><tr><th>Time</th><th>Type</th><th>Details</th><th style="text-align: right;">Amount</th></tr></thead><tbody id="detail-modal-tbody"></tbody></table></div></div></div></div>
    <div id="add-transaction-modal" class="modal"><div class="modal-content modal-content-sm"><div class="modal-header"><h2>Add New Transaction</h2><span class="close-button">&times;</span></div><div class="modal-body"><form id="add-transaction-form" novalidate><div class="form-group"><label for="add-transaction-type">Transaction Type</label><select id="add-transaction-type" name="transaction_type" required><option value="" disabled selected>-- Select Type --</option><option value="petty_cash_expense">Petty Cash Expense</option></select></div><div id="add-cash-fields" class="form-group hidden-section"><label for="add-amount">Amount (MWK)</label><input type="number" id="add-amount" name="amount" step="0.01" required><label for="add-description" style="margin-top: 10px;">Description/Reason</label><textarea id="add-description" name="description" rows="3" required></textarea></div><div id="add-transaction-error" class="modal-error"></div><button type="submit">Submit Transaction</button></form></div></div></div>
    <div id="edit-opening-balance-modal" class="modal"><div class="modal-content modal-content-sm"><div class="modal-header"><h2>Admin Approval Required</h2><span class="close-button">&times;</span></div><div class="modal-body"><p style="font-size: 0.9rem; color: #555; margin-bottom: 20px;">To set or change the opening balance, an Admin or Manager must enter their credentials.</p><form id="edit-balance-form" novalidate><div class="form-group"><label for="new-opening-balance">New Opening Balance (MWK)</label><input type="number" id="new-opening-balance" name="new_balance" step="0.01" required></div><div class="form-group"><label for="admin-username-input">Admin/Manager Username</label><input type="text" id="admin-username-input" name="admin_username" required></div><div class="form-group"><label for="admin-password-input">Admin/Manager Password</label><input type="password" id="admin-password-input" name="admin_password" required></div><div id="balance-error-message" class="modal-error"></div><button type="submit">Authorize and Update</button></form></div></div></div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // --- DOM CACHING ---
        const ui = {
            dateInput: document.getElementById('date-input'),
            openingBalanceSpan: document.getElementById('opening-balance'),
            grossTransactionsSpan: document.getElementById('gross-transactions'),
            cashInSpan: document.getElementById('cash-in'),
            cashOutSpan: document.getElementById('cash-out'),
            closingBalanceSpan: document.getElementById('closing-balance'),
            stockOpeningSpan: document.getElementById('stock-opening'),
            stockTotalMovedSpan: document.getElementById('stock-total-moved'),
            stockAddedSpan: document.getElementById('stock-added'),
            stockSoldSpan: document.getElementById('stock-sold'),
            stockAdjustedSpan: document.getElementById('stock-adjusted'),
            stockClosingSpan: document.getElementById('stock-closing'),
            productSearch: $('#product-search'),
            // Modals
            addTransactionModal: document.getElementById('add-transaction-modal'),
            detailModal: document.getElementById('detail-modal'),
            editBalanceModal: document.getElementById('edit-opening-balance-modal'),
            // Modal Triggers
            addTransactionBtn: document.getElementById('add-transaction-btn'),
            editBalanceTrigger: document.getElementById('edit-balance-trigger'),
            grossTransactionsCard: document.getElementById('gross-transactions-card'),
            // Modal Forms & Content
            addTransactionForm: document.getElementById('add-transaction-form'),
            addTransactionTypeSelect: document.getElementById('add-transaction-type'),
            addCashFields: document.getElementById('add-cash-fields'),
            addTransactionError: document.getElementById('add-transaction-error'),
            editBalanceForm: document.getElementById('edit-balance-form'),
            newBalanceInput: document.getElementById('new-opening-balance'),
            adminUsernameInput: document.getElementById('admin-username-input'),
            adminPasswordInput: document.getElementById('admin-password-input'),
            balanceErrorMessage: document.getElementById('balance-error-message'),
            detailModalTbody: document.getElementById('detail-modal-tbody'),
        };
        
        // --- HELPER FUNCTIONS ---
        const formatCurrency = (num) => parseFloat(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        function openModal(modal) { modal.style.display = "flex"; }
        function closeModal(modal) { modal.style.display = "none"; }
        
                // --- MASTER DATA FETCHING ---
        async function fetchPageData() {
            const selectedDate = ui.dateInput.value;
            const selectedProductId = ui.productSearch.val();
            
            let apiUrl = `api/get_daily_summary.php?date=${selectedDate}`;
            if (selectedProductId) {
                apiUrl += `&product_id=${selectedProductId}`;
            }

            try {
                const response = await fetch(apiUrl);
                if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
                const data = await response.json();
                updateUI(data);
            } catch (error) {
                console.error("Failed to fetch page data:", error);
                alert("Could not load summary data. Please check the console for errors.");
            }
        }

        // --- MASTER UI UPDATE ---
        function updateUI(data) {
            const fin = data.financial_summary;
            if (fin) {
                ui.openingBalanceSpan.textContent = formatCurrency(fin.opening_cash_balance);
                ui.cashInSpan.textContent = formatCurrency(fin.total_cash_in);
                ui.cashOutSpan.textContent = formatCurrency(fin.total_cash_out);
                ui.closingBalanceSpan.textContent = formatCurrency(fin.closing_cash_balance);
                const gross = parseFloat(fin.total_cash_in) - parseFloat(fin.total_cash_out);
                ui.grossTransactionsSpan.textContent = formatCurrency(gross);
            }

            const stock = data.stock_summary;
            const stockSpans = [ui.stockOpeningSpan, ui.stockTotalMovedSpan, ui.stockAddedSpan, ui.stockSoldSpan, ui.stockAdjustedSpan, ui.stockClosingSpan];
            if (stock) {
                ui.stockOpeningSpan.textContent = stock.opening_quantity || 0;
                ui.stockTotalMovedSpan.textContent = stock.total_moved || 0;
                ui.stockAddedSpan.textContent = stock.quantity_added || 0;
                ui.stockSoldSpan.textContent = stock.quantity_sold || 0;
                ui.stockAdjustedSpan.textContent = stock.quantity_adjusted || 0;
                ui.stockClosingSpan.textContent = stock.closing_quantity || 0;
            } else {
                stockSpans.forEach(span => span.textContent = '--');
            }

            // Populate daily transaction log
            populateTransactionsTable(data.transaction_list);
        }

        function populateTransactionsTable(transactions) {
            const tbody = document.getElementById('transactions-tbody');
            tbody.innerHTML = ''; // Clear existing rows

            if (!transactions || transactions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No transactions for this date.</td></tr>';
                return;
            }

            transactions.forEach(tx => {
                const row = document.createElement('tr');
                const transactionTime = new Date(tx.transaction_date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                const amountClass = parseFloat(tx.amount) >= 0 ? 'cash-in' : 'cash-out';

                row.innerHTML = `
                    <td>${transactionTime}</td>
                    <td>${tx.transaction_type}</td>
                    <td>${tx.category}</td>
                    <td class="${amountClass}">${formatCurrency(tx.amount)}</td>
                    <td>${tx.customer_name || tx.description || 'N/A'}</td>
                `;
                tbody.appendChild(row);
            });
        }
        
        // --- EVENT LISTENERS ---
        ui.dateInput.addEventListener('change', fetchPageData);
        document.querySelectorAll('.close-button').forEach(btn => btn.onclick = () => closeModal(btn.closest('.modal')));
        window.onclick = event => { if (event.target.classList.contains('modal')) closeModal(event.target); };

        // Edit Balance Modal Logic
        ui.editBalanceTrigger.addEventListener('click', () => { /* ... same as before ... */ openModal(ui.editBalanceModal); });
        ui.editBalanceForm.addEventListener('submit', async function(e) { /* ... same as before ... */ e.preventDefault(); const formData = new FormData(this); formData.append('date', ui.dateInput.value); try { const response = await fetch('api/update_opening_balance.php', { method: 'POST', body: formData }); const result = await response.json(); if (!response.ok) throw new Error(result.error); alert('Updated!'); closeModal(ui.editBalanceModal); fetchPageData(); } catch (error) { ui.balanceErrorMessage.textContent = error.message; } });

        // Add Transaction Modal Logic
        ui.addTransactionBtn.onclick = () => { /* ... same as before ... */ openModal(ui.addTransactionModal); };
        ui.addTransactionTypeSelect.addEventListener('change', () => { ui.addCashFields.classList.toggle('hidden-section', ui.addTransactionTypeSelect.value !== 'petty_cash_expense'); });
        ui.addTransactionForm.addEventListener('submit', async function(e) { /* ... same as before ... */ e.preventDefault(); const formData = new FormData(this); formData.append('date', ui.dateInput.value); try { const response = await fetch('api/add_petty_cash_expense.php', { method: 'POST', body: formData }); const result = await response.json(); if (!response.ok) throw new Error(result.error); alert('Added!'); closeModal(ui.addTransactionModal); fetchPageData(); } catch (error) { ui.addTransactionError.textContent = error.message; } });
        
        // Gross Transactions Detail Modal Logic
        ui.grossTransactionsCard.addEventListener('click', async () => {
            ui.detailModalTbody.innerHTML = `<tr><td colspan="4" style="text-align: center;">Loading...</td></tr>`; openModal(ui.detailModal);
            try {
                const response = await fetch(`api/get_cash_details.php?date=${ui.dateInput.value}`);
                const transactions = await response.json(); if (!response.ok) throw new Error(transactions.error);
                populateDetailModal(transactions);
            } catch (error) { ui.detailModalTbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: red;">${error.message}</td></tr>`; }
        });
        function populateDetailModal(transactions) { /* ... same as before ... */ ui.detailModalTbody.innerHTML = ''; if(transactions.length === 0){ui.detailModalTbody.innerHTML = '<tr><td colspan=4>No transactions.</td></tr>'; return;} transactions.forEach(tx => { const time = new Date(tx.transaction_time).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}); const amount = parseFloat(tx.amount); const amountClass = amount >= 0 ? 'cash-in' : 'cash-out'; const row = `<tr><td>${time}</td><td>${tx.type}</td><td>${tx.details}</td><td class="${amountClass}" style="text-align: right;">${formatCurrency(amount)}</td></tr>`; ui.detailModalTbody.insertAdjacentHTML('beforeend', row); }); }

        // Total Moved Detail Modal Logic
        document.getElementById('stock-total-moved-card').addEventListener('click', async () => {
            const selectedProductId = ui.productSearch.val();
            if (!selectedProductId) {
                alert("Please select a product to view stock movement details.");
                return;
            }
            ui.detailModalTbody.innerHTML = `<tr><td colspan="4" style="text-align: center;">Loading...</td></tr>`;
            document.getElementById('detail-modal-title').textContent = 'Stock Movement Details';
            openModal(ui.detailModal);
            try {
                const response = await fetch(`api/get_stock_details.php?date=${ui.dateInput.value}&product_id=${selectedProductId}`);
                const movements = await response.json();
                if (!response.ok) throw new Error(movements.error);
                populateStockDetailModal(movements);
            } catch (error) {
                ui.detailModalTbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: red;">${error.message}</td></tr>`;
            }
        });

        function populateStockDetailModal(movements) {
            ui.detailModalTbody.innerHTML = '';
            if (movements.length === 0) {
                ui.detailModalTbody.innerHTML = '<tr><td colspan=4>No stock movements for this product on this date.</td></tr>';
                return;
            }
            movements.forEach(mv => {
                const time = new Date(mv.transaction_date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                let typeClass = '';
                let quantitySign = '';
                if (mv.transaction_type === 'stock_in' || mv.transaction_type === 'return') {
                    typeClass = 'cash-in';
                    quantitySign = '+';
                } else if (mv.transaction_type === 'sale' || mv.transaction_type === 'stock_out') {
                    typeClass = 'cash-out';
                    quantitySign = '-';
                } else if (mv.transaction_type === 'adjustment') {
                    typeClass = (mv.quantity > 0) ? 'cash-in' : 'cash-out';
                    quantitySign = (mv.quantity > 0) ? '+' : '';
                }

                const row = `<tr>
                    <td>${time}</td>
                    <td>${mv.transaction_type.replace(/_/g, ' ').toUpperCase()}</td>
                    <td>${mv.notes || 'N/A'}</td>
                    <td class="${typeClass}" style="text-align: right;">${quantitySign}${Math.abs(mv.quantity)}</td>
                </tr>`;
                ui.detailModalTbody.insertAdjacentHTML('beforeend', row);
            });
        }
        
        // --- SELECT2 INITIALIZATION & EVENTS ---
        ui.productSearch.select2({
            placeholder: 'Search for a product...',
            allowClear: true,
            ajax: { url: 'api/search_products.php', dataType: 'json', delay: 250, data: params => ({ term: params.term }), processResults: data => ({ results: data.results }), cache: true }
        });
        ui.productSearch.on('select2:select', fetchPageData);
        ui.productSearch.on('select2:unselect', fetchPageData);

        // --- INITIALIZATION ---
        fetchPageData();
    });
    </script>
</body>
</html>