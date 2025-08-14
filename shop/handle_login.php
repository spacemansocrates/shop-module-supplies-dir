<?php
session_start();

// --- 1. DATABASE CONNECTION ---
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = ''; // Your password
$db_name = 'supplies';
$db_port = 3306;
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($mysqli->connect_error) {
    $_SESSION['login_error'] = "Database connection error.";
    header('Location: login.php');
    exit();
}

// --- 2. GET INPUT ---
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION['login_error'] = "Username and password are required.";
    header('Location: login.php');
    exit();
}

// --- 3. FETCH USER FROM DB ---
// We allow login for shopkeeper, manager, or admin roles.
$stmt_user = $mysqli->prepare("SELECT id, username, password_hash, role, full_name, is_active FROM users WHERE username = ? AND role IN ('shopkeeper', 'manager', 'admin')");
$stmt_user->bind_param("s", $username);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if ($result_user->num_rows === 1) {
    $user = $result_user->fetch_assoc();

    // --- 4. VERIFY PASSWORD & STATUS ---
    if ($user['is_active'] && password_verify($password, $user['password_hash'])) {
        
        // --- 5. FETCH SHOP ASSIGNMENT ---
        // A user must be assigned to at least one shop to use the POS
        $stmt_shop = $mysqli->prepare(
            "SELECT s.id, s.name FROM shops s 
             JOIN user_shop_access usa ON s.id = usa.shop_id 
             WHERE usa.user_id = ? AND s.is_active = 1 LIMIT 1"
        );
        $stmt_shop->bind_param("i", $user['id']);
        $stmt_shop->execute();
        $result_shop = $stmt_shop->get_result();

        if ($result_shop->num_rows === 1) {
            $shop = $result_shop->fetch_assoc();

            // --- 6. SUCCESS! CREATE SESSION ---
            session_regenerate_id(true); // Prevent session fixation attacks
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['shop_id'] = $shop['id'];
            $_SESSION['shop_name'] = $shop['name'];

            // Redirect to the POS dashboard
            header('Location: pos.php');
            exit();

        } else {
            // User exists but has no active shop assigned
            $_SESSION['login_error'] = "Access denied. You are not assigned to an active shop.";
        }

    } else {
        // Password incorrect or account inactive
        $_SESSION['login_error'] = "Invalid username or password.";
    }
} else {
    // User not found
    $_SESSION['login_error'] = "Invalid username or password.";
}

// If we reach here, login failed. Redirect back to login page.
header('Location: login.php');
exit();
?>