<?php
// api/get_stock_details.php
header('Content-Type: application/json');
session_start();

// --- Security & Auth ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// --- Inputs ---
$shop_id = (int)$_SESSION['shop_id'];
$date = $_GET['date'] ?? date('Y-m-d');
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

if (!$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Product ID is required.']);
    exit;
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $name = 'u789944046_suppliesdirect';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Connection Failed.']);
    exit();
}

// --- Fetch Stock Details ---
$sql_stock_details = "
    SELECT
        st.transaction_date,
        st.transaction_type,
        st.quantity,
        st.notes,
        p.name as product_name,
        p.sku
    FROM
        stock_transactions st
    JOIN
        products p ON st.product_id = p.id
    WHERE
        st.shop_id = :shop_id AND DATE(st.transaction_date) = :date AND st.product_id = :product_id
    ORDER BY
        st.transaction_date ASC
";

$stmt_stock_details = $pdo->prepare($sql_stock_details);
$stmt_stock_details->execute([
    'shop_id' => $shop_id,
    'date' => $date,
    'product_id' => $product_id
]);
$stock_details = $stmt_stock_details->fetchAll();

echo json_encode($stock_details);
?>
