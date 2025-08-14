<?php
session_start(); // Always call session_start() at the very beginning

echo '<pre>'; // Pre-formatted text for readability
print_r($_SESSION);
echo '</pre>';

// You can also check specific keys
if (isset($_SESSION['user_id'])) {
    echo "User ID: " . $_SESSION['user_id'] . "<br>";
}

if (isset($_SESSION['shop_id'])) {
    echo "Shop ID: " . $_SESSION['shop_id'] . "<br>";
}

if (isset($_SESSION['csrf_token'])) {
    echo "CSRF Token: " . $_SESSION['csrf_token'] . "<br>";
}

// ... rest of your petty_cash.php code
?>