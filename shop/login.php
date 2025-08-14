<?php
session_start();

// Redirect if user is already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['warehouse_id'])) {
        header('Location: warehouse_requests.php');
    } else {
        header('Location: dashboard.php'); // Or your main shop page
    }
    exit();
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_once '../includes/db_connect.php';

    try {
        $pdo = getDatabaseConnection();
    } catch (\PDOException $e) {
        $error_message = "Database connection failed.";
    }
    // --- End DB Connection ---

    if (empty($error_message)) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        if (empty($username) || empty($password)) {
            $error_message = "Username and password are required.";
        } else {
            // =======================================================
            // THE CORRECTED SQL QUERY
            // This combines your user_shop_access logic with the new warehouse_id logic.
            // =======================================================
            $sql = "SELECT 
                        u.id, 
                        u.username, 
                        u.password_hash, 
                        u.role, 
                        u.full_name,
                        u.warehouse_id,
                        s.id AS shop_id,
                        s.name AS shop_name 
                    FROM users u
                    LEFT JOIN user_shop_access usa ON u.id = usa.user_id
                    LEFT JOIN shops s ON usa.shop_id = s.id AND s.is_active = 1
                    WHERE u.username = ? AND u.is_active = 1
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Password is correct, start the session
                session_regenerate_id(true);

                // Store core user data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                // =======================================================
                // THE REDIRECTION LOGIC (This part remains the same and will now work)
                // =======================================================
                if (!empty($user['warehouse_id'])) {
                    // This is a WAREHOUSE USER
                    $_SESSION['warehouse_id'] = $user['warehouse_id'];
                    
                    // Update last login time
                    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

                    // Redirect to warehouse portal
                    header('Location: warehouse_requests.php');
                    exit();

                } elseif (!empty($user['shop_id'])) {
                    // This is a SHOP USER
                    $_SESSION['shop_id'] = $user['shop_id'];
                    $_SESSION['shop_name'] = $user['shop_name'];

                    // Update last login time
                    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

                    // Redirect to shop portal
                    header('Location: dashboard.php'); // Or your shop dashboard
                    exit();
                    
                } else {
                    // User exists, password correct, but they have no location assignment.
                    session_destroy();
                    $error_message = 'Access denied. Your account is not assigned to an active location.';
                }

            } else {
                // Invalid username or password
                $error_message = "Invalid username or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <!-- Your HTML and CSS can remain exactly the same as in your original file -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f7fa; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-container { background-color: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); width: 100%; max-width: 400px; text-align: center; }
        .login-container h1 { color: #333; margin-bottom: 10px; font-size: 24px; }
        .login-container p { color: #777; margin-bottom: 30px; }
        .login-form input { width: 100%; padding: 12px 20px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #ddd; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        .login-form button { width: 100%; padding: 12px; border: none; background-color: #007bff; color: white; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 500; transition: background-color 0.3s ease; }
        .login-form button:hover { background-color: #0056b3; }
        .error-message { color: #dc3545; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Welcome Back!</h1>
        <p>Please log in to your account</p>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form class="login-form" action="login.php" method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>