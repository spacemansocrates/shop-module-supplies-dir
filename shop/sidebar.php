<?php
// sidebar.php

// Get the current page's filename to determine which link is active
$current_page = basename($_SERVER['PHP_SELF']);
?>
<head>
        <link rel="stylesheet" href="style.css">
</head>
<aside class="sidebar">
    <h1 class="sidebar-header">Supplies Direct</h1>
    <nav>
        <ul class="sidebar-nav">
            <!-- For each link, we check if it's the current page and add the 'active' class -->
            <li>
                <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="pos.php" class="<?php echo ($current_page == 'pos.php') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Sales
                </a>
            </li>
            <!-- In sidebar.php -->
<li class="nav-item">
    <a href="counter_sales.php" class="<?php echo ($current_page == 'counter_sales') ? 'active' : ''; ?>">
        <i class="fas fa-calculator"></i>
        <span>Counter Sales</span>
    </a>
</li>
        
            <li>
                <a href="petty_cash_ledger.php" class="<?php echo ($current_page == 'petty_cash.php') ? 'active' : ''; ?>">
                    <i class="fas fa-wallet"></i> Petty Cash
                </a>
            </li>
            <li>
                <a href="request_stock.php" class="<?php echo ($current_page == 'request_stock.php') ? 'active' : ''; ?>">
                    <i class="fas fa-truck-loading"></i> Stock Requests
                </a>
            </li>
            <li>
                <a href="#" class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
             <li>
                <a href="/Quotation_SDL/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>