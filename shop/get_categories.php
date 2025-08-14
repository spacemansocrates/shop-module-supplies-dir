<?php
// --- get_categories.php ---

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}
$current_shop_id = (int)$_SESSION['shop_id'];

require_once '../includes/db_connect.php';

try {
    $pdo = getDatabaseConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// SQL to get all unique categories for products that are actually in stock at the current shop
// --- THIS PART REMAINS UNCHANGED ---
$sql = "
    SELECT DISTINCT 
        c.id, 
        c.name 
    FROM 
        categories c
    JOIN 
        products p ON c.id = p.category_id
    JOIN 
        shop_stock ss ON p.id = ss.product_id
    WHERE 
        ss.shop_id = ? AND ss.quantity_in_stock > 0
    ORDER BY 
        c.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$current_shop_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ======================= NEW CODE BLOCK STARTS HERE =======================

// 1. Define your category-to-icon mapping here.
// This is the "raw approach" map.
$category_icons = [
    'Electronics' => 'fa-laptop-code',
    'Apparel'     => 'fa-tshirt',
    'Tyres'       => 'fa-car-tire', // The icon you requested!
    'Groceries'   => 'fa-shopping-basket',
    'Books'       => 'fa-book-open',
    // Add any other category-icon pairs here...
    
    // It's crucial to have a fallback icon for any category not in this list.
    'Default'     => 'fa-tag' 
];

// 2. Loop through the categories fetched from the database and add the icon.
$categories_with_icons = [];
foreach ($categories as $category) {
    // Get the category name
    $category_name = $category['name'];

    // Find the icon in our map. If not found, use the 'Default' icon.
    // The '??' operator is a clean way to handle this fallback.
    $icon = $category_icons[$category_name] ?? $category_icons['Default'];
    
    // Add the 'icon' key to the category's array
    $category['icon'] = $icon;
    
    // Add the updated category array to our new results array
    $categories_with_icons[] = $category;
}

// ======================== NEW CODE BLOCK ENDS HERE ========================


// Finally, encode the NEW array that now includes the icons.
echo json_encode($categories_with_icons);