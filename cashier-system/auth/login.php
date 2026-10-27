<?php
// login.php - the login page.

define('PUBLIC_PAGE', true);   // this page must open without being logged in
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

// Already logged in? Go straight to the DASHBOARD.
if (current_user()) {
    header('Location: ../' . home_page_for(current_user()['role']));
    exit;
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT id, name, username, password_hash, role, is_active FROM users WHERE username = ?'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // password_verify compares the typed password with the saved hash.
    if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);   // a fresh session id after login, for safety
        $_SESSION['user'] = [
            'id'       => (int)$user['id'],
            'name'     => $user['name'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ];
        header('Location: ../' . home_page_for($user['role']));
        exit;
    }

    // Same message for a wrong username or a wrong password, on purpose.
    usleep(400000);   // a short pause makes guessing passwords slower
    $error = 'Wrong username or password.';
}

$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in &middot; CASEMPCO</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/login.css">
</head>
<body class="login-page">
<div class="login-wrap">
    <div class="card login-card">
        <?php if (is_file(dirname(__DIR__) . '/img/logo.png')): ?>
            <!-- Your logo: save the image as img/logo.png -->
            <div class="login-logo-wrap">
                <img class="login-logo" src="../img/logo.png" alt="CASEMPCO">
            </div>
        <?php endif; ?>
        <h1>CASEMPCO</h1>
        <p class="hint" style="text-align:center;">Please log in</p>

        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert--ok"><?= e($_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" value="<?= e($username) ?>"
                       autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </div>
            <button class="btn" type="submit" style="width:100%;">Log in</button>
        </form>

        <?php if ($userCount === 0): ?>
            <p class="hint">No account yet. <a href="setup_admin.php">Create the first admin account.</a></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
