<?php
function getPDO() {
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function getMySQLi() {
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $port = getenv('DB_PORT') ?: 3306;
    $conn = new mysqli($host, $user, $pass, $name, $port);
    if ($conn->connect_error) {
        die("Database Connection Failed: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    $timezone = getenv('DB_TIMEZONE') ?: '+02:00';
    $conn->query("SET time_zone = '$timezone'");
    return $conn;
}

