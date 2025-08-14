<?php
// --- Authentication and Session Handling ---
session_start();
require_once 'db_connect.php';

// If user is not logged in or has no shop assigned, redirect to login page.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    header('Location: login.php');
    exit();
}

// Store session variables for easy access
$user_id = $_SESSION['user_id'];
$shop_id = $_SESSION['shop_id'];
$username = $_SESSION['full_name'];
$currentDate = date("F j, Y");

// --- Data Fetching from Database ---

// Get current shop and user info
$shop_info_stmt = $conn->prepare("SELECT name, shop_code FROM shops WHERE id = ?");
$shop_info_stmt->bind_param("i", $shop_id);
$shop_info_stmt->execute();
$shop_info = $shop_info_stmt->get_result()->fetch_assoc();
$shop_info_stmt->close();

$user_info_stmt = $conn->prepare("SELECT last_login_at FROM users WHERE id = ?");
$user_info_stmt->bind_param("i", $user_id);
$user_info_stmt->execute();
$user_info = $user_info_stmt->get_result()->fetch_assoc();
$user_info_stmt->close();
$last_login_time = $user_info['last_login_at'] ? date("g:i A", strtotime($user_info['last_login_at'])) : 'N/A';

// --- STATS CARDS ---
$stats = [];

// 1. Low Stock Items
$stmt = $conn->prepare("SELECT COUNT(id) as count FROM shop_stock WHERE shop_id = ? AND quantity_in_stock <= minimum_stock_level AND minimum_stock_level > 0");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$stats['low_stock_items'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();

// New low stock items since yesterday (simplified as items updated recently that are low)
$stmt = $conn->prepare("SELECT COUNT(id) as count FROM shop_stock WHERE shop_id = ? AND quantity_in_stock <= minimum_stock_level AND minimum_stock_level > 0 AND last_updated >= CURDATE() - INTERVAL 1 DAY");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$stats['new_since_yesterday'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();


// 2. Today's Sales & Percentage Change
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_net_amount), 0) as total FROM invoices WHERE shop_id = ? AND invoice_date = CURDATE()");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$todays_sales = $stmt->get_result()->fetch_assoc()['total'];
$stats['todays_sales'] = $todays_sales;
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_net_amount), 0) as total FROM invoices WHERE shop_id = ? AND invoice_date = CURDATE() - INTERVAL 1 DAY");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$yesterdays_sales = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

if ($yesterdays_sales > 0) {
    $stats['sales_change_percent'] = round((($todays_sales - $yesterdays_sales) / $yesterdays_sales) * 100);
} else {
    $stats['sales_change_percent'] = $todays_sales > 0 ? 100 : 0; // Or handle as 'N/A'
}

// 3. Petty Cash
$stmt = $conn->prepare("SELECT pcf.current_balance, (SELECT pct.amount FROM petty_cash_transactions pct WHERE pct.float_id = pcf.id AND pct.transaction_type = 'expense' ORDER BY pct.transaction_date DESC LIMIT 1) as last_expense FROM petty_cash_floats pcf WHERE pcf.shop_id = ? AND pcf.is_active = 1 LIMIT 1");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$petty_cash_data = $stmt->get_result()->fetch_assoc();
$stats['petty_cash'] = $petty_cash_data['current_balance'] ?? 0.00;
$stats['last_expense'] = $petty_cash_data['last_expense'] ?? 0.00;
$stmt->close();

// 4. Incoming Transfers
$stmt = $conn->prepare("SELECT COUNT(id) as count FROM stock_transfers WHERE to_shop_id = ? AND status = 'In-Transit'");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$stats['incoming_transfers'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();

// --- RECENT ACTIVITY ---
$float_id_query = $conn->prepare("SELECT id FROM petty_cash_floats WHERE shop_id = ? AND is_active = 1 LIMIT 1");
$float_id_query->bind_param("i", $shop_id);
$float_id_query->execute();
$float_id_result = $float_id_query->get_result();
$float_id = $float_id_result->num_rows > 0 ? $float_id_result->fetch_assoc()['id'] : -1;
$float_id_query->close();

$sql_activity = "
    (SELECT 'fa-shopping-cart' as icon, 'green' as color, 'New sale completed' as title, CONCAT('Sale #', invoice_number, ' - $', FORMAT(total_net_amount, 2)) as detail, created_at as timestamp FROM invoices WHERE shop_id = ? ORDER BY created_at DESC LIMIT 2)
    UNION ALL
    (SELECT 'fa-wallet' as icon, 'blue' as color, 'Petty cash expense recorded' as title, CONCAT('$', FORMAT(amount, 2), ' - ', description) as detail, transaction_date as timestamp FROM petty_cash_transactions WHERE float_id = ? AND transaction_type = 'expense' ORDER BY transaction_date DESC LIMIT 2)
    UNION ALL
    (SELECT 'fa-truck' as icon, 'orange' as color, 'Stock transfer shipped' as title, CONCAT('Request #', transfer_reference) as detail, shipped_at as timestamp FROM stock_transfers WHERE to_shop_id = ? AND status = 'In-Transit' ORDER BY shipped_at DESC LIMIT 2)
    UNION ALL
    (SELECT 'fa-exclamation-triangle' as icon, 'red' as title, 'Low stock alert triggered' as title, CONCAT('Product #', p.sku, ' - ', p.name) as detail, ss.last_updated as timestamp FROM shop_stock ss JOIN products p ON ss.product_id = p.id WHERE ss.shop_id = ? AND ss.quantity_in_stock <= ss.minimum_stock_level AND ss.minimum_stock_level > 0 ORDER BY ss.last_updated DESC LIMIT 2)
    ORDER BY timestamp DESC
    LIMIT 4
";

$stmt = $conn->prepare($sql_activity);
$stmt->bind_param("iiii", $shop_id, $float_id, $shop_id, $shop_id);
$stmt->execute();
$recent_activity_result = $stmt->get_result();
$recent_activity = [];
while($row = $recent_activity_result->fetch_assoc()) {
    $row['time'] = time_ago($row['timestamp']); // Use the time_ago function
    $recent_activity[] = $row;
}
$stmt->close();

// --- TOP SELLING ITEMS (Last 30 days) ---
$total_qty_sold_stmt = $conn->prepare("SELECT SUM(ii.quantity) as total FROM invoice_items ii JOIN invoices i ON ii.invoice_id = i.id WHERE i.shop_id = ? AND i.invoice_date >= CURDATE() - INTERVAL 30 DAY");
$total_qty_sold_stmt->bind_param("i", $shop_id);
$total_qty_sold_stmt->execute();
$total_qty_sold = $total_qty_sold_stmt->get_result()->fetch_assoc()['total'] ?? 1; // Avoid division by zero
$total_qty_sold_stmt->close();

$top_items_stmt = $conn->prepare("SELECT p.name, SUM(ii.quantity) as total_sold FROM invoice_items ii JOIN products p ON ii.product_id = p.id JOIN invoices i ON ii.invoice_id = i.id WHERE i.shop_id = ? AND i.invoice_date >= CURDATE() - INTERVAL 30 DAY GROUP BY p.id, p.name ORDER BY total_sold DESC LIMIT 4");
$top_items_stmt->bind_param("i", $shop_id);
$top_items_stmt->execute();
$top_items_result = $top_items_stmt->get_result();
$top_selling_items = [];
while($item = $top_items_result->fetch_assoc()) {
    $item['percent'] = ($total_qty_sold > 0) ? round(($item['total_sold'] / $total_qty_sold) * 100) : 0;
    $top_selling_items[] = $item;
}
$top_items_stmt->close();

// --- SALES CHART DATA (Sales by hour for today) ---
$chart_labels = [];
$chart_data = [];
// Initialize chart with hours 8am to 5pm (17:00)
for ($i = 8; $i <= 17; $i++) {
    $chart_labels[] = date('ga', strtotime("$i:00")); // e.g., 8am, 1pm
    $chart_data[$i] = 0;
}

$chart_stmt = $conn->prepare("SELECT HOUR(created_at) as sale_hour, SUM(total_net_amount) as hourly_total FROM invoices WHERE shop_id = ? AND invoice_date = CURDATE() GROUP BY sale_hour ORDER BY sale_hour ASC");
$chart_stmt->bind_param("i", $shop_id);
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();
while($row = $chart_result->fetch_assoc()) {
    if (isset($chart_data[$row['sale_hour']])) {
        $chart_data[(int)$row['sale_hour']] = (float)$row['hourly_total'];
    }
}
$chart_stmt->close();
// Final data array for JS
$final_chart_data = array_values($chart_data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Manager Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="dashboard-container">
        <!-- ==================== SIDEBAR ==================== -->
       <?php require_once 'sidebar.php'; ?>
        <!-- ==================== MAIN CONTENT ==================== -->
        <main class="main-content">
            <header class="main-header">
                <div class="welcome-message">
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $username)[0]); ?></h1>
                    <p><?php echo htmlspecialchars($shop_info['name']); ?> | Last login: Today, <?php echo $last_login_time; ?></p>
                </div>
                <div class="header-actions">
                    <span class="date"><?php echo $currentDate; ?></span>
                    <i class="fas fa-bell notification-bell"></i>
                </div>
            </header>

            <section class="quick-actions-section">
                <h2 class="sr-only">Quick Actions</h2>
                <div class="quick-actions">
                    <button class="action-button new-sale"><i class="fas fa-shopping-cart"></i> New Sale</button>
                   <button class="action-button record-expense">
  <i class="fa-solid fa-money-bill-wave"></i> Record Expense
</button>

                    <button class="action-button request-stock"><i class="fas fa-dolly-flatbed"></i> Request Stock</button>
                </div>
            </section>

            <section class="shop-status-section">
                <h2>Shop Status</h2>
                <div class="status-cards">
                    <!-- Low Stock Alert Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Low Stock Alert</h3>
                            <div class="card-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                        <div class="card-body">
                            <h2><?php echo $stats['low_stock_items']; ?> Items</h2>
                        </div>
                        <div class="card-footer">
                            <p><span class="trend-up"><i class="fas fa-arrow-up"></i> <?php echo $stats['new_since_yesterday']; ?> new since yesterday</span></p>
                            <a href="#">View details</a>
                        </div>
                    </div>
                    <!-- Today's Sales Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Today's Sales</h3>
                            <div class="card-icon green"><i class="fas fa-dollar-sign"></i></div>
                        </div>
                        <div class="card-body">
                            <h2>MWK<?php echo number_format($stats['todays_sales'], 2); ?></h2>
                        </div>
                        <div class="card-footer">
                            <p><span class="<?php echo $stats['sales_change_percent'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                                <i class="fas fa-arrow-<?php echo $stats['sales_change_percent'] >= 0 ? 'up' : 'down'; ?>"></i> 
                                <?php echo abs($stats['sales_change_percent']); ?>% from yesterday</span>
                            </p>
                            <a href="#">View details</a>
                        </div>
                    </div>
                    <!-- Petty Cash Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Petty Cash</h3>
                            <div class="card-icon blue"><i class="fas fa-wallet"></i></div>
                        </div>
                        <div class="card-body">
                            <h2>MWK<?php echo number_format($stats['petty_cash'], 2); ?></h2>
                        </div>
                        <div class="card-footer">
                            <p>— Last expense: $<?php echo number_format($stats['last_expense'], 2); ?></p>
                            <a href="petty_cash_ledger.php">View details</a>
                        </div>
                    </div>
                    <!-- Incoming Stock Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Incoming Stock</h3>
                            <div class="card-icon orange"><i class="fas fa-truck"></i></div>
                        </div>
                        <div class="card-body">
                            <h2><?php echo $stats['incoming_transfers']; ?> Transfers</h2>
                        </div>
                        <div class="card-footer">
                            <p><i class="fas fa-clock"></i> In-Transit</p>
                            <a href="stock_requests.php">View details</a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="recent-activity-section">
                <div class="recent-activity-header">
                    <h2>Recent Activity</h2>
                    <a href="#">View all</a>
                </div>
                <div class="activity-list">
                    <?php foreach ($recent_activity as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon <?php echo $activity['color']; ?>">
                            <i class="fas <?php echo $activity['icon']; ?>"></i>
                        </div>
                        <div class="activity-details">
                            <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                            <p><?php echo htmlspecialchars($activity['detail']); ?></p>
                        </div>
                        <span class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            
            <section class="overview-section">
                <div class="overview-header">
                    <h2>Today's Overview</h2>
                    <i class="fas fa-ellipsis-h"></i>
                </div>
                <div class="overview-grid">
                    <div class="chart-container">
                        <h3>Sales by Hour</h3>
                        <canvas id="salesChart"></canvas>
                    </div>
                    <div class="top-items-container">
                        <h3>Top Selling Items</h3>
                        <ul class="top-items-list">
                            <?php foreach ($top_selling_items as $item): ?>
                            <li class="top-item">
                                <div class="item-details">
                                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="progress-bar">
                                        <div class="progress" style="width: <?php echo $item['percent']; ?>%;"></div>
                                    </div>
                                </div>
                                <span class="item-percent"><?php echo $item['percent']; ?>%</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const ctx = document.getElementById('salesChart').getContext('2d');
            
            // Data is now passed from PHP
            const chartLabels = <?php echo json_encode($chart_labels); ?>;
            const chartData = <?php echo json_encode($final_chart_data); ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Sales',
                        data: chartData,
                        borderColor: 'var(--primary-blue)',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'var(--primary-blue)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'var(--primary-blue)',
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { display: false, drawBorder: false },
                            ticks: {
                                callback: function(value) { return '$' + value; },
                                stepSize: 100
                            }
                        },
                        x: { grid: { display: false, drawBorder: false } }
                    }
                }
            });
        });
    </script>
</body>
</html>