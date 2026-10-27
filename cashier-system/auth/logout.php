<?php
// logout.php - ends the session and returns to the login page.

define('PUBLIC_PAGE', true);
require __DIR__ . '/../includes/auth.php';   // starts the session

$_SESSION = [];   // forget who was logged in

// Delete the session cookie in the browser as well.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
