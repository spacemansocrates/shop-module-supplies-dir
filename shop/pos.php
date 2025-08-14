<?php
// --- pos.php (TOP OF FILE) ---

session_start();

// !! SECURE THE PAGE !!
// If the user is not logged in, redirect to the login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$category_icon_map = [
    'Electronics'   => 'fa-laptop-code',
    'Tyres'         => 'fa-car-tire',
    'Car Batteries' => 'fa-car-battery',
    'Oil'           => 'fa-oil-can',
    'Apparel'       => 'fa-tshirt',
    'Groceries'     => 'fa-shopping-basket'
    // Add more categories as needed...
];

// Define a default icon for any category not in the map above.
$default_product_icon = 'fa-box-open';

// ======================== NEW CODE BLOCK ENDS HERE ========================

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - New Sale</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="pos.css">
</head>
<body>
    <!-- pos.php (in the <main> section) -->

    
    <!-- ==================== SIDEBAR ==================== -->
    <aside class="pos-sidebar">
        <div class="logo">POS</div>
        <nav>
            <ul class="sidebar-nav">
                <li><a href="dashboard.php"><i class="fas fa-home"></i></a></li>
                <li><a href="#" class="active"><i class="fas fa-cash-register"></i></a></li>
                <li><a href="request_stock.php"><i class="fas fa-truck-loading"></i></a></li>
                <li><a href="#"><i class="fas fa-cog"></i></a></li>
            </ul>
        </nav>
    </aside>
    

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="pos-main">
  <header class="main-header">
    <!-- Display the shop name from the session -->
    <h1>Shop: <?php echo htmlspecialchars($_SESSION['shop_name']); ?></h1> 
    <div class="cashier-info">
        <!-- Display the logged-in user's name -->
        <span>Cashier: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
        <a href="logout.php" title="Logout" style="color: inherit; text-decoration: none; margin-left: 10px;">
            <i class="fas fa-sign-out-alt icon"></i>
        </a>
    </div>
</header>

        <div class="product-selection">
            <div class="search-bar">
                <i class="fas fa-barcode"></i>
                <input type="text" placeholder="Scan barcode or search products by name/SKU...">
            </div>

         <!-- pos.php -> in the <main> section -->
<div class="categories">
    <h3>Categories</h3>
    <!-- This will be populated by JavaScript -->
    <div class="category-buttons-container" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <!-- Buttons will be inserted here -->
    </div>
</div>

  <!-- THIS IS THE NEW, CORRECTED CODE BLOCK -->
<div class="product-grid">
    <!-- Products will be dynamically loaded here by the JavaScript function fetchProducts() -->
</div>
        </div>
    </main>

    <!-- ==================== SALE SUMMARY ==================== -->
    <aside class="sale-summary">
        <div class="summary-header">
            <h2>Current Sale</h2>
            <a href="#" class="clear-all">Clear All</a>
        </div>
        
        <div class="cart-items-list">
            <!-- Cart items will be dynamically inserted here by JavaScript -->
        </div>

        <div class="customer-info">
            <div class="customer-info-header">
                <h3>Customer Information</h3>
                <a href="#">+ Add Customer</a>
            </div>
            <p>Walk-in Customer</p>
        </div>

        <div class="bill-summary">
            <div class="bill-row">
                <span>Subtotal</span>
                <span id="subtotal">MWK0.00</span>
            </div>
            <div class="bill-row">
                <span>VAT (16.5%)</span>
                <span id="vat">MWK0.00</span>
            </div>
            <!-- This is the new, dynamic discount row -->
            <div class="bill-row">
                <span>Discount</span>
                <span id="discountDisplay">
                    <span id="discountAmountText">-MWK0.00</span>
                    <a class="remove-discount">(Remove)</a>
                </span>
                <a href="#" id="addDiscountBtn">Add</a>
            </div>
            <div class="bill-row total-row">
                <span>Total</span>
                <span id="total">MWK0.00</span>
            </div>
        </div>

        <div class="payment-options">
            <button class="payment-btn active"><i class="fas fa-money-bill-wave"></i> Cash</button>
            <button class="payment-btn"><i class="far fa-credit-card"></i> Card</button>
            <button class="payment-btn"><i class="fas fa-mobile-alt"></i> Mobile</button>
        </div>

        <button class="complete-sale-btn"><i class="fas fa-check-circle"></i> Complete Sale</button>
    </aside>
    <div id="confirmSaleModal" class="modal-backdrop">
    <div class="modal-content">
        <h3>Confirm Sale</h3>
        <p>Are you sure you want to complete this sale?</p>
        <div class="modal-actions">
            <button id="cancelSaleBtn" class="modal-btn cancel">Cancel</button>
            <button id="confirmSaleBtn" class="modal-btn confirm">Confirm</button>
        </div>
    </div>
</div>
<!-- pos.php -> Add this before the <script> tag -->

<!-- ==================== DISCOUNT MODAL ==================== -->
<div id="discountModal" class="modal-backdrop">
    <div class="modal-content">
        <h3>Add Discount</h3>
        <div class="discount-input-group">
            <input type="number" id="discountValueInput" placeholder="Enter value" step="0.01">
            <select id="discountTypeSelect">
                <!-- MODIFIED: Changed currency symbol text -->
                <option value="fixed">MWK</option>
                <option value="percentage">%</option>
            </select>
        </div>
        <p id="discountError" class="error-message"></p>
        <div class="modal-actions">
            <button id="cancelDiscountBtn" class="modal-btn cancel">Cancel</button>
            <button id="applyDiscountBtn" class="modal-btn confirm">Apply</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- 1. ELEMENT SELECTORS ---
    const productGrid = document.querySelector('.product-grid');
    const searchInput = document.querySelector('.search-bar input');
    const categoryButtonsContainer = document.querySelector('.category-buttons-container');
    const cartItemsList = document.querySelector('.cart-items-list');
    const subtotalEl = document.getElementById('subtotal');
    const vatEl = document.getElementById('vat');
    const totalEl = document.getElementById('total');
    const clearAllBtn = document.querySelector('.clear-all');
    const paymentOptions = document.querySelector('.payment-options');
    const completeSaleBtn = document.querySelector('.complete-sale-btn');
    const confirmSaleModal = document.getElementById('confirmSaleModal');
    const confirmSaleBtn = document.getElementById('confirmSaleBtn');
    const cancelSaleBtn = document.getElementById('cancelSaleBtn');
    const addDiscountBtn = document.getElementById('addDiscountBtn');
    const discountDisplay = document.getElementById('discountDisplay');
    const discountAmountText = document.getElementById('discountAmountText');
    const discountModal = document.getElementById('discountModal');
    const applyDiscountBtn = document.getElementById('applyDiscountBtn');
    const cancelDiscountBtn = document.getElementById('cancelDiscountBtn');
    const discountValueInput = document.getElementById('discountValueInput');
    const discountTypeSelect = document.getElementById('discountTypeSelect');
    const discountError = document.getElementById('discountError');

    // --- 2. STATE ---
    let cart = [];
    const VAT_RATE = 0.165;
    let selectedPaymentMethod = 'Cash';
    let discount = { type: 'none', value: 0 };

    // --- 3. EVENT LISTENERS ---
    searchInput.addEventListener('input', filterAndSearchProducts);
    categoryButtonsContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.category-btn');
        if (button) {
            categoryButtonsContainer.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
            button.classList.add('active');
            filterAndSearchProducts();
        }
    });
    productGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card');
        if (card && !card.classList.contains('out-of-stock')) {
            const productData = { ...card.dataset };
            productData.price = parseFloat(productData.price);
            addToCart(card.dataset.id, productData);
        }
    });
    cartItemsList.addEventListener('click', (e) => {
        const target = e.target;
        const cartItemDiv = target.closest('.cart-item');
        if (!cartItemDiv) return;
        const productId = cartItemDiv.dataset.id;
        if (target.matches('.qty-plus')) { updateQuantity(productId, 1); }
        else if (target.matches('.qty-minus')) { updateQuantity(productId, -1); }
        else if (target.matches('.remove-item') || target.closest('.remove-item')) { removeFromCart(productId); }
    });
    addDiscountBtn.addEventListener('click', (e) => {
        e.preventDefault();
        discountError.textContent = '';
        discountValueInput.value = '';
        discountModal.style.display = 'flex';
        discountValueInput.focus();
    });
    cancelDiscountBtn.addEventListener('click', () => { discountModal.style.display = 'none'; });
    applyDiscountBtn.addEventListener('click', applyDiscount);
    discountDisplay.querySelector('.remove-discount').addEventListener('click', () => {
        discount = { type: 'none', value: 0 };
        updateTotals();
    });
    clearAllBtn.addEventListener('click', (e) => { e.preventDefault(); cart = []; renderCart(); });
    paymentOptions.addEventListener('click', (e) => {
        const button = e.target.closest('.payment-btn');
        if (!button) return;
        paymentOptions.querySelectorAll('.payment-btn').forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        selectedPaymentMethod = button.textContent.trim();
    });
    completeSaleBtn.addEventListener('click', () => {
        if (cart.length === 0) { alert('Cannot complete sale. The cart is empty.'); return; }
        confirmSaleModal.style.display = 'flex';
    });
    cancelSaleBtn.addEventListener('click', () => { confirmSaleModal.style.display = 'none'; });
    confirmSaleBtn.addEventListener('click', () => {
        confirmSaleBtn.disabled = true;
        confirmSaleBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        completeSale(cart, selectedPaymentMethod, discount);
    });

    // --- 4. FUNCTIONS ---
    function applyDiscount() {
        const value = parseFloat(discountValueInput.value);
        const type = discountTypeSelect.value;
        discountError.textContent = '';
        if (isNaN(value) || value <= 0) {
            discountError.textContent = 'Please enter a valid positive number.';
            return;
        }
        // MODIFIED: Added validation for percentage discount
        if (type === 'percentage' && value > 100) {
            discountError.textContent = 'Percentage cannot be over 100.';
            return;
        }
        discount = { type: type, value: value };
        discountModal.style.display = 'none';
        updateTotals();
    }

    // MODIFIED: Complete overhaul of this function for new discount logic and currency
    function updateTotals() {
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        const vat = subtotal * VAT_RATE;
        const totalBeforeDiscount = subtotal + vat;

        let discountAmount = 0;
        if (discount.type === 'percentage') {
            // Apply percentage discount on the total (subtotal + VAT)
            discountAmount = totalBeforeDiscount * (discount.value / 100);
        } else if (discount.type === 'fixed') {
            // Apply a fixed amount discount
            discountAmount = discount.value;
        }

        // Sanity check: Ensure discount doesn't make the total negative
        if (discountAmount > totalBeforeDiscount) {
            discountAmount = totalBeforeDiscount;
        }

        const total = totalBeforeDiscount - discountAmount;

        // Update the display elements using the "MWK" currency symbol
        subtotalEl.textContent = `MWK${subtotal.toFixed(2)}`;
        vatEl.textContent = `MWK${vat.toFixed(2)}`;
        totalEl.textContent = `MWK${total.toFixed(2)}`;

        // Handle the display of the discount row
        if (discount.type !== 'none' && discount.value > 0) {
            discountAmountText.textContent = `-MWK${discountAmount.toFixed(2)}`;
            discountDisplay.style.display = 'inline';
            addDiscountBtn.style.display = 'none';
        } else {
            discountDisplay.style.display = 'none';
            addDiscountBtn.style.display = 'inline';
        }
    }

    async function completeSale(cartData, paymentMethod, discountData) {
        const salePayload = { 
            cart: cartData, 
            paymentMethod: paymentMethod, 
            discount: discountData 
        };
        
        try {
            const response = await fetch('complete_sale.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' }, 
                body: JSON.stringify(salePayload) 
            });

            const result = await response.json();

            if (response.ok) {
                alert(`Sale Complete!\nInvoice Number: ${result.invoice_number}`);
                cart = [];
                discount = { type: 'none', value: 0 };
                renderCart();
            } else {
                throw new Error(result.error || 'An unknown error occurred.');
            }
        } catch (error) {
            alert(`Sale Failed: ${error.message}`);
        } finally {
            confirmSaleModal.style.display = 'none';
            confirmSaleBtn.disabled = false;
            confirmSaleBtn.innerHTML = 'Confirm';
        }
    }
    
    async function fetchProducts(query = '') {
        try {
            const response = await fetch(`search_products.php?query=${encodeURIComponent(query)}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const products = await response.json();
            renderProductGrid(products);
        } catch (error) {
            console.error("Could not fetch products:", error);
            productGrid.innerHTML = '<p class="error">Error loading products.</p>';
        }
    }
    
    async function fetchCategories() {
        try {
            const response = await fetch(`get_categories.php`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const categories = await response.json();
            renderCategories(categories);
        } catch (error) {
            console.error("Could not fetch categories:", error);
        }
    }

    function renderCategories(categories) {
        let buttonsHTML = '<button class="category-btn active" data-category="All">All</button>';
        categories.forEach(cat => {
            buttonsHTML += `<button class="category-btn" data-category="${cat.name}">${cat.name}</button>`;
        });
        categoryButtonsContainer.innerHTML = buttonsHTML;
    }

    function renderProductGrid(products) {
        productGrid.innerHTML = '';
        if (products.length === 0) {
            productGrid.innerHTML = '<p>No products found.</p>';
            return;
        }
        products.forEach(product => {
            const hasStock = product.stock > 0;
            let stockInfoHTML;
            if (hasStock) {
                // MODIFIED: Changed currency symbol
                stockInfoHTML = `<div class="price">MWK${parseFloat(product.price).toFixed(2)}</div>
                                 <div class="stock">In Stock: ${product.stock}</div>`;
            } else {
                stockInfoHTML = `<div class="stock">Out of Stock</div>
                                 <a href="request_stock.php?product_id=${product.id}" class="request-stock-btn">
                                     <i class="fas fa-warehouse"></i> Request Stock
                                 </a>`;
            }

            const productCardHTML = `<div class="product-card ${!hasStock ? 'out-of-stock' : ''}" data-id="${product.id}" data-name="${product.name}" data-price="${product.price}" data-sku="${product.sku}" data-icon="${product.icon || 'fa-box-open'}" data-category="${product.category}">
                                        <div class="product-info">
                                            <i class="fas ${product.icon || 'fa-box-open'} icon"></i>
                                            <div class="product-details">
                                                <h4>${product.name}</h4>
                                                <p>SKU: ${product.sku}</p>
                                            </div>
                                        </div>
                                        ${stockInfoHTML}
                                    </div>`;
            productGrid.insertAdjacentHTML('beforeend', productCardHTML);
        });
    }

    function renderCart() {
        cartItemsList.innerHTML = '';
        if (cart.length === 0) {
            cartItemsList.innerHTML = '<p style="text-align:center; color: var(--text-light); margin-top:20px;">Your cart is empty.</p>';
        } else {
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                // MODIFIED: Changed currency symbol
                const cartItemHTML = `<div class="cart-item" data-id="${item.id}">
                                        <i class="fas ${item.icon} icon"></i>
                                        <div class="item-details">
                                            <h4>${item.name}</h4>
                                            <p>SKU: ${item.sku}</p>
                                        </div>
                                        <div class="quantity-controls">
                                            <button class="qty-minus">-</button>
                                            <input type="text" value="${item.quantity}" readonly>
                                            <button class="qty-plus">+</button>
                                        </div>
                                        <span class="item-price">MWK${itemTotal.toFixed(2)}</span>
                                        <button class="remove-item">×</button>
                                    </div>`;
                cartItemsList.insertAdjacentHTML('beforeend', cartItemHTML);
            });
        }
        updateTotals();
    }
    
    function filterAndSearchProducts() {
        const searchTerm = searchInput.value.toLowerCase();
        const activeCategory = document.querySelector('.category-btn.active').dataset.category;
        const allProducts = document.querySelectorAll('.product-card');
        allProducts.forEach(product => {
            const productName = product.dataset.name.toLowerCase();
            const productSku = product.dataset.sku.toLowerCase();
            const productCategory = product.dataset.category;
            const categoryMatch = (activeCategory === 'All' || productCategory === activeCategory);
            const searchMatch = (productName.includes(searchTerm) || productSku.includes(searchTerm));
            if (categoryMatch && searchMatch) {
                product.style.display = 'flex';
            } else {
                product.style.display = 'none';
            }
        });
    }

    function addToCart(productId, productData) {
        const existingItem = cart.find(item => item.id === productId);
        if (existingItem) {
            existingItem.quantity++;
        } else {
            cart.push({
                id: productId, name: productData.name, price: productData.price,
                sku: productData.sku, icon: productData.icon, quantity: 1
            });
        }
        renderCart();
    }
    
    function updateQuantity(productId, change) {
        const item = cart.find(item => item.id === productId);
        if (item) {
            item.quantity += change;
            if (item.quantity <= 0) {
                removeFromCart(productId);
            } else {
                renderCart();
            }
        }
    }
    
    function removeFromCart(productId) {
        cart = cart.filter(item => item.id !== productId);
        renderCart();
    }

    // --- 5. INITIALIZATION ---
    function initializePOS() {
        // These will use the updated render functions with the correct currency
        fetchCategories();
        fetchProducts();
        renderCart();
    }

    initializePOS();
});
</script>

</body>
</html>