<?php
// includes/db.php
// Connects PHP to the cashier_system database using PDO.
// Every other page loads this file with: require __DIR__ . '/includes/db.php';
 
$host    = 'localhost';
$dbname  = 'cashier_system';
$user    = 'root';   // XAMPP default
$pass    = '';       // XAMPP default is an empty password
$charset = 'utf8mb4';
 
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
 
$options = [
    // Throw an error when a query fails, instead of failing silently.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Return rows as ['name' => 'Cola 2L'] instead of numbered arrays.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Use real prepared statements (safer against SQL injection).
    PDO::ATTR_EMULATE_PREPARES   => false,
];
 
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Do not show the real error to users on a live system.
    http_response_code(500);
    die('Database connection failed. Check includes/db.php and that MySQL is running.');
}
 
// Every page that loads this file needs a logged-in user, unless the page
// sets PUBLIC_PAGE first (login, logout, and first-time setup). See auth.php.
require_once __DIR__ . '/auth.php';