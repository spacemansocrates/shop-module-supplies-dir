<?php
// api/search_products.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

$shop_id = (int)$_SESSION['shop_id'];
$term = $_GET['term'] ?? '';

require_once __DIR__ . '/../config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([]);
    exit();
}

// Query to find products in the current shop that match the search term
$sql = "
    SELECT p.id, p.name as text 
    FROM products p
    JOIN shop_stock ss ON p.id = ss.product_id
    WHERE ss.shop_id = :shop_id AND (p.name LIKE :term OR p.sku LIKE :term)
    LIMIT 20
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['shop_id' => $shop_id, 'term' => '%' . $term . '%']);
$results = $stmt->fetchAll();

// Return data in the format Select2 expects
echo json_encode(['results' => $results]);