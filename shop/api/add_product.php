<?php
session_start();
// Security Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

// --- DB Connection ---
$host = 'srv582.hstgr.io'; $dbname = 'u789944046_suppliesdirect'; $user = 'u789944046_socrates'; $pass = 'Naho1386'; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $pdo = new PDO($dsn, $user, $pass, $options); } catch (\PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Database error.']); exit(); }

// --- Get and Validate Data ---
$sku = trim($_POST['new_product_sku'] ?? '');
$name = trim($_POST['new_product_name'] ?? '');
$description = trim($_POST['new_product_description'] ?? '');
$category_id = filter_var($_POST['new_product_category'], FILTER_VALIDATE_INT);
$created_by_user_id = $_SESSION['user_id'];

if (empty($sku) || empty($name) || $category_id === false) {
    http_response_code(400);
    echo json_encode(['error' => 'SKU, Name, and Category are required.']);
    exit();
}

// Check if SKU already exists
$stmt_check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
$stmt_check->execute([$sku]);
if ($stmt_check->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode(['error' => 'This SKU already exists. Please use a unique SKU.']);
    exit();
}

// --- Insert into Database ---
try {
    $sql_insert = "INSERT INTO products (sku, name, description, category_id, created_by_user_id, updated_by_user_id) 
                   VALUES (?, ?, ?, ?, ?, ?)";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([$sku, $name, $description, $category_id, $created_by_user_id, $created_by_user_id]);
    
    $new_product_id = $pdo->lastInsertId();

    // Return the new product's data so we can add it to the search dropdown
    $response = [
        'success' => true,
        'message' => 'Product added successfully!',
        'product' => [
            'id' => $new_product_id,
            'sku' => $sku,
            'name' => $name,
            'text' => $sku . ' - ' . $name
        ]
    ];

    http_response_code(201); // Created
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save product. ' . $e->getMessage()]);
}