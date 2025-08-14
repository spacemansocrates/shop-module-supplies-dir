<?php
// --- search_products.php (UPDATED) ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Start the session to access session variables
session_start();

header('Content-Type: application/json');

// Check if a shop_id is set in the session. If not, the user is not "logged in".
if (!isset($_SESSION['shop_id']) || empty($_SESSION['shop_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'No shop selected. Please log in.']);
    exit();
}

$current_shop_id = (int)$_SESSION['shop_id'];

// --- Database Connection (remains the same) ---
$db_host = 'srv582.hstgr.io';
$db_name = 'u789944046_suppliesdirect';
$db_user = 'u789944046_socrates';
$db_pass = 'Naho1386';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// --- Get Search Query (remains the same) ---
$query = isset($_GET['query']) ? $_GET['query'] : '';
$search_term = '%' . $query . '%';

// --- Prepare and Execute SQL Query (remains the same) ---
$sql = "
    SELECT 
        p.id, p.name, p.sku, 
        p.default_unit_price as price, 
        ss.quantity_in_stock as stock,
        c.name as category,
        'fa-box-open' as icon
    FROM 
        products p
    LEFT JOIN 
        shop_stock ss ON p.id = ss.product_id AND ss.shop_id = ? -- Filter stock by current shop
    LEFT JOIN 
        categories c ON p.category_id = c.id
    WHERE 
        (p.name LIKE ? OR p.sku LIKE ?)
    LIMIT 20";

$stmt = $pdo->prepare($sql);
// Use the $current_shop_id from the session in the query
$stmt->execute([$current_shop_id, $search_term, $search_term]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($products);