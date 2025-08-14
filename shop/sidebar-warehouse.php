<?php
// Get the current script name to set the 'active' class
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<nav id="sidebar">
    <div class="sidebar-header">
        <h3>Warehouse Portal</h3>
    </div>

    <ul class="list-unstyled components">
        <li class="<?= $current_page == 'warehouse_dashboard.php' ? 'active' : '' ?>">
            <a href="warehouse_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        </li>
        <li class="<?= $current_page == 'warehouse_requests.php' ? 'active' : '' ?>">
            <a href="warehouse_requests.php"><i class="fas fa-dolly-flatbed"></i> Stock Requests</a>
        </li>
        <li class="<?= $current_page == 'warehouse_inventory.php' ? 'active' : '' ?>">
            <a href="warehouse_inventory.php"><i class="fas fa-boxes"></i> Inventory & Stock Take</a>
        </li>
         <li class="<?= $current_page == '/Quotation_SDL/logout.php' ? 'active' : '' ?>">
            <a href="/Quotation_SDL/logout.php"><i class="fas fa-logout"></i> Log out</a>
        </li>
    </ul>
</nav>

<style>
/* Basic Sidebar Styling - Can be expanded */
#sidebar { min-width: 250px; max-width: 250px; background: #2c3e50; color: #fff; transition: all 0.3s; }
#sidebar .sidebar-header { padding: 20px; background: #1a252f; text-align: center; }
#sidebar ul.components { padding: 20px 0; border-bottom: 1px solid #47748b; }
#sidebar ul p { color: #fff; padding: 10px; }
#sidebar ul li a { padding: 15px 20px; font-size: 1.1em; display: block; color: #ced4da; }
#sidebar ul li a:hover { color: #fff; background: #1a252f; }
#sidebar ul li.active > a, a[aria-expanded="true"] { color: #fff; background: #1a252f; }
#sidebar ul li a i { margin-right: 10px; }
</style>