<?php
// --- PHP Data Simulation for Products ---
// In a real application, this data would be fetched from a database.
$products = [
    [
        'id' => 1,
        'name' => 'Smartphone X',
        'sku' => 'SM-X1001',
        'price' => 599.99,
        'stock' => 15,
        'icon' => 'fa-mobile-alt',
        'category' => 'Electronics'
    ],
    [
        'id' => 2,
        'name' => 'Wireless Headphones',
        'sku' => 'WH-2210',
        'price' => 89.99,
        'stock' => 8,
        'icon' => 'fa-headphones',
        'category' => 'Electronics'
    ],
    [
        'id' => 3,
        'name' => 'Laptop Pro',
        'sku' => 'LP-3300',
        'price' => 1299.99,
        'stock' => 5,
        'icon' => 'fa-laptop',
        'category' => 'Electronics'
    ],
    [
        'id' => 4,
        'name' => 'Mechanical Keyboard',
        'sku' => 'KB-4400',
        'price' => 129.99,
        'stock' => 12,
        'icon' => 'fa-keyboard',
        'category' => 'Electronics'
    ],
    [
        'id' => 5,
        'name' => 'Wireless Mouse',
        'sku' => 'WM-5500',
        'price' => 49.99,
        'stock' => 20,
        'icon' => 'fa-mouse',
        'category' => 'Electronics'
    ],
    [
        'id' => 6,
        'name' => 'Monitor 27"',
        'sku' => 'MN-6600',
        'price' => 249.99,
        'stock' => 7,
        'icon' => 'fa-desktop',
        'category' => 'Electronics'
    ]
];
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

    <style>
        :root {
            --primary-blue: #007bff;
            --sidebar-bg: #2c3e50;
            --main-bg: #f4f6f9;
            --card-bg: #ffffff;
            --text-dark: #343a40;
            --text-light: #6c757d;
            --border-color: #e9ecef;
            --stock-green: #e9f6ec;
            --stock-text-green: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--main-bg);
            color: var(--text-dark);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* --- POS Sidebar --- */
        .pos-sidebar {
            width: 80px;
            background-color: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
        }

        .pos-sidebar .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 30px;
        }

        .sidebar-nav {
            list-style: none;
        }

        .sidebar-nav li a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            margin-bottom: 15px;
            border-radius: 10px;
            color: #bdc3c7;
            font-size: 1.5rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .sidebar-nav li a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav li a.active {
            background-color: var(--primary-blue);
            color: white;
        }

        .sidebar-nav li:last-child {
            margin-top: auto;
        }

        /* --- Main POS Content --- */
        .pos-main {
            flex: 1;
            padding: 24px 32px;
            display: flex;
            flex-direction: column;
        }

        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .main-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }

        .cashier-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-light);
        }
        
        .cashier-info .icon {
            font-size: 1.5rem;
            color: var(--primary-blue);
        }

        .product-selection {
            flex: 1;
            overflow-y: auto;
            padding-right: 15px; /* for scrollbar */
        }
        
        /* Custom scrollbar for webkit browsers */
        .product-selection::-webkit-scrollbar { width: 8px; }
        .product-selection::-webkit-scrollbar-track { background: #f1f1f1; }
        .product-selection::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        .product-selection::-webkit-scrollbar-thumb:hover { background: #aaa; }


        .search-bar {
            position: relative;
            margin-bottom: 24px;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
        }
        
        .search-bar i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }
        
        .categories {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
        }

        .category-btn {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }

        .category-btn:hover {
            background-color: #e9ecef;
        }

        .category-btn.active {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .product-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .product-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-3px);
        }

        .product-info {
            display: flex;
            gap: 16px;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .product-info .icon {
            font-size: 1.5rem;
            color: var(--text-light);
        }

        .product-details h4 { font-size: 0.95rem; font-weight: 600; margin: 0; }
        .product-details p { font-size: 0.8rem; color: var(--text-light); margin: 0; }
        
        .product-card .price {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin-bottom: 8px;
        }
        
        .product-card .stock {
            font-size: 0.8rem;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 6px;
            background-color: var(--stock-green);
            color: var(--stock-text-green);
            display: inline-block;
        }

        /* --- Sale Summary --- */
        .sale-summary {
            width: 400px;
            background-color: var(--card-bg);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 24px;
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .summary-header h2 { font-size: 1.2rem; font-weight: 600; }
        .summary-header .clear-all { color: #dc3545; text-decoration: none; font-weight: 500; font-size: 0.9rem;}
        
        .cart-items-list {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 16px;
        }

        .cart-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .cart-item:last-child { border-bottom: none; }
        
        .cart-item .icon { font-size: 1.3rem; color: var(--text-light); }
        .cart-item .item-details { flex-grow: 1; }
        .cart-item h4 { font-size: 0.9rem; font-weight: 500; }
        .cart-item p { font-size: 0.8rem; color: var(--text-light); }
        
        .quantity-controls { display: flex; align-items: center; gap: 8px; }
        .quantity-controls button {
            width: 24px; height: 24px;
            border: 1px solid var(--border-color);
            background: none; border-radius: 4px; cursor: pointer;
        }
        .quantity-controls input {
            width: 30px; text-align: center; border: none; font-weight: 500;
        }
        
        .cart-item .item-price { font-weight: 600; font-size: 0.95rem; }
        .cart-item .remove-item {
            background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 1rem;
        }
        
        .customer-info { margin-bottom: 24px; }
        .customer-info-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;}
        .customer-info-header h3 { font-size: 1rem; font-weight: 600;}
        .customer-info-header a { text-decoration: none; color: var(--primary-blue); font-weight: 500; font-size: 0.9rem;}
        .customer-info p { color: var(--text-light); }

        .bill-summary { font-size: 0.95rem; margin-bottom: 24px;}
        .bill-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .bill-row span:first-child { color: var(--text-light); }
        .bill-row span:last-child { font-weight: 500; }
        .bill-row a { text-decoration: none; color: var(--primary-blue); }
        .total-row {
            border-top: 2px solid var(--border-color);
            padding-top: 16px;
            margin-top: 16px;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .payment-options { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 24px; }
        .payment-btn {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: white;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .payment-btn.active, .payment-btn:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }
        
        .complete-sale-btn {
            width: 100%;
            padding: 16px;
            background-color: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .complete-sale-btn:hover { opacity: 0.9; }

    </style>
</head>
<body>
    
    <!-- ==================== SIDEBAR ==================== -->
    <aside class="pos-sidebar">
        <div class="logo">POS</div>
        <nav>
            <ul class="sidebar-nav">
                <li><a href="#"><i class="fas fa-home"></i></a></li>
                <li><a href="#" class="active"><i class="fas fa-cash-register"></i></a></li>
                <li><a href="#"><i class="fas fa-box"></i></a></li>
                <li><a href="#"><i class="fas fa-chart-line"></i></a></li>
                <li><a href="#"><i class="fas fa-users"></i></a></li>
                <li><a href="#"><i class="fas fa-cog"></i></a></li>
            </ul>
        </nav>
    </aside>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="pos-main">
        <header class="main-header">
            <h1>New Sale</h1>
            <div class="cashier-info">
                <span>Cashier: John Doe</span>
                <i class="fas fa-user-circle icon"></i>
            </div>
        </header>

        <div class="product-selection">
            <div class="search-bar">
                <i class="fas fa-barcode"></i>
                <input type="text" placeholder="Scan barcode or search products...">
            </div>

            <div class="categories">
                <h3>Categories</h3>
                <button class="category-btn active" data-category="All">All</button>
                <button class="category-btn" data-category="Electronics">Electronics</button>
                <button class="category-btn" data-category="Groceries">Groceries</button>
                <button class="category-btn" data-category="Clothing">Clothing</button>
                <button class="category-btn" data-category="Home">Home</button>
            </div>

            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                <div class="product-card" data-id="<?php echo $product['id']; ?>" data-name="<?php echo htmlspecialchars($product['name']); ?>" data-price="<?php echo $product['price']; ?>" data-sku="<?php echo $product['sku']; ?>" data-icon="<?php echo $product['icon']; ?>" data-category="<?php echo $product['category']; ?>">
                    <div class="product-info">
                        <i class="fas <?php echo $product['icon']; ?> icon"></i>
                        <div class="product-details">
                            <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                            <p>SKU: <?php echo $product['sku']; ?></p>
                        </div>
                    </div>
                    <div class="price">$<?php echo number_format($product['price'], 2); ?></div>
                    <div class="stock">In Stock: <?php echo $product['stock']; ?></div>
                </div>
                <?php endforeach; ?>
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
                <span id="subtotal">$0.00</span>
            </div>
            <div class="bill-row">
                <span>VAT (10%)</span>
                <span id="vat">$0.00</span>
            </div>
            <div class="bill-row">
                <span>Discount</span>
                <a href="#" id="discount">Add</a>
            </div>
            <div class="bill-row total-row">
                <span>Total</span>
                <span id="total">$0.00</span>
            </div>
        </div>

        <div class="payment-options">
            <button class="payment-btn active"><i class="fas fa-money-bill-wave"></i> Cash</button>
            <button class="payment-btn"><i class="far fa-credit-card"></i> Card</button>
            <button class="payment-btn"><i class="fas fa-mobile-alt"></i> Mobile</button>
        </div>

        <button class="complete-sale-btn"><i class="fas fa-check-circle"></i> Complete Sale</button>
    </aside>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const productGrid = document.querySelector('.product-grid');
    const cartItemsList = document.querySelector('.cart-items-list');
    const subtotalEl = document.getElementById('subtotal');
    const vatEl = document.getElementById('vat');
    const totalEl = document.getElementById('total');
    const clearAllBtn = document.querySelector('.clear-all');
    const categoryBtns = document.querySelectorAll('.category-btn');

    let cart = []; // { id, name, price, quantity, sku, icon }
    const VAT_RATE = 0.10;

    // --- EVENT LISTENERS ---

    // Add product to cart
    productGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card');
        if (card) {
            const productId = card.dataset.id;
            addToCart(productId, card.dataset);
        }
    });

    // Handle cart quantity changes and item removal
    cartItemsList.addEventListener('click', (e) => {
        const target = e.target;
        const cartItem = target.closest('.cart-item');
        if (!cartItem) return;

        const productId = cartItem.dataset.id;
        
        if (target.matches('.qty-plus')) {
            updateQuantity(productId, 1);
        } else if (target.matches('.qty-minus')) {
            updateQuantity(productId, -1);
        } else if (target.matches('.remove-item')) {
            removeFromCart(productId);
        }
    });
    
    // Clear the entire cart
    clearAllBtn.addEventListener('click', (e) => {
        e.preventDefault();
        cart = [];
        renderCart();
    });
    
    // Handle category filtering
    categoryBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            categoryBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterProducts(btn.dataset.category);
        });
    });


    // --- FUNCTIONS ---

    function addToCart(productId, productData) {
        const existingItem = cart.find(item => item.id === productId);
        if (existingItem) {
            existingItem.quantity++;
        } else {
            cart.push({
                id: productId,
                name: productData.name,
                price: parseFloat(productData.price),
                sku: productData.sku,
                icon: productData.icon,
                quantity: 1
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
    
    function renderCart() {
        cartItemsList.innerHTML = ''; // Clear current view
        if (cart.length === 0) {
             cartItemsList.innerHTML = '<p style="text-align:center; color: var(--text-light); margin-top:20px;">Your cart is empty.</p>';
        } else {
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                const cartItemHTML = `
                    <div class="cart-item" data-id="${item.id}">
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
                        <span class="item-price">$${itemTotal.toFixed(2)}</span>
                        <button class="remove-item">×</button>
                    </div>
                `;
                cartItemsList.insertAdjacentHTML('beforeend', cartItemHTML);
            });
        }
        updateTotals();
    }

    function updateTotals() {
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        const vat = subtotal * VAT_RATE;
        const total = subtotal + vat;

        subtotalEl.textContent = `$${subtotal.toFixed(2)}`;
        vatEl.textContent = `$${vat.toFixed(2)}`;
        totalEl.textContent = `$${total.toFixed(2)}`;
    }
    
    function filterProducts(category) {
        const allProducts = document.querySelectorAll('.product-card');
        allProducts.forEach(product => {
            if (category === 'All' || product.dataset.category === category) {
                product.style.display = 'block';
            } else {
                product.style.display = 'none';
            }
        });
    }

    // Initial render on load
    renderCart();
});
</script>

</body>
</html>